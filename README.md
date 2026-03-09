# laravel-cloudwatch-viewer

A beautiful, zero-dependency UI for querying and live-streaming AWS CloudWatch logs directly inside your Laravel application.

> **This is a viewer, not a logger.** Every existing Laravel/CloudWatch package pushes logs *to* CloudWatch. This package reads logs *from* CloudWatch using CloudWatch Insights and `FilterLogEvents` — giving you a searchable, paginated log viewer accessible from your own app URL.

---

## Features

- **Dark-theme UI** — Inter + IBM Plex Mono fonts, no frontend build step required
- **Insights search** — query up to 50 log groups in a single CloudWatch Insights call
- **Live streaming** — real-time log tail via `FilterLogEvents` with 5-second polling
- **Mode toggle** — switch between Query and Live modes from the header
- **Filters** — level, message, user ID, request ID, URL, date range, "has context"
- **Multi-timezone** — pick any timezone; date inputs and displayed timestamps adjust automatically
- **Configurable fields & columns** — control exactly which CloudWatch fields are fetched and shown
- **Result caching** — optional Laravel cache integration to cut CloudWatch API costs
- **Slide-over detail panel** — click any row to see all fields + JSON in a slide-over drawer
- **Client-side pagination** — 25 rows per page with smart ellipsis
- **Request ID filter** — click a Request ID cell to re-search filtered by that request
- **Security** — submitted log groups are always validated against your config before any AWS call

---

## Requirements

- PHP **^8.1**
- Laravel **^10.0 | ^11.0 | ^12.0**
- `aws/aws-sdk-php` **^3.0**
- AWS credentials with the permissions listed below

---

## Installation

### 1. Install the package

```bash
composer require weboccult/laravel-cloudwatch-viewer
```

The service provider is auto-discovered via Laravel's package auto-discovery.

### 2. Publish the config

```bash
php artisan vendor:publish --tag=cloudwatch-viewer-config
```

### 3. Add your log groups

Edit `config/cloudwatch-viewer.php` and add the CloudWatch log groups you want to expose:

```php
'groups' => [
    [
        'name'    => 'Production App',
        'value'   => '/aws/apprunner/my-app/production/application',
        'enabled' => true,
    ],
    [
        'name'    => 'Staging App',
        'value'   => '/aws/apprunner/my-app/staging/application',
        'enabled' => true,
    ],
],
```

### 4. Configure IAM permissions

Attach the following permissions to your application's IAM role or user:

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Effect": "Allow",
            "Action": [
                "logs:StartQuery",
                "logs:GetQueryResults",
                "logs:FilterLogEvents",
                "logs:DescribeLogGroups"
            ],
            "Resource": "*"
        }
    ]
}
```

For tighter security, restrict `Resource` to specific log group ARNs:

```json
"Resource": [
    "arn:aws:logs:us-east-1:123456789012:log-group:/aws/apprunner/my-app/*"
]
```

### 5. Visit the viewer

```
https://yourapp.com/cloudwatch-logs
```

---

## Configuration

All configuration lives in `config/cloudwatch-viewer.php` after publishing.

| Key | Default | Description |
|-----|---------|-------------|
| `route_prefix` | `cloudwatch-logs` | URI prefix for the viewer. Override with `CLOUDWATCH_VIEWER_PREFIX`. |
| `middleware` | `['web']` | Middleware applied to all viewer routes. Add `auth` or custom middleware here. |
| `region` | `us-east-1` | AWS region. Reads `AWS_DEFAULT_REGION`. |
| `auth_by` | `'iam'` | Authentication method: `'iam'`, `'credentials'`, or `'profile'`. |
| `query_limit` | `500` | Max results per Insights query (CloudWatch max is 10,000). |
| `default_hours` | `24` | Hours to look back when no date range is specified. |
| `cache.enabled` | `false` | Enable result caching for Insights queries. |
| `cache.ttl` | `300` | Cache lifetime in seconds. |
| `cache.store` | `null` | Laravel cache store (null = default). E.g. `'redis'`. |
| `fields` | see config | CloudWatch Insights fields to fetch per query. |
| `columns` | see config | Table columns shown in the UI. |
| `context_fields` | see config | Fields checked by the "Hide logs without context" filter. |
| `groups` | `[]` | Log group definitions (see below). |

### AWS Authentication (`auth_by`)

```php
// Use the EC2/ECS/App Runner instance role (recommended for production)
'auth_by' => 'iam',

// Use explicit access key + secret
'auth_by' => 'credentials',
'credentials' => [
    'key'    => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
],

// Use a named credentials profile from ~/.aws/credentials
'auth_by' => 'profile',
'profile' => env('AWS_PROFILE', 'my-profile'),
```

### Log group definition

```php
[
    'name'    => 'Friendly Display Name',  // Shown in the sidebar
    'value'   => '/aws/your/log/group',    // Exact CloudWatch log group path
    'enabled' => true,                      // false to hide without removing
]
```

### Configuring fields and columns

`fields` controls which fields are fetched in every Insights query. `columns` controls which of those appear as table columns:

```php
'fields' => [
    '@timestamp', '@logStream', 'level_name', 'message',
    'context.request_id', 'context.user_id', 'context.url',
],

'columns' => [
    ['label' => 'Timestamp',  'field' => '@timestamp'],
    ['label' => 'Level',      'field' => 'level_name'],
    ['label' => 'Message',    'field' => 'message'],
    ['label' => 'User ID',    'field' => 'context.user_id'],
    ['label' => 'URL',        'field' => 'context.url'],
    ['label' => 'Request ID', 'field' => 'context.request_id'],
],
```

### Enabling query result caching

```php
'cache' => [
    'enabled' => true,
    'ttl'     => 300,      // 5 minutes
    'store'   => 'redis',  // use your preferred cache driver
],
```

Or via `.env`:

```env
CLOUDWATCH_VIEWER_CACHE=true
CLOUDWATCH_VIEWER_CACHE_TTL=300
CLOUDWATCH_VIEWER_CACHE_STORE=redis
```

> Live streaming results are **never** cached — only Insights (Query mode) responses are cached.

---

## Customization

### Restrict access with middleware

```php
'middleware' => ['web', 'auth', 'can:view-cloudwatch-logs'],
```

### Change the URL prefix

```php
'route_prefix' => 'admin/logs',
```

Or via `.env`:

```env
CLOUDWATCH_VIEWER_PREFIX=admin/logs
```

### Customize the view

```bash
php artisan vendor:publish --tag=cloudwatch-viewer-views
```

This copies the view to `resources/views/vendor/cloudwatch-viewer/index.blade.php` for full customization.

---

## How It Works

### Query mode (CloudWatch Insights)

1. The UI sends a `GET /cloudwatch-logs/fetch` request with your selected filters.
2. The controller validates that all requested log groups exist in your config.
3. A CloudWatch Insights query is built and submitted via `StartQuery`.
4. The controller polls `GetQueryResults` until complete (up to 10 seconds).
5. Results are flattened from CloudWatch's field/value pair format into plain objects and returned as JSON.
6. Optionally, the response is stored in Laravel cache to skip the round-trip on repeat queries.

### Live mode (`FilterLogEvents`)

1. Clicking **Live** starts a 5-second polling interval.
2. Each poll calls `FilterLogEvents` for all selected log groups (one call per group, since the API does not support multiple groups).
3. A millisecond-precision cursor (`next_start_ms`) tracks the last event seen to prevent gaps or duplicates.
4. New events are prepended to the table and highlighted with a fade animation.
5. Clicking **Query** stops the polling.

### Log format compatibility

The package works with **any log format that writes JSON to CloudWatch**. It flattens nested JSON using dot-notation to match your configured `fields` array. For example, given this log entry:

```json
{
    "datetime": "2025-01-01 12:00:00",
    "level_name": "ERROR",
    "message": "Something failed",
    "context": {
        "user_id": 42,
        "request_id": "abc-123",
        "url": "/api/orders"
    }
}
```

The package maps `context.user_id`, `context.request_id`, `context.url` automatically — as long as those fields are listed in your `fields` config. This is compatible with **Monolog's `JsonFormatter`** out of the box, and adaptable to any structured JSON log format by adjusting `fields`, `columns`, and `context_fields` in your config.

---

## License

MIT — see [LICENSE](LICENSE).
