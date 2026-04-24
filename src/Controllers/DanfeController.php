<?php

namespace HubApp\Controllers;

use HubApp\Services\DanfeService;
use HubApp\Helpers\RequestHelper;
use HubApp\Helpers\ResponseHelper;

class DanfeController
{
    public static function gerarPdfs(string $apelido, string $moduleName): void
    {
        $registros = RequestHelper::getJsonInput();

        $result = DanfeService::gerarPdfs($apelido, $moduleName, $registros);

        ResponseHelper::json($result);
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