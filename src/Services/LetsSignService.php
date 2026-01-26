<?php

namespace Agroprodutor\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Exception;

class LetsSignService
{
    private const BASE_URL = 'https://api.letssign.com.br/partners/v1/';

    private static function getClient(string $token): Client
    {
        return new Client([
            'base_uri' => self::BASE_URL,
            'headers' => [
                'Authorization' => $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'timeout' => 60,
        ]);
    }

    /**
     * Cria uma solicitação de assinatura de documento na API LetsSign
     *
     * @param string $accountId UUID da conta LetsSign
     * @param string $token Token de autenticação do parceiro
     * @param array $documentData Dados do documento para assinatura
     * @return array Resposta da API com documentId
     * @throws Exception
     */
    public static function createDocumentSignature(string $accountId, string $token, array $documentData): array
    {
        try {
            $client = self::getClient($token);

            $response = $client->post("accounts/{$accountId}/document-signatures", [json_encode($documentData)]);

            $body = json_decode($response->getBody()->getContents(), true);

            return [
                'success' => true,
                'data' => $body,
            ];
        } catch (GuzzleException $e) {
            self::logError('createDocumentSignature', $e->getMessage(), [
                'accountId' => $accountId,
                'documentName' => $documentData['documentName'] ?? 'unknown',
            ]);

            return [
                'success' => false,
                'error' => 'Erro ao criar assinatura: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Baixa o documento assinado da API LetsSign
     *
     * @param string $accountId UUID da conta LetsSign
     * @param string $token Token de autenticação do parceiro
     * @param string $documentId ID do documento
     * @return array Resposta com o conteúdo do PDF ou erro
     * @throws Exception
     */
    public static function downloadSignedDocument(string $accountId, string $token, string $documentId): array
    {
        try {
            $client = self::getClient($token);

            $response = $client->get("accounts/{$accountId}/documents/{$documentId}/download/signed");

            $content = $response->getBody()->getContents();
            $contentType = $response->getHeaderLine('Content-Type');

            return [
                'success' => true,
                'content' => $content,
                'contentType' => $contentType,
            ];
        } catch (GuzzleException $e) {
            self::logError('downloadSignedDocument', $e->getMessage(), [
                'accountId' => $accountId,
                'documentId' => $documentId,
            ]);

            return [
                'success' => false,
                'error' => 'Erro ao baixar documento assinado: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Consulta o status de um documento
     *
     * @param string $accountId UUID da conta LetsSign
     * @param string $token Token de autenticação do parceiro
     * @param string $documentId ID do documento
     * @return array Status do documento
     */
    public static function getDocumentStatus(string $accountId, string $token, string $documentId): array
    {
        try {
            $client = self::getClient($token);

            $response = $client->get("accounts/{$accountId}/documents/{$documentId}");

            $body = json_decode($response->getBody()->getContents(), true);

            return [
                'success' => true,
                'data' => $body,
            ];
        } catch (GuzzleException $e) {
            self::logError('getDocumentStatus', $e->getMessage(), [
                'accountId' => $accountId,
                'documentId' => $documentId,
            ]);

            return [
                'success' => false,
                'error' => 'Erro ao consultar status: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Cria uma solicitação de assinatura a partir de um arquivo PDF
     *
     * @param string $accountId UUID da conta LetsSign
     * @param string $token Token de autenticação do parceiro
     * @param array $payload Dados do documento para assinatura (sem contentFile)
     * @param string $fileContent Conteúdo binário do arquivo PDF
     * @return array Resposta da API com documentId
     */
    public static function createDocumentSignatureFromFile(
        string $accountId,
        string $token,
        array $payload,
        string $fileContent
    ): array {
        // Converter conteúdo binário para base64
        $payload['contentFile'] = base64_encode($fileContent);

        // Definir contentType se não existir
        if (!isset($payload['contentType'])) {
            $payload['contentType'] = 'application/pdf';
        }

        // Chamar método existente
        return self::createDocumentSignature($accountId, $token, $payload);
    }

    /**
     * Registra erros no log
     */
    private static function logError(string $method, string $message, array $context = []): void
    {
        $logDir = __DIR__ . '/../../storage/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }

        $logFile = $logDir . '/letssign_' . date('Y-m-d') . '.log';
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = json_encode($context);

        $logMessage = "[{$timestamp}] [{$method}] {$message} | Context: {$contextStr}\n";

        file_put_contents($logFile, $logMessage, FILE_APPEND);
    }
}
