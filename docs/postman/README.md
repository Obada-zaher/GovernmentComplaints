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

## Offline Sync Flow

1. Use `01-auth` to log in as citizen and save `citizen_token`.
2. Use `02-lookups` to fetch departments, categories, and priorities.
3. Open `04-citizen-complaints / Citizen - Offline Sync`.
4. Run `Sync Offline Complaint - JSON`.
5. Run `Sync Same Offline Complaint Again - Idempotency Test` to verify the same `client_uuid` returns the same complaint without creating a duplicate.
6. Run `List Offline Submissions`.
7. Run `Show Offline Submission`.

Mobile/web clients should generate one `client_uuid` per local offline complaint, store it locally, and reuse it for retries until the server returns a synced complaint.

## Notifications Flow

1. Use `01-auth` to log in as citizen, admin, and employee.
2. Use `04-citizen-complaints` to create a complaint.
3. Use `03-admin-management / Admin - Complaints` to assign the complaint.
4. Use `05-employee-complaints / Employee - Complaints` or admin status update requests to update status.
5. Run this locally when you need to generate SLA breach notifications:

```bash
php artisan complaints:check-sla
```

6. Use `06-notifications` to list notifications, save `notification_id`, check unread count, mark read, mark all read, and delete a notification.

Notification recipients:

- Citizens receive assignment, status, resolved, and closed updates for their own complaints.
- Assigned employees receive assignment and SLA breach notifications.
- Active admins receive new complaint, employee escalation, and SLA breach notifications.

## Reports Flow

1. Use `01-auth` to log in as admin and save `admin_token`.
2. Create sample complaints with `04-citizen-complaints`.
3. Assign and process complaints with `03-admin-management` and `05-employee-complaints`.
4. Run the SLA checker if you need breached complaint data:

```bash
php artisan complaints:check-sla
```

5. Open `07-reports` and run overview, distribution, SLA, employee, trend, and snapshot requests.

Reports are admin-only and return backend analytics data for dashboard cards, tables, and charts. They do not create frontend charts.

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
- `04-citizen-complaints.postman_collection.json`: citizen web/mobile complaint creation, tracking, attachments, and offline sync.
- `05-employee-complaints.postman_collection.json`: employee-accessible complaint list, show, and status processing.
- `06-notifications.postman_collection.json`: authenticated notification list, unread count, mark read, read all, and delete requests.
- `07-reports.postman_collection.json`: admin-only overview, distribution, SLA, employee performance, trends, and report snapshot requests.

## Team Usage

- Frontend auth team: use `01-auth`.
- Admin dashboard team: use `03-admin-management`, `06-notifications`, and `07-reports`.
- Citizen web/mobile team: use `04-citizen-complaints`.
- Employee dashboard team: use `05-employee-complaints`.

## Security Notes

Do not commit real tokens, OTPs, reset tokens, Mailtrap credentials, or production secrets.

Before committing exported environments, reset sensitive values such as `citizen_token`, `admin_token`, `employee_token`, `citizen_otp`, `admin_otp`, `employee_otp`, and `reset_token`. Also reset workflow variables such as `notification_id`, `snapshot_id`, `offline_submission_id`, and `offline_client_uuid` if they came from a local run.
