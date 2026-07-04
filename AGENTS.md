# Project Instructions for Codex

Project: Government Complaint Management System Backend API.

Use Laravel as REST API backend only. Do not build frontend pages.

Architecture rules:
- API prefix: /api/v1
- Use Laravel Sanctum for API authentication.
- Use FormRequest classes for validation.
- Use API Resources for responses.
- Use Services for business logic.
- Use Policies or middleware for role-based authorization.
- Use migrations, seeders, factories, and feature tests.
- Update Postman collection after every API change.
- Do not commit .env or secrets.
- Keep response format consistent.

Response format:
{
  "success": true,
  "message": "Human readable message",
  "data": {},
  "meta": {}
}

Error format:
{
  "success": false,
  "message": "Error message",
  "errors": {}
}

Main roles:
- citizen
- employee
- admin

Main modules:
- Authentication and OTP
- Role Based Access Control
- Departments
- Complaint categories
- Priorities and SLA rules
- Complaints
- Attachments
- Complaint lifecycle and timeline
- Notifications
- Reports
- Offline sync
- Simple complaint classification
