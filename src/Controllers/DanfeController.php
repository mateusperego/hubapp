<?php

namespace HubApp\Controllers;

use HubApp\Services\DanfeService;
use HubApp\Helpers\RequestHelper;
use HubApp\Helpers\ResponseHelper;

class DanfeController
{
    public static function gerarPdfs(string $apelido, string $moduleName): void
    {
        // Ambos viram caminho em storage/: só barra o que poderia escapar dele,
        // sem impor um tamanho de apelido que algum cliente pode não seguir.
        if (!preg_match('/^\d+$/', $apelido) || !preg_match('/^[A-Za-z0-9_-]+$/', $moduleName)) {
            ResponseHelper::json(['SUCCESS' => false, 'erro' => 'apelido ou módulo inválido'], 400);
            return;
        }

        // Body inválido não pode virar lote vazio: o cliente marcaria as notas
        // como enviadas sem que nada tivesse sido gravado.
        $registros = RequestHelper::getJsonInputOrNull();
        if ($registros === null || ($registros !== [] && array_keys($registros) !== range(0, count($registros) - 1))) {
            ResponseHelper::json(['SUCCESS' => false, 'erro' => 'O corpo deve ser um array JSON de notas'], 400);
            return;
        }

        $result = DanfeService::gerarPdfs($apelido, $moduleName, $registros);

        ResponseHelper::json($result['body'], $result['status']);
    }

    public static function downloadPdf(string $apelido, string $moduleName, string $clifor, string $dataMov): void
    {
        $pdfPath = __DIR__ . "/../../storage/pdf/{$apelido}/{$moduleName}/{$clifor}/{$dataMov}.pdf";

        if (!file_exists($pdfPath)) {
            ResponseHelper::notFound('PDF não encontrado');
            return;
        }

        $content = file_get_contents($pdfPath);
        ResponseHelper::pdfDownload($content, "{$clifor}_{$dataMov}.pdf");
    }
}