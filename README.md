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

PHPUnit 12 exits with status `1` when it emits a test-runner warning, even if every test passed (`OK, but there were issues!`). When Cursor or another agent is detected, `laravel/pao` rewrites that output as JSON with `"result":"passed"` and hides the warning, so the suite can look fully green while the process still exits `1`. Disable Pao with `PAO_DISABLE=1` to see the native PHPUnit summary.

## Auth

Cookie-based Sanctum SPA authentication. Documented in `../leadflow-docs/05-authentication.md`.

Seeded local password: `password`
