# Security Checklist

This checklist records the security posture for the academic delivery build.

## Implemented

- `.env` is ignored by Git.
- `.env.example` contains placeholders only.
- Passwords are hashed through Laravel casts or `Hash::make`.
- OTP values are not returned in API responses.
- Sanctum tokens are issued only after OTP verification.
- Sensitive auth routes are rate limited.
- Admin APIs require `auth:sanctum` and `role:admin`.
- Employee APIs require `auth:sanctum` and `role:employee`.
- Citizen APIs require `auth:sanctum` and `role:citizen`.
- User model hides `password` and `remember_token`.
- File uploads validate mime type and maximum file size.
- Database notifications remain the reliable notification channel.
- Firebase, Twilio, SMS, and SMTP credentials are read from `.env`.
- Notification delivery logs do not expose raw device tokens or provider secrets.
- Health endpoint does not expose filesystem paths or secrets.

## Production Recommendations

- Serve the API only over HTTPS.
- Replace Mailtrap with a real SMTP provider in production.
- Store Firebase and SMS provider credentials only in server environment variables.
- Run queue workers under a process supervisor.
- Run `php artisan schedule:run` from server cron every minute.
- Set `APP_DEBUG=false`.
- Use strong database passwords and restrict database network access.
- Configure backups for database and uploaded files.
- Review CORS settings for the production frontend domain.
- Rotate credentials if any exported Postman environment contains real tokens.

## Operational Notes

- Run `php artisan queue:work` for queued email, push, and SMS jobs.
- Run `php artisan complaints:check-sla` manually or through the scheduler for SLA breach detection.
- Do not commit `.env`, exported real Postman tokens, Firebase credentials, SMS credentials, or production SMTP passwords.
