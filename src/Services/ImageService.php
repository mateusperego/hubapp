<?php

namespace HubApp\Services;

use finfo;

class ImageService
{
    private const MAX_SIZE = 5 * 1024 * 1024;
    private const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public static function upload(string $cnpj, string $codigo, array $file): array
    {
        $cnpj = self::sanitizeCnpj($cnpj);
        $codigo = self::sanitizeCodigo($codigo);

        if (empty($cnpj)) {
            return ['success' => false, 'error' => 'CNPJ inválido'];
        }

        if (empty($codigo)) {
            return ['success' => false, 'error' => 'Código inválido. Use apenas letras, números, hífens e underscores'];
        }

        $validation = self::validateFile($file);
        if (!$validation['success']) {
            return $validation;
        }

        $storagePath = self::getStoragePath($cnpj);
        self::ensureDirectoryExists($storagePath);

        $filename = self::buildFilename($codigo, $validation['ext']);
        $filePath = $storagePath . $filename;

        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            self::logError('upload', 'Falha ao mover arquivo enviado', [
                'cnpj' => $cnpj,
                'codigo' => $codigo,
                'filename' => $filename,
            ]);
            return ['success' => false, 'error' => 'Erro ao salvar imagem'];
        }

        return [
            'success' => true,
            'filename' => $filename,
            'url' => "/public/images/{$cnpj}/{$codigo}",
            'codigo'   => $codigo,
        ];
    }

    public static function getImagePath(string $cnpj, string $codigo): ?string
    {
        $cnpj = self::sanitizeCnpj($cnpj);
        $codigo = self::sanitizeCodigo(pathinfo($codigo, PATHINFO_FILENAME));

        if (empty($cnpj) || empty($codigo)) {
            return null;
        }

        $storagePath = self::getStoragePath($cnpj);

        foreach (['jpg', 'png', 'webp'] as $ext) {
            $filePath = $storagePath . $codigo . '.' . $ext;
            if (file_exists($filePath) && is_file($filePath)) {
                return $filePath;
            }
        }

        return null;
    }

    public static function listImages(string $cnpj, string $baseUrl = ''): array
    {
        $cnpj = self::sanitizeCnpj($cnpj);

        if (empty($cnpj)) {
            return [];
        }

        $storagePath = self::getStoragePath($cnpj);

        if (!is_dir($storagePath)) {
            return [];
        }

        $images = [];
        $files = glob($storagePath . '*.{jpg,png,webp}', GLOB_BRACE);

        foreach ($files as $filePath) {
            $codigo = pathinfo($filePath, PATHINFO_FILENAME);
            $images[] = [
                'codigo' => $codigo,
                'url'    => $baseUrl . "/public/images/{$cnpj}/{$codigo}",
            ];
        }

        return $images;
    }

    private static function validateFile(array $file): array
    {
        if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => 'Erro no upload do arquivo'];
        }

        if ($file['size'] > self::MAX_SIZE) {
            return ['success' => false, 'error' => 'Arquivo excede o tamanho máximo de 5MB'];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);

        if (!in_array($mimeType, self::ALLOWED_TYPES, true)) {
            return ['success' => false, 'error' => 'Tipo de arquivo não permitido. Use: jpeg, png ou webp'];
        }

        $originalExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($originalExt, self::ALLOWED_EXTENSIONS, true)) {
            return ['success' => false, 'error' => 'Extensão de arquivo não permitida. Use: jpg, jpeg, png ou webp'];
        }

        $ext = $originalExt === 'jpeg' ? 'jpg' : $originalExt;

        return ['success' => true, 'ext' => $ext];
    }

    private static function sanitizeCnpj(string $cnpj): string
    {
        return preg_replace('/\D/', '', $cnpj);
    }

    private static function sanitizeCodigo(string $codigo): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '', $codigo);
    }

    private static function buildFilename(string $codigo, string $ext): string
    {
        return "{$codigo}.{$ext}";
    }

    private static function getStoragePath(string $cnpj): string
    {
        return __DIR__ . '/../../storage/images/' . $cnpj . '/';
    }

    private static function ensureDirectoryExists(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    private static function logError(string $method, string $message, array $context = []): void
    {
        $logDir = __DIR__ . '/../../storage/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }

        $logFile = $logDir . '/image_' . date('Y-m-d') . '.log';
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = json_encode($context);

        $logMessage = "[{$timestamp}] [{$method}] {$message} | Context: {$contextStr}\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
    }
}
