<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreJwtConfigRequest;
use App\Http\Requests\StoreStaticTokenConfigRequest;
use App\Http\Requests\SwitchAuthTypeRequest;
use App\Http\Requests\UpdateJwtConfigRequest;
use App\Http\Requests\UpdateStaticTokenConfigRequest;
use App\Http\Resources\AiFeatureConfigAdminResource;
use App\Models\AiFeatureConfig;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AiFeatureConfigController extends Controller
{
    use ResponseTrait;
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try {
            $configs = AiFeatureConfig::with(['feature', 'dataSource'])
                ->when($request->has('feature_id'), fn($q) => $q->where('ai_feature_id', $request->feature_id))
                ->when($request->has('vendor'), fn($q) => $q->whereHas('dataSource', fn($sq) => $sq->where('name', $request->vendor)))
                ->when($request->has('active_only'), fn($q) => $q->where('is_active', true))
                ->when($request->has('auth_type'), fn($q) => $q->where('auth_type', $request->auth_type))
                ->orderBy('priority')
                ->paginate($request->get('per_page', 15));

            return $this->success(
                data: AiFeatureConfigAdminResource::collection($configs),
                message: 'Configs retrieved successfully',
                status: Response::HTTP_OK
            );
        } catch (\Exception $e) {
            return $this->error(
                errors: $e->getCode(),
                message: $e->getMessage()
            );
        }
    }

    public function storeStaticToken(StoreStaticTokenConfigRequest $request)
    {
        try {
            DB::beginTransaction();

            $validated = $request->validated();
            $cleanedData = array_merge(
                Arr::except($validated, [
                    'auth_secret_key_env',
                    'jwt_expiry_seconds',
                    'jwt_nbf_offset_seconds'
                ]),
                [
                    'created_by' => auth()->id(),
                    'auth_secret_key_env' => null,
                    'jwt_expiry_seconds' => null,
                    'jwt_nbf_offset_seconds' => null,
                ]
            );
            $config = AiFeatureConfig::create($cleanedData);

            DB::commit();

            Log::info('Static token config created', [
                'config_id' => $config->id,
                'auth_type' => $config->auth_type,
                'user_id' => auth()->id()
            ]);

            return $this->success(
                data: new AiFeatureConfigAdminResource($config->load(['feature', 'dataSource'])),
                message: 'Static token configuration created successfully',
                status: Response::HTTP_CREATED
            );

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error(
                errors: $e->getCode(),
                message: $e->getMessage()
            );
        }
    }

    /**
     * Store JWT config (Kling, Luma, etc.)
     */
    public function storeJwt(StoreJwtConfigRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated['auth_type'] = 'jwt';

        // No json_encode needed - casts handle it!
        $config = AiFeatureConfig::create($validated);

        return response()->json([
            'success' => true,
            'data' => $config,
            'message' => 'AI feature config created successfully'
        ], 201);
    }

    /**
     * Update static token config
     */
    public function updateStaticToken(UpdateStaticTokenConfigRequest $request, AiFeatureConfig $aiFeatureConfig)
    {
        try {
            // Verify it's actually a static token config
            if (!in_array($aiFeatureConfig->auth_type, ['token', 'bearer', 'static_token'])) {
                return $this->error(
                    message: "This configuration uses '{$aiFeatureConfig->auth_type}' authentication. Use the appropriate endpoint to update it.",
                    status: Response::HTTP_BAD_REQUEST
                );
            }

            DB::beginTransaction();

            $validated = $request->validated();

            $cleanedData = array_merge(
                Arr::except($validated, [
                    'auth_secret_key_env',
                    'jwt_expiry_seconds',
                    'jwt_nbf_offset_seconds'
                ]),
                [
                    'auth_secret_key_env' => null,
                    'jwt_expiry_seconds' => null,
                    'jwt_nbf_offset_seconds' => null,
                ]
            );

            $aiFeatureConfig->update($cleanedData);

            DB::commit();

            Log::info('Static token config updated', [
                'config_id' => $aiFeatureConfig->id,
                'user_id' => auth()->id()
            ]);

            return $this->success(
                data: new AiFeatureConfigAdminResource($aiFeatureConfig->load(['feature', 'dataSource'])),
                message: 'Config updated successfully',
                status: Response::HTTP_OK
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error(
                errors: $e->getCode(),
                message: $e->getMessage()
            );
        }
    }

    /**
     * Update JWT config
     */
    public function updateJwt(UpdateJwtConfigRequest $request, AiFeatureConfig $aiFeatureConfig)
    {
        try {
            // Verify it's actually a JWT config
            if ($aiFeatureConfig->auth_type !== 'jwt') {
                return $this->error(
                    message: "This configuration uses '{$aiFeatureConfig->auth_type}' authentication. Use the appropriate endpoint to update it.",
                    status: Response::HTTP_BAD_REQUEST
                );
            }

            DB::beginTransaction();

            $validated = $request->validated();

            $aiFeatureConfig->update($validated);

            DB::commit();

            Log::info('JWT config updated', [
                'config_id' => $aiFeatureConfig->id,
                'user_id' => auth()->id()
            ]);

            return $this->success(
                data: new AiFeatureConfigAdminResource($aiFeatureConfig->load(['feature', 'dataSource'])),
                message: 'Config updated successfully',
                status: Response::HTTP_OK
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error(
                errors: $e->getCode(),
                message: $e->getMessage()
            );
        }
    }

    /**
     * Switch auth type (clears incompatible fields)
     */
    public function switchAuthType(SwitchAuthTypeRequest $request, AiFeatureConfig $aiFeatureConfig)
    {
        try {
            $oldAuthType = $aiFeatureConfig->auth_type;
            $newAuthType = $request->new_auth_type;

            if ($oldAuthType === $newAuthType) {
                return $this->error(
                    message: 'Configuration already uses this authentication type',
                    status: Response::HTTP_BAD_REQUEST
                );
            }

            DB::beginTransaction();

            // Clear fields based on new type
            $updates = ['auth_type' => $newAuthType];

            if (in_array($newAuthType, ['token', 'bearer', 'static_token'])) {
                // Switching TO static token - clear JWT fields
                $updates['auth_value_from_env'] = null;
                $updates['auth_secret_key_env'] = null;
                $updates['jwt_expiry_seconds'] = null;
                $updates['jwt_nbf_offset_seconds'] = null;

                $message = "Authentication switched to static token. All fields cleared - please configure your API token.";

            } elseif ($newAuthType === 'jwt') {
                // Switching TO JWT - clear everything and set JWT defaults
                $updates['auth_value_from_env'] = null;      // Clear old token
                $updates['auth_secret_key_env'] = null;      // Need both keys
                $updates['jwt_expiry_seconds'] = 1800;       // 30 minutes default
                $updates['jwt_nbf_offset_seconds'] = 5;      // 5 seconds default

                $message = "Authentication switched to JWT. All fields cleared - please configure your access key and secret key. JWT timing defaults applied.";
            }

            $aiFeatureConfig->update($updates);

            DB::commit();

            Log::warning('Auth type switched - fields cleared', [
                'config_id' => $aiFeatureConfig->id,
                'old_type' => $oldAuthType,
                'new_type' => $newAuthType,
                'cleared_fields' => array_keys(array_filter($updates, fn($v) => $v === null)),
                'set_defaults' => array_keys(array_filter($updates, fn($v) => $v !== null && $v !== $newAuthType)),
                'user_id' => auth()->id()
            ]);


            return $this->success(
                data: new AiFeatureConfigAdminResource($aiFeatureConfig->load(['feature', 'dataSource'])),
                message: $message,
                status: Response::HTTP_OK
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error(
                errors: $e->getCode(),
                message: $e->getMessage()
            );
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(AiFeatureConfig $aiFeatureConfig)
    {

        try {
            $aiFeatureConfig->delete();
            return $this->success(
                message: 'Config deleted successfully',
                status: Response::HTTP_NO_CONTENT
            );
        } catch (\Exception $e) {
            return $this->error(
                errors: $e->getCode(),
                message: $e->getMessage()
            );
        }
    }
}
