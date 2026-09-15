<?php

namespace HubApp\Controllers;

use HubApp\Helpers\RequestHelper;
use HubApp\Helpers\ResponseHelper;
use HubApp\Services\MirrorFrameStorageService;

/**
 * Quadros de espelhamento entre o aparelho e o painel.
 *
 * Por que isto existe fora do relay: uma conexão de painel carrega **todos**
 * os vendedores, e o Workerman descarta o *próximo* pacote quando o buffer de
 * envio de 1 MiB enche. Um quadro trafegando por lá arriscaria, em silêncio,
 * a resposta de outro vendedor — e possivelmente o `pong` que mantém o painel
 * inteiro no ar. É a mesma divisão que os backups já fazem: comandos no relay,
 * bytes no HTTP.
 *
 * Autenticado com o mesmo `RELAY_TOKEN`, como os backups.
 */
class MirrorController
{
    public function upload(
        string $cnpj,
        string $sellerId,
        string $sessionId,
        string $seq
    ): void {
        if (!self::authorized()) {
            return;
        }

        // Corpo cru, não multipart: o quadro é um PNG só, e o boundary do
        // multipart custaria banda do plano de dados do vendedor a cada
        // captura para não resolver nada.
        $bytes = file_get_contents('php://input');
        if ($bytes === false) {
            ResponseHelper::error('Corpo da requisição ilegível', 400);
            return;
        }

        $error = MirrorFrameStorageService::store(
            $cnpj,
            $sellerId,
            $sessionId,
            $seq,
            $bytes
        );

        if ($error !== null) {
            ResponseHelper::error($error, 400);
            return;
        }

        ResponseHelper::json(['success' => true]);
    }

    public function download(
        string $cnpj,
        string $sellerId,
        string $sessionId,
        string $seq
    ): void {
        if (!self::authorized()) {
            return;
        }

        $bytes = MirrorFrameStorageService::take($cnpj, $sellerId, $sessionId, $seq);
        if ($bytes === null) {
            // Também é o que responde a um segundo GET do mesmo quadro: a
            // leitura apaga. Melhor 404 do que devolver uma tela velha.
            ResponseHelper::error('Quadro não encontrado', 404);
            return;
        }

        header('Content-Type: image/png');
        header('Content-Length: ' . strlen($bytes));
        // O quadro é a tela de um vendedor, com dados de clientes dentro.
        // Nenhum proxy no caminho tem por que guardá-lo.
        header('Cache-Control: no-store, no-cache, must-revalidate');
        echo $bytes;
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
