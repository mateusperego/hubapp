<?php

require __DIR__ . '/../vendor/autoload.php';

use Agroprodutor\Controllers\DanfeController;
use Agroprodutor\Controllers\AgroProdutorController;
use FastRoute\RouteCollector;

$dispatcher = FastRoute\simpleDispatcher(function (RouteCollector $r) {
    // Rotas DANFE - /public/danfe/...
    $r->addRoute('POST', '/public/danfe/{apelido}/{moduleName}/gerar', [DanfeController::class, 'gerarPdfs']);
    $r->addRoute('GET', '/public/danfe/{apelido}/{moduleName}/pdf/{clienteId}', [DanfeController::class, 'downloadPdf']);

    // Rotas Agroprodutor - /public/agroprodutor/...
    $r->addRoute('POST', '/public/agroprodutor/{apelido}/{moduleName}/setjson[/{campoChave}]', [AgroProdutorController::class, 'setJson']);
    $r->addRoute('GET', '/public/agroprodutor/{apelido}/{moduleName}/getjson[/{jsonName}]', [AgroProdutorController::class, 'getJson']);
    $r->addRoute('POST', '/public/agroprodutor/{apelido}/{moduleName}/register', [AgroProdutorController::class, 'register']);
    $r->addRoute('POST', '/public/agroprodutor/{apelido}/{moduleName}/validate', [AgroProdutorController::class, 'validateCredentials']);
});

$httpMethod = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];

if (false !== $pos = strpos($uri, '?')) {
    $uri = substr($uri, 0, $pos);
}
$uri = rawurldecode($uri);

$routeInfo = $dispatcher->dispatch($httpMethod, $uri);

switch ($routeInfo[0]) {
    case FastRoute\Dispatcher::NOT_FOUND:
        http_response_code(404);
        echo json_encode(['error' => 'Rota não encontrada']);
        break;

    case FastRoute\Dispatcher::METHOD_NOT_ALLOWED:
        $allowedMethods = $routeInfo[1];
        http_response_code(405);
        echo json_encode(['error' => 'Método não permitido', 'allowed' => $allowedMethods]);
        break;

    case FastRoute\Dispatcher::FOUND:
        $handler = $routeInfo[1];
        $vars = $routeInfo[2];

        [$controller, $method] = $handler;

        // Chama o método do controller com os parâmetros da rota
        call_user_func_array([$controller, $method], $vars);
        break;
}
