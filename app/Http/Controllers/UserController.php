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
            $data = $request->validated();
            $role = $data['role'] ?? User::ROLE_USER;
            unset($data['role']); // not a User column

            [$user, $tempPassword] = $this->userService->createUserWithTemporaryPassword(
                $data,
                $role
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

    /**
     * Replace a user's roles with the supplied list.
     *
     * PUT /api/users/{id}/roles
     * Body: { "roles": ["admin"] }   // or ["user"], or both
     *
     * Admin-only (gated at route level by manage-users permission).
     * Uses syncRoles() so passing an empty array removes all roles —
     * we explicitly reject that here because a user with no role has
     * no permissions and can't do anything.
     */
    public function updateRoles(Request $request, $id): JsonResponse
    {
        try {
            $request->validate([
                'roles' => ['required', 'array', 'min:1'],
                'roles.*' => ['string', \Illuminate\Validation\Rule::in([User::ROLE_ADMIN, User::ROLE_USER])],
            ]);

            $user = User::findOrFail($id);

            // Defensive: prevent admin from demoting themselves and
            // accidentally locking out the last admin. We don't enforce
            // a "must keep at least one admin" invariant here because
            // it's hard to do correctly under concurrent updates;
            // instead we just block self-demotion as a sanity rail.
            if ((int) $id === (int) auth()->id()
                && ! in_array(User::ROLE_ADMIN, $request->input('roles'), true)
                && $user->hasRole(User::ROLE_ADMIN)
            ) {
                return $this->error(
                    errors: null,
                    message: 'You cannot remove the admin role from your own account.',
                    status: Response::HTTP_FORBIDDEN
                );
            }

            $user->syncRoles($request->input('roles'));

            Log::info('Admin updated user roles', [
                'admin_id' => auth()->id(),
                'target_user_id' => $user->id,
                'roles' => $request->input('roles'),
            ]);

            return $this->success(
                new UserResource($user->fresh()),
                'User roles updated.'
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error(errors: $e->errors(), message: 'Invalid roles', status: Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Exception $e) {
            return $this->error(errors: null, message: $e->getMessage());
        }
    }
}