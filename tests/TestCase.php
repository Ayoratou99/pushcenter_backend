<?php

namespace Tests;

use App\Models\User;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Sign the given user in for the next API calls by attaching a real JWT,
     * so the guard, the middleware and the claims are all exercised.
     */
    protected function actingAsUser(User $user): static
    {
        $token = app(JwtService::class)->issueAccessToken($user);

        return $this->withHeader('Authorization', 'Bearer ' . $token);
    }

    /**
     * An administrator with Google Authenticator already confirmed.
     */
    protected function adminUser(array $attributes = []): User
    {
        return User::factory()->admin()->twoFactorEnabled()->create($attributes);
    }

    /**
     * A manager working on every application.
     */
    protected function globalManager(array $attributes = []): User
    {
        return User::factory()->globalScope()->twoFactorEnabled()->create($attributes);
    }

    /**
     * A manager restricted to the given applications.
     *
     * @param  array<int, int>  $businessIds
     */
    protected function restrictedManager(array $businessIds = [], array $attributes = []): User
    {
        $user = User::factory()->twoFactorEnabled()->create($attributes);
        $user->businesses()->sync($businessIds);

        return $user;
    }

    protected function actingAsAdmin(array $attributes = []): User
    {
        $admin = $this->adminUser($attributes);
        $this->actingAsUser($admin);

        return $admin;
    }
}
