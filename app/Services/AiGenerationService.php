<?php

namespace App\Services;

use App\Models\AiFeature;
use App\Models\DataSource;
use App\Contracts\PollStrategyInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

class AiGenerationService
{
    public function __construct(private PollStrategyResolver $strategyResolver) {}

    /**
     * Generate content using the specified AI feature and input.
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
        if (!$dataSource) {
            throw new InvalidArgumentException("No data source found for vendor '{$vendor}'.");
        }
        $dataSourceId = $dataSource->id;
        $config = $feature->configs()
            ->where('data_source_id', $dataSourceId)
            ->where('is_active', true)
            ->orderBy('priority')
            ->firstOrFail();

        // Validate input against the config's vendor-specific standard_schema
        $standardSchema = $config->standard_schema;
        $rules = [];
        foreach (array_merge($standardSchema['required'] ?? [], $standardSchema['optional'] ?? []) as $field) {
            $rules[$field] = in_array($field, $standardSchema['required'] ?? []) ? 'required|string' : 'sometimes|string';
        }
        $validator = Validator::make($input, $rules);
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
        Log::debug('Service params for generation', ['params' => $serviceParams]);

        // Build request options
        $client = new Client();
        $options = [
            'headers' => [
                $config->auth_header_key => $config->auth_type . ' ' . env($config->auth_value_from_env),
                'Content-Type' => $config->content_type,
            ],
            'timeout' => 120,  // 2 min timeout for AI calls
        ];
        $options['method'] = $config->method;

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
        Log::debug('Request options', ['options' => $options]);

        // Send request
        $response = $client->request($config->method, $config->endpoint, $options);

        return json_decode($response->getBody()->getContents(), true);
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

        $vendorName = DataSource::find($dataSourceId)->name ?? 'unknown';
        Log::debug('Polling task', ['task_id' => $taskId, 'endpoint' => $pollEndpoint, 'vendor' => $vendorName]);

        $client = new Client();
        try {
            $response = $client->get($pollEndpoint, [
                'headers' => [
                    $config->auth_header_key => $config->auth_type . ' ' . env($config->auth_value_from_env),
                ],
                'timeout' => 30,
            ]);
        } catch (GuzzleException $e) {
            Log::error('Polling request failed', ['error' => $e->getMessage(), 'endpoint' => $pollEndpoint]);
            throw $e;
        }

        $data = json_decode($response->getBody()->getContents(), true);
        Log::debug('Raw poll response', ['data' => $data]);  // For debugging

        return $strategy->parseResponse($data ?? []);
    }

    /**
     * Map standard input fields to vendor-specific fields.
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

    // Removed: Unused buildPollEndpoint() method
}