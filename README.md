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

Complaint creation, file upload, notifications, and complaint lifecycle APIs will be implemented in later tasks.

## Development Commands

```bash
php artisan migrate:fresh --seed
php artisan test
```
