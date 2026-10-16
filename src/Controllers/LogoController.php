<?php

namespace HubApp\Controllers;

use HubApp\Helpers\RequestHelper;
use HubApp\Helpers\ResponseHelper;
use HubApp\Services\LogoService;

class LogoController
{
    /**
     * GET /public/logos/{cnpj}
     *
     * O app baixa a logo a cada sincronização mandando o ETag que já tem;
     * quando nada mudou, responde 304 sem corpo para poupar o plano de dados
     * do vendedor.
     */
    public function serve(string $cnpj): void
    {
        try {
            $logo = LogoService::find($cnpj);

            if ($logo === null) {
                ResponseHelper::notFound('Logo não encontrada');
                return;
            }

            $path = $logo['path'];
            $etag = LogoService::etag($path);

            header('ETag: ' . $etag);
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($path)) . ' GMT');
            // no-cache: pode guardar, mas revalida sempre — a logo troca sem aviso.
            header('Cache-Control: no-cache');

            if (self::matchesEtag(RequestHelper::getHeader('If-None-Match'), $etag)) {
                http_response_code(304);
                header_remove('Content-Type');
                return;
            }

            header('Content-Type: ' . $logo['mime']);
            header('Content-Length: ' . filesize($path));
            readfile($path);
        } catch (\Throwable $e) {
            ResponseHelper::error('Erro interno ao servir a logo', 500);
        }
    }

    /**
     * Comparação fraca do RFC 9110: aceita `*`, lista separada por vírgula e
     * o prefixo `W/` que proxies colocam ao reescrever o ETag.
     */
    private static function matchesEtag(?string $header, string $etag): bool
    {
        if ($header === null || trim($header) === '') {
            return false;
        }
        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '*') {
                return true;
            }
            if (stripos($candidate, 'W/') === 0) {
                $candidate = substr($candidate, 2);
            }
            if ($candidate === $etag) {
                return true;
            }
        }
        return false;
    }
}
