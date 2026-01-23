<?php

namespace Agroprodutor\Helpers;

class ResponseHelper
{
    public static function pdf(string $content): void
    {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="danfe.pdf"');
        header('Content-Length: ' . strlen($content));

        echo $content;
    }

    public static function json($data): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
    }

    public static function text(string $content): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        echo $content;
    }

    public static function pdfDownload(string $content, string $filename): void
    {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($content));

        echo $content;
    }

    public static function notFound(string $message = 'Não encontrado'): void
    {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $message]);
    }

    public static function error(string $message, int $statusCode = 500): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => $message]);
    }
}