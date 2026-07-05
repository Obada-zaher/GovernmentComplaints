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
- Assign complaints and update complaint lifecycle fields.
- View reports and analytics.
- View notification delivery logs.

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

Use `per_page` where supported. The API caps page size to safe limits.

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
docs/postman/collections/
```

OpenAPI specification:

```text
docs/openapi/gcms-api.openapi.yaml
docs/openapi/gcms-api.openapi.json
```
