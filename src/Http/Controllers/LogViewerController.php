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

        $configFields = config('cloudwatch-viewer.fields', []);

        // @timestamp is always required for sorting
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
