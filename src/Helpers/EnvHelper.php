<?php

namespace HubApp\Helpers;

use RuntimeException;

/**
 * Leitura de variáveis de ambiente.
 *
 * Em desenvolvimento os valores vêm do .env; em produção (Jelastic) vêm das
 * Variables do nó, que o Apache exporta para o processo. Ler só $_ENV não cobre
 * o segundo caso: o variables_order padrão do PHP é "GPCS", sem o "E" que
 * popularia $_ENV com o ambiente do sistema. Por isso consultamos getenv()
 * primeiro e só então os superglobais.
 */
class EnvHelper
{
    public static function get(string $name, ?string $default = null): ?string
    {
        foreach ([getenv($name), $_ENV[$name] ?? false, $_SERVER[$name] ?? false] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return $default;
    }

    /** Aborta quando a variável não está definida, em vez de seguir com string vazia. */
    public static function required(string $name): string
    {
        $value = self::get($name);

        if ($value === null) {
            throw new RuntimeException(
                "Variável de ambiente {$name} não definida. "
                . 'Em produção, cadastre-a nas Variables do nó no Jelastic; '
                . 'localmente, no .env (ver .env.example).'
            );
        }

        return $value;
    }
}
