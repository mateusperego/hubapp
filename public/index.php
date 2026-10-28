<?php

/*
 * Fatal do PHP (memória, tempo de execução, autoload, .env inválido) saía como
 * 500 de corpo vazio e sem nada em storage/logs — o cliente só via o status.
 * Registrado antes do bootstrap para cobrir também as falhas dele. A reserva é
 * liberada no shutdown para sobrar memória quando o fatal for justamente ela.
 */
$fatalReserve = str_repeat(' ', 256 * 1024);

register_shutdown_function(function () use (&$fatalReserve) {
    $fatalReserve = null;

    $erro = error_get_last();
    if ($erro === null || !in_array($erro['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    $raiz = dirname(__DIR__);
    $mensagem = str_replace($raiz, '', $erro['message']);

    $logDir = $raiz . '/storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }
    @file_put_contents($logDir . '/fatal_' . date('Y-m-d') . '.log', sprintf(
        "[%s] %s %s | %s em %s:%d | corpo %d bytes, pico de memória %d bytes, %.1fs\n",
        date('Y-m-d H:i:s'),
        $_SERVER['REQUEST_METHOD'] ?? '-',
        $_SERVER['REQUEST_URI'] ?? '-',
        $mensagem,
        str_replace($raiz, '', $erro['file']),
        $erro['line'],
        (int) ($_SERVER['CONTENT_LENGTH'] ?? 0),
        memory_get_peak_usage(true),
        microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))
    ), FILE_APPEND);

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode(['SUCCESS' => false, 'erro' => $mensagem]);
});

require __DIR__ . '/../src/bootstrap.php';

use HubApp\Controllers\DanfeController;
use HubApp\Controllers\AgroProdutorController;
use HubApp\Controllers\PushNotificationController;
use HubApp\Controllers\LetsSignController;
use HubApp\Controllers\ImageController;
use HubApp\Controllers\BackupController;
use HubApp\Controllers\LogoController;
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
        '/public/danfe/{apelido}/{moduleName}/pdf/{clifor}/{dataMov}',
        [DanfeController::class, 'downloadPdf']
    );

    /* =======================
     * HUBAPP
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
        'GET',
        '/public/agroprodutor/{apelido}/auth/list',
        [AgroProdutorController::class, 'listAuth']
    );

    $r->addRoute(
        'DELETE',
        '/public/agroprodutor/{apelido}/auth/delete',
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
     * BACKUPS DOS VENDEDORES
     *
     * Autenticados com o RELAY_TOKEN. O app sobe a cópia do dia; o painel
     * lista e baixa. É o que sobrevive ao aparelho sumir.
     * ======================= */
    $r->addRoute(
        'POST',
        '/public/backups/{cnpj}/{sellerId}/upload',
        [BackupController::class, 'upload']
    );

    $r->addRoute(
        'GET',
        '/public/backups/{cnpj}/{sellerId}',
        [BackupController::class, 'list']
    );

    $r->addRoute(
        'GET',
        '/public/backups/{cnpj}/{sellerId}/download/{file}',
        [BackupController::class, 'download']
    );

    /* =======================
     * IMAGES
     * ======================= */
    $r->addRoute(
        'POST',
        '/public/images/upload',
        [ImageController::class, 'upload']
    );

    $r->addRoute(
        'GET',
        '/public/images/{cnpj}',
        [ImageController::class, 'list']
    );

    $r->addRoute(
        'GET',
        '/public/images/{cnpj}/{codigo}',
        [ImageController::class, 'serve']
    );

    $r->addRoute(
        'DELETE',
        '/public/images/{cnpj}/{codigo}',
        [ImageController::class, 'delete']
    );

    /* =======================
     * LOGOS
     * ======================= */
    $r->addRoute(
        'GET',
        '/public/logos/{cnpj}',
        [LogoController::class, 'serve']
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
