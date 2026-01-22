<?php

namespace Agroprodutor\Controllers;

use Agroprodutor\Auth\GoogleOAuth;
use Agroprodutor\Firebase\FcmClient;

class PushNotificationController
{
    public static function send(): void
    {
        $body = json_decode(file_get_contents('php://input'), true);

        if (!$body || empty($body['token']) || empty($body['title'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Payload inválido']);
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
            'data' => $body['data'] ?? [],
        ];

        $result = $fcm->send($accessToken, $message);

        echo json_encode([
            'success' => true,
            'firebase' => $result
        ]);
    }
}
