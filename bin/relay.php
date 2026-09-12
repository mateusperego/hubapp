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

require __DIR__ . '/../vendor/autoload.php';

use HubApp\Relay\Handshake;
use HubApp\Relay\RelayLog;
use HubApp\Relay\RelayServer;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$token = trim((string) ($_ENV['RELAY_TOKEN'] ?? ''));

if ($token === '') {
    fwrite(STDERR, "RELAY_TOKEN não definido no .env. O relay não sobe sem token." . PHP_EOL);
    exit(1);
}

$port = (int) ($_ENV['RELAY_PORT'] ?? 8443);

$allowlist = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) ($_ENV['RELAY_SELLER_ALLOWLIST'] ?? ''))
)));

// Em daemon o stdout vai para o log do Workerman; escrever nos dois duplicaria.
if (in_array('-d', $argv, true)) {
    RelayLog::silenceStdout();
}

(new RelayServer($port, new Handshake($token, $allowlist)))->run();
