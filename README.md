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

## Development Commands

```bash
php artisan migrate:fresh --seed
php artisan test
```
