<?php

namespace App\Services;

use App\Models\AiFeature;
use App\Models\DataSource;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class AiGenerationService
{
    public function __construct(
        private PollStrategyResolver $strategyResolver,
        private AuthStrategyResolver $authResolver,
    ) {}

    /**
     * Generate content using the specified AI feature and input.
     *
     * @param string $featureName
     * @param array $input
     * @param string|null $callbackJobId  If provided, auto-injects the webhook callback URL
     * @return array
     */
    public function generate(string $featureName, array $input, ?string $callbackJobId = null): array
    {
        $feature = AiFeature::where('name', $featureName)
            ->where('is_active', true)
            ->firstOrFail();
        Log::debug('Feature resolved', ['feature' => $featureName, 'id' => $feature->id]);

        $dataSourceId = $feature->data_source_id;
        if (!$dataSourceId) {
            throw new InvalidArgumentException("No data source found for vendor.");
        }

        $config = $feature->configs()
            ->where('data_source_id', $dataSourceId)
            ->where('is_active', true)
            ->orderBy('priority')
            ->firstOrFail();

        // Decode JSON fields
        $standardSchema = $this->decodeIfString($config->standard_schema, 'standard_schema');
        $requestBodyTemplate = $this->decodeIfString($config->request_body_template ?? null, 'request_body_template');
        $responseMapping = $this->decodeIfString($config->response_mapping ?? [], 'response_mapping');

        // Apply defaults from schema
        $inputWithDefaults = $this->applySchemaDefaults($input, $standardSchema);

        // Auto-inject callback URL for callback-mode configs
        if ($config->completion_mode === 'callback' && $callbackJobId) {
            $callbackUrl = $this->buildCallbackUrl($callbackJobId);
            $inputWithDefaults['callback_url'] = $callbackUrl;
            Log::debug('Callback URL injected', ['callback_url' => $callbackUrl]);
        }

        $rules = $this->buildValidationRules($standardSchema);
        $validator = Validator::make($inputWithDefaults, $rules);
        if ($validator->fails()) {
            throw new InvalidArgumentException(
                'Invalid input for ('. $featureName . '): ' . json_encode($validator->errors())
            );
        }

        // Map input to service-specific params
        $serviceParams = $this->buildFromTemplate(
            $requestBodyTemplate,
            $inputWithDefaults,
            $standardSchema
        );
        Log::debug('Service params for generation', ['params' => $serviceParams]);

        $authStrategy = $this->authResolver->resolve($config);
        $authHeaderValue = $authStrategy->generateAuthHeader($config);

        // Build the HTTP request
        $pendingRequest = Http::timeout(120)
            ->withHeaders([
                $config->auth_header_key => $authHeaderValue,
                'Content-Type' => $config->content_type,
            ]);

        Log::debug('Request options (redacted)', [
            'endpoint' => $config->endpoint,
            'method' => $config->method,
            'body_format' => $config->body_format,
        ]);

        try {
            if ($config->body_format === 'json') {
                $response = $pendingRequest->send($config->method, $config->endpoint, [
                    'json' => $serviceParams,
                ]);
            } elseif ($config->body_format === 'form-data') {
                $multipart = collect($serviceParams)->map(function ($value, $key) {
                    return ['name' => $key, 'contents' => is_array($value) ? json_encode($value) : $value];
                })->values()->toArray();
                $response = $pendingRequest->send($config->method, $config->endpoint, [
                    'multipart' => $multipart,
                ]);
            } else {
                $response = $pendingRequest->asForm()->send($config->method, $config->endpoint, [
                    'form_params' => $serviceParams,
                ]);
            }

            if ($response->status() === 429) {
                Log::warning('Rate limit hit; job will retry');
                throw new RequestException($response);
            }

            $response->throw();
        } catch (RequestException $e) {
            if ($e->response?->status() === 429) {
                Log::warning('Rate limit hit; job will retry');
            }
            throw $e;
        }

        $responseData = $response->json();
        Log::debug('Generation API response received', [
            'response' => $responseData,
        ]);

        // Use structured generation mapping to extract task_id and check for errors
        $generationConfig = $responseMapping['generation'] ?? $responseMapping;
        $taskIdPath = $generationConfig['task_id_path'] ?? $responseMapping['task_id'] ?? null;

        // Check if the generation response itself indicates an error
        $successCodes = $generationConfig['success_codes'] ?? null;
        if ($successCodes) {
            $genErrorCodePath = $generationConfig['error_code_path'] ?? 'code';
            $responseCode = data_get($responseData, $genErrorCodePath);
            if ($responseCode !== null && !in_array($responseCode, $successCodes)) {
                $genErrorMsgPath = $generationConfig['error_message_path'] ?? 'message';
                $errorMsg = data_get($responseData, $genErrorMsgPath, 'Generation request rejected by vendor');
                throw new \RuntimeException("[Code: {$responseCode}] {$errorMsg}");
            }
        }

        // Extract task_id: try configured path first, then common vendor paths
        $taskId = null;
        $resolvedPath = null;

        if ($taskIdPath) {
            $taskId = data_get($responseData, $taskIdPath);
            $resolvedPath = $taskIdPath;
        }

        // Fallback: try common vendor task_id locations
        if ($taskId === null) {
            $fallbackPaths = ['data.task_id', 'data.id', 'task_id', 'id'];
            foreach ($fallbackPaths as $path) {
                $taskId = data_get($responseData, $path);
                if ($taskId !== null) {
                    $resolvedPath = $path . ' (fallback)';
                    Log::warning('task_id_path not configured or returned null, used fallback', [
                        'configured_path' => $taskIdPath,
                        'fallback_path' => $path,
                        'task_id' => $taskId,
                    ]);
                    break;
                }
            }
        }

        // Last resort: generate a unique ID (should not happen with proper config)
        if ($taskId === null) {
            $taskId = uniqid('task_');
            Log::error('Could not extract task_id from response — using generated ID. Check response_mapping.generation.task_id_path', [
                'configured_path' => $taskIdPath,
                'generated_task_id' => $taskId,
                'response_keys' => array_keys($responseData),
            ]);
        }

        $responseData['task_id'] = $taskId;

        Log::debug('Task ID extracted', ['task_id' => $taskId, 'path' => $resolvedPath]);

        return $responseData;
    }

    /**
     * Poll the status of a generation task.
     *
     * Now uses GenericPollStrategy with the full response_mapping config.
     * The strategy reads url_template, status_path, progress_path, asset paths,
     * etc. from the mapping — no vendor-specific code needed.
     */
    public function pollStatus(string $taskId, int $dataSourceId, string $featureName): array
    {
        $feature = AiFeature::where('name', $featureName)->firstOrFail();
        $config = $feature->configs()
            ->where('data_source_id', $dataSourceId)
            ->where('is_active', true)
            ->firstOrFail();

        // Decode the full response mapping
        $responseMapping = $this->decodeIfString($config->response_mapping ?? [], 'response_mapping');

        // Use status_check_endpoint if set, fall back to main endpoint
        $statusCheckEndpoint = $config->status_check_endpoint ?: $config->endpoint;
        if (empty($statusCheckEndpoint)) {
            throw new InvalidArgumentException('Neither status_check_endpoint nor endpoint is set; cannot poll.');
        }

        // Resolve generic strategy with the full mapping
        $strategy = $this->strategyResolver->resolve($dataSourceId, $responseMapping);
        $pollEndpoint = $strategy->buildEndpoint($statusCheckEndpoint, $taskId);
        $pollMethod = $strategy->getPollingMethod();

        $authStrategy = $this->authResolver->resolve($config);
        $authHeaderValue = $authStrategy->generateAuthHeader($config);

        $dataSource = DataSource::find($dataSourceId);
        $vendorName = $dataSource->name ?? 'unknown';
        Log::debug('Polling task', [
            'task_id' => $taskId,
            'endpoint' => $pollEndpoint,
            'method' => $pollMethod,
            'vendor' => $vendorName,
        ]);

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    $config->auth_header_key => $authHeaderValue,
                ])
                ->send($pollMethod, $pollEndpoint);

            if ($response->status() === 429) {
                Log::warning('Rate limit on poll; job will retry', ['vendor' => $vendorName]);
                throw new RequestException($response);
            }

            $response->throw();
        } catch (RequestException $e) {
            if ($e->response?->status() === 429) {
                Log::warning('Rate limit on poll; job will retry', ['vendor' => $vendorName]);
            } else {
                Log::error('Polling request failed', ['error' => $e->getMessage(), 'endpoint' => $pollEndpoint]);
            }
            throw $e;
        }

        $data = $response->json();
        Log::debug('Poll response received', ['task_id' => $taskId]);

        return $strategy->parseResponse($data ?? [], $responseMapping);
    }

    /**
     * Build the callback URL for webhook-mode generation.
     *
     * Uses the APP_URL from config so it works correctly in all environments.
     */
    private function buildCallbackUrl(string $jobId): string
    {
        $baseUrl = rtrim(config('app.url'), '/');
        return "{$baseUrl}/api/webhook/{$jobId}";
    }

    /**
     * Apply default values from schema to input
     */
    private function applySchemaDefaults(array $input, array $schema): array
    {
        $result = $input;

        foreach ($schema['fields'] ?? [] as $field) {
            $name = $field['name'];
            if (isset($field['default']) && !array_key_exists($name, $result)) {
                $result[$name] = $field['default'];
                Log::debug('Applied default value', ['field' => $name, 'default' => $field['default']]);
            }
        }

        return $result;
    }

    /**
     * Build request body from template by replacing placeholders
     */
    private function buildFromTemplate(array $template, array $input, array $schema): array
    {
        $json = json_encode($template, JSON_PRETTY_PRINT);

        $typeMap = [];
        foreach ($schema['fields'] ?? [] as $field) {
            $typeMap[$field['name']] = $field['type'] ?? 'string';
        }

        foreach ($input as $key => $value) {
            $placeholder = '"{{' . $key . '}}"';
            $type = $typeMap[$key] ?? 'string';

            $replacement = match ($type) {
                'integer' => is_numeric($value) ? (int) $value : 0,
                'number', 'float' => is_numeric($value) ? (float) $value : 0.0,
                'boolean' => $value ? 'true' : 'false',
                'array', 'object' => json_encode($value),
                default => json_encode($value),
            };

            $json = str_replace($placeholder, $replacement, $json);
        }

        if (preg_match('/{{[^}]+}}/', $json)) {
            Log::warning('Template has unreplaced placeholders', ['json' => $json]);
            $json = $this->removeUnusedPlaceholders($json);
        }

        $result = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('Failed to parse template result', ['error' => json_last_error_msg(), 'json' => $json]);
            throw new InvalidArgumentException('Invalid template result: ' . json_last_error_msg());
        }

        return $result;
    }

    /**
     * Remove unused placeholders and clean up JSON structure
     */
    private function removeUnusedPlaceholders(string $json): string
    {
        $lines = explode("\n", $json);
        $cleaned = array_filter($lines, function($line) {
            return !preg_match('/{{[^}]+}}/', $line);
        });

        $json = implode("\n", $cleaned);
        $json = preg_replace('/,(\s*[}\]])/', '$1', $json);
        $json = preg_replace('/,\s*,/', ',', $json);

        return $json;
    }

    /**
     * Build validation rules from schema
     */
    private function buildValidationRules(array $schema): array
    {
        $rules = [];

        foreach ($schema['fields'] ?? [] as $field) {
            $name = $field['name'];

            if (!empty($field['hidden'])) {
                continue;
            }

            $required = $field['required'] ?? false;
            $type = $field['type'] ?? 'string';

            if (isset($field['default'])) {
                $required = false;
            }

            $rule = $required ? 'required' : 'nullable';

            if ($type === 'array') {
                $rule .= '|array';
                if (isset($field['min'])) $rule .= '|min:' . $field['min'];
                if (isset($field['max'])) $rule .= '|max:' . $field['max'];
                if (isset($field['each'])) {
                    $rules[$name . '.*'] = $field['each'];
                }
            } else {
                $rule .= '|' . $type;
                if (isset($field['max'])) $rule .= '|max:' . $field['max'];
            }

            $rules[$name] = $rule;
        }

        Log::debug('Generated validation rules', ['rules' => $rules]);

        return $rules;
    }

    /**
     * Helper to decode JSON string to array if needed.
     */
    private function decodeIfString($value, string $fieldName): array
    {
        if (is_null($value)) {
            return [];
        }

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
}
