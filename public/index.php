<?php

require __DIR__ . '/../vendor/autoload.php';

use Agroprodutor\Controllers\DanfeController;
use Agroprodutor\Controllers\AgroProdutorController;

$uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$parts = explode('/', $uri);
$method = $_SERVER['REQUEST_METHOD'];

// Rotas com prefixo: /agroprodutor/public/... ou /danfe/public/...
// parts[0] = 'agroprodutor' ou 'danfe'
// parts[1] = 'public'
// parts[2+] = parâmetros da rota

// Rota: POST /danfe/public/{apelido}/{moduleName}/gerar
if ($parts[0] === 'danfe' && isset($parts[1]) && $parts[1] === 'public' && isset($parts[2]) && isset($parts[3]) && isset($parts[4]) && $parts[4] === 'gerar' && $method === 'POST') {
    DanfeController::gerarPdfs($parts[2], $parts[3]);
    exit;
}

// Rota: GET /danfe/public/{apelido}/{moduleName}/pdf/{clienteId}
if ($parts[0] === 'danfe' && isset($parts[1]) && $parts[1] === 'public' && isset($parts[2]) && isset($parts[3]) && isset($parts[4]) && $parts[4] === 'pdf' && isset($parts[5]) && $method === 'GET') {
    DanfeController::downloadPdf($parts[2], $parts[3], $parts[5]);
    exit;
}

// Rotas /agroprodutor/public/{apelido}/{moduleName}/...
if ($parts[0] === 'agroprodutor' && isset($parts[1]) && $parts[1] === 'public' && isset($parts[2]) && isset($parts[3]) && isset($parts[4])) {
    $apelido = $parts[2];
    $moduleName = $parts[3];
    $action = $parts[4];

    // POST /agroprodutor/public/{apelido}/{moduleName}/setjson/{campoChave?}
    if ($action === 'setjson' && $method === 'POST') {
        $campoChave = isset($parts[5]) ? $parts[5] : 'CLIFOR';
        AgroProdutorController::setJson($apelido, $moduleName, $campoChave);
        exit;
    }

    // GET /agroprodutor/public/{apelido}/{moduleName}/getjson/{jsonName?}
    if ($action === 'getjson' && $method === 'GET') {
        $jsonName = isset($parts[5]) ? $parts[5] : '';
        AgroProdutorController::getJson($apelido, $moduleName, $jsonName);
        exit;
    }

    // POST /agroprodutor/public/{apelido}/{moduleName}/register
    if ($action === 'register' && $method === 'POST') {
        AgroProdutorController::register($apelido, $moduleName);
        exit;
    }

    // POST /agroprodutor/public/{apelido}/{moduleName}/validate
    if ($action === 'validate' && $method === 'POST') {
        AgroProdutorController::validateCredentials($apelido, $moduleName);
        exit;
    }
}

http_response_code(404);
echo 'Rota não encontrada';
