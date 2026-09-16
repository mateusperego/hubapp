<?php

namespace HubApp\Relay;

/**
 * Validação do `hello`, a única mensagem aceita antes da autenticação.
 *
 * Fica separada do servidor porque é a peça que decide quem entra: é a mais
 * barata de ler inteira e a que não pode ganhar atalho.
 */
class Handshake
{
    /** Quem não se identificar nesse prazo é desconectado. */
    public const DEADLINE_SECONDS = 10;

    public const ROLE_PANEL  = 'panel';
    public const ROLE_SELLER = 'seller';

    // O canal de espelhamento. Mesma porta, mesmo token, mesmo hello de texto —
    // só o papel muda, e do segundo frame em diante a conexão é binária.
    //
    // Mesmo Worker de propósito: o `count = 1` existe porque presença e
    // pareamento vivem em memória, e um socket de mídia num segundo processo
    // não enxergaria nem o vendedor nem o painel.
    public const ROLE_MIRROR_SOURCE = 'mirror_source';
    public const ROLE_MIRROR_SINK   = 'mirror_sink';

    // Códigos de fechamento. Os 40xx são nossos; o cliente usa para decidir se
    // tenta de novo ou se para e pede ação humana.
    public const CLOSE_BAD_HELLO    = 4000;
    public const CLOSE_BAD_TOKEN    = 4001;
    public const CLOSE_BAD_ROLE     = 4003;
    public const CLOSE_PANEL_EXISTS = 4009;

    public const CLOSE_MIRROR_NO_PEER     = 4010;
    public const CLOSE_MIRROR_SOURCE_GONE = 4011;
    public const CLOSE_MIRROR_TOO_MANY    = 4012;

    private string $token;

    /** @var string[] Vazio libera qualquer seller_id. */
    private array $sellerAllowlist;

    public function __construct(string $token, array $sellerAllowlist = [])
    {
        $this->token           = $token;
        $this->sellerAllowlist = $sellerAllowlist;
    }

    /**
     * @return array{ok: true, role: string, identity: array}
     *        |array{ok: false, code: int, reason: string}
     */
    public function inspect(array $message): array
    {
        if (($message['type'] ?? null) !== 'hello') {
            return self::reject(self::CLOSE_BAD_HELLO, 'Esperava hello.');
        }

        $token = $message['token'] ?? null;

        // hash_equals: comparação em tempo constante. Sem isso, o tempo de
        // resposta entrega o token byte a byte para quem estiver medindo.
        if (!is_string($token) || !hash_equals($this->token, $token)) {
            return self::reject(self::CLOSE_BAD_TOKEN, 'Token inválido.');
        }

        $role = $message['role'] ?? null;

        return match ($role) {
            self::ROLE_PANEL         => $this->inspectPanel($message),
            self::ROLE_SELLER        => $this->inspectSeller($message),
            self::ROLE_MIRROR_SOURCE,
            self::ROLE_MIRROR_SINK   => $this->inspectMirror($message, $role),
            default                  => self::reject(self::CLOSE_BAD_ROLE, 'Role desconhecida.'),
        };
    }

    private function inspectPanel(array $message): array
    {
        $panelId = trim((string) ($message['panel_id'] ?? ''));

        if ($panelId === '') {
            return self::reject(self::CLOSE_BAD_HELLO, 'Informe o panel_id.');
        }

        return [
            'ok'       => true,
            'role'     => self::ROLE_PANEL,
            'identity' => ['panel_id' => $panelId],
        ];
    }

    private function inspectSeller(array $message): array
    {
        $sellerId = trim((string) ($message['seller_id'] ?? ''));

        if ($sellerId === '') {
            return self::reject(self::CLOSE_BAD_HELLO, 'Informe o seller_id.');
        }

        if ($this->sellerAllowlist !== [] && !in_array($sellerId, $this->sellerAllowlist, true)) {
            return self::reject(self::CLOSE_BAD_TOKEN, "Vendedor {$sellerId} não liberado.");
        }

        // O que o painel mostra na lista de conectados, e o que ele usa para
        // achar os backups deste vendedor no servidor.
        //
        // A lista é fixa de propósito — o relay repassa o que conhece, não o
        // que o app inventar. O custo disso é que um campo novo no `hello`
        // precisa ser acrescentado aqui também, ou some no caminho sem aviso.
        //
        // Só o seller_id é obrigatório; o resto tem default para um app antigo
        // continuar entrando.
        return [
            'ok'       => true,
            'role'     => self::ROLE_SELLER,
            'identity' => [
                'seller_id'   => $sellerId,
                'seller_name' => (string) ($message['seller_name'] ?? ''),
                'tenant'      => (string) ($message['tenant'] ?? ''),
                'device'      => (string) ($message['device'] ?? ''),
                'app_version' => (string) ($message['app_version'] ?? ''),
                'db_version'  => (int) ($message['db_version'] ?? 0),
            ],
        ];
    }

    /**
     * As duas pontas do canal binário.
     *
     * O `ticket` é sorteado pelo aparelho e chegou aqui pelo canal de controle,
     * que já é autenticado — o relay não o valida contra nada, ele *é* o
     * segredo. É o que amarra assistir à tela ao consentimento que o vendedor
     * acabou de dar: só o token compartilhado deixaria qualquer painel se
     * pendurar em qualquer vendedor.
     *
     * Nunca entra em log.
     */
    private function inspectMirror(array $message, string $role): array
    {
        $sellerId  = trim((string) ($message['seller_id'] ?? ''));
        $sessionId = trim((string) ($message['session_id'] ?? ''));
        $ticket    = trim((string) ($message['ticket'] ?? ''));

        if ($sellerId === '' || $sessionId === '' || $ticket === '') {
            return self::reject(
                self::CLOSE_BAD_HELLO,
                'Informe seller_id, session_id e ticket.'
            );
        }

        // A allowlist vale aqui também: de graça, e certo.
        if ($this->sellerAllowlist !== [] && !in_array($sellerId, $this->sellerAllowlist, true)) {
            return self::reject(self::CLOSE_BAD_TOKEN, "Vendedor {$sellerId} não liberado.");
        }

        return [
            'ok'       => true,
            'role'     => $role,
            'identity' => [
                'seller_id'  => $sellerId,
                'session_id' => $sessionId,
                'ticket'     => $ticket,
            ],
        ];
    }

    private static function reject(int $code, string $reason): array
    {
        return ['ok' => false, 'code' => $code, 'reason' => $reason];
    }
}
