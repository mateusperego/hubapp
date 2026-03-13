<?php

namespace Agroprodutor\Services;

use SplFileInfo;

class JsonStorageService
{
    public static function getBasePath(string $apelido, string $moduleName): string
    {
        return __DIR__ . "/../../storage/json/{$apelido}/{$moduleName}/";
    }

    public static function ensureDirectoryExists(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
    }

    public static function setJson(string $apelido, string $moduleName, array $registros, string $campoChave): int
    {
        $basePath = self::getBasePath($apelido, $moduleName);
        self::ensureDirectoryExists($basePath);

        $gruposPorChave = [];
        foreach ($registros as $registro) {
            if (isset($registro[$campoChave])) {
                $chave = $registro[$campoChave];
                if (!isset($gruposPorChave[$chave])) {
                    $gruposPorChave[$chave] = [];
                }
                $gruposPorChave[$chave][] = $registro;
            }
        }

        $arquivosCriados = 0;
        foreach ($gruposPorChave as $chave => $dados) {
            $filePath = $basePath . $chave . '.json';

            if (file_exists($filePath)) {
                unlink($filePath);
            }

            $result = file_put_contents($filePath, json_encode($dados));
            if ($result !== false) {
                $arquivosCriados++;
            }
        }

        return $arquivosCriados;
    }

    public static function getJson(string $apelido, string $moduleName, string $jsonName = ''): array
    {
        $basePath = self::getBasePath($apelido, $moduleName);
        $return = [];

        if (empty($jsonName)) {
            $itens = glob($basePath . '*.json');
            if ($itens !== false && count($itens) > 0) {
                foreach ($itens as $item) {
                    $info = new SplFileInfo($item);
                    $return[] = ['nome' => $info->getBasename()];
                }
            }
        } else {
            $filePath = $basePath . $jsonName . '.json';
            if (file_exists($filePath)) {
                $conteudo = file_get_contents($filePath);

                if (strtolower($jsonName) === 'totais') {
                    $info = new SplFileInfo($filePath);
                    $modTime = $info->getMTime();
                    $dataFormatada = date('Y-m-d H:i:s', $modTime);

                    $return = [
                        'dados' => json_decode($conteudo, true),
                        'data' => $dataFormatada
                    ];
                } else {
                    $return = json_decode($conteudo, true) ?? [];
                }
            }
        }

        return $return;
    }

    public static function register(string $apelido, string $moduleName, array $registro): array
    {
        $basePath = self::getBasePath($apelido, $moduleName);
        self::ensureDirectoryExists($basePath);

        if (!isset($registro['CNPJCPF'])) {
            return ['success' => false, 'error' => 'CPF_NAO_INFORMADO'];
        }

        $cnpjcpf = $registro['CNPJCPF'];
        $filePath = $basePath . $cnpjcpf . '.json';

        if (file_exists($filePath)) {
            return ['success' => false, 'error' => 'CPF_JA_CADASTRADO'];
        }

        $result = file_put_contents($filePath, json_encode($registro));

        if ($result === false) {
            return ['success' => false, 'error' => 'ERRO_AO_SALVAR'];
        }

        return ['success' => true];
    }

    public static function validateCredentials(string $apelido, string $moduleName, string $cnpjcpf, string $password): bool
    {
        $basePath = self::getBasePath($apelido, $moduleName);
        $filePath = $basePath . $cnpjcpf . '.json';

        if (!file_exists($filePath)) {
            return false;
        }

        $conteudo = file_get_contents($filePath);
        $registro = json_decode($conteudo, true);

        if (is_array($registro) && isset($registro['PASSWORD']) && $registro['PASSWORD'] === $password) {
            return true;
        }

        return false;
    }

    public static function listCpf(string $apelido, string $moduleName): array
    {
        $basePath = self::getBasePath($apelido, $moduleName);
        $cpfs = [];

        $arquivos = glob($basePath . '*.json');
        if ($arquivos !== false) {
            foreach ($arquivos as $arquivo) {
                $cpfs[] = pathinfo($arquivo, PATHINFO_FILENAME);
            }
        }

        return $cpfs;
    }

    public static function listAuth(string $apelido): array
    {
        $basePath = self::getBasePath($apelido, 'auth');
        $result = [];

        $arquivos = glob($basePath . '*.json');
        if ($arquivos !== false) {
            foreach ($arquivos as $arquivo) {
                $conteudo = file_get_contents($arquivo);
                $dados = json_decode($conteudo, true);
                if ($dados !== null) {
                    $result[] = $dados;
                }
            }
        }

        return $result;
    }

    public static function deleteAuth(string $apelido, string $cnpjcpf): array
    {
        $basePath = self::getBasePath($apelido, 'auth');
        $filePath = $basePath . $cnpjcpf . '.json';

        if (!file_exists($filePath)) {
            return ['success' => false, 'error' => 'REGISTRO_NAO_ENCONTRADO'];
        }

        if (unlink($filePath)) {
            return ['success' => true];
        }

        return ['success' => false, 'error' => 'ERRO_AO_EXCLUIR'];
    }
}
