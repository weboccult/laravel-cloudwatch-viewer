<?php

namespace Weboccult\CloudWatchViewer\Tests\Unit;

use Weboccult\CloudWatchViewer\Tests\TestCase;

class ParseLogEventTest extends TestCase
{
    private InspectableController $ctrl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctrl = new InspectableController();
    }

    private function makeEvent(string $message, int $timestampMs = 0): array
    {
        return [
            'timestamp'     => $timestampMs ?: (time() * 1000),
            'message'       => $message,
            'logStreamName' => 'my-stream',
        ];
    }

    // ── JSON log entries ────────────────────────────────────────────────────

    public function test_json_log_event_is_parsed_and_mapped_to_config_fields(): void
    {
        $entry = $this->ctrl->callParseLogEvent($this->makeEvent(json_encode([
            'level_name' => 'ERROR',
            'message'    => 'Test error',
        ])));

        $this->assertSame('ERROR',      $entry['level_name']);
        $this->assertSame('Test error', $entry['message']);
        $this->assertSame('my-stream',  $entry['@logStream']);
    }

    public function test_nested_context_fields_are_flattened_to_dot_notation(): void
    {
        $entry = $this->ctrl->callParseLogEvent($this->makeEvent(json_encode([
            'message' => 'Request in',
            'context' => [
                'user_id'    => 42,
                'request_id' => 'req-abc',
                'url'        => '/api/orders',
            ],
        ])));

        $this->assertSame(42,          $entry['context.user_id']);
        $this->assertSame('req-abc',   $entry['context.request_id']);
        $this->assertSame('/api/orders', $entry['context.url']);
    }

    public function test_only_config_fields_are_returned(): void
    {
        // Config has specific fields; extra fields in the log should NOT appear
        $entry = $this->ctrl->callParseLogEvent($this->makeEvent(json_encode([
            'level_name'  => 'INFO',
            'message'     => 'Hello',
            'secret_data' => 'should-not-appear',
            'extra_key'   => 'also-ignored',
        ])));

        $this->assertArrayNotHasKey('secret_data', $entry);
        $this->assertArrayNotHasKey('extra_key',   $entry);
        $this->assertArrayHasKey('level_name',     $entry);
    }

    public function test_datetime_field_overrides_timestamp(): void
    {
        $customDatetime = '2020-06-15 08:30:00';

        $entry = $this->ctrl->callParseLogEvent($this->makeEvent(json_encode([
            'message'  => 'test',
            'datetime' => $customDatetime,
        ])));

        // @timestamp should be based on the log's own datetime, not the CloudWatch event timestamp
        $this->assertStringContainsString('2020-06-15', $entry['@timestamp']);
    }

    public function test_message_falls_back_to_raw_when_not_in_json(): void
    {
        // JSON without a 'message' key — should still produce a message
        $entry = $this->ctrl->callParseLogEvent($this->makeEvent(json_encode([
            'level_name' => 'INFO',
            // no 'message' key
        ])));

        $this->assertArrayHasKey('message', $entry);
    }

    // ── Non-JSON log entries ────────────────────────────────────────────────

    public function test_non_json_log_event_returns_raw_message(): void
    {
        $raw   = 'Plain text log line without JSON structure';
        $entry = $this->ctrl->callParseLogEvent($this->makeEvent($raw));

        $this->assertSame($raw, $entry['message']);
    }

    public function test_timestamp_is_always_set_from_event(): void
    {
        $tsMs  = 1735689600000; // 2025-01-01 00:00:00 UTC
        $entry = $this->ctrl->callParseLogEvent($this->makeEvent('{}', $tsMs));

        $this->assertSame('2025-01-01 00:00:00', $entry['@timestamp']);
    }

    // ── flattenArray ────────────────────────────────────────────────────────

    public function test_flat_array_is_returned_unchanged(): void
    {
        $input  = ['key1' => 'val1', 'key2' => 'val2'];
        $result = $this->ctrl->callFlattenArray($input);

        $this->assertSame($input, $result);
    }

    public function test_nested_array_is_flattened_with_dot_separator(): void
    {
        $input  = ['context' => ['user_id' => 42, 'url' => '/api']];
        $result = $this->ctrl->callFlattenArray($input);

        $this->assertSame(42,    $result['context.user_id']);
        $this->assertSame('/api', $result['context.url']);
        $this->assertArrayNotHasKey('context', $result);
    }

    public function test_deeply_nested_array_is_fully_flattened(): void
    {
        $input  = ['a' => ['b' => ['c' => 'deep']]];
        $result = $this->ctrl->callFlattenArray($input);

        $this->assertSame('deep', $result['a.b.c']);
    }

    public function test_prefix_is_prepended_to_keys(): void
    {
        $result = $this->ctrl->callFlattenArray(['id' => 1], 'context');

        $this->assertArrayHasKey('context.id', $result);
        $this->assertSame(1, $result['context.id']);
    }

    public function test_empty_nested_array_is_not_expanded(): void
    {
        $input  = ['context' => [], 'message' => 'hello'];
        $result = $this->ctrl->callFlattenArray($input);

        // Empty arrays are stored as-is (not recursed into), but the key is preserved
        $this->assertArrayHasKey('context', $result);
        $this->assertSame([], $result['context']);
        $this->assertSame('hello', $result['message']);
    }

    public function test_scalar_values_are_preserved(): void
    {
        $input  = ['int' => 1, 'float' => 1.5, 'bool' => true, 'null' => null, 'str' => 'text'];
        $result = $this->ctrl->callFlattenArray($input);

        $this->assertSame(1,      $result['int']);
        $this->assertSame(1.5,    $result['float']);
        $this->assertTrue($result['bool']);
        $this->assertNull($result['null']);
        $this->assertSame('text', $result['str']);
    }
}
