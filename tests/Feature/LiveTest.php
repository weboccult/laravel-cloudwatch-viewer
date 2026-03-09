<?php

namespace Weboccult\CloudWatchViewer\Tests\Feature;

use Weboccult\CloudWatchViewer\Http\Controllers\LogViewerController;
use Weboccult\CloudWatchViewer\Tests\Fakes\FakeLogViewerController;
use Weboccult\CloudWatchViewer\Tests\TestCase;
use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;

class LiveTest extends TestCase
{
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

    private function makeCloudWatchEvent(string $message, int $timestampMs = 0): array
    {
        return [
            'timestamp'     => $timestampMs ?: (time() * 1000),
            'message'       => $message,
            'logStreamName' => 'my-log-stream',
            'ingestionTime' => time() * 1000,
            'eventId'       => 'event-' . rand(1000, 9999),
        ];
    }

    // ── Validation ─────────────────────────────────────────────────────────

    public function test_returns_422_when_no_log_groups(): void
    {
        $this->getJson('/cloudwatch-logs/live')
             ->assertStatus(422)
             ->assertJsonFragment(['error' => 'At least one log group must be selected.']);
    }

    public function test_returns_422_when_group_not_in_config(): void
    {
        $this->getJson('/cloudwatch-logs/live?log_groups[]=/evil/logs')
             ->assertStatus(422)
             ->assertJsonFragment(['error' => 'No valid log groups selected.']);
    }

    public function test_returns_422_when_group_is_disabled(): void
    {
        $this->getJson('/cloudwatch-logs/live?log_groups[]=/aws/app/disabled')
             ->assertStatus(422)
             ->assertJsonFragment(['error' => 'No valid log groups selected.']);
    }

    // ── Success ────────────────────────────────────────────────────────────

    public function test_returns_events_from_filter_log_events(): void
    {
        $tsMs = time() * 1000;

        $this->bindFakeController([
            new Result([
                'events'    => [
                    $this->makeCloudWatchEvent(
                        json_encode(['level_name' => 'ERROR', 'message' => 'Live error log']),
                        $tsMs
                    ),
                ],
                'nextToken' => null,
                'searchedLogStreams' => [],
            ]),
        ]);

        $response = $this->getJson('/cloudwatch-logs/live?log_groups[]=/aws/app/production&start_ts_ms=' . ($tsMs - 5000));

        $response->assertStatus(200)
                 ->assertJsonStructure(['events', 'next_start_ms', 'warnings'])
                 ->assertJsonCount(1, 'events')
                 ->assertJsonPath('events.0.message', 'Live error log')
                 ->assertJsonPath('events.0.level_name', 'ERROR');
    }

    public function test_next_start_ms_is_one_ms_after_last_event(): void
    {
        $tsMs     = 1735689600000;
        $startMs  = $tsMs - 5000; // non-zero so PHP truthy check passes

        $this->bindFakeController([
            new Result([
                'events'   => [$this->makeCloudWatchEvent('{"message":"test"}', $tsMs)],
                'nextToken' => null,
            ]),
        ]);

        $response = $this->getJson('/cloudwatch-logs/live?log_groups[]=/aws/app/production&start_ts_ms=' . $startMs);

        $response->assertStatus(200)
                 ->assertJsonPath('next_start_ms', $tsMs + 1);
    }

    public function test_returns_empty_events_when_no_new_logs(): void
    {
        $this->bindFakeController([
            new Result(['events' => [], 'nextToken' => null]),
        ]);

        $response = $this->getJson('/cloudwatch-logs/live?log_groups[]=/aws/app/production');

        $response->assertStatus(200)
                 ->assertJsonCount(0, 'events')
                 ->assertJsonPath('warnings', []);
    }

    public function test_has_context_filter_excludes_logs_without_context(): void
    {
        $tsMs = time() * 1000;

        $this->bindFakeController([
            new Result([
                'events' => [
                    $this->makeCloudWatchEvent(
                        json_encode(['level_name' => 'INFO', 'message' => 'With context', 'context' => ['user_id' => 42]]),
                        $tsMs
                    ),
                    $this->makeCloudWatchEvent(
                        json_encode(['level_name' => 'DEBUG', 'message' => 'No context']),
                        $tsMs + 1
                    ),
                ],
                'nextToken' => null,
            ]),
        ]);

        $response = $this->getJson('/cloudwatch-logs/live?log_groups[]=/aws/app/production&has_context=1&start_ts_ms=0');

        $response->assertStatus(200)
                 ->assertJsonCount(1, 'events')
                 ->assertJsonPath('events.0.message', 'With context');
    }

    public function test_events_are_sorted_newest_first(): void
    {
        $now = time() * 1000;

        $this->bindFakeController([
            new Result([
                'events' => [
                    $this->makeCloudWatchEvent(json_encode(['message' => 'older']), $now),
                    $this->makeCloudWatchEvent(json_encode(['message' => 'newer']), $now + 5000),
                ],
                'nextToken' => null,
            ]),
        ]);

        $response = $this->getJson('/cloudwatch-logs/live?log_groups[]=/aws/app/production&start_ts_ms=0');

        $response->assertStatus(200);
        $events = $response->json('events');
        $this->assertSame('newer', $events[0]['message']);
        $this->assertSame('older', $events[1]['message']);
    }

    public function test_raw_timestamp_is_not_returned_to_client(): void
    {
        $this->bindFakeController([
            new Result([
                'events'   => [$this->makeCloudWatchEvent(json_encode(['message' => 'test']), time() * 1000)],
                'nextToken' => null,
            ]),
        ]);

        $response = $this->getJson('/cloudwatch-logs/live?log_groups[]=/aws/app/production&start_ts_ms=0');

        $events = $response->json('events');
        $this->assertArrayNotHasKey('_raw_timestamp', $events[0]);
    }

    // ── Error handling ─────────────────────────────────────────────────────

    public function test_returns_500_when_all_groups_fail(): void
    {
        $handler = new MockHandler();
        $handler->append(function ($cmd) {
            throw new AwsException('AccessDeniedException', $cmd);
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

        $this->getJson('/cloudwatch-logs/live?log_groups[]=/aws/app/production')
             ->assertStatus(500)
             ->assertJsonStructure(['error']);
    }

    public function test_returns_partial_results_with_warnings_when_one_group_fails(): void
    {
        // Two groups selected; first succeeds, second fails.
        // Result: events from first group, warnings array non-empty, HTTP 200.
        $tsMs = time() * 1000;

        $handler = new MockHandler();
        // First call (production group) succeeds
        $handler->append(new Result([
            'events'   => [$this->makeCloudWatchEvent(json_encode(['message' => 'ok log']), $tsMs)],
            'nextToken' => null,
        ]));
        // Second call (staging group) throws
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

        $response = $this->getJson(
            '/cloudwatch-logs/live?log_groups[]=/aws/app/production&log_groups[]=/aws/app/staging&start_ts_ms=0'
        );

        $response->assertStatus(200)
                 ->assertJsonCount(1, 'events')
                 ->assertJsonPath('events.0.message', 'ok log');

        $this->assertNotEmpty($response->json('warnings'));
    }

    // ── Pagination ─────────────────────────────────────────────────────────

    public function test_paginates_with_next_token(): void
    {
        $tsMs = time() * 1000;

        // First page returns nextToken, second page doesn't
        $this->bindFakeController([
            new Result([
                'events'    => [$this->makeCloudWatchEvent(json_encode(['message' => 'page1']), $tsMs)],
                'nextToken' => 'token-page-2',
            ]),
            new Result([
                'events'    => [$this->makeCloudWatchEvent(json_encode(['message' => 'page2']), $tsMs + 1)],
                'nextToken' => null,
            ]),
        ]);

        $response = $this->getJson('/cloudwatch-logs/live?log_groups[]=/aws/app/production&start_ts_ms=0');

        $response->assertStatus(200)
                 ->assertJsonCount(2, 'events'); // both pages merged
    }
}
