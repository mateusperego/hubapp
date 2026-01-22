<?php

namespace Agroprodutor\Controllers;

use Agroprodutor\Auth\GoogleOAuth;
use Agroprodutor\Firebase\FcmClient;

class PushNotificationController
{
    private static function getBody(): array
    {
        return json_decode(file_get_contents('php://input'), true) ?? [];
    }

    /**
     * Envio para UM token
     */
    public static function send(): void
    {
        try {
            $body = self::getBody();

            if (empty($body['token']) || empty($body['title'])) {
                http_response_code(400);
                echo json_encode(['error' => 'token e title são obrigatórios']);
                return;
            }

            $config = require __DIR__ . '/../../config/firebase.php';

            $oauth = new GoogleOAuth($config);
            $accessToken = $oauth->getAccessToken();

            $fcm = new FcmClient($config['project_id']);

            $message = [
                'token' => $body['token'],
                'notification' => [
                    'title' => $body['title'],
                    'body'  => $body['body'] ?? '',
                ],
            ];

            if (!empty($body['data']) && is_array($body['data'])) {
                $message['data'] = self::normalizeData($body['data']);
            }

            $result = $fcm->send($accessToken, $message);

            echo json_encode([
                'success'  => true,
                'firebase' => $result
            ]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error'   => $e->getMessage()
            ]);
        }
    }

    /**
     * Envio para VÁRIOS tokens
     */
    public static function sendMulti(): void
    {
        try {
            $body = self::getBody();

            if (empty($body['tokens']) || !is_array($body['tokens'])) {
                http_response_code(400);
                echo json_encode(['error' => 'tokens deve ser um array']);
                return;
            }

            if (empty($body['title'])) {
                http_response_code(400);
                echo json_encode(['error' => 'title é obrigatório']);
                return;
            }

            $config = require __DIR__ . '/../../config/firebase.php';

            $oauth = new GoogleOAuth($config);
            $accessToken = $oauth->getAccessToken();

            $fcm = new FcmClient($config['project_id']);

            $responses = [];

            foreach ($body['tokens'] as $token) {
                $message = [
                    'token' => $token,
                    'notification' => [
                        'title' => $body['title'],
                        'body'  => $body['body'] ?? '',
                    ],
                ];

                if (!empty($body['data']) && is_array($body['data'])) {
                    $message['data'] = self::normalizeData($body['data']);
                }

                $responses[] = $fcm->send($accessToken, $message);
            }

            echo json_encode([
                'success' => true,
                'sent'    => count($responses),
                'results' => $responses
            ]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error'   => $e->getMessage()
            ]);
        }
    }

    /**
     * Envio para TODOS via TOPIC (broadcast)
     */
    public static function sendToTopic(): void
    {
        try {
            $body = self::getBody();

            if (empty($body['topic'])) {
                http_response_code(400);
                echo json_encode(['error' => 'topic é obrigatório']);
                return;
            }

            if (empty($body['title'])) {
                http_response_code(400);
                echo json_encode(['error' => 'title é obrigatório']);
                return;
            }

            $config = require __DIR__ . '/../../config/firebase.php';

            $oauth = new GoogleOAuth($config);
            $accessToken = $oauth->getAccessToken();

            $fcm = new FcmClient($config['project_id']);

            $message = [
                'topic' => $body['topic'],
                'notification' => [
                    'title' => $body['title'],
                    'body'  => $body['body'] ?? '',
                ],
            ];

            if (!empty($body['data']) && is_array($body['data'])) {
                $message['data'] = self::normalizeData($body['data']);
            }

            $result = $fcm->send($accessToken, $message);

            echo json_encode([
                'success'  => true,
                'firebase' => $result
            ]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error'   => $e->getMessage()
            ]);
        }
    }


    private static function normalizeData(array $data): array
    {
        $normalized = [];

        foreach ($data as $key => $value) {
            if (is_scalar($value)) {
                $normalized[$key] = (string) $value;
            } else {
                // objetos / arrays viram JSON string
                $normalized[$key] = json_encode($value);
            }
        }

        return $normalized;
    }
}
