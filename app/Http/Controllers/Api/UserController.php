<?php

namespace App\Http\Controllers\Api;

use App\Models\Business;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Internal user management. Admin only.
 *
 * A manager is either "global" (every application) or "restricted" and then
 * attached to a precise list of applications through the business_user pivot.
 *
 * @OA\Tag(name="Users", description="Internal user management")
 */
class UserController extends BaseController
{
    public function __construct(private JwtService $jwt)
    {
    }

    /**
     * @OA\Get(
     *     path="/api/v1/users",
     *     tags={"Users"},
     *     security={{"bearerAuth":{}}},
     *     summary="List users",
     *     @OA\Parameter(name="search", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="role", in="query", @OA\Schema(type="string", enum={"admin","manager"})),
     *     @OA\Parameter(name="scope", in="query", @OA\Schema(type="string", enum={"global","restricted"})),
     *     @OA\Parameter(name="is_active", in="query", @OA\Schema(type="boolean")),
     *     @OA\Parameter(name="two_factor", in="query", description="enabled|disabled", @OA\Schema(type="string")),
     *     @OA\Parameter(name="business_id", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="sort_by", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="sort_dir", in="query", @OA\Schema(type="string", enum={"asc","desc"})),
     *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Paginated users")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::query()->with('businesses:id,name');

        $query->search($request->query('search'));

        if ($request->filled('role')) {
            $query->where('role', $request->query('role'));
        }

        if ($request->filled('scope')) {
            $query->where('scope', $request->query('scope'));
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('two_factor')) {
            $request->query('two_factor') === 'enabled'
                ? $query->whereNotNull('two_factor_confirmed_at')
                : $query->whereNull('two_factor_confirmed_at');
        }

        if ($request->filled('business_id')) {
            $businessId = $request->query('business_id');
            // Global users implicitly cover every application.
            $query->where(function ($q) use ($businessId) {
                $q->whereHas('businesses', fn ($b) => $b->where('businesses.id', $businessId))
                    ->orWhere('scope', User::SCOPE_GLOBAL)
                    ->orWhere('role', User::ROLE_ADMIN);
            });
        }

        if ($request->filled('created_from')) {
            $query->whereDate('created_at', '>=', $request->query('created_from'));
        }

        if ($request->filled('created_to')) {
            $query->whereDate('created_at', '<=', $request->query('created_to'));
        }

        $sortBy = in_array($request->query('sort_by'), ['name', 'email', 'role', 'created_at', 'last_login_at'], true)
            ? $request->query('sort_by')
            : 'created_at';
        $sortDir = $request->query('sort_dir') === 'asc' ? 'asc' : 'desc';

        $perPage = min((int) $request->query('per_page', 15), 100);

        return $this->successResponse($query->orderBy($sortBy, $sortDir)->paginate($perPage));
    }

    /**
     * @OA\Post(
     *     path="/api/v1/users",
     *     tags={"Users"},
     *     security={{"bearerAuth":{}}},
     *     summary="Create a user",
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"name","email","password","role"},
     *         @OA\Property(property="name", type="string"),
     *         @OA\Property(property="email", type="string", format="email"),
     *         @OA\Property(property="password", type="string"),
     *         @OA\Property(property="role", type="string", enum={"admin","manager"}),
     *         @OA\Property(property="scope", type="string", enum={"global","restricted"}),
     *         @OA\Property(property="business_ids", type="array", @OA\Items(type="integer"))
     *     )),
     *     @OA\Response(response=201, description="Created")
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), $this->rules());

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $user = User::create([
            'name' => $request->input('name'),
            'email' => $request->input('email'),
            'password' => $request->input('password'),
            'phone' => $request->input('phone'),
            'role' => $request->input('role', User::ROLE_MANAGER),
            'scope' => $this->resolveScope($request),
            'is_active' => $request->boolean('is_active', true),
            'must_change_password' => $request->boolean('must_change_password', true),
        ]);

        $this->syncBusinesses($user, $request);

        return $this->createdResponse($user->fresh()->load('businesses:id,name'), 'User created successfully');
    }

    /**
     * @OA\Get(
     *     path="/api/v1/users/{id}",
     *     tags={"Users"},
     *     security={{"bearerAuth":{}}},
     *     summary="Get a user",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="User")
     * )
     */
    public function show($id): JsonResponse
    {
        $user = User::with('businesses:id,name')->find($id);

        if (! $user) {
            return $this->notFoundResponse('User');
        }

        return $this->successResponse($user);
    }

    /**
     * @OA\Put(
     *     path="/api/v1/users/{id}",
     *     tags={"Users"},
     *     security={{"bearerAuth":{}}},
     *     summary="Update a user",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Updated")
     * )
     */
    public function update(Request $request, $id): JsonResponse
    {
        $user = User::find($id);

        if (! $user) {
            return $this->notFoundResponse('User');
        }

        $validator = Validator::make($request->all(), $this->rules($user));

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        // Never let an admin lock everyone out by demoting/disabling the last admin.
        if ($this->wouldRemoveLastAdmin($user, $request)) {
            return $this->errorResponse('At least one active administrator must remain.', null, 422);
        }

        $user->fill($request->only(['name', 'email', 'phone']));

        if ($request->filled('role')) {
            $user->role = $request->input('role');
        }

        if ($request->has('scope') || $request->has('role')) {
            $user->scope = $this->resolveScope($request, $user);
        }

        if ($request->has('is_active')) {
            $user->is_active = $request->boolean('is_active');
        }

        if ($request->filled('password')) {
            $user->password = $request->input('password');
            $user->must_change_password = $request->boolean('must_change_password', false);
        }

        $user->save();

        $this->syncBusinesses($user, $request);

        // A user who just lost access must not keep working with an old token.
        if (! $user->is_active) {
            $this->jwt->revokeAllRefreshTokens($user);
        }

        return $this->updatedResponse($user->fresh()->load('businesses:id,name'), 'User updated successfully');
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/users/{id}",
     *     tags={"Users"},
     *     security={{"bearerAuth":{}}},
     *     summary="Delete a user",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Deleted")
     * )
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $user = User::find($id);

        if (! $user) {
            return $this->notFoundResponse('User');
        }

        if ((int) $user->getKey() === (int) $request->user()->getKey()) {
            return $this->errorResponse('You cannot delete your own account.', null, 422);
        }

        if ($user->isAdmin() && User::where('role', User::ROLE_ADMIN)->where('is_active', true)->count() <= 1) {
            return $this->errorResponse('At least one active administrator must remain.', null, 422);
        }

        $this->jwt->revokeAllRefreshTokens($user);
        $user->delete();

        return $this->deletedResponse('User deleted successfully');
    }

    /**
     * @OA\Put(
     *     path="/api/v1/users/{id}/businesses",
     *     tags={"Users"},
     *     security={{"bearerAuth":{}}},
     *     summary="Assign a manager to applications, or to all of them",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         @OA\Property(property="scope", type="string", enum={"global","restricted"}),
     *         @OA\Property(property="business_ids", type="array", @OA\Items(type="integer"))
     *     )),
     *     @OA\Response(response=200, description="Assignments updated")
     * )
     */
    public function assignBusinesses(Request $request, $id): JsonResponse
    {
        $user = User::find($id);

        if (! $user) {
            return $this->notFoundResponse('User');
        }

        $validator = Validator::make($request->all(), [
            'scope' => ['nullable', Rule::in([User::SCOPE_GLOBAL, User::SCOPE_RESTRICTED])],
            'business_ids' => 'nullable|array',
            'business_ids.*' => 'integer|exists:businesses,id',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $scope = $request->input('scope', $user->scope);
        $user->forceFill(['scope' => $scope])->save();

        if ($scope === User::SCOPE_GLOBAL) {
            // A global manager does not need explicit rows.
            $user->businesses()->detach();
        } else {
            $user->businesses()->sync($request->input('business_ids', []));
        }

        return $this->updatedResponse(
            $user->fresh()->load('businesses:id,name'),
            'Application assignments updated successfully'
        );
    }

    /**
     * @OA\Post(
     *     path="/api/v1/users/{id}/reset-two-factor",
     *     tags={"Users"},
     *     security={{"bearerAuth":{}}},
     *     summary="Reset a user's Google Authenticator enrolment",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Reset")
     * )
     */
    public function resetTwoFactor($id): JsonResponse
    {
        $user = User::find($id);

        if (! $user) {
            return $this->notFoundResponse('User');
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ])->save();

        $this->jwt->revokeAllRefreshTokens($user);

        return $this->successResponse(
            $user->fresh(),
            'Two-factor authentication reset. The user will configure it again on the next login.'
        );
    }

    /**
     * @OA\Get(
     *     path="/api/v1/users/options/businesses",
     *     tags={"Users"},
     *     security={{"bearerAuth":{}}},
     *     summary="Applications available for a manager assignment",
     *     @OA\Response(response=200, description="Business list")
     * )
     */
    public function businessOptions(): JsonResponse
    {
        return $this->successResponse(
            Business::query()->orderBy('name')->get(['id', 'name', 'status'])
        );
    }

    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private function rules(?User $user = null): array
    {
        $unique = Rule::unique('users', 'email');

        if ($user) {
            $unique->ignore($user->getKey());
        }

        return [
            'name' => ($user ? 'sometimes|' : 'required|') . 'string|max:255',
            'email' => [$user ? 'sometimes' : 'required', 'email', 'max:255', $unique],
            'password' => [$user ? 'nullable' : 'required', Password::min(8)->letters()->numbers()],
            'phone' => 'nullable|string|max:50',
            'role' => [$user ? 'sometimes' : 'required', Rule::in([User::ROLE_ADMIN, User::ROLE_MANAGER])],
            'scope' => ['nullable', Rule::in([User::SCOPE_GLOBAL, User::SCOPE_RESTRICTED])],
            'is_active' => 'nullable|boolean',
            'must_change_password' => 'nullable|boolean',
            'business_ids' => 'nullable|array',
            'business_ids.*' => 'integer|exists:businesses,id',
        ];
    }

    private function resolveScope(Request $request, ?User $user = null): string
    {
        $role = $request->input('role', $user?->role ?? User::ROLE_MANAGER);

        // Admins always reach everything.
        if ($role === User::ROLE_ADMIN) {
            return User::SCOPE_GLOBAL;
        }

        return $request->input('scope', $user?->scope ?? User::SCOPE_RESTRICTED);
    }

    private function syncBusinesses(User $user, Request $request): void
    {
        if ($user->scope === User::SCOPE_GLOBAL || $user->isAdmin()) {
            $user->businesses()->detach();

            return;
        }

        if ($request->has('business_ids')) {
            $user->businesses()->sync($request->input('business_ids', []));
        }
    }

    private function wouldRemoveLastAdmin(User $user, Request $request): bool
    {
        if (! $user->isAdmin()) {
            return false;
        }

        $demoting = $request->filled('role') && $request->input('role') !== User::ROLE_ADMIN;
        $disabling = $request->has('is_active') && ! $request->boolean('is_active');

        if (! $demoting && ! $disabling) {
            return false;
        }

        return User::where('role', User::ROLE_ADMIN)
            ->where('is_active', true)
            ->whereKeyNot($user->getKey())
            ->doesntExist();
    }
}
