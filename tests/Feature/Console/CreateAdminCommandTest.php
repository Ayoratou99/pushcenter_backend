<?php

namespace Tests\Feature\Console;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_administrator(): void
    {
        $this->artisan('admin:create', [
            '--email' => 'root@example.com',
            '--password' => 'password123',
            '--name' => 'Root Admin',
        ])->assertSuccessful();

        $user = User::where('email', 'root@example.com')->first();

        $this->assertNotNull($user);
        $this->assertSame('Root Admin', $user->name);
        $this->assertSame(User::ROLE_ADMIN, $user->role);
        $this->assertSame(User::SCOPE_GLOBAL, $user->scope);
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check('password123', $user->password));
        // The admin configures Google Authenticator on their first login.
        $this->assertFalse($user->hasTwoFactorEnabled());
    }

    public function test_it_refuses_a_duplicate_email_without_force(): void
    {
        User::factory()->create(['email' => 'root@example.com']);

        $this->artisan('admin:create', [
            '--email' => 'root@example.com',
            '--password' => 'password123',
            '--name' => 'Root Admin',
        ])->assertFailed();
    }

    public function test_force_updates_an_existing_account(): void
    {
        User::factory()->create(['email' => 'root@example.com', 'name' => 'Old name']);

        $this->artisan('admin:create', [
            '--email' => 'root@example.com',
            '--password' => 'newPassword123',
            '--name' => 'New name',
            '--force' => true,
        ])->assertSuccessful();

        $user = User::where('email', 'root@example.com')->first();

        $this->assertSame('New name', $user->name);
        $this->assertSame(User::ROLE_ADMIN, $user->role);
        $this->assertTrue(Hash::check('newPassword123', $user->password));
    }

    public function test_it_rejects_a_weak_password(): void
    {
        $this->artisan('admin:create', [
            '--email' => 'root@example.com',
            '--password' => 'short',
            '--name' => 'Root Admin',
        ])->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_it_rejects_an_invalid_email(): void
    {
        $this->artisan('admin:create', [
            '--email' => 'not-an-email',
            '--password' => 'password123',
            '--name' => 'Root Admin',
        ])->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_the_created_admin_can_sign_in(): void
    {
        $this->artisan('admin:create', [
            '--email' => 'root@example.com',
            '--password' => 'password123',
            '--name' => 'Root Admin',
        ])->assertSuccessful();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'root@example.com',
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonPath('data.two_factor_setup_required', true);
    }

    public function test_user_create_makes_a_global_manager(): void
    {
        $this->artisan('user:create', [
            '--email' => 'manager@example.com',
            '--password' => 'password123',
            '--name' => 'Global Manager',
            '--scope' => 'global',
        ])->assertSuccessful();

        $user = User::where('email', 'manager@example.com')->first();

        $this->assertSame(User::ROLE_MANAGER, $user->role);
        $this->assertSame(User::SCOPE_GLOBAL, $user->scope);
        $this->assertCount(0, $user->businesses);
    }

    public function test_user_create_attaches_a_restricted_manager_to_applications(): void
    {
        $businesses = Business::factory()->count(2)->create();

        $this->artisan('user:create', [
            '--email' => 'manager@example.com',
            '--password' => 'password123',
            '--name' => 'Scoped Manager',
            '--scope' => 'restricted',
            '--business' => $businesses->pluck('id')->map(fn ($id) => (string) $id)->all(),
        ])->assertSuccessful();

        $user = User::where('email', 'manager@example.com')->first();

        $this->assertSame(User::SCOPE_RESTRICTED, $user->scope);
        $this->assertCount(2, $user->businesses);
    }

    public function test_user_create_rejects_an_unknown_business(): void
    {
        $this->artisan('user:create', [
            '--email' => 'manager@example.com',
            '--password' => 'password123',
            '--name' => 'Scoped Manager',
            '--business' => ['999999'],
        ])->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }
}
