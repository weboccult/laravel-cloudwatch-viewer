<?php

namespace Adiechahk\CloudWatchViewer\Http\Controllers;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

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

        try {
            $startResult = $client->startQuery($params);
            $queryId = $startResult['queryId'];
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Failed to start CloudWatch query: ' . $e->getMessage()], 500);
        }

        $logs = [];
        $status = 'Unknown';

        for ($attempt = 0; $attempt < 10; $attempt++) {
            sleep(1);

            try {
                $result = $client->getQueryResults(['queryId' => $queryId]);
            } catch (\Throwable $e) {
                return response()->json(['error' => 'Failed to retrieve query results: ' . $e->getMessage()], 500);
            }

            $status = $result['status'] ?? 'Unknown';

            if (!in_array($status, ['Running', 'Scheduled'])) {
                $logs = $this->flattenResults($result['results'] ?? []);
                break;
            }
        }

        return response()->json([
            'logs' => $logs,
            'count' => count($logs),
            'status' => $status,
        ]);
    }

    private function buildQueryString(Request $request): string
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
            $filters[] = '(ispresent(context.request_id) or ispresent(context.url) or ispresent(context.user_id))';
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

    private function resolveStartTime(Request $request): int
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

    private function resolveEndTime(Request $request): int
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

    private function flattenResults(array $results): array
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
            $events = array_values(array_filter($events, fn ($e) =>
                ! empty($e['context.request_id'])
                || ! empty($e['context.url'])
                || ! empty($e['context.user_id'])
            ));
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

    private function parseLogEvent(array $event): array
    {
        $entry = [
            '@timestamp'     => gmdate('Y-m-d H:i:s', (int) ($event['timestamp'] / 1000)),
            '@logStream'     => $event['logStreamName'],
            '_raw_timestamp' => $event['timestamp'],
        ];

        $decoded = json_decode(trim($event['message']), true);

        if (is_array($decoded)) {
            $entry['level_name']          = $decoded['level_name'] ?? null;
            $entry['message']             = $decoded['message'] ?? $event['message'];
            $entry['context.request_id']  = $decoded['context']['request_id'] ?? null;
            $entry['context.user_id']     = $decoded['context']['user_id'] ?? null;
            $entry['context.url']         = $decoded['context']['url'] ?? null;
            $entry['context.method']      = $decoded['context']['method'] ?? null;
            $entry['context.ip']          = $decoded['context']['ip'] ?? null;
            $entry['context.environment'] = $decoded['context']['environment'] ?? null;

            // Use the log's own datetime if present (more precise than event timestamp)
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

    private function buildLiveFilterPattern(Request $request): ?string
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

    private function makeClient(): CloudWatchLogsClient
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

    private function getEnabledGroups(): array
    {
        $groups = config('cloudwatch-viewer.groups', []);

        return array_values(array_filter($groups, fn($g) => ($g['enabled'] ?? true) === true));
    }
}
