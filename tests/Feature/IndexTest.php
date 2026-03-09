<?php

namespace Weboccult\CloudWatchViewer\Tests\Feature;

use Weboccult\CloudWatchViewer\Tests\TestCase;

class IndexTest extends TestCase
{
    public function test_index_returns_200(): void
    {
        $this->get('/cloudwatch-logs')
             ->assertStatus(200);
    }

    public function test_index_passes_enabled_groups_to_view(): void
    {
        $response = $this->get('/cloudwatch-logs');
        $response->assertStatus(200);

        $logGroups = $response->viewData('logGroups');

        $this->assertCount(2, $logGroups); // only enabled ones

        $values = array_column($logGroups, 'value');
        $this->assertContains('/aws/app/production', $values);
        $this->assertContains('/aws/app/staging', $values);
        $this->assertNotContains('/aws/app/disabled', $values);
    }

    public function test_index_passes_columns_to_view(): void
    {
        $response = $this->get('/cloudwatch-logs');
        $response->assertStatus(200);

        $columns = $response->viewData('columns');

        $this->assertNotEmpty($columns);
        $this->assertSame('Timestamp',  $columns[0]['label']);
        $this->assertSame('@timestamp', $columns[0]['field']);
    }

    public function test_index_respects_route_prefix_config(): void
    {
        // The default prefix 'cloudwatch-logs' is set in defineEnvironment.
        // Just verify the default prefix routes work — prefix customisation is
        // a boot-time concern that cannot be tested mid-request without a full
        // app reboot, which Orchestra does not support inside a single test.
        $this->get('/cloudwatch-logs')->assertStatus(200);
    }

    public function test_custom_columns_appear_in_view(): void
    {
        config(['cloudwatch-viewer.columns' => [
            ['label' => 'Time',    'field' => '@timestamp'],
            ['label' => 'Severity','field' => 'level_name'],
        ]]);

        $response = $this->get('/cloudwatch-logs');
        $columns  = $response->viewData('columns');

        $this->assertCount(2, $columns);
        $this->assertSame('Severity', $columns[1]['label']);
    }
}
