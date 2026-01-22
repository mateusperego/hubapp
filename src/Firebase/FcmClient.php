<?php

namespace Agroprodutor\Firebase;

use GuzzleHttp\Client;
use NFePHP\Common\Exception\ExceptionCollection;

class FcmClient
{
    private string $projectId;

    public function __construct(string $projectId)
    {
        $this->projectId = $projectId;
    }

    public function send(string $accessToken, array $message): array
    {
        try {
            $client = new Client();

            $response = $client->post(
            "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send",
            [
                'headers' => [
                    'Authorization' => "Bearer {$accessToken}",
                    'Content-Type'  => 'application/json; charset=UTF-8',
                ],
                'json' => ['message' => $message],
            ]
        );

        $retorno = $response->getBody();
        
        } catch (\Exception $e) {
           $retorno = $e->getMessage(); 
        }

        return json_decode($retorno, true);
    }
}
