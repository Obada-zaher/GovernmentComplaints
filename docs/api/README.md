# GCMS API Guide

Base URL for local development:

```text
http://127.0.0.1:8000/api/v1
```

All API responses use the same envelope.

Success:

```json
{
  "success": true,
  "message": "Human readable message",
  "data": {},
  "meta": {}
}
```

Error:

```json
{
  "success": false,
  "message": "Error message",
  "errors": {}
}
```

## Authentication Flow

1. Register or log in with `01-auth`.
2. The API sends OTP through Mailtrap in local development.
3. Verify OTP with `/auth/verify-otp`.
4. Store the returned Sanctum bearer token.
5. Send protected requests with:

```http
Authorization: Bearer <token>
Accept: application/json
```

OTP codes and password reset tokens are never returned in API responses.

## Role Modules

Citizen APIs:

- Create and track complaints.
- Upload complaint attachments.
- Sync offline complaints with `client_uuid`.
- Read notifications and manage device tokens/preferences.

Employee APIs:

- List assigned or department-accessible complaints.
- Show complaint details.
- Move complaints through allowed lifecycle statuses.

Admin APIs:

- Manage departments, categories, priorities, SLA rules, and classification rules.
- List, create, view, and update citizens, employees, and admins.
- List government employees for department-matched complaint assignment.
- Assign complaints and update complaint lifecycle fields.
- View reports and analytics.
- View notification delivery logs.

## Admin User Management

Citizens can self-register through `POST /api/v1/auth/register`; public registration always creates a citizen. Admins can separately provision and manage citizens, employees, and admins:

- `GET /api/v1/admin/users`
- `POST /api/v1/admin/users`
- `GET /api/v1/admin/users/{user}`
- `PATCH /api/v1/admin/users/{user}`
- `PATCH /api/v1/admin/users/{user}/status`
- `GET /api/v1/admin/employees`

Employees require an active department. Citizens and admins are system-wide and have no department assignment. `GET /admin/users` lists every role; `GET /admin/employees` is the employee-only lookup used by the complaint-assignment UI.

Use the general user update endpoint for safe profile, role, and department changes only. It rejects `is_active`; account lifecycle changes must use `/admin/users/{user}/status`. Deactivation revokes every Sanctum token, and reactivation does not restore old tokens, so the user must authenticate again. Inactive accounts cannot access protected APIs. Admins cannot deactivate themselves or demote their own role, and the final active admin cannot be deactivated or demoted.

Complaint assignment requires an active employee with a department matching the complaint department. Employees can access complaints assigned to themselves and unassigned complaints in their own department, but never coworker assignments or other departments' complaints.

## Frontend Integration Order

1. Auth and OTP.
2. Lookups for departments, categories, priorities, and statuses.
3. Citizen complaint creation and tracking.
4. Admin complaint review and assignment.
5. Employee complaint processing.
6. Notifications, device tokens, and preferences.
7. Reports and analytics.
8. Classification preview for suggested department/category.

## Pagination

List endpoints return pagination metadata in `meta`, typically:

```json
{
  "current_page": 1,
  "from": 1,
  "last_page": 2,
  "per_page": 15,
  "to": 15,
  "total": 25
}
```

Use `per_page` where supported. Admin user and employee lists cap `per_page` at 100.

## File Uploads

Complaint attachments use `multipart/form-data` with `attachments[]`.

Allowed file types:

- `jpg`
- `jpeg`
- `png`
- `pdf`
- `doc`
- `docx`

Maximum file size is 5120 KB per file.

## Documentation Files

Postman collections:

```text
docs/postman/shared.postman_collection.json
docs/postman/mobile.postman_collection.json
docs/postman/web.postman_collection.json
```

OpenAPI specification:

```text
docs/openapi/gcms-api.openapi.yaml
docs/openapi/gcms-api.openapi.json
```
