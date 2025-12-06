<?php

namespace App\Services;

use App\Models\AiFeature;
use App\Models\DataSource;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\MessageFormatter;
use GuzzleHttp\Middleware;
use Illuminate\Support\Arr;
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
     */
    public function generate(string $featureName, array $input): array
    {
        $feature = AiFeature::where('name', $featureName)
            ->where('is_active', true)
            ->firstOrFail();
        Log::debug($feature);
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

        // Apply defaults from schema
        $inputWithDefaults = $this->applySchemaDefaults($input, $standardSchema);

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

        // Build request options (redact sensitive for log)
        $handlerStack = HandlerStack::create();

        $handlerStack->push(Middleware::log(
            Log::getLogger(),
            new MessageFormatter(MessageFormatter::DEBUG)
        ));
        $client = new Client();
        $options = [
            'method' => $config->method,
            'headers' => [
                $config->auth_header_key => $authHeaderValue,
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
                Log::warning('Rate limit hit; job will retry');
                throw $e;  // Let job retry
            }
            throw $e;
        }

        $responseData = json_decode($response->getBody()->getContents(), true);

        Log::debug('Response of API Call ', ['response' => $responseData]);

        // Extract task_id via mapping
        $responseMapping = $this->decodeIfString($config->response_mapping ?? [], 'response_mapping');
        $taskIdPath = $responseMapping['task_id'] ?? 'task_id';
        Log::debug('Task ID path', ['task_id' => $taskIdPath]);
        $taskId = data_get($responseData, $taskIdPath) ?? $responseData['id'] ?? uniqid('task_');
        $responseData['task_id'] = $taskId;  // Ensure present

        return $responseData;
    }

    /**
     * Apply default values from schema to input
     *
     * @param array $input User-provided input
     * @param array $schema Standard schema definition
     * @return array Input with defaults applied
     */
    private function applySchemaDefaults(array $input, array $schema): array
    {
        $result = $input;

        foreach ($schema['fields'] ?? [] as $field) {
            $name = $field['name'];

            // If field has default and not provided by user, apply default
            if (isset($field['default']) && !array_key_exists($name, $result)) {
                $result[$name] = $field['default'];

                Log::debug('Applied default value', [
                    'field' => $name,
                    'default' => $field['default']
                ]);
            }
        }

        return $result;
    }

    /**
     * Build request body from template by replacing placeholders
     *
     * @param array $template Request body template with {{placeholders}}
     * @param array $input User input with defaults applied
     * @param array $schema Standard schema for type information
     * @return array Final request body
     */
    private function buildFromTemplate(
        array $template,
        array $input,
        array $schema
    ): array {
        $json = json_encode($template, JSON_PRETTY_PRINT);

        // Build type map from schema
        $typeMap = [];
        foreach ($schema['fields'] ?? [] as $field) {
            $typeMap[$field['name']] = $field['type'] ?? 'string';
        }

        // Replace placeholders with actual values
        foreach ($input as $key => $value) {
            $placeholder = '"{{' . $key . '}}"';
            $type = $typeMap[$key] ?? 'string';

            // Convert to proper JSON representation based on type
            $replacement = match($type) {
                'integer', 'number' => is_numeric($value) ? (int)$value : 0,
                'float' => is_numeric($value) ? (float)$value : 0.0,
                'boolean' => $value ? 'true' : 'false',
                'array', 'object' => json_encode($value),
                default => json_encode($value), // string
            };

            $json = str_replace($placeholder, $replacement, $json);
        }

        // Remove any remaining placeholders (shouldn't happen if schema is correct)
        if (preg_match('/{{[^}]+}}/', $json)) {
            Log::warning('Template has unreplaced placeholders', ['json' => $json]);
            $json = $this->removeUnusedPlaceholders($json);
        }

        $result = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('Failed to parse template result', [
                'error' => json_last_error_msg(),
                'json' => $json
            ]);
            throw new InvalidArgumentException('Invalid template result: ' . json_last_error_msg());
        }

        return $result;
    }

    /**
     * Remove unused placeholders and clean up JSON structure
     *
     * @param string $json JSON string with placeholders
     * @return string Cleaned JSON string
     */
    private function removeUnusedPlaceholders(string $json): string
    {
        // Remove lines with unreplaced placeholders
        $lines = explode("\n", $json);
        $cleaned = array_filter($lines, function($line) {
            return !preg_match('/{{[^}]+}}/', $line);
        });

        $json = implode("\n", $cleaned);

        // Fix trailing commas
        $json = preg_replace('/,(\s*[}\]])/', '$1', $json);

        // Fix double commas
        $json = preg_replace('/,\s*,/', ',', $json);

        return $json;
    }

    /**
     * Build validation rules from schema
     *
     * @param array $schema Standard schema definition
     * @return array Laravel validation rules
     */
    private function buildValidationRules(array $schema): array
    {
        $rules = [];

        foreach ($schema['fields'] ?? [] as $field) {
            $name = $field['name'];

            // Skip hidden fields from user input validation
            if (!empty($field['hidden'])) {
                continue;
            }

            $required = $field['required'] ?? false;
            $type = $field['type'] ?? 'string';

            // If field has default, it becomes optional
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
     * Map standard input fields to vendor-specific fields (LEGACY)
     * Only used when request_body_template is not set
     */
    private function mapParams(array $input, array $mapping): array
    {
        return collect($mapping)
            ->filter(fn($serviceKey) => $serviceKey !== null)
            ->mapWithKeys(fn($serviceKey, $standardKey) => [
                $serviceKey => $input[$standardKey] ?? '',
            ])
            ->toArray();
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

    /**
     * Poll the status of a generation task.
     */
    public function pollStatus(string $taskId, int $dataSourceId, string $featureName): array
    {
        $feature = AiFeature::where('name', $featureName)->firstOrFail();
        $config = $feature->configs()
            ->where('data_source_id', $dataSourceId)
            ->where('is_active', true)
            ->firstOrFail();

        $generateEndpoint = $config->endpoint;
        if (empty($generateEndpoint)) {
            throw new InvalidArgumentException('Config endpoint is missing; cannot build poll URL.');
        }

        $strategy = $this->strategyResolver->resolve($dataSourceId);
        $pollEndpoint = $strategy->buildEndpoint($generateEndpoint, $taskId);

        $authStrategy = $this->authResolver->resolve($config);
        $authHeaderValue = $authStrategy->generateAuthHeader($config);

        $dataSource = DataSource::find($dataSourceId);
        $vendorName = $dataSource->name ?? 'unknown';
        Log::debug('Polling task', ['task_id' => $taskId, 'endpoint' => $pollEndpoint, 'vendor' => $vendorName]);

        $client = new Client();
        try {
            $response = $client->get($pollEndpoint, [
                'headers' => [
                    $config->auth_header_key => $authHeaderValue,
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
        $responseMapping = $this->decodeIfString($config->response_mapping ?? [], 'response_mapping');

        Log::debug('Response mapping', ['responseMapping' => $responseMapping]);
        Log::debug($data);

        return $strategy->parseResponse($data ?? [], $responseMapping);
    }
}