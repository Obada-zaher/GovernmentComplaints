# API Localization Contract

Send one of these optional headers to any `/api/v1` endpoint:

```http
Accept-Language: en
Accept-Language: ar
```

Regional variants such as `en-US`, `en-GB`, `ar-SY`, and `ar-SA` are accepted. A normal quality-weighted header such as `ar-SY,ar;q=0.9,en;q=0.8` is also supported. Missing, malformed, or unsupported language values safely use the configured default/fallback locale (English by default).

Localization changes human-readable text only:

- response `message` and validation text;
- system-generated timeline notes and notification title/body text;
- Department, Complaint Category, and Priority display values returned in their existing `name` and `description` fields;
- those same display names when nested in complaints, classification results, SLA rules, users, assignments, and reports;
- system-generated authentication, OTP, email, SMS, and push text.

The response envelope, JSON keys, nesting, pagination, IDs, codes, roles, complaint statuses, sources, notification types, dates, URLs, booleans, numeric values, and classification method remain identical. In particular, `status` intentionally remains a canonical value such as `submitted` or `in_progress`; frontend logic must use stable IDs/codes/statuses rather than human-readable messages or names.

Complaint titles, descriptions, addresses, user names, and user-entered notes are stored and returned exactly as entered. `Accept-Language` controls display output only and never selects classification rules or translates complaint content. The classifier evaluates Arabic, English, and mixed complaint text independently of the UI language.

Example lookup value:

```json
// Accept-Language: en
{"id": 2, "name": "Electricity", "code": "electricity"}

// Accept-Language: ar
{"id": 2, "name": "الكهرباء", "code": "electricity"}
```

Both examples have exactly the same keys and machine values; only the display name differs.
