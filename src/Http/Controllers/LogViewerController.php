<?php

namespace Weboccult\CloudWatchViewer\Http\Controllers;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;

class LogViewerController extends Controller
{
    public function index(): \Illuminate\View\View
    {
        $logGroups = $this->getEnabledGroups();
        $columns   = config('cloudwatch-viewer.columns', []);

        return view('cloudwatch-viewer::index', compact('logGroups', 'columns'));
    }

    public function fetch(Request $request): JsonResponse
    {
        $submittedGroups = $request->input('log_groups', []);

        if (empty($submittedGroups)) {
            return response()->json(['error' => 'At least one log group must be selected.'], 422);
        }

        // Security: only allow groups defined in config
        $allowedValues = array_column($this->getEnabledGroups(), 'value');
        $sanitizedGroups = array_values(array_intersect($submittedGroups, $allowedValues));

        if (empty($sanitizedGroups)) {
            return response()->json(['error' => 'No valid log groups selected.'], 422);
        }

        $queryString = $this->buildQueryString($request);

        $startTime = $this->resolveStartTime($request);
        $endTime = $this->resolveEndTime($request);

        $client = $this->makeClient();

        $params = [
            'queryString' => $queryString,
            'startTime' => $startTime,
            'endTime' => $endTime,
            'limit' => (int) config('cloudwatch-viewer.query_limit', 500),
        ];

        if (count($sanitizedGroups) === 1) {
            $params['logGroupName'] = $sanitizedGroups[0];
        } else {
            $params['logGroupNames'] = $sanitizedGroups;
        }

        $execute = fn () => $this->executeInsightsQuery($client, $params);

        $cacheEnabled = config('cloudwatch-viewer.cache.enabled', false);

        if ($cacheEnabled) {
            $cacheKey = 'cwv:' . md5(implode(',', $sanitizedGroups) . $queryString . $startTime . $endTime);
            $cacheTtl = (int) config('cloudwatch-viewer.cache.ttl', 300);
            $store    = config('cloudwatch-viewer.cache.store');
            $payload  = Cache::store($store)->remember($cacheKey, $cacheTtl, $execute);
        } else {
            $payload = $execute();
        }

        if (isset($payload['error'])) {
            return response()->json(['error' => $payload['error']], 500);
        }

        return response()->json($payload);
    }

    protected function pollIntervalSeconds(): int { return 1; }
    protected function maxPollAttempts(): int { return 10; }

    protected function executeInsightsQuery(CloudWatchLogsClient $client, array $params): array
    {
        try {
            $queryId = $client->startQuery($params)['queryId'];
        } catch (\Throwable $e) {
            return ['error' => 'Failed to start CloudWatch query: ' . $e->getMessage()];
        }

        $logs   = [];
        $status = 'Unknown';

        for ($attempt = 0; $attempt < $this->maxPollAttempts(); $attempt++) {
            sleep($this->pollIntervalSeconds());

            try {
                $result = $client->getQueryResults(['queryId' => $queryId]);
            } catch (\Throwable $e) {
                return ['error' => 'Failed to retrieve query results: ' . $e->getMessage()];
            }

            $status = $result['status'] ?? 'Unknown';

            if (! in_array($status, ['Running', 'Scheduled'])) {
                $logs = $this->flattenResults($result['results'] ?? []);
                break;
            }
        }

        return ['logs' => $logs, 'count' => count($logs), 'status' => $status];
    }

    protected function buildQueryString(Request $request): string
    {
        $filters = [];

        $level = $request->input('level', 'ALL');
        if ($level !== 'ALL') {
            $safeLevel = addslashes($level);
            $filters[] = "level_name = \"{$safeLevel}\"";
        }

        if ($message = $request->input('message')) {
            $safe = addslashes($message);
            $filters[] = "message like \"{$safe}\"";
        }

        if ($userId = $request->input('user_id')) {
            $safe = addslashes($userId);
            $filters[] = "context.user_id = \"{$safe}\"";
        }

        if ($requestId = $request->input('request_id')) {
            $safe = addslashes($requestId);
            $filters[] = "context.request_id = \"{$safe}\"";
        }

        if ($url = $request->input('url')) {
            $safe = addslashes($url);
            $filters[] = "context.url like \"{$safe}\"";
        }

        if ($request->boolean('has_context')) {
            $contextFields = config('cloudwatch-viewer.context_fields', ['context.request_id', 'context.url', 'context.user_id']);
            $checks = implode(' or ', array_map(fn ($f) => "ispresent({$f})", $contextFields));
            $filters[] = "({$checks})";
        }

        $configFields = config('cloudwatch-viewer.fields', []);

        if (! in_array('@timestamp', $configFields)) {
            array_unshift($configFields, '@timestamp');
        }

        $fields = implode(', ', $configFields);

        $queryString = "fields {$fields}";

        if (!empty($filters)) {
            $queryString .= "\n| filter " . implode(' and ', $filters);
        }

        $limit = (int) config('cloudwatch-viewer.query_limit', 500);
        $queryString .= "\n| sort @timestamp desc";
        $queryString .= "\n| limit {$limit}";

        return $queryString;
    }

    protected function resolveStartTime(Request $request): int
    {
        // Prefer a UTC Unix timestamp sent directly from the JS timezone converter
        if ($ts = $request->input('start_ts')) {
            return (int) $ts;
        }

        // Fallback: plain date string (interpreted as server timezone)
        if ($startDate = $request->input('start_date')) {
            $ts = strtotime($startDate);
            if ($ts !== false) {
                return $ts;
            }
        }

        $defaultHours = (int) config('cloudwatch-viewer.default_hours', 24);

        return time() - ($defaultHours * 3600);
    }

    protected function resolveEndTime(Request $request): int
    {
        // Prefer a UTC Unix timestamp sent directly from the JS timezone converter
        if ($ts = $request->input('end_ts')) {
            return (int) $ts;
        }

        // Fallback: plain date string (interpreted as server timezone)
        if ($endDate = $request->input('end_date')) {
            $ts = strtotime($endDate);
            if ($ts !== false) {
                return $ts;
            }
        }

        return time();
    }

    protected function flattenResults(array $results): array
    {
        $logs = [];

        foreach ($results as $result) {
            $entry = [];
            foreach ($result as $field) {
                $entry[$field['field']] = $field['value'];
            }
            $logs[] = $entry;
        }

        return $logs;
    }

    public function live(Request $request): JsonResponse
    {
        $submittedGroups = $request->input('log_groups', []);

        if (empty($submittedGroups)) {
            return response()->json(['error' => 'At least one log group must be selected.'], 422);
        }

        $allowedValues   = array_column($this->getEnabledGroups(), 'value');
        $sanitizedGroups = array_values(array_intersect($submittedGroups, $allowedValues));

        if (empty($sanitizedGroups)) {
            return response()->json(['error' => 'No valid log groups selected.'], 422);
        }

        // start_ts_ms is a millisecond cursor managed entirely in ms to avoid
        // precision loss from seconds rounding at page boundaries
        $startTimeMs = $request->input('start_ts_ms')
            ? (int) $request->input('start_ts_ms')
            : (time() - 60) * 1000;

        $endTimeMs     = time() * 1000;
        $filterPattern = $this->buildLiveFilterPattern($request);
        $client        = $this->makeClient();
        $events        = [];
        $nextStartMs   = $startTimeMs;
        $errors        = [];

        foreach ($sanitizedGroups as $group) {
            $params = [
                'logGroupName' => $group,
                'startTime'    => $startTimeMs,
                'endTime'      => $endTimeMs,
                'limit'        => 100,
            ];

            if ($filterPattern) {
                $params['filterPattern'] = $filterPattern;
            }

            try {
                // Paginate with nextToken until all events in the window are fetched
                do {
                    $result = $client->filterLogEvents($params);

                    foreach ($result['events'] as $event) {
                        $events[] = $this->parseLogEvent($event);

                        if ($event['timestamp'] >= $nextStartMs) {
                            $nextStartMs = $event['timestamp'] + 1;
                        }
                    }

                    $params['nextToken'] = $result['nextToken'] ?? null;
                } while (! empty($params['nextToken']));

            } catch (\Throwable $e) {
                $errors[] = "[{$group}] " . $e->getMessage();
            }
        }

        if (! empty($errors) && empty($events)) {
            return response()->json(['error' => implode(' | ', $errors)], 500);
        }

        // Apply has_context filter server-side
        if ($request->boolean('has_context')) {
            $contextFields = config('cloudwatch-viewer.context_fields', ['context.request_id', 'context.url', 'context.user_id']);
            $events = array_values(array_filter($events, function ($e) use ($contextFields) {
                foreach ($contextFields as $field) {
                    if (! empty($e[$field])) return true;
                }
                return false;
            }));
        }

        // Sort newest first (same as Insights output)
        usort($events, fn ($a, $b) => ($b['_raw_timestamp'] ?? 0) <=> ($a['_raw_timestamp'] ?? 0));

        // Strip internal tracking field before sending to client
        $events = array_map(function ($e) {
            unset($e['_raw_timestamp']);
            return $e;
        }, $events);

        return response()->json([
            'events'       => array_values($events),
            'next_start_ms' => $nextStartMs,  // milliseconds — no precision lost
            'warnings'     => $errors,         // partial failures surfaced to UI
        ]);
    }

    protected function parseLogEvent(array $event): array
    {
        $entry = [
            '@timestamp'     => gmdate('Y-m-d H:i:s', (int) ($event['timestamp'] / 1000)),
            '@logStream'     => $event['logStreamName'],
            '_raw_timestamp' => $event['timestamp'],
        ];

        $decoded = json_decode(trim($event['message']), true);

        if (is_array($decoded)) {
            // Flatten nested JSON into dot-notation keys (e.g. context.user_id)
            // then pick only the fields declared in config so the response stays lean
            $flat         = $this->flattenArray($decoded);
            $configFields = config('cloudwatch-viewer.fields', []);

            foreach ($configFields as $field) {
                if (array_key_exists($field, $flat)) {
                    $entry[$field] = $flat[$field];
                }
            }

            // Ensure message always has a value
            if (empty($entry['message'])) {
                $entry['message'] = $decoded['message'] ?? $event['message'];
            }

            // If the log record carries its own high-precision datetime, prefer it
            // over the CloudWatch event timestamp (common with Monolog, Laravel, etc.)
            if (! empty($decoded['datetime'])) {
                $ts = strtotime($decoded['datetime']);
                if ($ts !== false) {
                    $entry['@timestamp'] = gmdate('Y-m-d H:i:s', $ts);
                }
            }
        } else {
            // Non-JSON log line — surface the raw message
            $entry['message'] = trim($event['message']);
        }

        return $entry;
    }

    /**
     * Recursively flatten a nested array into dot-notation keys.
     * ['context' => ['user_id' => 1]] → ['context.user_id' => 1]
     */
    protected function flattenArray(array $array, string $prefix = ''): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            $fullKey = $prefix !== '' ? "{$prefix}.{$key}" : (string) $key;

            if (is_array($value) && ! empty($value)) {
                $result += $this->flattenArray($value, $fullKey);
            } else {
                $result[$fullKey] = $value;
            }
        }

        return $result;
    }

    protected function buildLiveFilterPattern(Request $request): ?string
    {
        $conditions = [];

        $level = $request->input('level', 'ALL');
        if ($level !== 'ALL') {
            $conditions[] = '$.level_name = "' . addslashes($level) . '"';
        }

        if ($message = $request->input('message')) {
            $conditions[] = '$.message = "*' . addslashes($message) . '*"';
        }

        if ($userId = $request->input('user_id')) {
            $conditions[] = '$.context.user_id = "' . addslashes($userId) . '"';
        }

        if ($requestId = $request->input('request_id')) {
            $conditions[] = '$.context.request_id = "' . addslashes($requestId) . '"';
        }

        if ($url = $request->input('url')) {
            $conditions[] = '$.context.url = "*' . addslashes($url) . '*"';
        }

        if (empty($conditions)) {
            return null;
        }

        return '{ ' . implode(' && ', $conditions) . ' }';
    }

    protected function makeClient(): CloudWatchLogsClient
    {
        $config = [
            'region' => config('cloudwatch-viewer.region', 'us-east-1'),
            'version' => 'latest',
        ];

        $authBy = config('cloudwatch-viewer.auth_by', 'iam');

        if ($authBy === 'credentials') {
            $config['credentials'] = config('cloudwatch-viewer.credentials');
        } elseif ($authBy === 'profile') {
            $config['profile'] = config('cloudwatch-viewer.profile');
        }
        // 'iam': no credentials set — SDK uses instance/task role automatically

        return new CloudWatchLogsClient($config);
    }

    protected function getEnabledGroups(): array
    {
        $groups = config('cloudwatch-viewer.groups', []);

        return array_values(array_filter($groups, fn($g) => ($g['enabled'] ?? true) === true));
    }
}
