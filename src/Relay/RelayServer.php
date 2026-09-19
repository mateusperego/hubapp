<?php

namespace HubApp\Relay;

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Websocket;
use Workerman\Timer;
use Workerman\Worker;

/**
 * Ponto de encontro entre o painel de suporte e os apps dos vendedores.
 *
 * O celular está atrás do NAT da operadora e o Mac do suporte atrás do roteador
 * do escritório: nenhum dos dois aceita conexão de entrada, mas os dois abrem
 * conexão de saída para cá. Era isso que antes exigia port-forward, DDNS e
 * certificado renovado na mão no Mac.
 *
 * O relay é burro de propósito. Ele autentica, sabe quem está online e repassa
 * mensagem. Não interpreta `query` nem `correction` — esse contrato é entre
 * painel e app, e está em docs/protocol.md do el_monitor_apps.
 */
class RelayServer
{
    /** Intervalo do ping de aplicação para cada ponta. */
    private const HEARTBEAT_SECONDS = 30;

    /** Silêncio total por três janelas de ping derruba a conexão. */
    private const SILENCE_TOLERANCE_SECONDS = 90;

    /** Varredura das sessões de espelhamento sem ninguém assistindo. */
    private const MIRROR_SWEEP_SECONDS = 2;

    /**
     * Relatório do que passou em cada espelhamento.
     *
     * Os contadores existiam desde o primeiro dia e ninguém os lia. Sem esta
     * linha no log, nenhum ajuste de FPS ou de qualidade pode ser defendido com
     * número: as duas pontas só sabem relatar o que *pretendiam* fazer, e o
     * relay é o único ponto do caminho que vê o que de fato passou.
     */
    private const MIRROR_REPORT_SECONDS = 10;

    /** Uma linha de erro por conexão por essa janela. */
    private const ERROR_WINDOW_SECONDS = 5.0;

    /** @var array<int, float> id da conexão => último erro registrado */
    private array $errorWindow = [];

    private Worker $worker;
    private Handshake $handshake;
    private ConnectionRegistry $registry;
    private MirrorRegistry $mirrors;
    private MirrorRouter $mirrorRouter;

    public function __construct(int $port, Handshake $handshake, string $bind = '127.0.0.1')
    {
        $this->handshake = $handshake;
        $this->registry  = new ConnectionRegistry();
        $this->mirrors   = new MirrorRegistry();

        $this->mirrorRouter = new MirrorRouter($this->mirrors, $this->registry);

        $this->worker = new Worker("websocket://{$bind}:{$port}");
        $this->worker->name = 'hubapp-relay';

        // Um processo só: todo o estado de presença e o mapa req_id => painel
        // vive em memória. Com dois processos, o painel cairia num worker e o
        // vendedor no outro, e nenhum dos dois se enxergaria.
        $this->worker->count = 1;

        $this->worker->onWorkerStart = fn() => $this->onStart($port);
        $this->worker->onConnect     = fn(TcpConnection $c) => $this->onConnect($c);
        $this->worker->onMessage     = fn(TcpConnection $c, $frame) => $this->onMessage($c, (string) $frame);
        $this->worker->onClose       = fn(TcpConnection $c) => $this->onClose($c);
        $this->worker->onError       = fn(TcpConnection $c, $code, $message) =>
            $this->onConnectionError($c, (int) $code, (string) $message);
    }

    public function run(): void
    {
        Worker::runAll();
    }

    private function onStart(int $port): void
    {
        RelayLog::info("relay escutando na porta {$port}");

        Timer::add(self::HEARTBEAT_SECONDS, fn() => $this->heartbeat());
        Timer::add(Handshake::DEADLINE_SECONDS, fn() => $this->dropSilentHandshakes());
        Timer::add(self::MIRROR_SWEEP_SECONDS, fn() => $this->sweepIdleMirrors());
        Timer::add(self::MIRROR_REPORT_SECONDS, fn() => $this->reportMirrors());
    }

    private function onConnect(TcpConnection $connection): void
    {
        // O teto do Workerman é de 10 MiB, mas nada neste protocolo passa de
        // `Envelope::MAX_BYTES`. Sem alinhar os dois, um frame de 9 MiB é
        // recebido inteiro na memória do processo só para ser descartado pelo
        // `Envelope::decode` depois. As conexões de mídia baixam isto de novo
        // em `tuneMirrorConnection`, quando o papel já é conhecido.
        $connection->maxPackageSize = Envelope::MAX_BYTES;

        $this->registry->hold($connection);
    }

    private function onMessage(TcpConnection $connection, string $frame): void
    {
        $this->registry->recordSignal($connection);

        // O caminho de mídia vem antes do `Envelope::decode`, e a ordem é o
        // ponto: bytes opacos não podem passar por `json_decode`. Um frame de
        // vídeo de 100 KB, trinta vezes por segundo, seria decodificado como
        // JSON, falharia, e o `strlen > MAX_BYTES` o descartaria em silêncio.
        //
        // O `hello` do socket de mídia chega antes de o papel existir, então
        // cai no caminho JSON normal e é autenticado como qualquer outro.
        $role = $this->registry->roleOf($connection);

        if ($role === Handshake::ROLE_MIRROR_SOURCE) {
            $this->mirrorRouter->forward($connection, $frame);
            return;
        }

        if ($role === Handshake::ROLE_MIRROR_SINK) {
            // O painel não fala por este socket. Descarta e segue.
            return;
        }

        $message = Envelope::decode($frame);

        if ($message === null) {
            // Descartar e seguir. Fechar a conexão do painel por um frame ruim
            // tiraria todos os vendedores do ar de uma vez.
            RelayLog::warning(
                "frame descartado de {$this->describe($connection)}: "
                . 'não é objeto JSON dentro do limite de ' . Envelope::MAX_BYTES . ' bytes'
            );
            return;
        }

        if ($this->registry->isPending($connection)) {
            $this->authenticate($connection, $message);
            return;
        }

        match ($this->registry->roleOf($connection)) {
            Handshake::ROLE_PANEL  => $this->fromPanel($connection, $message),
            Handshake::ROLE_SELLER => $this->fromSeller($connection, $message),
            default                => $connection->close(),
        };
    }

    private function authenticate(TcpConnection $connection, array $message): void
    {
        $verdict = $this->handshake->inspect($message);

        if ($verdict['ok'] !== true) {
            RelayLog::warning(
                "hello recusado de {$connection->getRemoteIp()}: {$verdict['reason']}"
            );
            $connection->close(Envelope::encode([
                'type'  => 'error',
                'error' => $verdict['reason'],
                'code'  => $verdict['code'],
            ]));
            return;
        }

        if ($verdict['role'] === Handshake::ROLE_PANEL) {
            $this->admitPanel($connection, $verdict['identity']['panel_id']);
            return;
        }

        if ($verdict['role'] === Handshake::ROLE_MIRROR_SOURCE) {
            $this->admitMirrorSource($connection, $verdict['identity']);
            return;
        }

        if ($verdict['role'] === Handshake::ROLE_MIRROR_SINK) {
            $this->admitMirrorSink($connection, $verdict['identity']);
            return;
        }

        $this->admitSeller($connection, $verdict['identity']);
    }

    private function admitPanel(TcpConnection $connection, string $panelId): void
    {
        if (!$this->registry->recordPanel($connection, $panelId)) {
            RelayLog::warning("painel {$panelId} recusado: já existe um conectado");
            $connection->close(Envelope::encode([
                'type'  => 'error',
                'error' => "Já existe um painel conectado como {$panelId}.",
                'code'  => Handshake::CLOSE_PANEL_EXISTS,
            ]));
            return;
        }

        // O snapshot vai junto com o welcome e é autoritativo: sem ele, um
        // seller_offline perdido numa queda viraria vendedor fantasma na tela.
        $connection->send(Envelope::encode([
            'type'     => 'welcome',
            'role'     => Handshake::ROLE_PANEL,
            'panel_id' => $panelId,
            'sellers'  => $this->registry->sellerSnapshot(),
        ]));

        RelayLog::info("painel {$panelId} conectado");
    }

    private function admitSeller(TcpConnection $connection, array $identity): void
    {
        $sellerId = $identity['seller_id'];
        $previous = $this->registry->recordSeller($connection, $identity);

        if ($previous !== null) {
            RelayLog::info("vendedor {$sellerId} reconectou; conexão anterior encerrada");
            $previous->close();
        }

        $connection->send(Envelope::encode([
            'type'      => 'welcome',
            'role'      => Handshake::ROLE_SELLER,
            'seller_id' => $sellerId,
        ]));

        $this->announce([
            'type'      => 'seller_online',
            'seller_id' => $sellerId,
            'hello'     => $identity,
        ]);

        RelayLog::info("vendedor {$sellerId} conectado ({$identity['device']})");
    }

    private function admitMirrorSource(TcpConnection $connection, array $identity): void
    {
        $this->registry->recordMirror(
            $connection,
            Handshake::ROLE_MIRROR_SOURCE,
            $identity['session_id']
        );

        $this->tuneMirrorConnection($connection);
        $opened = $this->mirrors->openSource($connection, $identity);

        // A fonte reconectou apresentando o mesmo ticket. A sessão foi mantida
        // com os painéis que já estavam dentro; o que sai é a conexão velha.
        // O `forget` vem antes do `close` porque sem ele o `onClose` dela
        // entraria no caminho de fonte e encerraria a sessão que acabou de
        // nascer.
        if ($opened['previousSource'] !== null) {
            RelayLog::info(
                "espelhamento {$identity['session_id']}: fonte reconectou; "
                . 'conexão anterior encerrada'
            );
            $this->registry->forget($opened['previousSource']);
            $opened['previousSource']->close();
        }

        RelayLog::info(
            "espelhamento {$identity['session_id']} aberto por {$identity['seller_id']}"
        );

        // Os que esperavam e não couberam saem com a recusa na mão. Soltar a
        // referência deixava o socket aberto sem dono e sem watchdog.
        foreach ($opened['refused'] as $sink) {
            RelayLog::warning(
                "painel que esperava foi recusado no espelhamento "
                . "{$identity['session_id']}: {$sink['reason']}"
            );
            $this->refuseMirror($sink['connection'], $sink['code'], $sink['reason']);
        }

        // Painéis que discaram antes da fonte chegar entram agora.
        foreach ($opened['waiting'] as $sink) {
            $this->mirrorRouter->warmUp($opened['key'], $sink);
            RelayLog::info(
                "painel que esperava entrou no espelhamento {$identity['session_id']}"
            );
        }

        // Painéis que já estavam assistindo a fonte anterior. Encoder novo,
        // parameter sets novos: eles voltaram para `warming` e precisam de um
        // ponto de retomada, igual a quem acabou de chegar.
        foreach ($opened['retained'] as $sink) {
            $this->mirrorRouter->warmUp($opened['key'], $sink);
            RelayLog::info(
                "painel mantido no espelhamento {$identity['session_id']} após a fonte reconectar"
            );
        }
    }

    private function admitMirrorSink(TcpConnection $connection, array $identity): void
    {
        $verdict = $this->mirrors->attachSink($connection, $identity);

        if ($verdict['ok'] !== true) {
            RelayLog::warning(
                "painel recusado no espelhamento {$identity['session_id']}: {$verdict['reason']}"
            );
            $this->refuseMirror($connection, $verdict['code'], $verdict['reason']);
            return;
        }

        $this->registry->recordMirror(
            $connection,
            Handshake::ROLE_MIRROR_SINK,
            $identity['session_id']
        );

        $this->tuneMirrorConnection($connection);

        if (($verdict['pending'] ?? false) === true) {
            // A fonte ainda não chegou. O socket fica aberto, sem receber nada,
            // até ela aparecer ou até o prazo estourar.
            RelayLog::info(
                "painel aguardando a fonte do espelhamento {$identity['session_id']}"
            );
            return;
        }

        $this->mirrorRouter->warmUp($verdict['key'], $connection);

        RelayLog::info("painel entrou no espelhamento {$identity['session_id']}");
    }

    /**
     * Fecha uma conexão de espelhamento dizendo por quê.
     *
     * Volta o frame para **texto** antes de escrever. As conexões de mídia
     * carregam `BINARY_TYPE_ARRAYBUFFER` porque é isso que o vídeo exige, e
     * sem trocar aqui a explicação saía como um frame binário — que o painel
     * tentava ler como quadro, descartava por não ter a assinatura, e o
     * operador ficava com "a conexão com o relay caiu" para todo motivo
     * diferente.
     */
    private function refuseMirror(TcpConnection $connection, int $code, string $reason): void
    {
        $connection->websocketType = Websocket::BINARY_TYPE_BLOB;

        $this->registry->forget($connection);
        $connection->close(Envelope::encode([
            'type'  => 'error',
            'error' => $reason,
            'code'  => $code,
        ]));
    }

    /**
     * Limites próprios das conexões de mídia.
     *
     * `maxSendBufferSize` é por conexão, então mexer nele aqui não encosta em
     * painel nem em vendedor no canal de controle.
     *
     * Quem decide o que é entregue é a política do `MirrorRouter` — 128 KiB de
     * marca-d'água alta. Este teto é só a rede de segurança, e por isso tem de
     * ficar **acima** do pior caso que a política permite: a fila no limite
     * (128 KiB) mais um frame inteiro (512 KiB). Em 256 KiB ele disparava
     * antes da política, e aí quem escolhia o que descartar era o Workerman —
     * que descarta *o frame mais novo*, ou seja, com sorte o keyframe que
     * repararia a imagem, e ainda chamava `onError` a cada frame. Era
     * exatamente a falha que o pré-cheque existe para evitar.
     */
    private function tuneMirrorConnection(TcpConnection $connection): void
    {
        $connection->websocketType     = Websocket::BINARY_TYPE_ARRAYBUFFER;
        $connection->maxSendBufferSize = 786432; // 768 KiB
        $connection->maxPackageSize    = MirrorFrame::MAX_BYTES;
    }

    /**
     * Fecha as sessões de espelhamento que ficaram sem ninguém assistindo.
     *
     * Sem isto, um painel fechado à força deixaria o celular codificando e
     * gastando bateria e a franquia de dados do vendedor indefinidamente — o
     * pior modo de falha que esta funcionalidade tem. A tolerância existe para
     * o painel conseguir reconectar sem derrubar a sessão.
     */
    private function sweepIdleMirrors(): void
    {
        foreach ($this->mirrors->expiredOrphanSinks() as $orphan) {
            RelayLog::warning(
                'painel desistiu de esperar: nenhuma fonte com este ticket'
            );
            $this->refuseMirror(
                $orphan,
                Handshake::CLOSE_MIRROR_NO_PEER,
                'Nenhuma transmissão com este ticket.'
            );
        }

        // Fonte que parou de entregar imagem. Sem isto a sessão vivia para
        // sempre: ela tem painéis, então nunca é considerada ociosa, e nenhuma
        // das duas pontas tem timeout de "sem frame" — o operador ficava com um
        // quadro congelado na tela e nenhum erro em lugar nenhum.
        foreach ($this->mirrors->staleSources() as $key => $session) {
            RelayLog::warning(
                "espelhamento {$session['session_id']} encerrado: a fonte não "
                . 'entrega imagem há ' . (int) round($session['silent']) . 's'
            );

            $this->mirrorRouter->tellSourceToStop(
                $session['seller_id'],
                $session['session_id'],
                'error'
            );

            $this->closeMirrorSession($key, 'source_gone');
        }

        foreach ($this->mirrors->idleSessions() as $key => $session) {
            RelayLog::info(
                "espelhamento {$session['session_id']} encerrado: "
                . "{$session['reason']}"
            );

            $this->mirrorRouter->tellSourceToStop(
                $session['seller_id'],
                $session['session_id'],
                'no_sink'
            );

            $this->closeMirrorSession($key, 'no_sink');
        }
    }

    private function closeMirrorSession(string $key, string $reason): void
    {
        // Lido antes de fechar: depois do `closeSession` a sessão não existe
        // mais e com ela vai embora a única medição independente das duas
        // pontas sobre o que esta transmissão realmente fez.
        $summary = $this->mirrors->lifetimeStats($key);
        $closed  = $this->mirrors->closeSession($key);

        $this->mirrorRouter->forgetWarnWindow($key);

        if ($summary !== null) {
            RelayLog::info(sprintf(
                'espelhamento %s: %ds, %d frames (%d KiB), %d repassados, '
                . '%d descartados, %d perdidos antes do relay, %d sem painel',
                $summary['session_id'],
                (int) $summary['lived'],
                $summary['frames_in'],
                $summary['kib_in'],
                $summary['forwarded'],
                $summary['discarded'],
                $summary['gaps'],
                $summary['no_sink']
            ));
        }

        if ($closed === null) {
            return;
        }

        foreach ($closed['sinks'] as $sink) {
            $this->refuseMirror(
                $sink,
                Handshake::CLOSE_MIRROR_SOURCE_GONE,
                'A transmissão foi encerrada no aparelho.'
            );
        }

        $this->mirrorRouter->announceStop(
            $closed['seller_id'],
            $closed['session_id'],
            $reason
        );
    }

    /**
     * Uma linha por espelhamento ativo, com o que passou na última janela.
     *
     * `fps` e `kbps` são medidos **aqui**, do que chegou de verdade, e não são a
     * mesma coisa que o `mirror_stats` que o aparelho manda ao painel: aquele é
     * o que o encoder pretendia produzir. Quando os dois números divergem, a
     * diferença é justamente o problema que se está procurando.
     *
     * `perdidos` vem do salto de sequência no cabeçalho, ou seja, é perda que
     * aconteceu **antes** do relay — no uplink do vendedor. É o que separa "a
     * rede do celular está ruim" de "o painel não dá conta", que até agora eram
     * indistinguíveis no log.
     */
    private function reportMirrors(): void
    {
        foreach ($this->mirrors->activeKeys() as $key) {
            $stats = $this->mirrors->drainStats($key);

            // Sessão parada não merece uma linha a cada dez segundos; se ela
            // parou de verdade, quem fala é o watchdog de fonte morta.
            if ($stats === null || $stats['fps_in'] <= 0.0) {
                continue;
            }

            RelayLog::info(sprintf(
                'espelhamento %s: %s fps, %d kbps, %d painéis, %d repassados, '
                . '%d descartados, %d perdidos antes do relay, pico de fila %d B',
                $stats['session_id'],
                $stats['fps_in'],
                $stats['kbps_in'],
                $stats['sinks'],
                $stats['forwarded'],
                $stats['discarded'],
                $stats['gaps'],
                $stats['peak_queue']
            ));
        }
    }

    private function fromPanel(TcpConnection $connection, array $message): void
    {
        if (($message['type'] ?? null) === 'pong') {
            return;
        }

        // O painel também pinga, e por outro motivo: quem fica calado por três
        // janelas é derrubado, e um painel ocioso não tem o que dizer. Sem este
        // caso o ping dele caía no `unwrap`, virava um aviso de envelope
        // inválido a cada 30 segundos e o log de verdade se perdia no meio.
        //
        // A resposta é o que dá ao painel prova de que este processo está vivo:
        // o frame de controle do WebSocket pode ser respondido por um proxy no
        // caminho, e aí a conexão parece boa com o relay morto.
        if (($message['type'] ?? null) === 'ping') {
            $connection->send(Envelope::encode(['type' => 'pong']));
            return;
        }

        $envelope = Envelope::unwrap($message);

        if ($envelope === null) {
            RelayLog::warning(
                "envelope inválido do painel {$this->registry->nameOf($connection)}: "
                . 'esperava {seller_id, payload}'
            );
            return;
        }

        $sellerId = $envelope['seller_id'];
        $payload  = $envelope['payload'];
        $reqId    = is_string($payload['req_id'] ?? null) ? $payload['req_id'] : null;
        $seller   = $this->registry->seller($sellerId);

        if ($seller === null) {
            $connection->send(
                Envelope::errorFor($sellerId, $reqId, 'Vendedor não está conectado.')
            );
            return;
        }

        if ($reqId !== null) {
            $this->registry->rememberRequest($reqId, (string) $this->registry->nameOf($connection));
        }

        // O payload vai cru: o app recebe exatamente o que o painel escreveu,
        // sem envelope.
        //
        // O retorno importa: uma conexão em fechamento aceita o `send` e não
        // entrega nada, e o painel ficava esperando o `req_id` até o tempo
        // limite dele sem saber que a mensagem nunca saiu.
        if ($seller->send(Envelope::encode($payload)) === false) {
            RelayLog::warning("envio para o vendedor {$sellerId} recusado pelo socket");
            $connection->send(
                Envelope::errorFor($sellerId, $reqId, 'Não foi possível falar com o aparelho.')
            );
        }
    }

    private function fromSeller(TcpConnection $connection, array $message): void
    {
        if (($message['type'] ?? null) === 'pong') {
            return;
        }

        $sellerId = (string) $this->registry->nameOf($connection);
        $reqId    = is_string($message['req_id'] ?? null) ? $message['req_id'] : null;
        $frame    = Envelope::wrap($sellerId, $message);

        // Resposta volta só para quem perguntou. Broadcast faria dois painéis
        // completarem o mesmo req_id e duplicaria o registro de auditoria.
        if ($reqId !== null) {
            $origin = $this->registry->panelWaitingFor($reqId);

            if ($origin !== null) {
                $origin->send($frame);
                return;
            }
        }

        // Sem req_id conhecido (um erro espontâneo, por exemplo) todo painel
        // precisa saber.
        foreach ($this->registry->panels() as $panel) {
            $panel->send($frame);
        }
    }

    private function onClose(TcpConnection $connection): void
    {
        $role = $this->registry->roleOf($connection);
        $name = $this->registry->nameOf($connection);

        unset($this->errorWindow[$connection->id]);

        $this->registry->forget($connection);

        if ($role === Handshake::ROLE_SELLER && $name !== null) {
            // Espelhamento não sobrevive ao canal de controle do dono. Se a
            // sessão continuasse, o painel ficaria recebendo imagem de um
            // vendedor que a lista de conectados já mostra como offline.
            foreach ($this->mirrors->keysOfSeller($name) as $key) {
                $this->closeMirrorSession($key, 'source_gone');
            }

            // Só anuncia se a conexão que caiu ainda era a registrada: numa
            // reconexão, a antiga fecha depois da nova entrar.
            if ($this->registry->seller($name) === null) {
                $this->announce([
                    'type'      => 'seller_offline',
                    'seller_id' => $name,
                    'reason'    => 'closed',
                ]);
                RelayLog::info("vendedor {$name} desconectou");
            }
            return;
        }

        if ($role === Handshake::ROLE_PANEL && $name !== null) {
            RelayLog::info("painel {$name} desconectou");
        }

        if ($role === Handshake::ROLE_MIRROR_SINK) {
            // Sai da sessão; o timer de ociosidade decide se ela acaba. Sair e
            // encerrar na hora tiraria do painel a chance de reconectar.
            $this->mirrors->detachSink($connection);
        }

        if ($role === Handshake::ROLE_MIRROR_SOURCE) {
            $key = $this->mirrors->sessionKeyOf($connection);

            if ($key !== null) {
                RelayLog::info("fonte do espelhamento {$name} caiu");
                $this->closeMirrorSession($key, 'source_gone');
            }
        }
    }

    /**
     * Ping de aplicação, e não só o frame de controle do WebSocket: um proxy
     * intermediário pode responder o controle sozinho, sem que este processo
     * esteja vivo do outro lado.
     */
    private function heartbeat(): void
    {
        $this->registry->dropExpiredRequests();

        foreach ($this->registry->silentSince(self::SILENCE_TOLERANCE_SECONDS) as $mute) {
            $name = $this->registry->nameOf($mute) ?? $mute->getRemoteIp();
            RelayLog::warning("sem sinal de {$name} por " . self::SILENCE_TOLERANCE_SECONDS . 's; encerrando');
            $this->closeSilent($mute);
        }

        $ping = Envelope::encode(['type' => 'ping']);

        foreach ($this->registry->panels() as $panel) {
            $panel->send($ping);
        }

        foreach ($this->registry->sellerSnapshot() as $identity) {
            $this->registry->seller($identity['seller_id'])?->send($ping);
        }
    }

    /**
     * Erro de conexão, no máximo um por janela por conexão.
     *
     * O Workerman chama isto uma vez por pacote descartado quando o buffer de
     * saída está cheio. Num socket de mídia isso é trinta vezes por segundo, e
     * cada linha é uma escrita em disco dentro do event loop — o log do
     * congestionamento passava a ser mais uma causa dele. A política do
     * `MirrorRouter` mais o teto de 768 KiB devem impedir que isso aconteça;
     * a janela existe para o caso de não impedirem.
     */
    private function onConnectionError(TcpConnection $connection, int $code, string $message): void
    {
        $now  = microtime(true);
        $seen = $this->errorWindow[$connection->id] ?? 0.0;

        if ($now - $seen < self::ERROR_WINDOW_SECONDS) {
            return;
        }

        $this->errorWindow[$connection->id] = $now;

        RelayLog::error("conexão {$connection->id} com erro {$code}: {$message}");
    }

    private function closeSilent(TcpConnection $connection): void
    {
        $role = $this->registry->roleOf($connection);
        $name = $this->registry->nameOf($connection);

        $this->registry->forget($connection);
        $connection->close();

        if ($role === Handshake::ROLE_SELLER && $name !== null) {
            $this->announce([
                'type'      => 'seller_offline',
                'seller_id' => $name,
                'reason'    => 'timeout',
            ]);
        }
    }

    private function dropSilentHandshakes(): void
    {
        foreach ($this->registry->expiredHandshakes(Handshake::DEADLINE_SECONDS) as $connection) {
            RelayLog::warning(
                "conexão de {$connection->getRemoteIp()} encerrada por não se identificar"
            );
            $this->registry->forget($connection);
            $connection->close(Envelope::encode([
                'type'  => 'error',
                'error' => 'Sem identificação dentro do prazo.',
                'code'  => Handshake::CLOSE_BAD_HELLO,
            ]));
        }
    }

    private function announce(array $event): void
    {
        $frame = Envelope::encode($event);

        foreach ($this->registry->panels() as $panel) {
            $panel->send($frame);
        }
    }

    private function describe(TcpConnection $connection): string
    {
        return $this->registry->nameOf($connection) ?? $connection->getRemoteIp();
    }
}
