<?php

namespace HubApp\Services;

/**
 * Quadros de espelhamento, de passagem.
 *
 * Um quadro **não é um backup**: ele existe entre o aparelho capturar e o
 * painel buscar, e não deve sobreviver a isso. Por isso a leitura apaga o
 * arquivo e a escrita varre o que ficou para trás. Acumular aqui seria criar
 * um repositório de telas de vendedores — com dados de clientes dentro — que
 * ninguém pediu e ninguém vigia.
 */
class MirrorFrameStorageService
{
    /** Teto por quadro. O aparelho já se limita bem abaixo disto. */
    private const MAX_BYTES = 2097152;

    /**
     * Prazo de um quadro abandonado. O painel busca em segundos; o que passar
     * disto é transferência interrompida.
     */
    private const TTL_SECONDS = 300;

    /**
     * Grava o quadro e devolve `null`, ou a mensagem de erro.
     */
    public static function store(
        string $cnpj,
        string $sellerId,
        string $sessionId,
        string $seq,
        string $bytes
    ): ?string {
        if ($bytes === '') {
            return 'Quadro vazio';
        }

        if (strlen($bytes) > self::MAX_BYTES) {
            return 'Quadro acima do limite';
        }

        // Assinatura do PNG. O que entra aqui é servido de volta como imagem,
        // então só imagem entra.
        if (substr($bytes, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            return 'O conteúdo não é um PNG';
        }

        $path = self::pathOf($cnpj, $sellerId, $sessionId, $seq);
        if ($path === null) {
            return 'Caminho inválido';
        }

        $dir = dirname($path);

        // Antes de criar, não depois: a pasta desta sessão nasce vazia, e a
        // varredura apagaria justamente ela.
        self::prune(dirname($dir));

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return 'Não foi possível criar a pasta do quadro';
        }

        return file_put_contents($path, $bytes) === false
            ? 'Não foi possível gravar o quadro'
            : null;
    }

    /**
     * Devolve os bytes **e apaga o arquivo**.
     *
     * A leitura é destrutiva de propósito: o painel lê uma vez e pede o
     * próximo. Um segundo GET do mesmo quadro é 404, que é a resposta certa —
     * diz que o laço pediu duas vezes, em vez de devolver uma imagem velha.
     */
    public static function take(
        string $cnpj,
        string $sellerId,
        string $sessionId,
        string $seq
    ): ?string {
        $path = self::pathOf($cnpj, $sellerId, $sessionId, $seq);
        if ($path === null || !is_file($path)) {
            return null;
        }

        $bytes = file_get_contents($path);
        @unlink($path);

        return $bytes === false ? null : $bytes;
    }

    /**
     * `null` quando algum segmento não sobrevive à sanitização.
     *
     * Os segmentos vêm da rede, do mesmo aparelho cuja tela está sendo lida.
     * O `seq` entra como nome de arquivo, então só dígitos.
     */
    private static function pathOf(
        string $cnpj,
        string $sellerId,
        string $sessionId,
        string $seq
    ): ?string {
        $cnpj = self::sanitize($cnpj);
        $sellerId = self::sanitize($sellerId);
        $sessionId = self::sanitize($sessionId);

        if ($cnpj === '' || $sellerId === '' || $sessionId === '') {
            return null;
        }

        if (preg_match('/^\d{1,9}$/', $seq) !== 1) {
            return null;
        }

        return self::root() . "/{$cnpj}/{$sellerId}/{$sessionId}/{$seq}.png";
    }

    /** Varre sessões abandonadas na pasta do vendedor. */
    private static function prune(string $sellerDir): void
    {
        if (!is_dir($sellerDir)) {
            return;
        }

        $deadline = time() - self::TTL_SECONDS;

        foreach (glob($sellerDir . '/*', GLOB_ONLYDIR) ?: [] as $session) {
            $empty = true;

            foreach (glob($session . '/*.png') ?: [] as $frame) {
                if (filemtime($frame) < $deadline) {
                    @unlink($frame);
                    continue;
                }
                $empty = false;
            }

            if ($empty) {
                @rmdir($session);
            }
        }
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2) . '/storage/mirror';
    }

    /** O mesmo saneamento dos backups: só o que compõe um identificador. */
    private static function sanitize(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '', $value) ?? '';
    }
}
