<?php

namespace Agroprodutor\Controllers;

use Agroprodutor\Services\JsonStorageService;
use Agroprodutor\Helpers\RequestHelper;
use Agroprodutor\Helpers\ResponseHelper;

class AgroProdutorController
{
    public static function setJson(string $apelido, string $moduleName, string $campoChave = 'CLIFOR'): void
    {
        $registros = RequestHelper::getJsonInput();

        $arquivosCriados = JsonStorageService::setJson($apelido, $moduleName, $registros, $campoChave);

        ResponseHelper::text((string) $arquivosCriados);
    }

    public static function getJson(string $apelido, string $moduleName, string $jsonName = ''): void
    {
        $result = JsonStorageService::getJson($apelido, $moduleName, $jsonName);

        ResponseHelper::json($result);
    }

    public static function register(string $apelido, string $moduleName): void
    {
        $registro = RequestHelper::getJsonInput();

        $result = JsonStorageService::register($apelido, $moduleName, $registro);

        ResponseHelper::json($result);
    }

    public static function validateCredentials(string $apelido, string $moduleName): void
    {
        $credenciais = RequestHelper::getJsonInput();

        if (!isset($credenciais['CNPJCPF']) || !isset($credenciais['PASSWORD'])) {
            ResponseHelper::json(['VALID' => false]);
            return;
        }

        $valid = JsonStorageService::validateCredentials(
            $apelido,
            $moduleName,
            $credenciais['CNPJCPF'],
            $credenciais['PASSWORD']
        );

        ResponseHelper::json(['VALID' => $valid]);
    }

    public static function deleteAuth(string $apelido): void
    {
        $dados = RequestHelper::getJsonInput();

        if (!isset($dados['cnpjcpf'])) {
            ResponseHelper::error('Campo cnpjcpf é obrigatório', 400);
            return;
        }

        $result = JsonStorageService::deleteAuth($apelido, $dados['cnpjcpf']);

        ResponseHelper::json($result);
    }
}
