<?php

namespace Weboccult\CloudWatchViewer\Tests\Unit;

use Weboccult\CloudWatchViewer\Http\Controllers\LogViewerController;
use Weboccult\CloudWatchViewer\Tests\TestCase;
use Illuminate\Http\Request;

/**
 * Exposes protected buildQueryString() for direct unit testing.
 */
class InspectableController extends LogViewerController
{
    public function callBuildQueryString(Request $request): string
    {
        return $this->buildQueryString($request);
    }

    public function callBuildLiveFilterPattern(Request $request): ?string
    {
        return $this->buildLiveFilterPattern($request);
    }

    public function callFlattenArray(array $array, string $prefix = ''): array
    {
        return $this->flattenArray($array, $prefix);
    }

    public function callParseLogEvent(array $event): array
    {
        return $this->parseLogEvent($event);
    }

    public function callGetEnabledGroups(): array
    {
        return $this->getEnabledGroups();
    }
}

class QueryStringTest extends TestCase
{
    private InspectableController $ctrl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctrl = new InspectableController();
    }

    private function makeRequest(array $params = []): Request
    {
        return Request::create('/fetch', 'GET', $params);
    }

    // ── Fields clause ───────────────────────────────────────────────────────

    public function test_fields_clause_includes_all_configured_fields(): void
    {
        $query = $this->ctrl->callBuildQueryString($this->makeRequest());

        $this->assertStringContainsString('fields ', $query);
        $this->assertStringContainsString('@timestamp', $query);
        $this->assertStringContainsString('level_name', $query);
        $this->assertStringContainsString('message', $query);
    }

    public function test_timestamp_always_injected_even_if_missing_from_config(): void
    {
        config(['cloudwatch-viewer.fields' => ['message', 'level_name']]); // no @timestamp

        $query = $this->ctrl->callBuildQueryString($this->makeRequest());

        $this->assertStringContainsString('@timestamp', $query);
    }

    public function test_sort_and_limit_always_appended(): void
    {
        $query = $this->ctrl->callBuildQueryString($this->makeRequest());

        $this->assertStringContainsString('sort @timestamp desc', $query);
        $this->assertStringContainsString('limit 500', $query);
    }

    public function test_limit_reflects_config(): void
    {
        config(['cloudwatch-viewer.query_limit' => 100]);

        $query = $this->ctrl->callBuildQueryString($this->makeRequest());

        $this->assertStringContainsString('limit 100', $query);
    }

    // ── Filters ─────────────────────────────────────────────────────────────

    public function test_no_filter_clause_when_no_filters_applied(): void
    {
        $query = $this->ctrl->callBuildQueryString($this->makeRequest(['level' => 'ALL']));

        $this->assertStringNotContainsString('| filter', $query);
    }

    public function test_level_filter_is_applied(): void
    {
        $query = $this->ctrl->callBuildQueryString($this->makeRequest(['level' => 'ERROR']));

        $this->assertStringContainsString('| filter', $query);
        $this->assertStringContainsString('level_name = "ERROR"', $query);
    }

    public function test_message_filter_is_applied(): void
    {
        $query = $this->ctrl->callBuildQueryString($this->makeRequest(['message' => 'payment failed']));

        $this->assertStringContainsString('message like "payment failed"', $query);
    }

    public function test_user_id_filter_is_applied(): void
    {
        $query = $this->ctrl->callBuildQueryString($this->makeRequest(['user_id' => '42']));

        $this->assertStringContainsString('context.user_id = "42"', $query);
    }

    public function test_request_id_filter_is_applied(): void
    {
        $query = $this->ctrl->callBuildQueryString($this->makeRequest(['request_id' => 'abc-123']));

        $this->assertStringContainsString('context.request_id = "abc-123"', $query);
    }

    public function test_url_filter_is_applied(): void
    {
        $query = $this->ctrl->callBuildQueryString($this->makeRequest(['url' => '/api/orders']));

        $this->assertStringContainsString('context.url like "/api/orders"', $query);
    }

    public function test_multiple_filters_joined_with_and(): void
    {
        $query = $this->ctrl->callBuildQueryString($this->makeRequest([
            'level'   => 'ERROR',
            'message' => 'timeout',
        ]));

        $this->assertStringContainsString(' and ', $query);
    }

    public function test_has_context_filter_uses_ispresent_on_config_fields(): void
    {
        $query = $this->ctrl->callBuildQueryString($this->makeRequest(['has_context' => '1']));

        $this->assertStringContainsString('ispresent(context.request_id)', $query);
        $this->assertStringContainsString('ispresent(context.url)', $query);
        $this->assertStringContainsString('ispresent(context.user_id)', $query);
    }

    public function test_has_context_filter_uses_custom_context_fields_from_config(): void
    {
        config(['cloudwatch-viewer.context_fields' => ['my_field', 'another_field']]);

        $query = $this->ctrl->callBuildQueryString($this->makeRequest(['has_context' => '1']));

        $this->assertStringContainsString('ispresent(my_field)', $query);
        $this->assertStringContainsString('ispresent(another_field)', $query);
        $this->assertStringNotContainsString('ispresent(context.request_id)', $query);
    }

    // ── Live filter pattern ─────────────────────────────────────────────────

    public function test_live_filter_pattern_is_null_with_no_filters(): void
    {
        $pattern = $this->ctrl->callBuildLiveFilterPattern($this->makeRequest());

        $this->assertNull($pattern);
    }

    public function test_live_filter_pattern_wraps_conditions_in_braces(): void
    {
        $pattern = $this->ctrl->callBuildLiveFilterPattern($this->makeRequest(['level' => 'ERROR']));

        $this->assertStringStartsWith('{', $pattern);
        $this->assertStringEndsWith('}', $pattern);
        $this->assertStringContainsString('$.level_name = "ERROR"', $pattern);
    }

    public function test_live_filter_pattern_multiple_conditions_use_double_ampersand(): void
    {
        $pattern = $this->ctrl->callBuildLiveFilterPattern($this->makeRequest([
            'level'   => 'ERROR',
            'user_id' => '99',
        ]));

        $this->assertStringContainsString('&&', $pattern);
    }

    public function test_live_filter_pattern_message_uses_wildcards(): void
    {
        $pattern = $this->ctrl->callBuildLiveFilterPattern($this->makeRequest(['message' => 'timeout']));

        $this->assertStringContainsString('$.message = "*timeout*"', $pattern);
    }

    // ── Enabled groups ──────────────────────────────────────────────────────

    public function test_get_enabled_groups_excludes_disabled_groups(): void
    {
        $groups = $this->ctrl->callGetEnabledGroups();
        $values = array_column($groups, 'value');

        $this->assertContains('/aws/app/production', $values);
        $this->assertContains('/aws/app/staging',    $values);
        $this->assertNotContains('/aws/app/disabled', $values);
    }

    public function test_get_enabled_groups_returns_empty_array_when_none_configured(): void
    {
        config(['cloudwatch-viewer.groups' => []]);

        $groups = $this->ctrl->callGetEnabledGroups();

        $this->assertSame([], $groups);
    }
}
