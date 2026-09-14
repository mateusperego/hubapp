<?php

namespace HubApp\Services;

/**
 * Cópias do banco dos vendedores, guardadas fora do aparelho.
 *
 * O app já guarda backups no celular, e aquilo resolve o caso comum — desfazer
 * uma correção que piorou as coisas. O que aquilo não resolve é o celular
 * sumir: desinstalar o app, formatar, perder, quebrar, trocar de aparelho.
 * Justamente quando o backup mais importa. Por isso ele também vem para cá.
 *
 * Chega comprimido: um export de 6 MB vira menos de 1 MB, e quem paga a
 * diferença é o plano de dados do vendedor.
 */
class BackupStorageService
{
    /** Teto por arquivo, coerente com o `upload_max_filesize` do php.ini. */
    private const MAX_SIZE = 100 * 1024 * 1024;

    /**
     * Quantas cópias de cada origem ficam por vendedor.
     *
     * A poda é por origem, e não no bolo, pelo mesmo motivo do aparelho: uma
     * sequência de correções apagaria os diários da semana — justamente as
     * cópias que salvam quando a correção foi a causa do problema.
     */
    private const KEEP = [
        'automatico' => 7,
        'manual' => 3,
        'correcao' => 3,
        'restauracao' => 2,
    ];

    private const DEFAULT_KEEP = 2;

    public static function store(
        string $cnpj,
        string $sellerId,
        array $file,
        array $meta
    ): array {
        $cnpj = self::sanitize($cnpj);
        $sellerId = self::sanitize($sellerId);

        if ($cnpj === '' || $sellerId === '') {
            return ['success' => false, 'error' => 'CNPJ ou vendedor inválido.'];
        }

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => 'Falha no envio do arquivo.'];
        }

        if (($file['size'] ?? 0) <= 0 || $file['size'] > self::MAX_SIZE) {
            return ['success' => false, 'error' => 'Arquivo vazio ou acima do limite.'];
        }

        $name = self::sanitizeFilename((string) ($meta['file'] ?? ''));
        if ($name === '') {
            return ['success' => false, 'error' => 'Nome de arquivo inválido.'];
        }

        $dir = self::directory($cnpj, $sellerId);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['success' => false, 'error' => 'Não foi possível criar a pasta de backups.'];
        }

        $path = $dir . '/' . $name;
        if (!move_uploaded_file($file['tmp_name'], $path)) {
            return ['success' => false, 'error' => 'Falha ao gravar o arquivo.'];
        }

        $record = [
            'file' => $name,
            'created_at' => (string) ($meta['created_at'] ?? date('c')),
            'origin' => self::sanitizeOrigin((string) ($meta['origin'] ?? '')),
            'documents' => (int) ($meta['documents'] ?? 0),
            'stores' => array_values(array_filter(
                array_map('strval', (array) ($meta['stores'] ?? []))
            )),
            // Tamanho do arquivo comprimido, que é o que ocupa disco aqui.
            'bytes' => filesize($path) ?: 0,
            // Tamanho do `.jsonl` original, que é o que o painel mostra.
            'original_bytes' => (int) ($meta['original_bytes'] ?? 0),
            'uploaded_at' => date('c'),
        ];

        file_put_contents(
            $path . '.meta.json',
            json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        self::prune($dir);

        return ['success' => true, 'backup' => $record];
    }

    /** @return array[] do mais recente para o mais antigo */
    public static function list(string $cnpj, string $sellerId): array
    {
        $dir = self::directory(self::sanitize($cnpj), self::sanitize($sellerId));
        if (!is_dir($dir)) {
            return [];
        }

        $backups = [];
        foreach (glob($dir . '/*.meta.json') ?: [] as $meta) {
            $decoded = json_decode((string) file_get_contents($meta), true);
            if (!is_array($decoded)) {
                continue;
            }
            // Metadado órfão: o arquivo foi apagado e a listagem não pode
            // oferecer o que não dá para baixar.
            if (!is_file(substr($meta, 0, -strlen('.meta.json')))) {
                continue;
            }
            $backups[] = $decoded;
        }

        usort(
            $backups,
            static fn(array $a, array $b) => strcmp(
                (string) ($b['created_at'] ?? ''),
                (string) ($a['created_at'] ?? '')
            )
        );

        return $backups;
    }

    /** Caminho absoluto de um backup, ou `null` se não existir. */
    public static function pathOf(string $cnpj, string $sellerId, string $file): ?string
    {
        $name = self::sanitizeFilename($file);
        if ($name === '') {
            return null;
        }

        $path = self::directory(self::sanitize($cnpj), self::sanitize($sellerId))
            . '/' . $name;

        return is_file($path) ? $path : null;
    }

    private static function prune(string $dir): void
    {
        $byOrigin = [];
        foreach (self::listDirectory($dir) as $backup) {
            $byOrigin[$backup['origin']][] = $backup['file'];
        }

        foreach ($byOrigin as $origin => $files) {
            $keep = self::KEEP[$origin] ?? self::DEFAULT_KEEP;
            foreach (array_slice($files, $keep) as $old) {
                @unlink($dir . '/' . $old);
                @unlink($dir . '/' . $old . '.meta.json');
            }
        }
    }

    /** @return array[] já ordenado, sem depender do `list` público */
    private static function listDirectory(string $dir): array
    {
        $backups = [];
        foreach (glob($dir . '/*.meta.json') ?: [] as $meta) {
            $decoded = json_decode((string) file_get_contents($meta), true);
            if (is_array($decoded) && isset($decoded['file'])) {
                $decoded['origin'] = self::sanitizeOrigin((string) ($decoded['origin'] ?? ''));
                $backups[] = $decoded;
            }
        }

        usort(
            $backups,
            static fn(array $a, array $b) => strcmp(
                (string) ($b['created_at'] ?? ''),
                (string) ($a['created_at'] ?? '')
            )
        );

        return $backups;
    }

    private static function directory(string $cnpj, string $sellerId): string
    {
        return dirname(__DIR__, 2) . "/storage/backups/{$cnpj}/{$sellerId}";
    }

    /**
     * Só o que compõe um identificador. É o que impede `../` de escapar da
     * pasta do vendedor — o mesmo cuidado que falta em `JsonStorageService`.
     */
    private static function sanitize(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '', $value) ?? '';
    }

    private static function sanitizeFilename(string $value): string
    {
        $name = basename($value);
        return preg_match('/^[A-Za-z0-9._-]{1,120}$/', $name) === 1 ? $name : '';
    }

    private static function sanitizeOrigin(string $value): string
    {
        return isset(self::KEEP[$value]) ? $value : 'manual';
    }
}
