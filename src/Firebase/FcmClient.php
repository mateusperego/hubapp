<?php

namespace HubApp\Firebase;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

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

            return json_decode(
                (string) $response->getBody(),
                true
            );

        } catch (RequestException $e) {

            return [
                'success' => false,
                'error'   => $e->getMessage(),
                'firebase_response' => $e->hasResponse()
                    ? json_decode((string) $e->getResponse()->getBody(), true)
                    : null
            ];

        } catch (\Throwable $e) {

            return [
                'success' => false,
                'error'   => $e->getMessage()
            ];
        }
    }
}
