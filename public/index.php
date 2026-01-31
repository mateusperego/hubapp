<?php

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

use Agroprodutor\Controllers\DanfeController;
use Agroprodutor\Controllers\AgroProdutorController;
use Agroprodutor\Controllers\PushNotificationController;
use Agroprodutor\Controllers\LetsSignController;
use FastRoute\RouteCollector;

$dispatcher = FastRoute\simpleDispatcher(function (RouteCollector $r) {

    /* =======================
     * DANFE
     * ======================= */
    $r->addRoute(
        'POST',
        '/public/danfe/{apelido}/{moduleName}/gerar',
        [DanfeController::class, 'gerarPdfs']
    );

    $r->addRoute(
        'GET',
        '/public/danfe/{apelido}/{moduleName}/pdf/{clienteId}',
        [DanfeController::class, 'downloadPdf']
    );

    /* =======================
     * AGROPRODUTOR
     * ======================= */
    $r->addRoute(
        'POST',
        '/public/agroprodutor/{apelido}/{moduleName}/setjson[/{campoChave}]',
        [AgroProdutorController::class, 'setJson']
    );

    $r->addRoute(
        'GET',
        '/public/agroprodutor/{apelido}/{moduleName}/getjson[/{jsonName}]',
        [AgroProdutorController::class, 'getJson']
    );

    $r->addRoute(
        'POST',
        '/public/agroprodutor/{apelido}/{moduleName}/register',
        [AgroProdutorController::class, 'register']
    );

    $r->addRoute(
        'POST',
        '/public/agroprodutor/{apelido}/{moduleName}/validate',
        [AgroProdutorController::class, 'validateCredentials']
    );

    $r->addRoute(
        'DELETE',
        '/public/agroprodutor/auth/{apelido}/delete',
        [AgroProdutorController::class, 'deleteAuth']
    );

    /* =======================
     * PUSH / FIREBASE
     * ======================= */
    $r->addRoute(
        'POST',
        '/public/push/{cnpj}/{app}/send',
        [PushNotificationController::class, 'send']
    );

    $r->addRoute(
        'POST',
        '/public/push/{cnpj}/{app}/send-multi',
        [PushNotificationController::class, 'sendMulti']
    );

    $r->addRoute(
        'POST',
        '/public/push/{cnpj}/{app}/topic',
        [PushNotificationController::class, 'sendToTopic']
    );

    /* =======================
     * LETSSIGN
     * ======================= */
    $r->addRoute(
        'POST',
        '/public/letssign/sign',
        [LetsSignController::class, 'signDocument']
    );

    $r->addRoute(
        'POST',
        '/public/letssign/sign-upload',
        [LetsSignController::class, 'signDocumentWithUpload']
    );

    $r->addRoute(
        'GET',
        '/public/letssign/download/{documentId}',
        [LetsSignController::class, 'downloadSignedDocument']
    );

    $r->addRoute(
        'GET',
        '/public/letssign/status/{documentId}',
        [LetsSignController::class, 'getDocumentStatus']
    );
});

$httpMethod = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];

// Remove query string (?a=b)
if (false !== $pos = strpos($uri, '?')) {
    $uri = substr($uri, 0, $pos);
}

$uri = rawurldecode($uri);

$routeInfo = $dispatcher->dispatch($httpMethod, $uri);

header('Content-Type: application/json; charset=UTF-8');

switch ($routeInfo[0]) {

    case FastRoute\Dispatcher::NOT_FOUND:
        http_response_code(404);
        echo json_encode(['error' => 'Rota não encontrada']);
        break;

    case FastRoute\Dispatcher::METHOD_NOT_ALLOWED:
        http_response_code(405);
        echo json_encode([
            'error' => 'Método não permitido',
            'allowed' => $routeInfo[1]
        ]);
        break;

    case FastRoute\Dispatcher::FOUND:
        [$controller, $method] = $routeInfo[1];
        $vars = $routeInfo[2];

        call_user_func_array(
            [new $controller, $method],
            $vars
        );
        break;
}
