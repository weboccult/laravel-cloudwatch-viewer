<?php

namespace Weboccult\CloudWatchViewer\Tests\Feature;

use Weboccult\CloudWatchViewer\Http\Controllers\LogViewerController;
use Weboccult\CloudWatchViewer\Tests\Fakes\FakeLogViewerController;
use Weboccult\CloudWatchViewer\Tests\TestCase;
use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;

class FetchTest extends TestCase
{
    // ── Helpers ────────────────────────────────────────────────────────────

    private function bindFakeController(array $mockResponses): void
    {
        $handler = new MockHandler();
        foreach ($mockResponses as $response) {
            $handler->append($response);
        }

        $client = new CloudWatchLogsClient([
            'region'      => 'us-east-1',
            'version'     => 'latest',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler'     => $handler,
        ]);

        $fake = new FakeLogViewerController();
        $fake->setClient($client);

        $this->app->bind(LogViewerController::class, fn () => $fake);
    }

    private function defaultFetchParams(array $override = []): array
    {
        return array_merge([
            'log_groups'  => ['/aws/app/production'],
            'level'       => 'ALL',
            'message'     => '',
            'user_id'     => '',
            'request_id'  => '',
            'url'         => '',
            'has_context' => '0',
            'start_ts'    => (string) (time() - 3600),
            'end_ts'      => (string) time(),
        ], $override);
    }

    private function makeLogResultRow(array $fields): array
    {
        return array_map(
            fn ($field, $value) => ['field' => $field, 'value' => $value],
            array_keys($fields),
            array_values($fields)
        );
    }

    // ── Validation ─────────────────────────────────────────────────────────

    public function test_returns_422_when_no_log_groups_submitted(): void
    {
        $this->getJson('/cloudwatch-logs/fetch')
             ->assertStatus(422)
             ->assertJsonFragment(['error' => 'At least one log group must be selected.']);
    }

    public function test_returns_422_when_submitted_group_not_in_config(): void
    {
        $this->getJson('/cloudwatch-logs/fetch?log_groups[]=/aws/hacker/logs')
             ->assertStatus(422)
             ->assertJsonFragment(['error' => 'No valid log groups selected.']);
    }

    public function test_returns_422_when_submitted_group_is_disabled(): void
    {
        $this->getJson('/cloudwatch-logs/fetch?log_groups[]=/aws/app/disabled')
             ->assertStatus(422)
             ->assertJsonFragment(['error' => 'No valid log groups selected.']);
    }

    public function test_strips_disallowed_groups_and_rejects_if_none_remain(): void
    {
        // Mix of a valid and an invalid group — invalid one stripped, valid one proceeds
        // But only the valid group should reach AWS. Here we just test the invalid-only case.
        $this->bindFakeController([
            new Result(['queryId' => 'qid-strip']),
            new Result(['status' => 'Complete', 'results' => []]),
        ]);

        $this->getJson('/cloudwatch-logs/fetch?log_groups[]=/aws/app/production&log_groups[]=/evil/group')
             ->assertStatus(200); // the evil group is stripped silently, valid one proceeds

        // But if ONLY invalid, must 422
        $this->getJson('/cloudwatch-logs/fetch?log_groups[]=/evil/group')
             ->assertStatus(422);
    }

    // ── Success ────────────────────────────────────────────────────────────

    public function test_returns_logs_on_successful_query(): void
    {
        $this->bindFakeController([
            new Result(['queryId' => 'qid-abc']),   // startQuery
            new Result([
                'status'  => 'Complete',
                'results' => [
                    $this->makeLogResultRow([
                        '@timestamp' => '2025-01-01 10:00:00',
                        'level_name' => 'ERROR',
                        'message'    => 'Something went wrong',
                    ]),
                ],
            ]),
        ]);

        $response = $this->getJson('/cloudwatch-logs/fetch?' . http_build_query($this->defaultFetchParams()));

        $response->assertStatus(200)
                 ->assertJsonStructure(['logs', 'count', 'status'])
                 ->assertJsonCount(1, 'logs')
                 ->assertJsonPath('logs.0.level_name', 'ERROR')
                 ->assertJsonPath('logs.0.message', 'Something went wrong')
                 ->assertJsonPath('count', 1)
                 ->assertJsonPath('status', 'Complete');
    }

    public function test_returns_empty_logs_when_query_has_no_results(): void
    {
        $this->bindFakeController([
            new Result(['queryId' => 'qid-empty']),
            new Result(['status' => 'Complete', 'results' => []]),
        ]);

        $response = $this->getJson('/cloudwatch-logs/fetch?' . http_build_query($this->defaultFetchParams()));

        $response->assertStatus(200)
                 ->assertJsonPath('count', 0)
                 ->assertJsonPath('logs', []);
    }

    public function test_polls_until_query_completes(): void
    {
        // First getQueryResults returns Running, second returns Complete
        $this->bindFakeController([
            new Result(['queryId' => 'qid-poll']),
            new Result(['status' => 'Running',  'results' => []]),
            new Result(['status' => 'Complete', 'results' => []]),
        ]);

        $response = $this->getJson('/cloudwatch-logs/fetch?' . http_build_query($this->defaultFetchParams()));

        $response->assertStatus(200)
                 ->assertJsonPath('status', 'Complete');
    }

    public function test_passes_multiple_groups_as_log_group_names(): void
    {
        // Just ensure no 422 (both groups are in config) and the request succeeds
        $this->bindFakeController([
            new Result(['queryId' => 'qid-multi']),
            new Result(['status' => 'Complete', 'results' => []]),
        ]);

        $params = $this->defaultFetchParams(['log_groups' => ['/aws/app/production', '/aws/app/staging']]);
        $this->getJson('/cloudwatch-logs/fetch?' . http_build_query($params))
             ->assertStatus(200);
    }

    // ── Error handling ─────────────────────────────────────────────────────

    public function test_returns_500_when_start_query_throws(): void
    {
        $handler = new MockHandler();
        $handler->append(function ($cmd) {
            throw new AwsException('Access Denied', $cmd);
        });

        $client = new CloudWatchLogsClient([
            'region'      => 'us-east-1',
            'version'     => 'latest',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler'     => $handler,
        ]);

        $fake = new FakeLogViewerController();
        $fake->setClient($client);
        $this->app->bind(LogViewerController::class, fn () => $fake);

        $response = $this->getJson('/cloudwatch-logs/fetch?' . http_build_query($this->defaultFetchParams()));

        $response->assertStatus(500)
                 ->assertJsonStructure(['error']);
    }

    public function test_returns_500_when_get_query_results_throws(): void
    {
        $handler = new MockHandler();
        $handler->append(new Result(['queryId' => 'qid-err']));
        $handler->append(function ($cmd) {
            throw new AwsException('Throttled', $cmd);
        });

        $client = new CloudWatchLogsClient([
            'region'      => 'us-east-1',
            'version'     => 'latest',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler'     => $handler,
        ]);

        $fake = new FakeLogViewerController();
        $fake->setClient($client);
        $this->app->bind(LogViewerController::class, fn () => $fake);

        $response = $this->getJson('/cloudwatch-logs/fetch?' . http_build_query($this->defaultFetchParams()));

        $response->assertStatus(500)
                 ->assertJsonStructure(['error']);
    }

    // ── Caching ────────────────────────────────────────────────────────────

    public function test_caching_is_skipped_when_disabled(): void
    {
        config(['cloudwatch-viewer.cache.enabled' => false]);

        $this->bindFakeController([
            new Result(['queryId' => 'qid-1']),
            new Result(['status' => 'Complete', 'results' => []]),
            new Result(['queryId' => 'qid-2']),
            new Result(['status' => 'Complete', 'results' => []]),
        ]);

        $params = $this->defaultFetchParams();
        // Both requests should hit AWS (handler has two pairs queued)
        $this->getJson('/cloudwatch-logs/fetch?' . http_build_query($params))->assertStatus(200);
        $this->getJson('/cloudwatch-logs/fetch?' . http_build_query($params))->assertStatus(200);
    }

    public function test_caching_serves_second_request_from_cache(): void
    {
        config([
            'cloudwatch-viewer.cache.enabled' => true,
            'cloudwatch-viewer.cache.ttl'     => 60,
            'cloudwatch-viewer.cache.store'   => null,
        ]);

        // Only queue ONE pair of responses — if caching works, second request won't hit AWS
        $this->bindFakeController([
            new Result(['queryId' => 'qid-cached']),
            new Result([
                'status'  => 'Complete',
                'results' => [
                    $this->makeLogResultRow(['@timestamp' => '2025-01-01', 'message' => 'cached log']),
                ],
            ]),
        ]);

        $params = $this->defaultFetchParams();
        $url    = '/cloudwatch-logs/fetch?' . http_build_query($params);

        $first  = $this->getJson($url);
        $second = $this->getJson($url); // would throw if AWS is called again (handler exhausted)

        $first->assertStatus(200);
        $second->assertStatus(200);
        $this->assertSame($first->json('logs'), $second->json('logs'));
    }
}
