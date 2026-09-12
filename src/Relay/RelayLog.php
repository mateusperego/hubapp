<?php

namespace HubApp\Relay;

/**
 * Log do relay, no mesmo padrão dos logs de DANFE e imagem:
 * um arquivo por dia em storage/logs/.
 *
 * Escreve também no stdout quando o processo está em primeiro plano, que é como
 * o relay roda durante o diagnóstico (`php bin/relay.php start`, sem -d).
 */
class RelayLog
{
    private static bool $echoToStdout = true;

    public static function silenceStdout(): void
    {
        self::$echoToStdout = false;
    }

    public static function info(string $message): void
    {
        self::write('INFO', $message);
    }

    public static function warning(string $message): void
    {
        self::write('WARN', $message);
    }

    public static function error(string $message): void
    {
        self::write('ERRO', $message);
    }

    private static function write(string $level, string $message): void
    {
        $line = sprintf('[%s] %s: %s', date('Y-m-d H:i:s'), $level, $message);

        if (self::$echoToStdout) {
            echo $line, PHP_EOL;
        }

        $directory = dirname(__DIR__, 2) . '/storage/logs';

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents(
            $directory . '/relay_' . date('Y-m-d') . '.log',
            $line . PHP_EOL,
            FILE_APPEND
        );
    }
}
