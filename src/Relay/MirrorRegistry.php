<?php

namespace HubApp\Relay;

use Workerman\Connection\TcpConnection;

/**
 * As sessões de espelhamento, pareadas por (seller_id, ticket).
 *
 * Fica separado do `ConnectionRegistry` de propósito: aquele decide presença, e
 * presença é o que o painel mostra na lista de conectados. Um espelhamento que
 * cai não pode tirar ninguém da lista, e essa separação é o que garante isso.
 *
 * O ticket é sorteado pelo aparelho e chega às duas pontas pelo canal de
 * controle, que já é autenticado. O relay não o valida contra nada — ele *é* o
 * segredo. Sem ele, qualquer painel com o token compartilhado poderia abrir um
 * sink contra qualquer vendedor e receber a tela sem o vendedor ter consentido
 * com coisa alguma. Nunca aparece em log.
 */
class MirrorRegistry
{
    /** Sink sem fonte desiste depois disso. */
    public const SINK_ORPHAN_SECONDS = 10.0;

    /**
     * Quanto tempo uma fonte recém-aberta espera pelo **primeiro** painel.
     *
     * Não confunda com [IDLE_SECONDS]. A fonte conecta antes de o painel saber
     * que a sessão existe: o aparelho só manda `mirror_ready` depois de o
     * socket de mídia estar no ar, e o painel ainda precisa receber a mensagem,
     * montar o decodificador e discar. Medir isso com a tolerância de "o último
     * painel saiu" derruba a transmissão antes de ela começar — foi exatamente
     * o que aconteceu, e a fonte morria em 4 s.
     *
     * Generoso de propósito: o pior caso é o celular codificar alguns segundos
     * para ninguém, e o melhor caso é o espelhamento funcionar.
     */
    public const FIRST_SINK_SECONDS = 15.0;

    /**
     * Sessão que **já teve** painel e ficou sem ninguém assistindo sobrevive
     * isso antes de o aparelho ser mandado parar — o bastante para um painel
     * reconectar.
     */
    public const IDLE_SECONDS = 3.0;

    /**
     * Silêncio da fonte que conta como fonte morta.
     *
     * O socket de mídia não tem keepalive: ele fica fora do ping de aplicação e
     * fora do watchdog de silêncio, e o Workerman 4 não emite ping de WebSocket
     * por conta própria. Uma fonte meio-aberta — celular que perde a rede sem
     * FIN — deixava a sessão viva para sempre, porque ela ainda tem painéis e
     * portanto nunca era considerada ociosa. O painel também não tem timeout de
     * "sem frame", então o operador ficava olhando um quadro congelado
     * indefinidamente, sem erro em ponta nenhuma.
     *
     * Oito segundos é seguro porque a fonte **não** para em tela parada: o
     * encoder do aparelho usa `KEY_REPEAT_PREVIOUS_FRAME_AFTER` de 100 ms, ou
     * seja, uma tela imóvel ainda rende cerca de 10 quadros por segundo. Sem
     * frame nenhum por oito segundos não é uma tela quieta, é uma fonte que
     * morreu.
     */
    public const SOURCE_STALE_SECONDS = 8.0;

    /**
     * Um aparelho, um encoder. Painéis a mais são fan-out do mesmo frame: custa
     * N× no enlace do relay e 0× no celular.
     */
    public const MAX_SINKS = 4;

    private const HIGH_WATER = 131072; //  128 KiB — entra em descarte
    private const LOW_WATER  =  16384; //   16 KiB — pode voltar a fluir

    /**
     * O menor bitrate que vale pedir, em bits por segundo.
     *
     * É o mesmo piso que o aparelho respeita (`BitrateGovernor.FLOOR_BITRATE`).
     * Pedir menos que isso não seria atendido e só gastaria uma mensagem.
     */
    public const FLOOR_BITRATE = 800000;

    /** O quanto se corta da taxa medida quando o enlace não aguenta. */
    private const TUNE_CUT = 0.7;

    /** @var array<string, array> chave da sessão => estado */
    private array $sessions = [];

    /** @var array<int, string> id da conexão => chave da sessão */
    private array $sessionByConnection = [];

    /**
     * Painéis que chegaram antes da fonte.
     *
     * A corrida é real e não teórica: o aparelho abre o socket de mídia de
     * forma assíncrona e anuncia o `mirror_ready` em seguida, então o painel
     * pode discar antes do hello da fonte chegar aqui. Recusar na hora fazia o
     * espelhamento falhar no instante em que o vendedor autorizava.
     *
     * @var array<string, array<int, array{connection: TcpConnection, since: float}>>
     */
    private array $pendingSinks = [];

    public static function keyFor(string $sellerId, string $ticket): string
    {
        return $sellerId . "\0" . $ticket;
    }

    /**
     * Abre (ou reabre) a sessão desta fonte.
     *
     * Reabrir com a mesma chave é um caso real: o aparelho reconecta o socket de
     * mídia e apresenta o mesmo ticket. Antes isto sobrescrevia a sessão inteira
     * e os painéis que já estavam dentro saíam de `sinks` sem sair de
     * `sessionByConnection` — paravam de receber frame, não eram fechados por
     * `closeSession`, e ficavam abertos com a imagem congelada para sempre.
     * Agora eles são **mantidos**, e quem sai é a fonte velha.
     *
     * Os painéis mantidos voltam para `warming`: encoder novo, parameter sets
     * possivelmente novos, e nada do que eles tinham antes serve para decodificar
     * o que vem agora. Pelo mesmo motivo o `lastConfig` é descartado — guardar o
     * SPS/PPS da fonte anterior é pior que não ter nenhum.
     *
     * @return array{
     *     key: string,
     *     waiting: TcpConnection[],
     *     retained: TcpConnection[],
     *     previousSource: TcpConnection|null
     * }
     */
    public function openSource(TcpConnection $connection, array $identity): array
    {
        $key = self::keyFor($identity['seller_id'], $identity['ticket']);

        $existing       = $this->sessions[$key] ?? null;
        $previousSource = null;
        $retainedSinks  = [];

        if ($existing !== null) {
            $previousSource = $existing['source'];

            // Tira a fonte velha do mapa **antes** de o chamador fechá-la. Sem
            // isto, o `onClose` dela encontraria esta chave e destruiria a
            // sessão que acabou de nascer.
            unset($this->sessionByConnection[$previousSource->id]);

            $retainedSinks = $existing['sinks'];
        }

        $now = microtime(true);

        $this->sessions[$key] = [
            'seller_id'   => $identity['seller_id'],
            'session_id'  => $identity['session_id'],
            'source'      => $connection,
            'sinks'       => $retainedSinks,
            'states'      => [],
            'drops'       => [],
            'lastConfig'  => null,
            // Zero é "não está ociosa": a contagem só começa quando o último
            // painel sai. Até o primeiro chegar, quem manda é `openedAt`.
            'idleSince'   => 0.0,
            'openedAt'    => $now,
            'everHadSink' => $retainedSinks !== [],
            'lastKeyAsk'  => 0.0,
            'lastTuneAsk' => 0.0,
            'forwarded'   => 0,
            'discarded'   => 0,
            // O watchdog de fonte morta. Começa valendo agora: uma fonte que
            // conecta e nunca manda nada também precisa ser recolhida.
            'lastFrameAt' => $now,
            // Medição, toda ela do que o relay de fato viu passar.
            'framesIn'    => 0,
            'bytesIn'     => 0,
            'noSinkFrames' => 0,
            'gaps'        => 0,
            'peakQueue'   => 0,
            'lastSequence' => null,
            'generation'  => null,
            // Amostrador de taxa: é daqui que sai o bitrate do `mirror_tune`.
            'rateStart'   => $now,
            'rateBytes'   => 0,
            'measuredBps' => 0,
            // Marcadores da janela de relatório.
            'reportedAt'        => $now,
            'reportedFramesIn'  => 0,
            'reportedBytesIn'   => 0,
            'reportedForwarded' => 0,
            'reportedDiscarded' => 0,
        ];

        foreach (array_keys($retainedSinks) as $id) {
            $this->sessions[$key]['states'][$id] = 'warming';
            $this->sessions[$key]['drops'][$id]  = 0;
        }

        $this->sessionByConnection[$connection->id] = $key;

        // Os painéis que chegaram antes entram agora, na ordem em que vieram.
        $waiting = [];
        $refused = [];

        foreach ($this->pendingSinks[$key] ?? [] as $parked) {
            $verdict = $this->attachSink($parked['connection'], $identity);

            if ($verdict['ok'] !== true) {
                // Recusado por não caber. Antes a referência era só solta, e a
                // conexão ficava aberta para sempre: fora de `sellers` e de
                // `panels`, nenhum watchdog a alcança.
                $refused[] = [
                    'connection' => $parked['connection'],
                    'code'       => $verdict['code'],
                    'reason'     => $verdict['reason'],
                ];
                continue;
            }

            if (($verdict['pending'] ?? false) !== true) {
                $waiting[] = $parked['connection'];
            }
        }

        unset($this->pendingSinks[$key]);

        return [
            'key'            => $key,
            'waiting'        => $waiting,
            'refused'        => $refused,
            'retained'       => array_values($retainedSinks),
            'previousSource' => $previousSource,
        ];
    }

    /**
     * @return array{ok: bool, key?: string, pending?: bool, reason?: string, code?: int}
     */
    public function attachSink(TcpConnection $connection, array $identity): array
    {
        $key = self::keyFor($identity['seller_id'], $identity['ticket']);

        if (!isset($this->sessions[$key])) {
            // Fica de lado em vez de ser recusado. A fonte pode estar a
            // milissegundos de chegar, e é o `expiredOrphanSinks` que decide
            // quando desistir.
            $this->pendingSinks[$key][$connection->id] = [
                'connection' => $connection,
                'since'      => microtime(true),
            ];

            return ['ok' => true, 'key' => $key, 'pending' => true];
        }

        if (count($this->sessions[$key]['sinks']) >= self::MAX_SINKS) {
            return [
                'ok'     => false,
                'code'   => Handshake::CLOSE_MIRROR_TOO_MANY,
                'reason' => 'Limite de painéis nesta transmissão.',
            ];
        }

        $this->sessions[$key]['everHadSink']             = true;
        $this->sessions[$key]['sinks'][$connection->id]  = $connection;
        // `warming`: entrou agora e ainda não tem de onde decodificar.
        $this->sessions[$key]['states'][$connection->id] = 'warming';
        $this->sessions[$key]['drops'][$connection->id]  = 0;
        $this->sessions[$key]['idleSince']               = 0.0;

        $this->sessionByConnection[$connection->id] = $key;

        return ['ok' => true, 'key' => $key];
    }

    public function sessionKeyOf(TcpConnection $connection): ?string
    {
        return $this->sessionByConnection[$connection->id] ?? null;
    }

    public function session(string $key): ?array
    {
        return $this->sessions[$key] ?? null;
    }

    public function isSource(string $key, TcpConnection $connection): bool
    {
        return ($this->sessions[$key]['source'] ?? null) === $connection;
    }

    /** @return TcpConnection[] */
    public function sinksOf(string $key): array
    {
        return array_values($this->sessions[$key]['sinks'] ?? []);
    }

    /**
     * Um frame chegou da fonte: adia o watchdog e alimenta as métricas.
     *
     * A detecção de salto de sequência é o que separa "a rede do vendedor está
     * perdendo pacote" de "o painel não está dando conta" — dois problemas com
     * tratamentos opostos, e até aqui indistinguíveis no log.
     */
    public function recordFrame(string $key, array $header, int $bytes): void
    {
        if (!isset($this->sessions[$key])) {
            return;
        }

        $session = &$this->sessions[$key];
        $now     = microtime(true);

        $session['lastFrameAt'] = $now;
        $session['framesIn']++;
        $session['bytesIn']  += $bytes;
        $session['rateBytes'] += $bytes;

        // A taxa medida se renova a cada segundo. É ela, e não o que o aparelho
        // relata, que vira o bitrate do `mirror_tune`.
        $elapsed = $now - $session['rateStart'];

        if ($elapsed >= 1.0) {
            $session['measuredBps'] = (int) round($session['rateBytes'] * 8 / $elapsed);
            $session['rateStart']   = $now;
            $session['rateBytes']   = 0;
        }

        // Geração nova é encoder reconfigurado: a sequência recomeça e comparar
        // com a anterior produziria um salto imaginário.
        if ($session['generation'] !== $header['generation']) {
            $session['generation']   = $header['generation'];
            $session['lastSequence'] = $header['sequence'];

            return;
        }

        $previous = $session['lastSequence'];
        $session['lastSequence'] = $header['sequence'];

        if ($previous === null) {
            return;
        }

        // uint32 dá a volta. A subtração mascarada acerta nos dois casos.
        $gap = (($header['sequence'] - $previous) & 0xFFFFFFFF) - 1;

        // Um salto enorme é reinício de contagem, não perda de mil frames.
        if ($gap > 0 && $gap < 1000) {
            $session['gaps'] += $gap;
        }
    }

    public function countNoSinkFrame(string $key): void
    {
        if (isset($this->sessions[$key])) {
            $this->sessions[$key]['noSinkFrames']++;
        }
    }

    public function observeQueue(string $key, int $queue): void
    {
        if (isset($this->sessions[$key]) && $queue > $this->sessions[$key]['peakQueue']) {
            $this->sessions[$key]['peakQueue'] = $queue;
        }
    }

    /**
     * O último frame de SPS/PPS, replicado para todo painel que entra.
     *
     * Menos de 1 KiB, e é o que torna a entrada de um segundo painel
     * independente do canal de controle. Keyframe não é guardado: são 120 KB
     * por sessão e ele envelhece — melhor pedir um novo.
     */
    public function rememberConfig(string $key, string $frame): void
    {
        $this->sessions[$key]['lastConfig'] = $frame;
    }

    public function lastConfig(string $key): ?string
    {
        return $this->sessions[$key]['lastConfig'] ?? null;
    }

    public function stateOf(string $key, TcpConnection $sink): string
    {
        return $this->sessions[$key]['states'][$sink->id] ?? 'warming';
    }

    public function setState(string $key, TcpConnection $sink, string $state): void
    {
        $this->sessions[$key]['states'][$sink->id] = $state;
    }

    public function countDrop(string $key, TcpConnection $sink): int
    {
        $this->sessions[$key]['discarded']++;

        return ++$this->sessions[$key]['drops'][$sink->id];
    }

    public function clearDrops(string $key, TcpConnection $sink): void
    {
        $this->sessions[$key]['drops'][$sink->id] = 0;
    }

    public function countForwarded(string $key): void
    {
        $this->sessions[$key]['forwarded']++;
    }

    public function highWater(): int
    {
        return self::HIGH_WATER;
    }

    public function lowWater(): int
    {
        return self::LOW_WATER;
    }

    /**
     * Pedido de keyframe limitado a um por 500 ms por sessão.
     *
     * Uma rajada de perda viraria uma rajada de keyframes, que é justamente o
     * mais caro num enlace que já está perdendo pacote. O app também limita do
     * lado dele; não confie só nisso.
     */
    public function mayAskKeyframe(string $key): bool
    {
        $now  = microtime(true);
        $last = $this->sessions[$key]['lastKeyAsk'] ?? 0.0;

        if ($now - $last < 0.5) {
            return false;
        }

        $this->sessions[$key]['lastKeyAsk'] = $now;

        return true;
    }

    /**
     * O bitrate que vale pedir agora, ou null se não há o que pedir.
     *
     * Sai de uma fração da taxa que o relay **mediu** chegando, e não do que o
     * aparelho diz estar usando: o relay recebe cada frame, então é a ponta que
     * sabe disso com mais precisão — e assim ele não precisa interpretar
     * nenhuma mensagem de aplicação para descobrir.
     *
     * Mesmo limite de 500 ms do keyframe, e pelo mesmo motivo.
     */
    public function nextTuneBitrate(string $key): ?int
    {
        $session = $this->sessions[$key] ?? null;

        if ($session === null || $session['measuredBps'] <= 0) {
            return null;
        }

        $now = microtime(true);

        if ($now - $session['lastTuneAsk'] < 0.5) {
            return null;
        }

        $target = (int) round($session['measuredBps'] * self::TUNE_CUT);

        // Já está no piso: insistir não muda nada no aparelho.
        if ($session['measuredBps'] <= self::FLOOR_BITRATE) {
            return null;
        }

        $this->sessions[$key]['lastTuneAsk'] = $now;

        return max(self::FLOOR_BITRATE, $target);
    }

    public function detachSink(TcpConnection $connection): ?string
    {
        foreach ($this->pendingSinks as $parkedKey => $parked) {
            unset($this->pendingSinks[$parkedKey][$connection->id]);
            if ($this->pendingSinks[$parkedKey] === []) {
                unset($this->pendingSinks[$parkedKey]);
            }
        }

        $key = $this->sessionByConnection[$connection->id] ?? null;
        unset($this->sessionByConnection[$connection->id]);

        if ($key === null || !isset($this->sessions[$key])) {
            return null;
        }

        unset(
            $this->sessions[$key]['sinks'][$connection->id],
            $this->sessions[$key]['states'][$connection->id],
            $this->sessions[$key]['drops'][$connection->id]
        );

        if ($this->sessions[$key]['sinks'] === []) {
            $this->sessions[$key]['idleSince'] = microtime(true);
        }

        return $key;
    }

    /** @return array{seller_id: string, session_id: string, sinks: TcpConnection[]}|null */
    public function closeSession(string $key): ?array
    {
        $session = $this->sessions[$key] ?? null;

        if ($session === null) {
            return null;
        }

        foreach (array_keys($session['sinks']) as $id) {
            unset($this->sessionByConnection[$id]);
        }
        unset($this->sessionByConnection[$session['source']->id], $this->sessions[$key]);

        return [
            'seller_id'  => $session['seller_id'],
            'session_id' => $session['session_id'],
            'sinks'      => array_values($session['sinks']),
        ];
    }

    /**
     * Sessões que devem ser encerradas por não ter ninguém assistindo.
     *
     * Duas contagens diferentes, e a distinção é o que faz a transmissão
     * conseguir começar:
     *
     * - fonte que **nunca** teve painel espera [FIRST_SINK_SECONDS];
     * - sessão que **já teve** e ficou vazia espera [IDLE_SECONDS].
     *
     * Sem encerrar nenhuma das duas, um painel fechado à força deixaria o
     * celular codificando e gastando bateria e a franquia de dados do vendedor
     * indefinidamente — o pior modo de falha que esta funcionalidade tem.
     *
     * @return array<string, array{seller_id: string, session_id: string, reason: string}>
     */
    public function idleSessions(): array
    {
        $now  = microtime(true);
        $idle = [];

        foreach ($this->sessions as $key => $session) {
            if ($session['sinks'] !== []) {
                continue;
            }

            if ($session['everHadSink'] !== true) {
                if ($now - $session['openedAt'] > self::FIRST_SINK_SECONDS) {
                    $idle[$key] = [
                        'seller_id'  => $session['seller_id'],
                        'session_id' => $session['session_id'],
                        'reason'     => 'nenhum painel chegou',
                    ];
                }
                continue;
            }

            if ($session['idleSince'] > 0.0
                && $now - $session['idleSince'] > self::IDLE_SECONDS) {
                $idle[$key] = [
                    'seller_id'  => $session['seller_id'],
                    'session_id' => $session['session_id'],
                    'reason'     => 'último painel saiu',
                ];
            }
        }

        return $idle;
    }

    /**
     * Sessões cuja fonte parou de entregar imagem.
     *
     * Ver [SOURCE_STALE_SECONDS]: é o único jeito de descobrir um socket de
     * mídia meio-aberto, porque essas conexões não têm ping nem watchdog de
     * silêncio, e uma sessão com painéis nunca é considerada ociosa.
     *
     * @return array<string, array{seller_id: string, session_id: string, silent: float}>
     */
    public function staleSources(): array
    {
        $now   = microtime(true);
        $stale = [];

        foreach ($this->sessions as $key => $session) {
            $silent = $now - $session['lastFrameAt'];

            if ($silent > self::SOURCE_STALE_SECONDS) {
                $stale[$key] = [
                    'seller_id'  => $session['seller_id'],
                    'session_id' => $session['session_id'],
                    'silent'     => $silent,
                ];
            }
        }

        return $stale;
    }

    /**
     * Painéis que esperaram demais por uma fonte que não veio.
     *
     * @return TcpConnection[]
     */
    public function expiredOrphanSinks(): array
    {
        $now     = microtime(true);
        $expired = [];

        foreach ($this->pendingSinks as $key => $parked) {
            foreach ($parked as $id => $entry) {
                if ($now - $entry['since'] > self::SINK_ORPHAN_SECONDS) {
                    $expired[] = $entry['connection'];
                    unset($this->pendingSinks[$key][$id]);
                }
            }

            if ($this->pendingSinks[$key] === []) {
                unset($this->pendingSinks[$key]);
            }
        }

        return $expired;
    }

    /** @return string[] chaves das sessões deste vendedor */
    public function keysOfSeller(string $sellerId): array
    {
        $keys = [];

        foreach ($this->sessions as $key => $session) {
            if ($session['seller_id'] === $sellerId) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /** @return string[] */
    public function activeKeys(): array
    {
        return array_keys($this->sessions);
    }

    /**
     * O que passou desde o último relatório, e zera a janela.
     *
     * Os contadores existiam desde o começo e nunca foram lidos por ninguém —
     * `forwarded` e `discarded` eram somados e jogados fora. Sem isto não há
     * como afirmar que qualquer ajuste de FPS ou de qualidade melhorou algo, e
     * as duas pontas só sabem relatar o que elas mesmas pretendiam fazer.
     *
     * @return array<string, int|float|string>|null
     */
    public function drainStats(string $key): ?array
    {
        $session = $this->sessions[$key] ?? null;

        if ($session === null) {
            return null;
        }

        $now    = microtime(true);
        $window = max(0.001, $now - $session['reportedAt']);

        $framesIn  = $session['framesIn']  - $session['reportedFramesIn'];
        $bytesIn   = $session['bytesIn']   - $session['reportedBytesIn'];
        $forwarded = $session['forwarded'] - $session['reportedForwarded'];
        $discarded = $session['discarded'] - $session['reportedDiscarded'];

        $this->sessions[$key]['reportedAt']        = $now;
        $this->sessions[$key]['reportedFramesIn']  = $session['framesIn'];
        $this->sessions[$key]['reportedBytesIn']   = $session['bytesIn'];
        $this->sessions[$key]['reportedForwarded'] = $session['forwarded'];
        $this->sessions[$key]['reportedDiscarded'] = $session['discarded'];
        $this->sessions[$key]['peakQueue']         = 0;

        return [
            'session_id' => $session['session_id'],
            'sinks'      => count($session['sinks']),
            'fps_in'     => round($framesIn / $window, 1),
            'kbps_in'    => (int) round($bytesIn * 8 / $window / 1000),
            'forwarded'  => $forwarded,
            'discarded'  => $discarded,
            'gaps'       => $session['gaps'],
            'peak_queue' => $session['peakQueue'],
            'no_sink'    => $session['noSinkFrames'],
        ];
    }

    /** O resumo acumulado da sessão, para a linha de fechamento. */
    public function lifetimeStats(string $key): ?array
    {
        $session = $this->sessions[$key] ?? null;

        if ($session === null) {
            return null;
        }

        return [
            'session_id' => $session['session_id'],
            'frames_in'  => $session['framesIn'],
            'kib_in'     => (int) round($session['bytesIn'] / 1024),
            'forwarded'  => $session['forwarded'],
            'discarded'  => $session['discarded'],
            'gaps'       => $session['gaps'],
            'no_sink'    => $session['noSinkFrames'],
            'lived'      => round(microtime(true) - $session['openedAt'], 1),
        ];
    }
}
