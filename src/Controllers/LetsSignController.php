<?php

namespace Agroprodutor\Controllers;

use Agroprodutor\Services\LetsSignService;
use Agroprodutor\Helpers\RequestHelper;
use Agroprodutor\Helpers\ResponseHelper;

class LetsSignController
{
    /**
     * Envia um documento para assinatura na API LetsSign
     *
     * Headers necessários:
     * - X-Account-Id: UUID da conta LetsSign
     * - X-Partner-Token: Token de autenticação do parceiro
     *
     * Body esperado:
     * {
     *   "documentName": "contrato.pdf",
     *   "contentFile": "base64_do_pdf",
     *   "contentType": "application/pdf",
     *   "signers": [
     *     {
     *       "email": "signatario@email.com",
     *       "name": "Nome do Signatário",
     *       "authenticationMethod": "Email",
     *       "signatureLinkMethod": "NotSend",
     *       "language": "Portuguese",
     *       "role": "Signer"
     *     }
     *   ],
     *   "reminderFrequency": "OneDay"
     * }
     */
    public function signDocument(): void
    {
        $accountId = RequestHelper::getHeader('X-Account-Id');
        $token = RequestHelper::getHeader('X-Partner-Token');

        if (empty($accountId) || empty($token)) {
            ResponseHelper::error('Headers X-Account-Id e X-Partner-Token são obrigatórios', 401);
            return;
        }

        $documentData = RequestHelper::getJsonInput();

        if (empty($documentData['documentName'])) {
            ResponseHelper::error('Campo documentName é obrigatório', 400);
            return;
        }

        if (empty($documentData['contentFile'])) {
            ResponseHelper::error('Campo contentFile é obrigatório', 400);
            return;
        }

        if (empty($documentData['signers']) || !is_array($documentData['signers'])) {
            ResponseHelper::error('Campo signers é obrigatório e deve ser um array', 400);
            return;
        }

        $result = LetsSignService::createDocumentSignature($accountId, $token, $documentData);

        if (!$result['success']) {
            ResponseHelper::error($result['error'], 500);
            return;
        }

        ResponseHelper::json([
            'success' => true,
            'message' => 'Documento enviado para assinatura',
            'data' => $result['data'],
        ]);
    }

    /**
     * Baixa um documento assinado da API LetsSign
     *
     * Headers necessários:
     * - X-Account-Id: UUID da conta LetsSign
     * - X-Partner-Token: Token de autenticação do parceiro
     */
    public function downloadSignedDocument(string $documentId): void
    {
        $accountId = RequestHelper::getHeader('X-Account-Id');
        $token = RequestHelper::getHeader('X-Partner-Token');

        if (empty($accountId) || empty($token)) {
            ResponseHelper::error('Headers X-Account-Id e X-Partner-Token são obrigatórios', 401);
            return;
        }

        $result = LetsSignService::downloadSignedDocument($accountId, $token, $documentId);

        if (!$result['success']) {
            ResponseHelper::error($result['error'], 500);
            return;
        }

        ResponseHelper::pdfDownload($result['content'], "{$documentId}.pdf");
    }

    /**
     * Consulta o status de um documento
     *
     * Headers necessários:
     * - X-Account-Id: UUID da conta LetsSign
     * - X-Partner-Token: Token de autenticação do parceiro
     */
    public function getDocumentStatus(string $documentId): void
    {
        $accountId = RequestHelper::getHeader('X-Account-Id');
        $token = RequestHelper::getHeader('X-Partner-Token');

        if (empty($accountId) || empty($token)) {
            ResponseHelper::error('Headers X-Account-Id e X-Partner-Token são obrigatórios', 401);
            return;
        }

        $result = LetsSignService::getDocumentStatus($accountId, $token, $documentId);

        if (!$result['success']) {
            ResponseHelper::error($result['error'], 500);
            return;
        }

        ResponseHelper::json([
            'success' => true,
            'data' => $result['data'],
        ]);
    }
}
