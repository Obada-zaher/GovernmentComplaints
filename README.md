# Government Complaint Management System API

Laravel REST API backend for managing citizen complaints, government department workflows, SLA tracking, notifications, reports, offline sync, and rule-based complaint classification.

This repository is backend-only. It does not include frontend, mobile, Python AI services, Firebase client code, or real SMS gateway credentials.

## Features

- Secure OTP-based authentication with Mailtrap for development.
- Laravel Sanctum API tokens.
- Role-based access for `citizen`, `employee`, and `admin`.
- Public lookup APIs for departments, complaint categories, priorities, and statuses.
- Citizen complaint creation, tracking, attachments, and offline sync.
- Admin complaint review, assignment, department/category/priority changes, and lifecycle updates.
- Employee complaint processing with timeline/status history.
- SLA deadline calculation and breach detection command.
- Database notifications, Mailtrap email notifications, push/SMS infrastructure, preferences, device tokens, and delivery logs.
- Admin reports and analytics APIs.
- Rule-based intelligent complaint classification.
- Postman collections, OpenAPI documentation, Docker development setup, and GitHub Actions CI.

## Tech Stack

- PHP 8.2+
- Laravel 12
- Laravel Sanctum
- MySQL
- Database queue driver
- Mailtrap for development email
- Optional Firebase Cloud Messaging configuration
- Optional SMS provider abstraction with `log` and Twilio branches
- PHPUnit

## Requirements

- PHP 8.2 or newer
- Composer
- MySQL 8 or compatible
- Node/NPM only if you use Laravel frontend asset tooling
- Docker Desktop if using Docker setup

## Local Installation

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate:fresh --seed
php artisan storage:link
php artisan serve
```

Local API base URL:

```text
http://127.0.0.1:8000/api/v1
```

Health check:

```bash
curl http://127.0.0.1:8000/api/v1/health
```

## Docker Installation

```bash
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate:fresh --seed
docker compose exec app php artisan storage:link
docker compose exec app php artisan test
```

The app is exposed at:

```text
http://localhost:8000
```

Docker services include `app`, `mysql`, `redis`, `queue`, and `scheduler`. Defaults are for local development only and do not contain production secrets.

## Environment Configuration

Copy `.env.example` to `.env` and configure:

- `APP_URL`
- database credentials
- Mailtrap SMTP credentials
- queue driver
- optional Firebase Cloud Messaging keys
- optional SMS provider credentials

Do not commit `.env` or real credentials.

## Mailtrap Setup

Mailtrap is used for OTP, password reset, and development complaint emails.

1. Create a Mailtrap account.
2. Open Email Sandbox SMTP settings.
3. Copy the Laravel SMTP values into `.env`.
4. Run:

```bash
php artisan config:clear
```

OTP codes and password reset tokens are never returned in API responses.

## Database and Demo Data

Default seed:

```bash
php artisan migrate:fresh --seed
```

Presentation/demo data:

```bash
php artisan db:seed --class=DemoDataSeeder
```

Demo accounts use password `password`.

| Role | Email |
| --- | --- |
| Admin | `admin@gcms.test` |
| Employee | `employee@gcms.test` |
| Citizen | `citizen@gcms.test` |

## Running Tests

```bash
php artisan test
```

CI runs the same test suite through GitHub Actions using MySQL and safe test environment settings.

## Queue Worker

Queued jobs are used for notification channels.

```bash
php artisan queue:work
```

In production, run the worker under a process supervisor.

## SLA Command

Manual SLA breach check:

```bash
php artisan complaints:check-sla
```

For production, run Laravel scheduler from cron:

```cron
* * * * * php /path-to-project/artisan schedule:run >> /dev/null 2>&1
```

## API Documentation

- Human API guide: `docs/api/README.md`
- OpenAPI YAML: `docs/openapi/gcms-api.openapi.yaml`
- OpenAPI JSON: `docs/openapi/gcms-api.openapi.json`
- Postman guide: `docs/postman/README.md`

All protected endpoints use:

```http
Authorization: Bearer <sanctum_token>
Accept: application/json
```

## Postman Collections

The API documentation for frontend teams is simplified into three files:

- `docs/postman/shared.postman_collection.json`
- `docs/postman/mobile.postman_collection.json`
- `docs/postman/web.postman_collection.json`

Use:

- Shared + Mobile for mobile app team.
- Shared + Web for web dashboard team.

Environment:

- `docs/postman/gcms-local.postman_environment.json`

More details:

- `docs/postman/README.md`

## Git Workflow

Recommended branch flow:

```bash
git checkout -b feature/<short-name>
php artisan test
git status
```

GitHub Actions runs tests on pushes and pull requests to `main` and `dev`.

## Security Notes

- Never commit `.env`, tokens, OTPs, Mailtrap credentials, Firebase credentials, SMS credentials, or production SMTP credentials.
- Use HTTPS in production.
- Set `APP_DEBUG=false` in production.
- Use real SMTP outside development.
- Store uploaded files and database backups securely.
- Review `docs/security/SECURITY_CHECKLIST.md` before delivery.

## Folder Structure

```text
app/
  Http/Controllers/Api/V1/
  Http/Requests/Api/V1/
  Http/Resources/Api/V1/
  Services/
database/
  migrations/
  seeders/
  factories/
docs/
  api/
  openapi/
  performance/
  postman/
  security/
tests/
  Feature/
```

## Academic Scope

This project demonstrates backend API design, authentication, RBAC, workflow management, SLA rules, notification infrastructure, reporting, offline sync, and deterministic classification. External paid AI APIs, frontend dashboards, real Firebase client integration, and real SMS credentials are intentionally outside this repository.
