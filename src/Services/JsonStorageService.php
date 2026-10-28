<?php

namespace HubApp\Services;

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

    /**
     * Nome que pode virar arquivo ou pasta tanto no Linux da produção quanto
     * no Windows do WAMP: sem separador, sem caractere proibido no NTFS, sem
     * nome reservado do Windows e sem começar com ponto (o que barra . e ..).
     */
    public static function nomeDeArquivoValido(string $nome): bool
    {
        return $nome !== ''
            && strlen($nome) <= 200
            && $nome[0] !== '.'
            && !preg_match('/[\x00-\x1F<>:"\/\\\\|?*]/', $nome)
            && !preg_match('/[. ]$/', $nome)
            && !preg_match('/^(CON|PRN|AUX|NUL|COM\d|LPT\d)(\..*)?$/i', $nome);
    }

    /**
     * Registros que o setJson descartaria em silêncio ou que quebrariam a
     * gravação: o valor do campo-chave vira nome de arquivo.
     *
     * @return array<int, array{indice: int, erro: string}>
     */
    public static function validarRegistros(array $registros, string $campoChave): array
    {
        $invalidos = [];

        foreach ($registros as $indice => $registro) {
            if (!is_array($registro) || ($registro !== [] && array_keys($registro) === range(0, count($registro) - 1))) {
                $invalidos[] = ['indice' => $indice, 'erro' => 'Registro não é um objeto'];
                continue;
            }

            $chave = $registro[$campoChave] ?? null;
            if (is_float($chave)) {
                // json_decode vira float o número com decimal ou maior que um inteiro do PHP.
                $invalidos[] = ['indice' => $indice, 'erro' => "{$campoChave} numérico com decimal ou grande demais: envie como texto"];
                continue;
            }
            if (!is_int($chave) && !is_string($chave)) {
                $invalidos[] = ['indice' => $indice, 'erro' => "{$campoChave} ausente ou não é texto/número"];
                continue;
            }

            if (!self::nomeDeArquivoValido((string) $chave)) {
                $invalidos[] = ['indice' => $indice, 'erro' => "{$campoChave} inválido para nome de arquivo: " . $chave];
            }
        }

        return $invalidos;
    }

    /**
     * Grava um arquivo por valor do campo-chave, substituindo o anterior.
     * Lança exceção se alguma gravação falhar: o cliente só pode dar o lote
     * como enviado quando tudo foi gravado.
     */
    public static function setJson(string $apelido, string $moduleName, array $registros, string $campoChave): int
    {
        $basePath = self::getBasePath($apelido, $moduleName);
        if (!is_dir($basePath) && !@mkdir($basePath, 0777, true) && !is_dir($basePath)) {
            throw new \RuntimeException("Não foi possível criar a pasta de {$apelido}/{$moduleName}");
        }

        $gruposPorChave = [];
        foreach ($registros as $registro) {
            $gruposPorChave[(string) $registro[$campoChave]][] = $registro;
        }

        foreach ($gruposPorChave as $chave => $dados) {
            $json = json_encode($dados);
            if ($json === false) {
                throw new \RuntimeException("Falha ao serializar {$chave}.json: " . json_last_error_msg());
            }

            // Grava ao lado e troca de uma vez: uma falha no meio não apaga o
            // arquivo anterior, como fazia o unlink antes da escrita.
            $filePath = $basePath . $chave . '.json';
            $tmp = $filePath . '.' . bin2hex(random_bytes(4)) . '.tmp';

            if (@file_put_contents($tmp, $json) !== strlen($json) || !@rename($tmp, $filePath)) {
                @unlink($tmp);
                throw new \RuntimeException("Falha ao gravar {$chave}.json");
            }
        }

        return count($gruposPorChave);
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
