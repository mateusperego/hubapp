<?php

namespace HubApp\Relay;

/**
 * Codificação das mensagens do relay.
 *
 * Existe separado do servidor por causa de uma regra que vale em todo lugar:
 * mensagem malformada é descartada, nunca derruba a conexão. Se o decode
 * ficasse espalhado pelo RelayServer, a tentação de lançar exceção no meio do
 * onMessage seria grande — e fechar a conexão do painel por um frame ruim
 * tiraria todos os vendedores do ar de uma vez.
 */
class Envelope
{
    /** Teto de frame. Acima disso o Workerman e o memory_limit do PHP sofrem. */
    public const MAX_BYTES = 1048576; // 1 MiB

    /**
     * Decodifica um frame recebido.
     *
     * @return array|null null para qualquer coisa que não seja um objeto JSON
     *                    dentro do limite de tamanho.
     */
    public static function decode(string $frame): ?array
    {
        if ($frame === '' || strlen($frame) > self::MAX_BYTES) {
            return null;
        }

        $decoded = json_decode($frame, true);

        // Lista JSON no topo também é recusada: toda mensagem do protocolo é
        // um objeto com a chave `type`.
        if (!is_array($decoded) || array_is_list($decoded)) {
            return null;
        }

        return $decoded;
    }

    public static function encode(array $message): string
    {
        return json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Envelopa uma mensagem do vendedor para o painel.
     *
     * O seller_id vem da sessão autenticada, não do que o vendedor escreveu.
     * Por isso é envelope e não merge: um merge deixaria o vendedor sobrescrever
     * o campo e se passar por outra loja.
     */
    public static function wrap(string $sellerId, array $payload): string
    {
        return self::encode([
            'seller_id' => $sellerId,
            'payload'   => $payload,
        ]);
    }

    /**
     * Lê o envelope que o painel manda.
     *
     * @return array{seller_id: string, payload: array}|null
     */
    public static function unwrap(array $message): ?array
    {
        $sellerId = $message['seller_id'] ?? null;
        $payload  = $message['payload'] ?? null;

        if (!is_string($sellerId) || trim($sellerId) === '') {
            return null;
        }

        if (!is_array($payload) || array_is_list($payload)) {
            return null;
        }

        return ['seller_id' => trim($sellerId), 'payload' => $payload];
    }

    /** Erro devolvido ao painel no lugar da resposta que o vendedor daria. */
    public static function errorFor(string $sellerId, ?string $reqId, string $message): string
    {
        $payload = ['type' => 'error', 'error' => $message];

        if ($reqId !== null) {
            $payload['req_id'] = $reqId;
        }

        return self::wrap($sellerId, $payload);
    }
}
