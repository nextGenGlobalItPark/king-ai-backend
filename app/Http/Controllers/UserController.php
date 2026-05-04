<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\UserService;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class UserController extends Controller
{
    use ResponseTrait;

    private UserService $userService;
    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $users = User::paginate(config('app.pagination_per_page'));
            return $this->success(UserResource::collection($users));
        } catch (\Exception $e) {
            return $this->error(errors: null, message: $e->getMessage());
        }
    }

    public function show($id): JsonResponse
    {
        try {
            $user = User::findOrFail($id);
            return $this->success(new UserResource($user));
        } catch (\Exception $e) {
            return $this->error(errors: null, message: $e->getMessage());
        }
    }

    /**
     * Admin-only: create a user with a temporary password.
     *
     * The temp password is generated server-side, emailed to the user
     * separately from the verification code, and ALSO returned ONCE in
     * this API response so the admin can pass it along out-of-band.
     * The user is forced to change it on first login.
     *
     * Response shape:
     *   {
     *     "data": {
     *       "user": { ... },
     *       "temporary_password": "Ab3kP9..."   // plaintext, shown ONCE
     *     },
     *     "message": "User created. Temporary password is shown ..."
     *   }
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        try {
            [$user, $tempPassword] = $this->userService->createUserWithTemporaryPassword(
                $request->validated()
            );

            return $this->success(
                data: [
                    'user' => new UserResource($user),
                    'temporary_password' => $tempPassword,
                ],
                message: 'User created. Two emails were sent (verification code + temp password). The temporary password is shown once — store it now if you need to share it out-of-band.',
                status: Response::HTTP_CREATED
            );
        } catch (\Exception $e) {
            return $this->error(errors: null, message: $e->getMessage());
        }
    }

    public function update(UpdateUserRequest $request, $id): JsonResponse
    {
        try {
            DB::beginTransaction();
            $user = User::findOrFail($id);

            $data = $request->validated();
            if (isset($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            }

            $user->update($data);
            DB::commit();

            return $this->success(new UserResource($user), 'User updated successfully');

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error(errors: null, message: $e->getMessage());
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            DB::beginTransaction();
            $user = User::findOrFail($id);
            $user->delete();
            DB::commit();

            return $this->success(null, 'User deleted successfully', Response::HTTP_NO_CONTENT);

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error(errors: null, message: $e->getMessage());
        }
    }
}