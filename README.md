# Government Complaint Management System API

Laravel REST API backend for government complaint management.

## Auth Endpoints

All API endpoints are prefixed with `/api/v1`.

| Method | Endpoint | Auth | Description |
| --- | --- | --- | --- |
| POST | `/api/v1/auth/register` | Public | Register a citizen and send OTP |
| POST | `/api/v1/auth/login` | Public | Login with email or phone and send OTP |
| POST | `/api/v1/auth/verify-otp` | Public | Verify OTP and issue Sanctum token |
| POST | `/api/v1/auth/resend-otp` | Public, throttled | Resend OTP |
| POST | `/api/v1/auth/forgot-password` | Public, throttled | Send password reset email |
| POST | `/api/v1/auth/reset-password` | Public, throttled | Reset password with token |
| GET | `/api/v1/auth/me` | Bearer token | Return current user profile |
| POST | `/api/v1/auth/change-password` | Bearer token, throttled | Change password using current password |
| POST | `/api/v1/auth/logout` | Bearer token | Revoke current access token |
| POST | `/api/v1/auth/logout-all` | Bearer token | Revoke all access tokens |

## Role Ping Endpoints

These endpoints verify role-based access control.

| Method | Endpoint | Required Role |
| --- | --- | --- |
| GET | `/api/v1/citizen/ping` | citizen |
| GET | `/api/v1/employee/ping` | employee |
| GET | `/api/v1/admin/ping` | admin |

## Response Format

Success:

```json
{
  "success": true,
  "message": "Message here",
  "data": {},
  "meta": {}
}
```

Error:

```json
{
  "success": false,
  "message": "Error message here",
  "errors": {}
}
```

## Seeded Test Accounts

All seeded users use the password `password`.

| Role | Email | Phone |
| --- | --- | --- |
| admin | `admin@gcms.test` | `0990000001` |
| employee | `employee@gcms.test` | `0990000002` |
| citizen | `citizen@gcms.test` | `0990000003` |

## Mailtrap Setup

Mailtrap is used for development email delivery. Do not put real Mailtrap credentials in GitHub.

1. Create a Mailtrap account.
2. Go to Email Sandbox and open your inbox.
3. Open SMTP Settings / Integrations.
4. Choose Laravel.
5. Copy the exact SMTP credentials from your Mailtrap dashboard into your local `.env`.
6. If Mailtrap shows a different port for your inbox, use the port from Mailtrap.
7. Clear cached configuration:

```bash
php artisan config:clear
```

Local `.env` example:

```env
MAIL_MAILER=smtp
MAIL_HOST=sandbox.smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=your_mailtrap_username
MAIL_PASSWORD=your_mailtrap_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=no-reply@gcms.test
MAIL_FROM_NAME="Government Complaints Management System"
```

OTP and password reset messages appear inside the Mailtrap inbox. OTP codes are never returned in API responses under any environment.

## Secure Authentication Flow

Register:

1. Send `POST /api/v1/auth/register`.
2. The API creates a citizen account with `email_verified_at = null`.
3. A 6-digit OTP is hashed in the database and emailed through Mailtrap.
4. Copy the OTP from Mailtrap.
5. Send `POST /api/v1/auth/verify-otp` with `purpose=register`.
6. The API marks the email verified and returns a Sanctum bearer token.

Login:

1. Send `POST /api/v1/auth/login` with email or phone and password.
2. Invalid credentials return a generic error.
3. If the email is not verified, the API sends a `verify_email` OTP and does not return a token.
4. If credentials are valid and email is verified, the API sends a `login` OTP through Mailtrap.
5. Send `POST /api/v1/auth/verify-otp` with `purpose=login`.
6. The API returns a Sanctum bearer token and user resource.

Example login request:

```json
{
  "login": "citizen@gcms.test",
  "password": "password"
}
```

Example verify request:

```json
{
  "user_id": 3,
  "otp": "123456",
  "purpose": "login",
  "device_name": "Postman"
}
```

Password recovery:

1. `POST /api/v1/auth/forgot-password` always returns a generic response.
2. If the email exists, Mailtrap receives a password reset token email.
3. `POST /api/v1/auth/reset-password` validates the token, changes the password, and revokes existing Sanctum tokens.
4. Reset password does not auto-login the user.

Password management:

1. `POST /api/v1/auth/change-password` requires the current password and a new confirmed password.
2. The new password must be different from the current password.
3. Changing password revokes other tokens and keeps the current token when possible.
4. `POST /api/v1/auth/logout` revokes only the current token.
5. `POST /api/v1/auth/logout-all` revokes all tokens for the user.

Sensitive auth actions are rate-limited and recorded in `auth_events` without storing passwords, OTPs, tokens, or secrets.

## Postman Organization

Postman files are split by module under `docs/postman`.

Environment files:

- `docs/postman/environments/gcms-local-mailtrap.postman_environment.json`
- `docs/postman/environments/gcms-production-template.postman_environment.json`

Collections:

- `docs/postman/collections/00-health.postman_collection.json`
- `docs/postman/collections/01-auth.postman_collection.json`
- `docs/postman/collections/02-lookups.postman_collection.json`
- `docs/postman/collections/03-admin-management.postman_collection.json`
- `docs/postman/collections/04-citizen-complaints.postman_collection.json`
- `docs/postman/collections/05-employee-complaints.postman_collection.json`
- `docs/postman/collections/06-notifications.postman_collection.json`
- `docs/postman/collections/07-reports.postman_collection.json`

Run the API locally:

```bash
php artisan migrate:fresh --seed
php artisan serve
```

Select `GCMS Local Mailtrap Environment` in Postman before sending requests.

Recommended auth flow:

1. Import the local Mailtrap environment first.
2. Import `01-auth`.
3. Run Register or Login.
4. Open Mailtrap, copy the OTP, and paste it into the matching environment variable.
5. Run Verify OTP to save a token.
6. Import and use the module collection you need.

See `docs/postman/README.md` for the team-by-team guide. Shared tokens, OTPs, reset tokens, and exported environments containing real secrets must not be committed.

## Lookups and Admin Management APIs

Frontend clients can fetch dropdown data before complaint creation with public lookup endpoints:

- `GET /api/v1/lookups/departments`
- `GET /api/v1/lookups/categories`
- `GET /api/v1/lookups/categories?department_id=1`
- `GET /api/v1/lookups/categories?department_code=municipality`
- `GET /api/v1/lookups/priorities`
- `GET /api/v1/lookups/complaint-statuses`

Admins can manage setup data with protected endpoints:

- `GET|POST /api/v1/admin/departments`
- `GET|PUT|DELETE /api/v1/admin/departments/{department}`
- `GET|POST /api/v1/admin/categories`
- `GET|PUT|DELETE /api/v1/admin/categories/{category}`
- `GET|POST /api/v1/admin/priorities`
- `GET|PUT|DELETE /api/v1/admin/priorities/{priority}`
- `GET|POST /api/v1/admin/sla-rules`
- `GET|PUT|DELETE /api/v1/admin/sla-rules/{slaRule}`

Admin endpoints require `Authorization: Bearer <admin_token>`. Use the Postman `Auth - Admin Login Flow` first, then run the `Admin - Departments`, `Admin - Categories`, `Admin - Priorities`, and `Admin - SLA Rules` folders. Public lookup folders do not require a token.

Citizen complaint creation and tracking APIs are available under `/api/v1/citizen/complaints`. Employee processing, admin assignment, notifications, offline sync, and classification will be implemented in later tasks.

## Citizen Complaint APIs

Citizen complaint routes require `Authorization: Bearer <citizen_token>` and are protected by the `citizen` role.

| Method | Endpoint | Description |
| --- | --- | --- |
| GET | `/api/v1/citizen/complaints` | List the authenticated citizen complaints with filters and pagination |
| POST | `/api/v1/citizen/complaints` | Create a complaint with optional location and attachments |
| GET | `/api/v1/citizen/complaints/{complaint}` | Show one owned complaint with attachments and timeline |
| POST | `/api/v1/citizen/complaints/{complaint}/attachments` | Add attachments to an owned complaint that is not closed or rejected |

Create complaint JSON example:

```json
{
  "title": "Street light is broken",
  "description": "The street light near my house has been broken for three days.",
  "department_id": 1,
  "category_id": 1,
  "priority_id": 2,
  "latitude": 33.5138,
  "longitude": 36.2765,
  "address": "Damascus",
  "source": "web"
}
```

For attachments, send `multipart/form-data` to the same create endpoint and append each file as `attachments[]`. Allowed file types are `jpg`, `jpeg`, `png`, `pdf`, `doc`, and `docx`; each file can be up to 5120 KB. Files are stored on the `public` disk under `complaints/{complaint_id}`, so run this once locally if the storage link does not exist:

```bash
php artisan storage:link
```

Complaint numbers are generated automatically as `GCMS-YYYY-000001`, incrementing within the current year. New complaints default to `submitted`, and the API creates the first timeline record with `to_status=submitted` and note `Complaint submitted by citizen`.

If `category_id` is provided without `department_id`, the department is inferred from the category. If both are provided, the category must belong to that department. If `priority_id` is omitted, the API uses the seeded `medium` priority when available.

`due_at` is calculated from the first active SLA rule that matches, in this order: department + category + priority, then department + priority, then priority only. If no active SLA rule matches, `due_at` remains `null`.

In Postman, import the local Mailtrap environment, run `01-auth` to set `citizen_token`, then use `04-citizen-complaints`. The JSON create request saves `complaint_id` and `complaint_number` for the show and attachment requests.

## Complaint Assignment and Lifecycle

The core complaint workflow is:

1. A citizen creates a complaint through `/api/v1/citizen/complaints`.
2. Admin users list and inspect complaints through `/api/v1/admin/complaints`.
3. Admin users assign complaints to employees with `PATCH /api/v1/admin/complaints/{complaint}/assign`.
4. Employees list accessible complaints through `/api/v1/employee/complaints`.
5. Employees process assigned or department-accessible complaints by updating status.

Status transitions are restricted:

- `submitted` -> `under_review`, `rejected`
- `under_review` -> `assigned`, `rejected`, `escalated`
- `assigned` -> `in_progress`, `escalated`
- `in_progress` -> `waiting_citizen`, `resolved`, `escalated`
- `waiting_citizen` -> `in_progress`, `resolved`
- `resolved` -> `closed`
- `escalated` -> `assigned`, `in_progress`, `resolved`

Admin assignment can move a `submitted` or `under_review` complaint to `assigned`. Employees cannot close or reject complaints; those actions remain admin-only when the lifecycle permits them.

Every status update creates a `complaint_status_histories` timeline record with `from_status`, `to_status`, `changed_by`, `note`, and `duration_minutes`. `duration_minutes` is calculated from the last timeline event, or from complaint creation when no prior timeline event exists.

The API sets lifecycle timestamps automatically:

- `first_response_at`: first admin or employee workflow action.
- `resolved_at`: first transition to `resolved`.
- `closed_at`: first transition to `closed`.

Admin users can also correct complaint department/category and priority. These changes create timeline records and recalculate `due_at` from active SLA rules when possible.

Use these Postman collections for the workflow:

- `docs/postman/collections/03-admin-management.postman_collection.json`
- `docs/postman/collections/05-employee-complaints.postman_collection.json`

## Development Commands

```bash
php artisan migrate:fresh --seed
php artisan config:clear
php artisan cache:clear
php artisan storage:link
php artisan test
```
