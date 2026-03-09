<?php

namespace Weboccult\CloudWatchViewer\Tests;

use Weboccult\CloudWatchViewer\CloudWatchViewerServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [CloudWatchViewerServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cloudwatch-viewer.groups', [
            ['name' => 'App Logs',  'value' => '/aws/app/production', 'enabled' => true],
            ['name' => 'Staging',   'value' => '/aws/app/staging',    'enabled' => true],
            ['name' => 'Disabled',  'value' => '/aws/app/disabled',   'enabled' => false],
        ]);

        $app['config']->set('cloudwatch-viewer.region',      'us-east-1');
        $app['config']->set('cloudwatch-viewer.auth_by',     'credentials');
        $app['config']->set('cloudwatch-viewer.credentials', ['key' => 'test-key', 'secret' => 'test-secret']);
        $app['config']->set('cloudwatch-viewer.middleware',  ['web']);
        $app['config']->set('cloudwatch-viewer.query_limit', 500);
        $app['config']->set('cloudwatch-viewer.default_hours', 24);
        $app['config']->set('cloudwatch-viewer.cache.enabled', false);
        $app['config']->set('cloudwatch-viewer.cache.ttl',     300);
        $app['config']->set('cloudwatch-viewer.cache.store',   null);

        $app['config']->set('cloudwatch-viewer.fields', [
            '@timestamp', '@logStream', 'level_name', 'message',
            'context.request_id', 'context.user_id', 'context.url',
        ]);

        $app['config']->set('cloudwatch-viewer.columns', [
            ['label' => 'Timestamp',  'field' => '@timestamp'],
            ['label' => 'Level',      'field' => 'level_name'],
            ['label' => 'Message',    'field' => 'message'],
            ['label' => 'User ID',    'field' => 'context.user_id'],
            ['label' => 'URL',        'field' => 'context.url'],
            ['label' => 'Request ID', 'field' => 'context.request_id'],
        ]);

        $app['config']->set('cloudwatch-viewer.context_fields', [
            'context.request_id',
            'context.url',
            'context.user_id',
        ]);
    }
}
