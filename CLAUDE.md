# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

HubApp is a PHP backend API serving as a multi-purpose hub for business applications, including DANFE (Documento Auxiliar da Nota Fiscal Eletrônica) PDF generation from NFe XML files, push notifications, document signing, and image management. It serves as a backend for a mobile application.

## Technology Stack

- **PHP 7.4+** (vanilla PHP, no framework in the main codebase)
- **Composer** for dependency management
- **NFePHP/sped-da** library for DANFE PDF generation
- **Apache** with mod_rewrite (WAMP environment)

## Project Structure

```
.
├── public/           # Front controller (index.php) + .htaccess de rewrite
├── bin/
│   └── relay.php     # Daemon WebSocket de suporte remoto (Workerman), fora do Apache
├── config/
│   └── firebase/{CNPJ}/{app}/firebase.php   # Config por cliente; a private key vem do ambiente
├── src/
│   ├── bootstrap.php # Autoload + carregamento das variáveis de ambiente
│   ├── Auth/         # GoogleOAuth (token para a FCM API)
│   ├── Controllers/  # Handlers das rotas
│   ├── Firebase/     # FcmClient
│   ├── Helpers/      # EnvHelper, RequestHelper, ResponseHelper
│   ├── Relay/        # Protocolo do relay (docs/relay-protocol.md)
│   └── Services/     # Regra de negócio (DANFE, imagens, backups, JSON, LetsSign)
├── storage/          # Runtime: imagens, PDFs, JSON, logs. Não versionado; volume persistente
├── docs/             # relay-protocol.md
└── vendor/           # Dependências — versionadas de propósito, o deploy é por git pull
```

Atenção ao DocumentRoot: no Jelastic ele aponta para a **raiz do projeto**, não para
`public/` — por isso as rotas em `public/index.php` carregam o prefixo `/public/`. O
`.htaccess` da raiz existe justamente para impedir acesso HTTP a `.env`, `config/`,
`storage/`, `src/`, `bin/` e `vendor/`.

## Configuração e segredos

Nada de segredo no repositório. As variáveis estão listadas em `.env.example`:

- **Local:** copie para `.env` (gitignored).
- **Produção (web):** nó Apache no Jelastic > `Additionally` > **Variables** > `Apply` +
  restart do nó.
- **Produção (relay):** o processo CLI não herda as Variables do nó; ele lê um arquivo
  `600` fora do webroot, carregado no start — ver `docs/relay-protocol.md`.

Toda leitura passa por `HubApp\Helpers\EnvHelper` (`get`/`required`), que consulta
`getenv()`, `$_ENV` e `$_SERVER` nessa ordem. Ler `$_ENV` direto não funciona em produção:
o `variables_order` padrão do PHP (`GPCS`) não popula `$_ENV` com o ambiente do sistema.

## Common Commands

```bash
# Install dependencies
composer install

# Update dependencies
composer update

# Clear composer cache if having issues
composer clear-cache
```

## Architecture

### Entry Point & Routing
- `public/index.php` handles all requests via Apache rewrite
- Simple path-based routing: `/danfe/{xmlName}` routes to `DanfeController::show()`

### Flow for DANFE Generation
1. Request hits `DanfeController::show($xmlName)`
2. `DanfeService::gerar($xmlName)` loads XML from `storage/xml/` and generates PDF using NFePHP
3. `ResponseHelper::pdf()` outputs the PDF with appropriate headers

### Namespace
- All classes use the `HubApp\` namespace (PSR-4 autoloading via Composer)

### Legacy Code
- `src/Controllers/agro_produtor.php` contains legacy CodeIgniter controller code (uses `CI_Controller`)
- This legacy code handles mobile app endpoints: login, JSON data storage, PDF batch generation

## Key Dependencies

- **nfephp-org/sped-da**: DANFE PDF generation from NFe XML
- **nfephp-org/sped-common**: Common NFePHP utilities
- **tecnickcom/tc-lib-barcode**: Barcode generation (used by sped-da)

## Development Notes

- XML files are stored in `storage/xml/{name}.xml`
- Generated PDFs use the NFePHP `Danfe` class with default portrait A4 layout
- The application runs on WAMP (Windows Apache MySQL PHP)
