<?php

namespace App\Services;

use App\Models\AiFeature;
use App\Models\DataSource;
use App\Contracts\PollStrategyInterface;
use App\Services\PollStrategyResolver;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Illuminate\Support\Str;  // For data_get if not using Laravel helper

class AiGenerationService
{
    public function __construct(private PollStrategyResolver $strategyResolver) {}

    /**
     * Generate content using the specified AI feature and input.
     */
    public function generate(string $featureName, array $input): array
    {
        $feature = AiFeature::where('name', $featureName)
            ->where('is_active', true)
            ->firstOrFail();

        $vendor = Config::get('ai.default_vendor', 'vidu');
        $dataSource = DataSource::query()->whereLike('name', $vendor)->first();
        if (!$dataSource) {
            throw new InvalidArgumentException("No data source found for vendor '{$vendor}'.");
        }
        $dataSourceId = $dataSource->id;
        $config = $feature->configs()
            ->where('data_source_id', $dataSourceId)
            ->where('is_active', true)
            ->orderBy('priority')
            ->firstOrFail();

        // Decode JSON fields if strings (fallback for cast issues)
        $standardSchema = $this->decodeIfString($config->standard_schema, 'standard_schema');
        $paramMapping = $this->decodeIfString($config->param_mapping, 'param_mapping');
        $defaultParams = $this->decodeIfString($config->default_params ?? [], 'default_params');
        $extraParams = $this->decodeIfString($config->extra_params ?? [], 'extra_params');
        $responseMapping = $this->decodeIfString($config->response_mapping ?? [], 'response_mapping');

        $rules = $this->buildValidationRules($standardSchema, $input);
        $validator = Validator::make($input, $rules);
        if ($validator->fails()) {
            throw new InvalidArgumentException(
                'Invalid input for ' . $vendor . ' (' . $featureName . '): ' . json_encode($validator->errors())
            );
        }

        // Map input to service-specific params
        $serviceParams = array_merge(
            $defaultParams,
            $this->mapParams($input, $paramMapping),
            $extraParams
        );
        Log::debug('Service params for generation', ['params' => $serviceParams]);

        // Build request options (redact sensitive for log)
        $client = new Client();
        $options = [
            'method' => $config->method,
            'headers' => [
                $config->auth_header_key => $config->auth_type . ' ' . env($config->auth_value_from_env),
                'Content-Type' => $config->content_type,
            ],
            'timeout' => 120,
        ];

        // Handle body format
        if ($config->body_format === 'json') {
            $options['json'] = $serviceParams;
        } elseif ($config->body_format === 'form-data') {
            $options['multipart'] = collect($serviceParams)->map(function ($value, $key) {
                return ['name' => $key, 'contents' => is_array($value) ? json_encode($value) : $value];
            })->values()->toArray();
        } else {
            $options['form_params'] = $serviceParams;
        }

        // Redact for log
        $logOptions = Arr::except($options, ['headers.Authorization']);
        if (isset($logOptions['json'])) $logOptions['json'] = Arr::except($logOptions['json'], ['prompt']);  // Example redact
        Log::debug('Request options (redacted)', ['options' => $logOptions]);

        // Send request with rate-limit handling
        try {
            $response = $client->request($config->method, $config->endpoint, $options);
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() === 429) {
                Log::warning('Rate limit hit; job will retry', ['vendor' => $vendor]);
                throw $e;  // Let job retry
            }
            throw $e;
        }

        $responseData = json_decode($response->getBody()->getContents(), true);

        // Extract task_id via mapping
        $taskIdPath = $responseMapping['task_id_path'] ?? 'task_id';
        $taskId = data_get($responseData, $taskIdPath) ?? $responseData['id'] ?? uniqid('task_');
        $responseData['task_id'] = $taskId;  // Ensure present

        return $responseData;
    }

    /**
     * Poll the status of a generation task.
     */
    public function pollStatus(string $taskId, int $dataSourceId, string $featureName): array
    {
        $feature = AiFeature::where('name', $featureName)->firstOrFail();
        $config = $feature->configs()->where('data_source_id', $dataSourceId)->where('is_active', true)->firstOrFail();

        $generateEndpoint = $config->endpoint;
        if (empty($generateEndpoint)) {
            throw new InvalidArgumentException('Config endpoint is missing; cannot build poll URL.');
        }

        $strategy = $this->strategyResolver->resolve($dataSourceId);
        $pollEndpoint = $strategy->buildEndpoint($generateEndpoint, $taskId);

        $dataSource = DataSource::find($dataSourceId);
        $vendorName = $dataSource->name ?? 'unknown';
        Log::debug('Polling task', ['task_id' => $taskId, 'endpoint' => $pollEndpoint, 'vendor' => $vendorName]);

        $client = new Client();
        try {
            $response = $client->get($pollEndpoint, [
                'headers' => [
                    $config->auth_header_key => $config->auth_type . ' ' . env($config->auth_value_from_env),
                ],
                'timeout' => 30,
            ]);
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() === 429) {
                Log::warning('Rate limit on poll; job will retry', ['vendor' => $vendorName]);
                throw $e;
            }
            Log::error('Polling request failed', ['error' => $e->getMessage(), 'endpoint' => $pollEndpoint]);
            throw $e;
        }

        $data = json_decode($response->getBody()->getContents(), true);
        Log::debug('Raw poll response (redacted)', ['data_keys' => array_keys($data ?? [])]);  // Avoid full dump

        return $strategy->parseResponse($data ?? []);
    }

    /**
     * Helper to decode JSON string to array if needed.
     */
    private function decodeIfString($value, string $fieldName): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error("Invalid JSON in {$fieldName}: " . json_last_error_msg(), ['raw' => $value]);
                throw new InvalidArgumentException("Invalid JSON in {$fieldName}: " . json_last_error_msg());
            }
            return $decoded ?? [];
        }
        return is_array($value) ? $value : [];
    }

    /**
     * Build dynamic validation rules from schema.
     */
    private function buildValidationRules(array $schema, array $input): array
    {
        $rules = [];

        if (isset($schema['fields']) && is_array($schema['fields'])) {
            foreach ($schema['fields'] as $fieldDef) {
                $name = $fieldDef['name'];
                $required = $fieldDef['required'] ?? false;
                $type = $fieldDef['type'] ?? 'string';
                $baseRule = $required ? 'required' : 'sometimes';

                if ($type === 'array') {
                    // Main array rule: NO '|type' or '|each' here
                    $rules[$name] = $baseRule . '|array|min:' . ($fieldDef['min'] ?? 1) . '|max:' . ($fieldDef['max'] ?? 10);

                    // Sub-rule for items: Separate key for 'each'
                    if (isset($fieldDef['each'])) {
                        $rules[$name . '.*'] = $fieldDef['each'];  // e.g., 'string'
                    }
                } else {
                    // Non-array: Standard rule
                    $rule = $baseRule . '|' . $type;
                    if (isset($fieldDef['max'])) {
                        $rule .= '|max:' . $fieldDef['max'];
                    }
                    $rules[$name] = $rule;
                }
                // Ignore UI fields (label, ui_hint, etc.)
            }
        } else {
            // Legacy fallback: Apply same logic
            foreach (array_merge($schema['required'] ?? [], $schema['optional'] ?? []) as $field) {
                $isRequired = in_array($field, $schema['required'] ?? []);
                $baseRule = $isRequired ? 'required' : 'sometimes';
                if ($field === 'images') {
                    $rules[$field] = $baseRule . '|array|min:3|max:5';  // Hardcoded for legacy
                    $rules[$field . '.*'] = 'string';  // Sub-rule
                } else {
                    $rules[$field] = $baseRule . '|string';
                }
            }
        }

        // DEBUG: Log rules to confirm (remove after fix)
        \Illuminate\Support\Facades\Log::debug('Generated validation rules for schema', ['rules' => $rules]);

        return $rules;
    }

    /**
     * Map standard input fields to vendor-specific fields.
     */
    private function mapParams(array $input, array $mapping): array
    {
        return collect($mapping)
            ->filter(fn($serviceKey) => $serviceKey !== null)
            ->mapWithKeys(fn($serviceKey, $standardKey) => [
                $serviceKey => $input[$standardKey] ?? '',  // Empty string for optionals (vendors often accept)
            ])
            ->toArray();  // Don't filter nulls; use ''
    }
}