<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeviceRegisterRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\Device;
use App\Models\User;
use App\Services\UserService;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    use ResponseTrait;

    private UserService $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }
    public function register(RegisterRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $user = $this->userService->createUser($request->validated());
            $token = $user->createToken('api-token')->plainTextToken;
            DB::commit();

            return $this->success(['user' => new UserResource($user), 'token' => $token], 'User registered successfully', Response::HTTP_CREATED);

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error(errors: null, message: $e->getMessage());
        }
    }

    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $user = User::where('email', $request->email)->first();

            if (!$user || !Hash::check($request->password, $user->password)) {
                return $this->error(null, 'Invalid credentials', Response::HTTP_UNAUTHORIZED);
            }

            $token = $user->createToken('api-token')->plainTextToken;

            return $this->success(['token' => $token], 'Login successful');

        } catch (\Exception $e) {
            return $this->error(errors: null, message: $e->getMessage());
        }
    }

    public function logout(Request $request): JsonResponse
    {
        try {
            $request->user()->currentAccessToken()->delete();
            return $this->success(null, 'Logged out successfully');
        } catch (\Exception $e) {
            return $this->error(errors: null, message: $e->getMessage());
        }
    }

    public function registerDevice(DeviceRegisterRequest $request)
    {
        try {
            DB::beginTransaction();
            Device::updateOrCreate(
                ['user_id' => auth()->id(), 'token' => $request->token],
                [
                    'type' => $request->type,
                    'platform' => $request->platform,
                    'is_active' => true,
                ]
            );
            DB::commit();
            return $this->success(message: 'Device registered successfully', status: Response::HTTP_CREATED);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error(errors: null, message: $e->getMessage());
        }
    }
}
