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

Complaint assignment requires an active employee with a department matching the complaint department. A submitted complaint must first move to `under_review`; terminal complaints cannot be assigned. Employees can access complaints assigned to themselves and unassigned complaints in their own department, but never coworker assignments or other departments' complaints.

An assigned employee or admin can move an `in_progress` complaint to `waiting_citizen` using the existing status endpoint with a non-blank `note`. This creates an internal information request. Citizen attachment uploads mark that request as responded but leave the complaint in `waiting_citizen`; an authorized employee must explicitly resume or resolve it.

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

## Citizen Duplicate Warning Check

`POST /api/v1/citizen/complaints/check-duplicates` accepts `latitude`, `longitude`, and an active `category_id`. It is a warning-only, read-only check: it looks for non-deleted complaints in the same category with an active status within the configured 15-meter default radius, returning only a limited public match summary. Coordinates `0,0` return no matches. `POST /api/v1/citizen/complaints` remains unchanged and always creates a valid complaint even when this check finds a match.

## Citizen Information Response

`POST /api/v1/citizen/complaints/{id}/information-response` lets the complaint owner submit exactly one textual answer to the active employee information request.

```json
{
  "message": "The subscription number is 87451239."
}
```

Success uses the existing complaint response shape and keeps the complaint waiting for employee review:

```json
{
  "success": true,
  "message": "Information response submitted successfully.",
  "data": { "id": 123, "status": "waiting_citizen", "timeline": [] },
  "meta": {}
}
```

A second text response for the same request returns `422` without overwriting the first:

```json
{
  "success": false,
  "message": "Validation failed.",
  "errors": {
    "message": ["This information request has already received a text response."]
  }
}
```

Attachments continue to use `POST /api/v1/citizen/complaints/{id}/attachments` unchanged. Calling text then attachments, or uploading an attachment first and then calling this endpoint, is supported; multiple attachment uploads remain supported. Another citizen receives `403` with the normal forbidden envelope.

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

Localization contract: [`LOCALIZATION.md`](LOCALIZATION.md)
