<?php

namespace HubApp\Controllers;

use HubApp\Services\ImageService;
use HubApp\Helpers\RequestHelper;
use HubApp\Helpers\ResponseHelper;
use finfo;

class ImageController
{
    public function upload(): void
    {
        $cnpj = RequestHelper::getHeader('X-CNPJ');
        $codigo = RequestHelper::getHeader('X-Codigo');

        if (empty($cnpj)) {
            ResponseHelper::error('Header X-CNPJ é obrigatório', 400);
            return;
        }

        if (empty($codigo)) {
            ResponseHelper::error('Header X-Codigo é obrigatório', 400);
            return;
        }

        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            ResponseHelper::error('Campo image é obrigatório', 400);
            return;
        }

        $result = ImageService::upload($cnpj, $codigo, $_FILES['image']);

        if (!$result['success']) {
            ResponseHelper::error($result['error'], 400);
            return;
        }

        $baseUrl = RequestHelper::getBaseUrl();

        ResponseHelper::json([
            'success' => true,
            'message' => 'Imagem enviada com sucesso',
            'data' => [
                'filename' => $result['filename'],
                'url'      => $baseUrl . $result['url'],
            ],
        ]);
    }

    public function list(string $cnpj): void
    {
        $baseUrl = RequestHelper::getBaseUrl();
        $images  = ImageService::listImages($cnpj, $baseUrl);

        ResponseHelper::json([
            'success' => true,
            'data'    => $images,
        ]);
    }

    public function serve(string $cnpj, string $codigo): void
    {
        try {
            $filePath = ImageService::getImagePath($cnpj, $codigo);

            if ($filePath === null) {
                ResponseHelper::notFound('Imagem não encontrada');
                return;
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($filePath);

            $allowedServeMimes = ['image/jpeg', 'image/png', 'image/webp'];
            if (!in_array($mimeType, $allowedServeMimes, true)) {
                ResponseHelper::error('Tipo de arquivo não suportado', 415);
                return;
            }

            header('Content-Type: ' . $mimeType);
            header('Content-Length: ' . filesize($filePath));
            header('Cache-Control: public, max-age=86400');
            readfile($filePath);
        } catch (\Throwable $e) {
            ResponseHelper::error('Erro interno: ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine(), 500);
        }
    }
}
