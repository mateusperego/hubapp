<?php

namespace HubApp\Controllers;

use HubApp\Services\JsonStorageService;
use HubApp\Helpers\RequestHelper;
use HubApp\Helpers\ResponseHelper;

class AgroProdutorController
{
    /**
     * Um lote de coletas de 17 MB (95 mil registros) estourava os 128 MB
     * padrão: o json_decode ocupa de 6 a 8 vezes o tamanho do corpo. Vale só
     * para esta rota; o cliente também divide os lotes por CLIFOR.
     */
    private const MEMORIA_SETJSON = 512 * 1024 * 1024;

    /**
     * Sobe o memory_limit até $bytes, sem nunca reduzir um limite maior (ou
     * ilimitado) já configurado no servidor.
     */
    private static function garantirMemoria(int $bytes): void
    {
        $atual = trim((string) ini_get('memory_limit'));
        if ($atual === '-1') {
            return;
        }

        $valor = (int) $atual;
        switch (strtoupper(substr($atual, -1))) {
            case 'G':
                $valor *= 1024;
                // no break
            case 'M':
                $valor *= 1024;
                // no break
            case 'K':
                $valor *= 1024;
        }

        if ($valor < $bytes) {
            ini_set('memory_limit', (string) $bytes);
        }
    }

    public static function setJson(string $apelido, string $moduleName, string $campoChave = 'CLIFOR'): void
    {
        // Os três viram caminho em storage/json: só barra o que escaparia dele
        // ou não seria um nome de arquivo, para não recusar cliente que já existe.
        foreach ([$apelido, $moduleName, $campoChave] as $segmento) {
            if (!JsonStorageService::nomeDeArquivoValido($segmento)) {
                ResponseHelper::json(['SUCCESS' => false, 'erro' => 'apelido, módulo ou campo-chave inválido'], 400);
                return;
            }
        }

        self::garantirMemoria(self::MEMORIA_SETJSON);

        // Corpo inválido virava lote vazio e respondia 200 "0": o cliente dava
        // as coletas como enviadas sem que nada tivesse sido gravado.
        $registros = RequestHelper::getJsonInputOrNull();
        if ($registros === null && json_last_error() !== JSON_ERROR_NONE) {
            ResponseHelper::json(['SUCCESS' => false, 'erro' => 'O corpo não é um JSON válido: ' . json_last_error_msg()], 400);
            return;
        }
        if ($registros === null || ($registros !== [] && array_keys($registros) !== range(0, count($registros) - 1))) {
            ResponseHelper::json(['SUCCESS' => false, 'erro' => 'O corpo deve ser um array JSON de registros'], 400);
            return;
        }

        $invalidos = JsonStorageService::validarRegistros($registros, $campoChave);
        if (!empty($invalidos)) {
            ResponseHelper::json([
                'SUCCESS' => false,
                'erro' => 'Lote rejeitado: registros inválidos. Nada foi gravado.',
                'itens' => array_slice($invalidos, 0, 50),
                'total_invalidos' => count($invalidos),
            ], 400);
            return;
        }

        try {
            $arquivosCriados = JsonStorageService::setJson($apelido, $moduleName, $registros, $campoChave);
        } catch (\Throwable $e) {
            error_log('[setjson] ' . $e->getMessage());
            ResponseHelper::json(['SUCCESS' => false, 'erro' => $e->getMessage()], 500);
            return;
        }

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

    public static function listAuth(string $apelido): void
    {
        $result = JsonStorageService::listAuth($apelido);
        ResponseHelper::json($result);
    }

    public static function deleteAuth(string $apelido): void
    {
        $dados = RequestHelper::getJsonInput();

        if (!isset($dados['CNPJCPF'])) {
            ResponseHelper::error('Campo CNPJCPF é obrigatório', 400);
            return;
        }

        $result = JsonStorageService::deleteAuth($apelido, $dados['CNPJCPF']);

        ResponseHelper::json($result);
    }
}
