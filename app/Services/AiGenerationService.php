<?php

namespace App\Services;

use App\Models\AiFeature;
use App\Models\DataSource;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

class AiGenerationService
{
    /**
     * Generate content using the specified AI feature and input.
     *
     * @param string $featureName
     * @param array $input
     * @return array
     * @throws InvalidArgumentException|GuzzleException
     */
    public function generate(string $featureName, array $input): array
    {
        // Fetch feature (for existence check)
        $feature = AiFeature::where('name', $featureName)
            ->where('is_active', true)
            ->firstOrFail();

        // Determine vendor and fetch config
        $vendor = Config::get('ai.default_vendor', 'vidu');
        $dataSource = DataSource::query()->whereLike('name', $vendor)->first();
        $dataSourceId = $dataSource->id;
        $config = $feature->configs()
            ->where('data_source_id', $dataSourceId)
            ->where('is_active', true)
            ->orderBy('priority')
            ->firstOrFail();

        // Validate input against the config's vendor-specific standard_schema
        $standardSchema = $config->standard_schema;
        $validator = Validator::make($input, [
            ...array_fill_keys($standardSchema['required'] ?? [], 'required|string'),  // Assume string for simplicity; customize as needed
            ...array_map(fn($field) => [$field => 'sometimes|string'], $standardSchema['optional'] ?? [])
        ]);

        if ($validator->fails()) {
            throw new InvalidArgumentException(
                'Invalid input for ' . $vendor . ' (' . $featureName . '): ' . json_encode($validator->errors())
            );
        }

        // Map input to service-specific params
        $serviceParams = array_merge(
            $config->default_params ?? [],
            $this->mapParams($input, $config->param_mapping ?? []),
            $config->extra_params ?? []
        );

        // Build request options
        $client = new Client();
        $options = [
            'method' => $config->method,
            'headers' => [
                $config->auth_header_key => $config->auth_type . ' ' . Config::get($config->auth_value_from_env),
                'Content-Type' => $config->content_type,
            ],
            'timeout' => 120,  // 2 min timeout for AI calls
        ];

        // Handle body format
        if ($config->body_format === 'json') {
            $options['json'] = $serviceParams;
        } elseif ($config->body_format === 'form-data') {
            $options['multipart'] = collect($serviceParams)->map(function ($value, $key) {
                return ['name' => $key, 'contents' => is_array($value) ? json_encode($value) : $value];
            })->values()->toArray();
        } else {
            // urlencoded fallback
            $options['form_params'] = $serviceParams;
        }

        // Send request
        $response = $client->request($config->method, $config->endpoint, $options);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Poll the status of a generation task.
     *
     * @param string $taskId
     * @param integer $vendorId
     * @param string $featureName
     * @return array
     * @throws GuzzleException
     */
    public function pollStatus(string $taskId, int $vendorId, string $featureName): array
    {
        $feature = AiFeature::where('name', $featureName)->firstOrFail();
        $config = $feature->configs()->where('data_source_id', $vendorId)->firstOrFail();

        // Construct poll endpoint (customize per vendor if needed; here assuming /tasks/{taskId} suffix)
        $pollEndpoint = $this->buildPollEndpoint($config->endpoint, $taskId);

        $client = new Client();
        $response = $client->get($pollEndpoint, [
            'headers' => [
                $config->auth_header_key => $config->auth_type . ' ' . Config::get($config->auth_value_from_env),
            ],
            'timeout' => 30,
        ]);

        $data = json_decode($response->getBody()->getContents(), true);

        // Normalize response (assume service returns 'status' and 'result' or 'error')
        $status = $data['status'] ?? 'unknown';
        if ($status === 'completed') {
            return [
                'status' => 'completed',
                'result' => $data['result'] ?? $data['data'] ?? []  // e.g., {'video_url': '...'}
            ];
        } elseif ($status === 'failed' || $status === 'error') {
            return [
                'status' => 'failed',
                'error' => $data['error'] ?? 'Unknown error'
            ];
        }

        return [
            'status' => 'processing',
            'progress' => $data['progress'] ?? null
        ];
    }

    /**
     * Map standard input fields to vendor-specific fields.
     *
     * @param array $input
     * @param array $mapping
     * @return array
     */
    private function mapParams(array $input, array $mapping): array
    {
        return collect($mapping)
            ->filter(fn($serviceKey) => $serviceKey !== null)
            ->mapWithKeys(fn($serviceKey, $standardKey) => [
                $serviceKey => $input[$standardKey] ?? null  // Use null for missing optionals
            ])
            ->filter()  // Remove nulls if vendor doesn't accept them
            ->toArray();
    }

    /**
     * Build poll endpoint based on vendor/config.
     *
     * @param string $generateEndpoint
     * @param string $taskId
     * @return string
     */
    private function buildPollEndpoint(string $generateEndpoint, string $taskId): string
    {
        // Generic: replace last segment with /tasks/{taskId}
        $parts = explode('/', rtrim($generateEndpoint, '/'));
        array_pop($parts);  // Remove last (e.g., 'text2video')
        $parts[] = 'tasks';
        $parts[] = $taskId;

        return implode('/', $parts);

        // Vendor-specific overrides if needed:
        // if (str_contains($generateEndpoint, 'vidu')) { ... }
        // if (str_contains($generateEndpoint, 'kling')) { ... }
    }
}