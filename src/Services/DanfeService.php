<?php

namespace Agroprodutor\Services;

use NFePHP\DA\NFe\Danfe;
use Exception;

class DanfeService
{
    public static function getBasePath(string $apelido, string $moduleName, ?string $clifor = null): string
    {
        $path = __DIR__ . "/../../storage/pdf/{$apelido}/{$moduleName}/";
        if ($clifor !== null) {
            $path .= "{$clifor}/";
        }
        return $path;
    }

    public static function ensureDirectoryExists(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
    }

    private static function deleteDirectory(string $path): bool
    {
        if (!is_dir($path)) {
            return true;
        }

        $items = array_diff(scandir($path), ['.', '..']);

        foreach ($items as $item) {
            $itemPath = $path . DIRECTORY_SEPARATOR . $item;

            if (is_dir($itemPath)) {
                self::deleteDirectory($itemPath);
            } else {
                unlink($itemPath);
            }
        }

        return rmdir($path);
    }

    public static function configurarDanfe(Danfe $danfe, string $cnpj): void
    {
        $danfe->descProdInfoComplemento = false;
        $danfe->setOcultarUnidadeTributavel(true);
        $danfe->obsContShow(false);
        $danfe->printParameters('P', 'A4', 2, 2);
        $danfe->setDefaultFont('times');
        $danfe->setDefaultDecimalPlaces(4);
        $danfe->debugMode(false);
        $danfe->creditsIntegratorFooter('EL Sistemas - https://www.elsistemas.com.br/');

        $logo = __DIR__ . '/../../storage/logos/' . $cnpj . '/logo.jpg';

        if (!file_exists($logo)) {
            throw new Exception("Logo não encontrada: $logo");
        }       

        $danfe->logoParameters($logo, 'L', false);
    }

    public static function gerarPdfs(string $apelido, string $moduleName, array $registros): array
    {
        try {
            $basePath = self::getBasePath($apelido, $moduleName);
            self::deleteDirectory($basePath);
            self::ensureDirectoryExists($basePath);

            if (empty($registros)) {
                return ['SUCCESS' => false, 'erro' => 'Nenhum registro enviado'];
            }

            $arquivosGerados = 0;
            $erros = [];

            foreach ($registros as $registro) {
                if (!isset($registro['CLIFOR']) || !isset($registro['XML_RETORNO']) || !isset($registro['DATA_MOV'])) {
                    continue;
                }

                $clifor = $registro['CLIFOR'];
                $dataMov = $registro['DATA_MOV'];
                $xml = $registro['XML_RETORNO'];

                try {
                    $danfe = new Danfe($xml);
                    self::configurarDanfe($danfe, $apelido);

                    $pdf = $danfe->render();

                    $cliforPath = self::getBasePath($apelido, $moduleName, $clifor);
                    self::ensureDirectoryExists($cliforPath);

                    // Extrai apenas ano e mês do DATA_MOV (formato esperado: YYYY-MM-DD)
                    $anoMes = substr($dataMov, 0, 7); // Resultado: YYYY-MM
                    $filePath = $cliforPath . $anoMes . '.pdf';

                    if (file_put_contents($filePath, $pdf) !== false) {
                        $arquivosGerados++;
                    }
                } catch (Exception $e) {
                    self::logError('gerarPdfs', $e->getMessage(), [
                        'apelido' => $apelido,
                        'moduleName' => $moduleName,
                        'clifor' => $clifor,
                        'dataMov' => $dataMov,
                    ]);
                    $erros[] = "CLIFOR $clifor (DATA_MOV $dataMov): " . $e->getMessage();
                }
            }

            $response = ['SUCCESS' => true, 'arquivos_gerados' => $arquivosGerados];
            if (!empty($erros)) {
                $response['erros'] = $erros;
            }

            return $response;

        } catch (Exception $e) {
            self::logError('gerarPdfs', $e->getMessage(), [
                'apelido' => $apelido,
                'moduleName' => $moduleName,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return [
                'SUCCESS' => false,
                'erro' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ];
        }
    }

    /**
     * Registra erros no log
     */
    private static function logError(string $method, string $message, array $context = []): void
    {
        $logDir = __DIR__ . '/../../storage/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }

        $logFile = $logDir . '/danfe_' . date('Y-m-d') . '.log';
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = json_encode($context);

        $logMessage = "[{$timestamp}] [{$method}] {$message} | Context: {$contextStr}\n";

        file_put_contents($logFile, $logMessage, FILE_APPEND);
    }
}
