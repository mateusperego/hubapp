<?php

namespace HubApp\Controllers;

use HubApp\Helpers\RequestHelper;
use HubApp\Helpers\ResponseHelper;
use HubApp\Services\BackupStorageService;

/**
 * Cópias do banco dos vendedores, fora do aparelho.
 *
 * Autenticado com o mesmo `RELAY_TOKEN` do canal de suporte: o app e o painel
 * já o carregam, e um segredo a mais só seria mais uma coisa para sair de
 * sincronia. Continua sendo o único endpoint autenticado deste servidor — os
 * outros nasceram sem autenticação nenhuma, o que é um problema separado.
 */
class BackupController
{
    public function upload(string $cnpj, string $sellerId): void
    {
        if (!self::authorized()) {
            return;
        }

        if (!isset($_FILES['backup'])) {
            ResponseHelper::error('Campo backup é obrigatório', 400);
            return;
        }

        $stores = $_POST['stores'] ?? '[]';
        $result = BackupStorageService::store($cnpj, $sellerId, $_FILES['backup'], [
            'file' => $_POST['file'] ?? '',
            'created_at' => $_POST['created_at'] ?? '',
            'origin' => $_POST['origin'] ?? '',
            'documents' => $_POST['documents'] ?? 0,
            'original_bytes' => $_POST['original_bytes'] ?? 0,
            'stores' => is_string($stores) ? (json_decode($stores, true) ?: []) : [],
        ]);

        if (!$result['success']) {
            ResponseHelper::error($result['error'], 400);
            return;
        }

        ResponseHelper::json(['success' => true, 'backup' => $result['backup']]);
    }

    public function list(string $cnpj, string $sellerId): void
    {
        if (!self::authorized()) {
            return;
        }

        ResponseHelper::json([
            'success' => true,
            'items' => BackupStorageService::list($cnpj, $sellerId),
        ]);
    }

    public function download(string $cnpj, string $sellerId, string $file): void
    {
        if (!self::authorized()) {
            return;
        }

        $path = BackupStorageService::pathOf($cnpj, $sellerId, $file);
        if ($path === null) {
            ResponseHelper::error('Backup não encontrado', 404);
            return;
        }

        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
    }

    /** Responde 401 e devolve `false` quando o token não confere. */
    private static function authorized(): bool
    {
        $expected = trim((string) ($_ENV['RELAY_TOKEN'] ?? ''));
        $header = (string) RequestHelper::getHeader('Authorization');
        $presented = str_starts_with($header, 'Bearer ')
            ? substr($header, 7)
            : '';

        // hash_equals: comparação em tempo constante, como no relay.
        if ($expected !== '' && hash_equals($expected, $presented)) {
            return true;
        }

        ResponseHelper::error('Não autorizado', 401);
        return false;
    }
}
