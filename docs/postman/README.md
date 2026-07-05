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

## Base URL

Local:

```text
http://127.0.0.1:8000/api/v1
```

Hosted:

Replace `base_url` with the deployed backend API URL.

## Security

Do not commit real tokens, OTPs, passwords, production URLs, or production secrets.