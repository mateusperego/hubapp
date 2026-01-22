<?php

namespace Agroprodutor\Auth;

use Firebase\JWT\JWT;
use GuzzleHttp\Client;

class GoogleOAuth
{
    private array $config;
    private string $cacheFile;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->cacheFile = __DIR__ . '/../../storage/token_cache.json';
    }

    public function getAccessToken(): string
    {
        // if ($this->tokenIsValid()) {
        //     $data = json_decode(file_get_contents($this->cacheFile), true);
        //     return $data['access_token'];
        // }

        return $this->requestNewToken();
    }

    private function tokenIsValid(): bool
    {
        if (!file_exists($this->cacheFile)) return false;

        $data = json_decode(file_get_contents($this->cacheFile), true);
        return isset($data['expires_at']) && $data['expires_at'] > time();
    }

    private function requestNewToken(): string
    {
        $now = time();

        $payload = [
            'iss'   => $this->config['client_email'],
            'scope' => $this->config['scope'],
            'aud'   => $this->config['token_uri'],
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];

        $jwt = JWT::encode($payload, $this->config['private_key'], 'RS256');

        $client = new Client();

        $response = $client->post($this->config['token_uri'], [
            'form_params' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ],
        ]);

        $data = json_decode($response->getBody(), true);

        file_put_contents($this->cacheFile, json_encode([
            'access_token' => $data['access_token'],
            'expires_at'   => time() + $data['expires_in'] - 60,
        ]));

        return $data['access_token'];
    }
}
