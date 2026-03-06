<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Route Prefix
    |--------------------------------------------------------------------------
    | The URI prefix for all CloudWatch Viewer routes.
    | Access the UI at: yourapp.com/{route_prefix}
    */
    'route_prefix' => env('CLOUDWATCH_VIEWER_PREFIX', 'cloudwatch-logs'),

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    | Middleware applied to all CloudWatch Viewer routes.
    | Add your own auth middleware here, e.g. ['web', 'auth', 'can:view-logs']
    */
    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | AWS Region
    |--------------------------------------------------------------------------
    */
    'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),

    /*
    |--------------------------------------------------------------------------
    | AWS Authentication
    |--------------------------------------------------------------------------
    | Controls how the package authenticates with AWS. Valid values:
    |
    |   'iam'         — Use the instance/task/App Runner IAM role (default, no keys needed)
    |   'credentials' — Explicit access key + secret (set AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY)
    |   'profile'     — Named credentials profile (set AWS_PROFILE)
    */
    'auth_by'     => env('AWS_AUTH_BY', 'iam'),
    'credentials' => [
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
    ],
    'profile'     => env('AWS_PROFILE'),

    /*
    |--------------------------------------------------------------------------
    | Query Limit
    |--------------------------------------------------------------------------
    | Maximum number of log entries returned per CloudWatch Insights query.
    | CloudWatch Insights maximum is 10,000.
    */
    'query_limit' => env('CLOUDWATCH_VIEWER_QUERY_LIMIT', 500),

    /*
    |--------------------------------------------------------------------------
    | Default Hours
    |--------------------------------------------------------------------------
    | Number of hours to look back when no date range is provided via the UI.
    */
    'default_hours' => env('CLOUDWATCH_VIEWER_DEFAULT_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Fields to Fetch
    |--------------------------------------------------------------------------
    | CloudWatch Insights fields included in every query.
    | @timestamp is always fetched automatically (required for sorting).
    | Add or remove fields to match your log structure.
    */
    'fields' => [
        '@timestamp',
        '@logStream',
        'level_name',
        'message',
        'context.request_id',
        'context.user_id',
        'context.url',
        'context.method',
        'context.ip',
        'context.environment',
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Columns
    |--------------------------------------------------------------------------
    | Columns shown in the log table. Each entry maps a header label to the
    | CloudWatch field it reads from.
    |
    | Special rendering is applied automatically for these known fields:
    |   @timestamp           → formatted datetime
    |   level_name           → coloured badge
    |   message              → truncated, click to open detail modal
    |   context.request_id   → first 8 chars, click to filter by request
    |
    | Any other field renders as plain truncated text.
    */
    'columns' => [
        ['label' => 'Timestamp',  'field' => '@timestamp'],
        ['label' => 'Level',      'field' => 'level_name'],
        ['label' => 'Message',    'field' => 'message'],
        ['label' => 'User ID',    'field' => 'context.user_id'],
        ['label' => 'URL',        'field' => 'context.url'],
        ['label' => 'Request ID', 'field' => 'context.request_id'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Groups
    |--------------------------------------------------------------------------
    | Define the CloudWatch log groups available in the viewer.
    | Only groups listed here can be queried (security: prevents arbitrary group access).
    |
    | - name:    Friendly display name shown in the UI
    | - value:   Actual CloudWatch log group path (e.g. /aws/apprunner/my-app/...)
    | - enabled: Set to false to hide without removing the entry
    */
    'groups' => [
        // [
        //     'name'    => 'Production App',
        //     'value'   => '/aws/apprunner/my-app/production/application',
        //     'enabled' => true,
        // ],
        // [
        //     'name'    => 'Staging App',
        //     'value'   => '/aws/apprunner/my-app/staging/application',
        //     'enabled' => true,
        // ],
    ],

];
