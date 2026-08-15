# GCMS Postman Collections

There are only 3 collections:

1. `shared.postman_collection.json`
   Used by both mobile and web teams.

2. `mobile.postman_collection.json`
   Used by the mobile citizen app team.

3. `web.postman_collection.json`
   Used by admin and employee web dashboard team.

## Import Order

1. Import `gcms-local.postman_environment.json`.
2. Import `shared.postman_collection.json`.
3. Import `mobile.postman_collection.json` OR `web.postman_collection.json` depending on your team.

Mobile team imports:

- `shared.postman_collection.json`
- `mobile.postman_collection.json`

Web team imports:

- `shared.postman_collection.json`
- `web.postman_collection.json`

## Web Admin User Management

The web collection's **Admin Management → Admin User Management** folder contains the admin-only user APIs. Authenticate first using the `admin_token` variable, then use the captured `user_id`, `employee_id`, and `department_id` variables in later requests.

- Employees must use an existing active `department_id`.
- Citizens and admins do not have department assignments.
- `PATCH /admin/users/{{user_id}}` updates safe profile, role, or department fields only. Do not send `is_active` there; it returns `422`.
- Use `PATCH /admin/users/{{user_id}}/status` to deactivate or reactivate an account. Deactivation revokes all Sanctum tokens; reactivation does not restore them.
- `GET /admin/employees` remains the employee-only lookup for complaint assignment. The target employee must be active and in the complaint's department.

## OTP Flow

1. Run login or register.
2. Open Mailtrap or the configured email channel.
3. Copy the OTP manually.
4. Paste it into the correct Postman environment variable:
   - `citizen_otp`
   - `admin_otp`
   - `employee_otp`
5. Run Verify OTP.

The API does not return OTP values in responses.

For Render demo testing, when `OTP_FIXED_CODE_ENABLED=true` and `OTP_FIXED_CODE=000000` are configured, use `000000` for every OTP purpose. This does not change any request or response shape.

## Base URL

Local:

```text
http://127.0.0.1:8000/api/v1
```

Hosted:

Replace `base_url` with the deployed backend API URL.

## Security

Do not commit real tokens, OTPs, production URLs, or production secrets. The bundled credential examples are local/demo placeholders only.
