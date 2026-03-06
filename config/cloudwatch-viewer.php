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
    | The package will use credentials in this order:
    | 1. Access key + secret (if AWS_ACCESS_KEY_ID is set)
    | 2. Named profile (if AWS_PROFILE is set)
    | 3. IAM role (EC2/ECS/App Runner instance role — no config needed)
    */
    'aws_key'     => env('AWS_ACCESS_KEY_ID'),
    'aws_secret'  => env('AWS_SECRET_ACCESS_KEY'),
    'aws_profile' => env('AWS_PROFILE'),

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
