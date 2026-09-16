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

    private Worker $worker;
    private Handshake $handshake;
    private ConnectionRegistry $registry;
    private MirrorRegistry $mirrors;
    private MirrorRouter $mirrorRouter;

    public function __construct(int $port, Handshake $handshake)
    {
        $this->handshake = $handshake;
        $this->registry  = new ConnectionRegistry();
        $this->mirrors   = new MirrorRegistry();

        $this->mirrorRouter = new MirrorRouter($this->mirrors, $this->registry);

        $this->worker = new Worker("websocket://0.0.0.0:{$port}");
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
            RelayLog::error("conexão {$c->id} com erro {$code}: {$message}");
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
    }

    private function onConnect(TcpConnection $connection): void
    {
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
        $this->mirrors->openSource($connection, $identity);

        RelayLog::info(
            "espelhamento {$identity['session_id']} aberto por {$identity['seller_id']}"
        );
    }

    private function admitMirrorSink(TcpConnection $connection, array $identity): void
    {
        $verdict = $this->mirrors->attachSink($connection, $identity);

        if ($verdict['ok'] !== true) {
            RelayLog::warning(
                "painel recusado no espelhamento {$identity['session_id']}: {$verdict['reason']}"
            );
            $connection->close(Envelope::encode([
                'type'  => 'error',
                'error' => $verdict['reason'],
                'code'  => $verdict['code'],
            ]));
            return;
        }

        $this->registry->recordMirror(
            $connection,
            Handshake::ROLE_MIRROR_SINK,
            $identity['session_id']
        );

        $this->tuneMirrorConnection($connection);
        $this->mirrorRouter->warmUp($verdict['key'], $connection);

        RelayLog::info("painel entrou no espelhamento {$identity['session_id']}");
    }

    /**
     * Limites próprios das conexões de mídia.
     *
     * `maxSendBufferSize` é por conexão, então baixá-lo aqui não encosta em
     * painel nem em vendedor no canal de controle. 256 KiB são uns dois
     * keyframes, ou meio segundo de vídeo em voo — acima disso a imagem já está
     * velha demais para valer a pena entregar.
     */
    private function tuneMirrorConnection(TcpConnection $connection): void
    {
        $connection->websocketType     = Websocket::BINARY_TYPE_ARRAYBUFFER;
        $connection->maxSendBufferSize = 262144;
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
        $closed = $this->mirrors->closeSession($key);

        if ($closed === null) {
            return;
        }

        foreach ($closed['sinks'] as $sink) {
            $this->registry->forget($sink);
            $sink->close(Envelope::encode([
                'type'  => 'error',
                'error' => 'Transmissão encerrada.',
                'code'  => Handshake::CLOSE_MIRROR_SOURCE_GONE,
            ]));
        }

        $this->mirrorRouter->announceStop(
            $closed['seller_id'],
            $closed['session_id'],
            $reason
        );
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
        $seller->send(Envelope::encode($payload));
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
