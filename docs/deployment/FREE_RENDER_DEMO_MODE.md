# Zero-Cost Render Demo Mode

Use the existing Render Web Service only for the academic demo. Do not create a Background Worker or Cron Job for this mode.

Set these Web Service environment values manually:

```text
GCMS_PROCESS_ROLE=web
QUEUE_CONNECTION=sync
```

`sync` executes Laravel `ShouldQueue` notification jobs immediately inside the HTTP request process. This removes the runtime dependency on `php artisan queue:work` for the demo while retaining the existing database queue, worker script, scheduler script, and production runtime roles for a future full deployment.

This is suitable for a small academic demonstration, not a high-scale production architecture. The preferred full deployment remains:

```text
QUEUE_CONNECTION=database
separate worker: sh scripts/render-queue-worker.sh
separate scheduler/cron: sh scripts/render-scheduler-run.sh
```

## Optional Notification Settings

For an in-app notification demonstration without external providers:

```text
PUSH_NOTIFICATIONS_ENABLED=false
SMS_NOTIFICATIONS_ENABLED=false
```

Database/in-app notifications continue to work. For real Expo testing, set `PUSH_NOTIFICATIONS_ENABLED=true` and optionally configure:

```text
EXPO_PUSH_URL=https://exp.host/--/api/v2/push/send
EXPO_PUSH_ACCESS_TOKEN=
```

Only set `EXPO_PUSH_ACCESS_TOKEN` when the Expo project requires push security. SMS remains optional; Twilio is not required for the academic demo. Never commit real secrets.

## SLA Checks

The Laravel schedule still registers `complaints:check-sla` every minute with overlap protection.

- Full deployment: a separate cron process runs `php artisan schedule:run` every minute.
- Zero-cost Render demo: no paid Cron Job is configured, so the deployed Web Service does not automatically run SLA checks.
- Local demo/testing: run `php artisan schedule:work` or `php artisan complaints:check-sla` manually.

## Attachment Storage

The default `public` attachment disk uses the container filesystem. This is acceptable for a short-lived academic demo but is not durable storage: files can disappear after a Render redeploy. Production deployments should use durable object storage; this demo mode does not change the attachment API or configure a storage provider.
