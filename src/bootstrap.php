<?php

/**
 * Bootstrap comum do web (public/index.php) e do relay (bin/relay.php).
 *
 * safeLoad: em produção não existe .env — os segredos vêm das Variables do nó
 * no Jelastic — e a ausência do arquivo não pode derrubar a aplicação.
 * createUnsafeImmutable: "unsafe" popula também o getenv(), que é a primeira
 * fonte consultada pelo EnvHelper; "immutable" garante que uma variável já
 * definida no ambiente não seja sobrescrita por um .env que por acaso exista.
 */

require __DIR__ . '/../vendor/autoload.php';

Dotenv\Dotenv::createUnsafeImmutable(__DIR__ . '/../')->safeLoad();
