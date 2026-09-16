<?php

/**
 * Relay de suporte remoto.
 *
 *   php bin/relay.php start      primeiro plano, log no terminal
 *   php bin/relay.php start -d   daemon
 *   php bin/relay.php status
 *   php bin/relay.php stop
 *
 * Roda como processo separado, fora do Apache: o modelo request/response do
 * mod_php não sustenta conexão longa. Contrato em docs/relay-protocol.md.
 */

require __DIR__ . '/../src/bootstrap.php';

use HubApp\Helpers\EnvHelper;
use HubApp\Relay\Handshake;
use HubApp\Relay\RelayLog;
use HubApp\Relay\RelayServer;

$token = (string) EnvHelper::get('RELAY_TOKEN', '');

if ($token === '') {
    fwrite(STDERR, "RELAY_TOKEN não definido no ambiente nem no .env. O relay não sobe sem token." . PHP_EOL);
    exit(1);
}

$port = (int) EnvHelper::get('RELAY_PORT', '8443');

/*
 * Só o proxy do Apache precisa alcançar o relay: ele publica `wss://<domínio>/relay`
 * apontando para `ws://127.0.0.1:8443`, e a porta não é exposta pelo container.
 * Escutar em 0.0.0.0 tornava a porta alcançável por qualquer coisa que chegasse
 * à interface — protegida apenas por a plataforma não a expor, o que é
 * configuração de outra pessoa, não uma garantia deste processo.
 *
 * Se algum dia o relay rodar em um nó diferente do Apache, é aqui que se abre.
 */
$bind = (string) EnvHelper::get('RELAY_BIND', '127.0.0.1');

$allowlist = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) EnvHelper::get('RELAY_SELLER_ALLOWLIST', ''))
)));

// Em daemon o stdout vai para o log do Workerman; escrever nos dois duplicaria.
if (in_array('-d', $argv, true)) {
    RelayLog::silenceStdout();
}

(new RelayServer($port, new Handshake($token, $allowlist), $bind))->run();
