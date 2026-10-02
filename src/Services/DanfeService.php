<?php

namespace HubApp\Services;

use NFePHP\DA\NFe\Danfe;
use DOMDocument;
use DOMXPath;
use Exception;
use Throwable;

/**
 * Geração incremental das DANFEs.
 *
 * O cliente envia só as notas que ainda não subiram e trata qualquer 2xx como
 * gravação durável: depois disso ele nunca mais manda aquelas notas. Por isso o
 * lote é tudo-ou-nada (valida e renderiza tudo antes de gravar) e nada que não
 * veio no lote é apagado.
 *
 * Layout em storage/pdf/{apelido}/{moduleName}/{clifor}/:
 *   notas/{chNFe}.pdf + .json  — uma por nota, sobrescrita no reenvio
 *   {YYYY-MM}.pdf + .json      — nota mais recente do mês (por dhEmi), servida
 *                                pela rota GET /pdf/{clifor}/{YYYY-MM}
 */
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
        if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
            throw new Exception("Não foi possível criar o diretório: $path");
        }
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

    /**
     * @return array{status: int, body: array}
     */
    public static function gerarPdfs(string $apelido, string $moduleName, array $registros): array
    {
        $contexto = ['apelido' => $apelido, 'moduleName' => $moduleName, 'qtd' => count($registros)];

        // 1. Validação: nenhum item inválido pode ser descartado em silêncio.
        $notas = [];
        $invalidos = [];
        foreach ($registros as $indice => $registro) {
            try {
                $notas[] = self::validarRegistro($registro);
            } catch (Exception $e) {
                $invalidos[] = [
                    'indice' => $indice,
                    'CLIFOR' => is_array($registro) ? ($registro['CLIFOR'] ?? null) : null,
                    'erro' => $e->getMessage(),
                ];
            }
        }

        if (!empty($invalidos)) {
            self::logError('gerarPdfs', 'Lote rejeitado: itens inválidos', $contexto + ['itens' => $invalidos]);
            return self::falha(422, 'Lote rejeitado: itens inválidos. Nada foi gravado.', ['itens' => $invalidos]);
        }

        // 2. Renderização em memória: logo ausente ou XML que não renderiza
        //    derrubam o lote antes de qualquer escrita.
        set_time_limit(300);
        foreach ($notas as $i => $nota) {
            try {
                $danfe = new Danfe($nota['xml']);
                self::configurarDanfe($danfe, $apelido);
                $notas[$i]['pdf'] = $danfe->render();
            } catch (Throwable $e) {
                self::logError('gerarPdfs', 'Falha ao renderizar: ' . $e->getMessage(), $contexto + [
                    'clifor' => $nota['clifor'],
                    'chNFe' => $nota['chNFe'],
                ]);
                return self::falha(500, 'Falha ao gerar a DANFE. Nada foi gravado.', ['itens' => [[
                    'indice' => $i,
                    'CLIFOR' => $nota['clifor'],
                    'chNFe' => $nota['chNFe'],
                    'erro' => $e->getMessage(),
                ]]]);
            }
        }

        // 3. Gravação. Se falhar no meio, o que já foi escrito é idêntico ao que
        //    o reenvio do lote escreveria de novo.
        $gravadas = [];
        foreach ($notas as $nota) {
            try {
                self::gravarNota($apelido, $moduleName, $nota);
            } catch (Throwable $e) {
                self::logError('gerarPdfs', 'Falha ao gravar: ' . $e->getMessage(), $contexto + [
                    'clifor' => $nota['clifor'],
                    'chNFe' => $nota['chNFe'],
                ]);
                return self::falha(500, 'Falha ao gravar a DANFE: ' . $e->getMessage());
            }

            $gravadas[] = ['chNFe' => $nota['chNFe'], 'CLIFOR' => $nota['clifor'], 'anoMes' => $nota['anoMes']];
        }

        return [
            'status' => 200,
            'body' => ['SUCCESS' => true, 'arquivos_gerados' => count($gravadas), 'notas' => $gravadas],
        ];
    }

    /**
     * Extrai do registro e do XML tudo o que a gravação precisa.
     */
    private static function validarRegistro($registro): array
    {
        if (!is_array($registro)) {
            throw new Exception('Item não é um objeto');
        }

        $clifor = isset($registro['CLIFOR']) && is_scalar($registro['CLIFOR']) ? (string) $registro['CLIFOR'] : '';
        if ($clifor === '' || !ctype_digit($clifor)) {
            throw new Exception('CLIFOR ausente ou não numérico');
        }

        $xml = $registro['XML_RETORNO'] ?? null;
        if (!is_string($xml) || trim($xml) === '') {
            throw new Exception('XML_RETORNO ausente');
        }

        $dom = new DOMDocument();
        $anterior = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);
        if (!$ok) {
            throw new Exception('XML_RETORNO não é um XML válido');
        }

        $xpath = new DOMXPath($dom);
        $texto = function (string $local) use ($xpath): string {
            $nos = $xpath->query("//*[local-name()='{$local}']");
            return $nos->length > 0 ? trim($nos->item(0)->textContent) : '';
        };

        $chNFe = $texto('chNFe');
        if ($chNFe === '') {
            $infNFe = $xpath->query("//*[local-name()='infNFe']");
            if ($infNFe->length > 0) {
                $chNFe = preg_replace('/^NFe/', '', $infNFe->item(0)->getAttribute('Id'));
            }
        }
        if (!preg_match('/^\d{44}$/', $chNFe)) {
            throw new Exception('Chave de acesso (chNFe) de 44 dígitos não encontrada no XML');
        }

        $dhEmi = $texto('dhEmi') ?: $texto('dEmi');

        $dataMov = $registro['DATA_MOV'] ?? null;
        if ($dataMov !== null && $dataMov !== '') {
            if (!is_string($dataMov) || !preg_match('/^(\d{4}-\d{2})/', $dataMov, $m)) {
                throw new Exception('DATA_MOV fora do formato YYYY-MM-DD');
            }
            $anoMes = $m[1];
        } elseif (preg_match('/^(\d{4}-\d{2})/', $dhEmi, $m)) {
            $anoMes = $m[1];
        } else {
            throw new Exception('Sem DATA_MOV e sem data de emissão (dhEmi) no XML');
        }

        return [
            'clifor' => $clifor,
            'chNFe' => $chNFe,
            'nProt' => $texto('nProt'),
            'dhRecbto' => $texto('dhRecbto'),
            'dhEmi' => $dhEmi,
            'anoMes' => $anoMes,
            'xml' => $xml,
        ];
    }

    /**
     * Upsert da nota e, se ela for a mais recente do mês, do PDF do mês.
     */
    private static function gravarNota(string $apelido, string $moduleName, array $nota): void
    {
        $cliforPath = self::getBasePath($apelido, $moduleName, $nota['clifor']);
        $notasPath = $cliforPath . 'notas/';
        self::ensureDirectoryExists($notasPath);

        $meta = [
            'chNFe' => $nota['chNFe'],
            'nProt' => $nota['nProt'],
            'dhRecbto' => $nota['dhRecbto'],
            'dhEmi' => $nota['dhEmi'],
            'anoMes' => $nota['anoMes'],
            'recebido_em' => date('c'),
        ];

        self::writeAtomic($notasPath . $nota['chNFe'] . '.pdf', $nota['pdf']);
        self::writeAtomic($notasPath . $nota['chNFe'] . '.json', json_encode($meta));

        // Dois lotes do mesmo cliente podem chegar juntos: a decisão sobre o
        // PDF do mês precisa ser serializada por produtor.
        $lock = fopen($cliforPath . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new Exception('Não foi possível obter o lock do produtor ' . $nota['clifor']);
        }

        try {
            $mesMetaPath = $cliforPath . $nota['anoMes'] . '.json';
            $atual = is_file($mesMetaPath) ? json_decode((string) file_get_contents($mesMetaPath), true) : null;

            if (!is_array($atual) || self::compararNotas($nota, $atual) >= 0) {
                self::writeAtomic($cliforPath . $nota['anoMes'] . '.pdf', $nota['pdf']);
                self::writeAtomic($mesMetaPath, json_encode(['chNFe' => $nota['chNFe'], 'dhEmi' => $nota['dhEmi']]));
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Ordena por (dhEmi, chNFe): o resultado não depende da ordem de chegada.
     * Mesma chave compara igual, então a reemissão sobrescreve.
     */
    private static function compararNotas(array $a, array $b): int
    {
        $ta = strtotime((string) ($a['dhEmi'] ?? '')) ?: 0;
        $tb = strtotime((string) ($b['dhEmi'] ?? '')) ?: 0;

        return [$ta, (string) $a['chNFe']] <=> [$tb, (string) ($b['chNFe'] ?? '')];
    }

    private static function writeAtomic(string $path, string $content): void
    {
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (file_put_contents($tmp, $content) !== strlen($content)) {
            @unlink($tmp);
            throw new Exception("Falha ao escrever $path");
        }

        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new Exception("Falha ao mover o arquivo para $path");
        }
    }

    private static function falha(int $status, string $erro, array $extra = []): array
    {
        return ['status' => $status, 'body' => ['SUCCESS' => false, 'erro' => $erro] + $extra];
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
