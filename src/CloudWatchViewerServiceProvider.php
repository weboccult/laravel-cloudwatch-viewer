<?php

namespace Weboccult\CloudWatchViewer;

use Illuminate\Support\ServiceProvider;

class CloudWatchViewerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/cloudwatch-viewer.php',
            'cloudwatch-viewer'
        );
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'cloudwatch-viewer');

        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/cloudwatch-viewer.php' => config_path('cloudwatch-viewer.php'),
            ], 'cloudwatch-viewer-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/cloudwatch-viewer'),
            ], 'cloudwatch-viewer-views');
        }
    }
}
