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
agroprodutor/
├── public/           # Web root (document root should point here)
│   ├── index.php     # Entry point with basic routing
│   └── .htaccess     # URL rewriting rules
├── src/
│   ├── Controllers/  # Request handlers
│   ├── Services/     # Business logic (DANFE generation)
│   └── Helpers/      # Utility classes (HTTP responses)
├── storage/
│   ├── xml/          # NFe XML files
│   ├── pdf/          # Generated PDF files
│   ├── json/         # JSON data files
│   └── logs/         # Application logs
└── vendor/           # Composer dependencies
```

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
