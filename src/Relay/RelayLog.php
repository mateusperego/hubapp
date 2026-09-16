<?php

namespace HubApp\Relay;

/**
 * Log do relay, no mesmo padrão dos logs de DANFE e imagem:
 * um arquivo por dia em storage/logs/.
 *
 * Escreve também no stdout quando o processo está em primeiro plano, que é como
 * o relay roda durante o diagnóstico (`php bin/relay.php start`, sem -d).
 *
 * O descritor fica **aberto** entre as linhas, e isso não é economia de
 * microssegundo: este log é escrito de dentro do event loop, inclusive no
 * caminho de mídia. Abrir, procurar o fim e fechar o arquivo a cada linha —
 * mais um `is_dir` por cima — é I/O bloqueante no meio do repasse de vídeo, e
 * aparece como jitter de FPS justamente quando há congestionamento e o log tem
 * mais a dizer. Quem limita a *quantidade* de linhas é quem chama; o que este
 * arquivo garante é que cada linha custe o mínimo.
 */
class RelayLog
{
    private static bool $echoToStdout = true;

    /** @var resource|null */
    private static $handle = null;

    /** A data do arquivo que está aberto, para virar na meia-noite. */
    private static ?string $openFor = null;

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
        $now  = time();
        $line = sprintf('[%s] %s: %s', date('Y-m-d H:i:s', $now), $level, $message);

        if (self::$echoToStdout) {
            echo $line, PHP_EOL;
        }

        $handle = self::handleFor(date('Y-m-d', $now));

        if ($handle !== null) {
            fwrite($handle, $line . PHP_EOL);
        }
    }

    /** @return resource|null */
    private static function handleFor(string $day)
    {
        if (self::$openFor === $day && is_resource(self::$handle)) {
            return self::$handle;
        }

        if (is_resource(self::$handle)) {
            fclose(self::$handle);
            self::$handle = null;
        }

        $directory = dirname(__DIR__, 2) . '/storage/logs';

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        // O modo 'a' já posiciona no fim a cada escrita, que é o que torna
        // seguro manter o descritor aberto.
        $handle = @fopen($directory . '/relay_' . $day . '.log', 'a');

        if ($handle === false) {
            // Sem log em disco o relay continua atendendo: perder a linha é
            // ruim, derrubar o espelhamento por causa dela é pior.
            self::$openFor = null;

            return null;
        }

        self::$handle  = $handle;
        self::$openFor = $day;

        return $handle;
    }
}
