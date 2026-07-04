# GCMS Postman Documentation

Postman files are split by module so each team can import only what it needs.

## Import Order

1. Import `environments/gcms-local-mailtrap.postman_environment.json`.
2. Select `GCMS Local Mailtrap Environment`.
3. Import `collections/01-auth.postman_collection.json`.
4. Register or log in, then verify the OTP from Mailtrap to get a token.
5. Import the module collections needed for your work.

## Complaint Workflow Demo Order

1. Use `01-auth` to log in as admin, employee, and citizen.
2. Use `04-citizen-complaints` to create a complaint and save `complaint_id`.
3. Use `03-admin-management / Admin - Complaints` to review and assign the complaint.
4. Use `05-employee-complaints / Employee - Complaints` to move the complaint through employee statuses.
5. Show the complaint from admin, employee, or citizen collections and verify the timeline/status history.

## Mailtrap OTP Flow

Register, login, resend OTP, and forgot password requests send email through Mailtrap.

1. Run the Register, Login, Resend OTP, or Forgot Password request.
2. Open your Mailtrap Email Sandbox inbox.
3. Copy the OTP or password reset token from the received email.
4. Paste it manually into the matching Postman environment variable:
   - `citizen_otp`
   - `admin_otp`
   - `employee_otp`
   - `login_otp`
   - `reset_token`
5. Run the matching verify or reset request.

OTP codes and reset tokens are never returned by the API response.

## Collections

- `00-health.postman_collection.json`: basic application health check.
- `01-auth.postman_collection.json`: auth, OTP, password recovery, password changes, logout, and security tests.
- `02-lookups.postman_collection.json`: public lookup endpoints for frontend dropdowns.
- `03-admin-management.postman_collection.json`: admin dashboard setup APIs.
- `04-citizen-complaints.postman_collection.json`: citizen web/mobile complaint creation and tracking.
- `05-employee-complaints.postman_collection.json`: employee-accessible complaint list, show, and status processing.
- `06-notifications.postman_collection.json`: placeholder; notification APIs are not implemented yet.
- `07-reports.postman_collection.json`: placeholder; report APIs are not implemented yet.

## Team Usage

- Frontend auth team: use `01-auth`.
- Admin dashboard team: use `03-admin-management`.
- Citizen web/mobile team: use `04-citizen-complaints`.
- Employee dashboard team: use `05-employee-complaints`.

## Security Notes

Do not commit real tokens, OTPs, reset tokens, Mailtrap credentials, or production secrets.

Before committing exported environments, reset sensitive values such as `citizen_token`, `admin_token`, `employee_token`, `citizen_otp`, `admin_otp`, `employee_otp`, and `reset_token`.
