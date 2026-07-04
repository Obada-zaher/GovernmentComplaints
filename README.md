# Government Complaint Management System API

Laravel REST API backend for government complaint management.

## Auth Endpoints

All API endpoints are prefixed with `/api/v1`.

| Method | Endpoint | Auth | Description |
| --- | --- | --- | --- |
| POST | `/api/v1/auth/register` | Public | Register a citizen and send OTP |
| POST | `/api/v1/auth/login` | Public | Login with email or phone and send OTP |
| POST | `/api/v1/auth/verify-otp` | Public | Verify OTP and issue Sanctum token for register/login |
| POST | `/api/v1/auth/resend-otp` | Public, throttled | Resend OTP |
| GET | `/api/v1/auth/me` | Bearer token | Return current user profile |
| POST | `/api/v1/auth/logout` | Bearer token | Revoke current access token |

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

## Example Login Flow

1. Send `POST /api/v1/auth/login`.
2. In `APP_ENV=local`, copy the OTP from the response. In other environments, the OTP is not returned.
3. Send `POST /api/v1/auth/verify-otp` with `user_id`, `otp`, and `purpose`.
4. Use the returned token as `Authorization: Bearer <token>` for protected endpoints.

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
  "purpose": "login"
}
```

## Postman Collection

Import these files into Postman:

1. Collection: `docs/postman/government-complaints-api.postman_collection.json`
2. Environment: `docs/postman/local.postman_environment.json`

Run the API locally:

```bash
php artisan migrate:fresh --seed
php artisan serve
```

Select `GCMS Local Environment` in Postman before sending requests.

Recommended auth flow:

1. Run `Auth - Citizen Register Flow / Register Citizen`.
2. If `APP_ENV=local`, the response includes the OTP and the Postman test script saves it automatically. In other environments, copy the OTP from your delivery channel or database test fixture into `citizen_otp`.
3. Run `Auth - Citizen Register Flow / Verify Citizen Register OTP`.
4. Use the saved `citizen_token` for protected citizen requests.
5. Run the admin and employee login folders after `php artisan migrate:fresh --seed` to use the seeded accounts.

OTP values are returned by the API only when `APP_ENV=local`. Shared tokens, OTPs, and exported environments containing real secrets must not be committed.

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

In Postman, import the collection and environment from `docs/postman`, run the citizen auth flow to set `citizen_token`, then use the `Citizen - Complaints` folder. The JSON create request saves `complaint_id` and `complaint_number` for the show and attachment requests.

## Development Commands

```bash
php artisan migrate:fresh --seed
php artisan storage:link
php artisan test
```
