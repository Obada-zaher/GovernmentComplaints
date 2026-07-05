# Performance Notes

This project uses pagination, selective eager loading, and indexed workflow fields to keep the API practical for a university demo and small production-style datasets.

## Pagination Strategy

Common list endpoints are paginated:

- Citizen complaint list.
- Admin complaint list.
- Employee complaint list.
- Notifications list.
- Offline submissions list.
- Reports that return complaint rows, such as SLA breaches.
- Admin delivery logs.
- Admin setup data lists.

Clients should pass `per_page` only when needed. The API caps page size to avoid large responses.

## Eager Loading Strategy

List endpoints load summary relationships such as department, category, priority, citizen, and assigned employee where useful.

Timeline/status history and attachments are intended for show/detail endpoints, not large list responses. This prevents unnecessary payload size and avoids common N+1 issues.

## Indexes

Migrations define indexes for frequent filters:

- Complaint number, citizen, department, category, priority, assigned employee, status, and due date.
- Notification user/channel/status/type filters.
- Offline submission citizen and `client_uuid` idempotency lookup.
- Classification rule department/category/keyword/active filters.

Add new indexes only when a real query pattern needs them.

## Background Work

Run the SLA checker through the scheduler for production-like usage:

```bash
php artisan complaints:check-sla
```

Run queue workers for email, push, and SMS jobs:

```bash
php artisan queue:work
```

## Future Improvements

- Cache public lookups if traffic grows.
- Cache expensive report aggregations with short TTLs.
- Archive old delivery logs if they become large.
- Add database monitoring before adding speculative indexes.
