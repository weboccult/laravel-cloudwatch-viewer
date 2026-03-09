<?php

namespace Weboccult\CloudWatchViewer\Tests\Fakes;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Weboccult\CloudWatchViewer\Http\Controllers\LogViewerController;

class FakeLogViewerController extends LogViewerController
{
    private CloudWatchLogsClient $fakeClient;

    public function setClient(CloudWatchLogsClient $client): void
    {
        $this->fakeClient = $client;
    }

    protected function makeClient(): CloudWatchLogsClient
    {
        return $this->fakeClient;
    }

    // No sleeping in tests
    protected function pollIntervalSeconds(): int { return 0; }
    protected function maxPollAttempts(): int { return 3; }
}
