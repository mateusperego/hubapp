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
     * Um aparelho, um encoder. Painéis a mais são fan-out do mesmo frame: custa
     * N× no enlace do relay e 0× no celular.
     */
    public const MAX_SINKS = 4;

    private const HIGH_WATER = 131072; //  128 KiB — entra em descarte
    private const LOW_WATER  =  16384; //   16 KiB — pode voltar a fluir

    /** @var array<string, array> chave da sessão => estado */
    private array $sessions = [];

    /** @var array<int, string> id da conexão => chave da sessão */
    private array $sessionByConnection = [];

    public static function keyFor(string $sellerId, string $ticket): string
    {
        return $sellerId . "\0" . $ticket;
    }

    public function openSource(TcpConnection $connection, array $identity): string
    {
        $key = self::keyFor($identity['seller_id'], $identity['ticket']);

        $this->sessions[$key] = [
            'seller_id'   => $identity['seller_id'],
            'session_id'  => $identity['session_id'],
            'source'      => $connection,
            'sinks'       => [],
            'states'      => [],
            'drops'       => [],
            'lastConfig'  => null,
            // Zero é "não está ociosa": a contagem só começa quando o último
            // painel sai. Até o primeiro chegar, quem manda é `openedAt`.
            'idleSince'   => 0.0,
            'openedAt'    => microtime(true),
            'everHadSink' => false,
            'lastKeyAsk'  => 0.0,
            'forwarded'   => 0,
            'discarded'   => 0,
        ];

        $this->sessionByConnection[$connection->id] = $key;

        return $key;
    }

    /**
     * @return array{ok: bool, key?: string, reason?: string, code?: int}
     */
    public function attachSink(TcpConnection $connection, array $identity): array
    {
        $key = self::keyFor($identity['seller_id'], $identity['ticket']);

        if (!isset($this->sessions[$key])) {
            return [
                'ok'     => false,
                'code'   => Handshake::CLOSE_MIRROR_NO_PEER,
                'reason' => 'Nenhuma transmissão com este ticket.',
            ];
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

    public function detachSink(TcpConnection $connection): ?string
    {
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

    public function statsOf(string $key): array
    {
        $session = $this->sessions[$key] ?? null;

        if ($session === null) {
            return [];
        }

        return [
            'session_id' => $session['session_id'],
            'sinks'      => count($session['sinks']),
            'forwarded'  => $session['forwarded'],
            'discarded'  => $session['discarded'],
        ];
    }
}
