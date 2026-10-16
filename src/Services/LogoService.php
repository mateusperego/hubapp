<?php

namespace HubApp\Services;

use finfo;

/**
 * Logos das empresas, servidas aos apps por CNPJ.
 *
 * Os arquivos ficam em `storage/logos/` com o CNPJ como nome: `{cnpj14}.png`
 * vale só para aquela filial; `{raiz8}.png` (as 8 primeiras posições) vale para
 * todas as filiais da empresa. O CNPJ cheio tem prioridade.
 */
class LogoService
{
    private const EXTENSIONS = ['png', 'jpg'];
    private const ALLOWED_MIMES = ['image/png', 'image/jpeg'];

    /** O mesmo teto do app: acima dele o app recusa e rebaixaria a cada sync. */
    private const MAX_SIZE = 2 * 1024 * 1024;

    /**
     * Caminho e MIME da logo do CNPJ, ou null quando não há logo válida.
     *
     * @return array{path: string, mime: string}|null
     */
    public static function find(string $cnpj): ?array
    {
        $cnpj = self::normalizeCnpj($cnpj);
        if (strlen($cnpj) < 8) {
            return null;
        }

        $candidates = [$cnpj];
        $root = substr($cnpj, 0, 8);
        if ($root !== $cnpj) {
            $candidates[] = $root;
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        foreach ($candidates as $name) {
            foreach (self::EXTENSIONS as $ext) {
                $path = self::getStoragePath() . $name . '.' . $ext;
                if (!is_file($path)) {
                    continue;
                }
                if (filesize($path) > self::MAX_SIZE) {
                    error_log("[logos] {$path} passa de 2 MB e foi ignorada");
                    continue;
                }
                $mime = $finfo->file($path);
                if (in_array($mime, self::ALLOWED_MIMES, true)) {
                    return ['path' => $path, 'mime' => $mime];
                }
            }
        }

        return null;
    }

    /**
     * ETag forte do arquivo. Leva o nome junto: trocar a logo da raiz pela do
     * CNPJ cheio muda o ETag mesmo que mtime e tamanho coincidam.
     */
    public static function etag(string $path): string
    {
        return '"' . md5(basename($path) . '|' . filemtime($path) . '|' . filesize($path)) . '"';
    }

    /**
     * Letras e dígitos em maiúsculas — o CNPJ alfanumérico da Receita tem
     * letras nas 12 primeiras posições, então tirar tudo que não é dígito o
     * destruiria.
     */
    private static function normalizeCnpj(string $cnpj): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($cnpj));
    }

    private static function getStoragePath(): string
    {
        return dirname(__DIR__, 2) . '/storage/logos/';
    }
}
