<?php

namespace HubApp\Relay;

use Workerman\Connection\TcpConnection;

/**
 * Quem está conectado e para onde vai cada resposta.
 *
 * Guarda tudo indexado por `$connection->id` em vez de pendurar propriedade na
 * conexão do Workerman: propriedade dinâmica é deprecated no PHP 8.2 e some do
 * radar quando chega a hora de limpar.
 */
class ConnectionRegistry
{
    /** @var array<int, TcpConnection> conexões que ainda não mandaram hello */
    private array $pending = [];

    /** @var array<int, float> id da conexão => quando chegou (prazo do hello) */
    private array $pendingSince = [];

    /** @var array<string, TcpConnection> seller_id => conexão */
    private array $sellers = [];

    /** @var array<string, array> seller_id => identificação do hello */
    private array $sellerIdentities = [];

    /** @var array<string, TcpConnection> panel_id => conexão */
    private array $panels = [];

    /** @var array<int, string> id da conexão => seller_id ou panel_id */
    private array $namesByConnection = [];

    /** @var array<int, string> id da conexão => role */
    private array $rolesByConnection = [];

    /** @var array<int, float> id da conexão => último sinal de vida */
    private array $lastSignal = [];

    /** @var array<string, array{panel: string, at: float}> req_id => painel de origem */
    private array $pendingRequests = [];

    /** Uma resposta que não chega nesse prazo deixa de ter dono conhecido. */
    private const REQUEST_TTL_SECONDS = 120;

    public function hold(TcpConnection $connection): void
    {
        $this->pending[$connection->id]      = $connection;
        $this->pendingSince[$connection->id] = microtime(true);
        $this->lastSignal[$connection->id]   = microtime(true);
    }

    public function isPending(TcpConnection $connection): bool
    {
        return isset($this->pending[$connection->id]);
    }

    /** @return TcpConnection[] conexões que estouraram o prazo do hello */
    public function expiredHandshakes(float $deadlineSeconds): array
    {
        $now     = microtime(true);
        $expired = [];

        foreach ($this->pendingSince as $id => $since) {
            if ($now - $since > $deadlineSeconds) {
                $expired[] = $this->pending[$id];
            }
        }

        return $expired;
    }

    /**
     * Registra um vendedor. Devolve a conexão anterior do mesmo seller_id, se
     * havia — o chamador fecha com reason `replaced`. Trocar o aparelho ou
     * reinstalar o app cai exatamente aqui.
     */
    public function recordSeller(TcpConnection $connection, array $identity): ?TcpConnection
    {
        $sellerId = $identity['seller_id'];
        $previous = $this->sellers[$sellerId] ?? null;

        if ($previous !== null) {
            $this->forget($previous);
        }

        $this->promote($connection, Handshake::ROLE_SELLER, $sellerId);
        $this->sellers[$sellerId]          = $connection;
        $this->sellerIdentities[$sellerId] = $identity;

        return $previous;
    }

    /** Recusa o segundo painel com o mesmo panel_id em vez de trocar. */
    public function recordPanel(TcpConnection $connection, string $panelId): bool
    {
        if (isset($this->panels[$panelId])) {
            return false;
        }

        $this->promote($connection, Handshake::ROLE_PANEL, $panelId);
        $this->panels[$panelId] = $connection;

        return true;
    }

    private function promote(TcpConnection $connection, string $role, string $name): void
    {
        unset($this->pending[$connection->id], $this->pendingSince[$connection->id]);

        $this->namesByConnection[$connection->id] = $name;
        $this->rolesByConnection[$connection->id] = $role;
        $this->lastSignal[$connection->id]        = microtime(true);
    }

    public function forget(TcpConnection $connection): void
    {
        $id   = $connection->id;
        $name = $this->namesByConnection[$id] ?? null;
        $role = $this->rolesByConnection[$id] ?? null;

        if ($role === Handshake::ROLE_SELLER && $name !== null) {
            // Só remove se ainda for a conexão registrada: um `replaced` já
            // trocou o índice, e apagar aqui derrubaria a conexão nova.
            if (($this->sellers[$name] ?? null) === $connection) {
                unset($this->sellers[$name], $this->sellerIdentities[$name]);
            }
        }

        if ($role === Handshake::ROLE_PANEL && $name !== null) {
            if (($this->panels[$name] ?? null) === $connection) {
                unset($this->panels[$name]);
            }

            foreach ($this->pendingRequests as $reqId => $origin) {
                if ($origin['panel'] === $name) {
                    unset($this->pendingRequests[$reqId]);
                }
            }
        }

        unset(
            $this->pending[$id],
            $this->pendingSince[$id],
            $this->namesByConnection[$id],
            $this->rolesByConnection[$id],
            $this->lastSignal[$id]
        );
    }

    public function roleOf(TcpConnection $connection): ?string
    {
        return $this->rolesByConnection[$connection->id] ?? null;
    }

    public function nameOf(TcpConnection $connection): ?string
    {
        return $this->namesByConnection[$connection->id] ?? null;
    }

    public function seller(string $sellerId): ?TcpConnection
    {
        return $this->sellers[$sellerId] ?? null;
    }

    /** @return TcpConnection[] */
    public function panels(): array
    {
        return array_values($this->panels);
    }

    /** @return array[] identificação de cada vendedor online — o snapshot do welcome */
    public function sellerSnapshot(): array
    {
        return array_values($this->sellerIdentities);
    }

    public function recordSignal(TcpConnection $connection): void
    {
        $this->lastSignal[$connection->id] = microtime(true);
    }

    /** @return TcpConnection[] conexões sem tráfego nenhum dentro da tolerância */
    public function silentSince(float $toleranceSeconds): array
    {
        $now    = microtime(true);
        $silent = [];

        foreach ($this->lastSignal as $id => $at) {
            if ($now - $at <= $toleranceSeconds) {
                continue;
            }

            $connection = $this->pending[$id] ?? $this->connectionById($id);

            if ($connection !== null) {
                $silent[] = $connection;
            }
        }

        return $silent;
    }

    private function connectionById(int $id): ?TcpConnection
    {
        foreach ([...array_values($this->sellers), ...array_values($this->panels)] as $connection) {
            if ($connection->id === $id) {
                return $connection;
            }
        }

        return null;
    }

    /**
     * Anota de qual painel saiu a requisição, para a resposta voltar só para ele.
     *
     * Com dois painéis abertos, devolver por broadcast faria os dois completarem
     * o mesmo req_id — e a auditoria registraria a correção duas vezes.
     */
    public function rememberRequest(string $reqId, string $panelId): void
    {
        $this->pendingRequests[$reqId] = ['panel' => $panelId, 'at' => microtime(true)];
    }

    public function panelWaitingFor(string $reqId): ?TcpConnection
    {
        $origin = $this->pendingRequests[$reqId] ?? null;

        if ($origin === null) {
            return null;
        }

        unset($this->pendingRequests[$reqId]);

        return $this->panels[$origin['panel']] ?? null;
    }

    public function dropExpiredRequests(): void
    {
        $now = microtime(true);

        foreach ($this->pendingRequests as $reqId => $origin) {
            if ($now - $origin['at'] > self::REQUEST_TTL_SECONDS) {
                unset($this->pendingRequests[$reqId]);
            }
        }
    }
}
