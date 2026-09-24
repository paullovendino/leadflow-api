# LeadFlow API

Laravel 13 REST API for LeadFlow.

## Requirements

- PHP 8.3+
- Composer
- PostgreSQL
- Extensions listed in the workspace README

## Setup

```bash
composer install
copy .env.example .env
php artisan key:generate
```

Set `DB_PASSWORD` and create the `leadflow` database, then:

```bash
php artisan migrate --seed
php artisan serve
```

The API listens at `http://localhost:8000`.

## Tests

```bash
php artisan test
```

Tests use SQLite in-memory and PHPUnit.

## Auth

Cookie-based Sanctum SPA authentication. Documented in `../leadflow-docs/05-authentication.md`.

Seeded local password: `password`
