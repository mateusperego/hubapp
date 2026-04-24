<?php

namespace HubApp\Helpers;

class RequestHelper
{
    public static function getJsonInput(): array
    {
        $jsonInput = file_get_contents('php://input');
        $decoded = json_decode($jsonInput, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Obtém o valor de um header HTTP
     *
     * @param string $name Nome do header (case-insensitive)
     * @return string|null Valor do header ou null se não existir
     */
    public static function getBaseUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host;
    }

    public static function getHeader(string $name): ?string
    {
        // Converte o nome do header para o formato do $_SERVER
        // Ex: X-Account-Id -> HTTP_X_ACCOUNT_ID
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        if (isset($_SERVER[$serverKey])) {
            return $_SERVER[$serverKey];
        }

        // Fallback: tentar obter via getallheaders() se disponível
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach ($headers as $key => $value) {
                if (strcasecmp($key, $name) === 0) {
                    return $value;
                }
            }
        }

        return null;
    }
}
