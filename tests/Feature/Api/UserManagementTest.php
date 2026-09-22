<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_manager_only_lists_the_managers_of_its_applications(): void
    {
        $mine = \App\Models\Business::factory()->create();
        $other = \App\Models\Business::factory()->create();
        $manager = $this->restrictedManager([$mine->id]);
        $colleague = $this->restrictedManager([$mine->id], ['email' => 'colleague@aninf.test']);
        $this->restrictedManager([$other->id], ['email' => 'stranger@aninf.test']);
        $this->adminUser(['email' => 'boss@aninf.test']);

        $emails = $this->actingAsUser($manager)->getJson('/api/v1/users')
            ->assertOk()
            ->json('data.data.*.email');

        $expected = ['colleague@aninf.test', $manager->email];
        sort($emails);
        sort($expected);
        $this->assertSame($expected, $emails);
    }

    public function test_an_admin_lists_users(): void
    {
        $this->actingAsAdmin();
        User::factory()->count(3)->create();

        $this->getJson('/api/v1/users')
            ->assertOk()
            ->assertJsonStructure(['data' => ['data', 'current_page', 'total']])
            ->assertJsonCount(4, 'data.data');
    }

    public function test_users_can_be_filtered_and_combined(): void
    {
        $this->actingAsAdmin();

        User::factory()->create(['name' => 'Alice Manager', 'email' => 'alice@example.com']);
        User::factory()->globalScope()->create(['name' => 'Bob Global', 'email' => 'bob@example.com']);
        User::factory()->inactive()->create(['name' => 'Carol Off', 'email' => 'carol@example.com']);

        // Search + role + active combined in a single call.
        $response = $this->getJson('/api/v1/users?search=alice&role=manager&is_active=1')->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertSame('alice@example.com', $response->json('data.data.0.email'));

        $this->assertCount(1, $this->getJson('/api/v1/users?scope=global&role=manager')->json('data.data'));
        $this->assertCount(1, $this->getJson('/api/v1/users?is_active=0')->json('data.data'));
        $this->assertCount(3, $this->getJson('/api/v1/users?two_factor=disabled')->json('data.data'));
    }

    public function test_an_admin_creates_a_restricted_manager_bound_to_applications(): void
    {
        $this->actingAsAdmin();
        $businesses = Business::factory()->count(2)->create();

        $response = $this->postJson('/api/v1/users', [
            'name' => 'New Manager',
            'email' => 'new.manager@example.com',
            'password' => 'password123',
            'role' => 'manager',
            'scope' => 'restricted',
            'business_ids' => $businesses->pluck('id')->all(),
        ])->assertStatus(201);

        $this->assertSame('restricted', $response->json('data.scope'));
        $this->assertCount(2, $response->json('data.businesses'));

        $user = User::where('email', 'new.manager@example.com')->first();
        $this->assertTrue(Hash::check('password123', $user->password));
        $this->assertFalse($user->hasTwoFactorEnabled(), '2FA is configured by the user on first login');
    }

    public function test_a_global_manager_is_not_attached_to_any_application(): void
    {
        $this->actingAsAdmin();
        $business = Business::factory()->create();

        $response = $this->postJson('/api/v1/users', [
            'name' => 'Global Manager',
            'email' => 'global@example.com',
            'password' => 'password123',
            'role' => 'manager',
            'scope' => 'global',
            'business_ids' => [$business->id],
        ])->assertStatus(201);

        $this->assertSame('global', $response->json('data.scope'));
        $this->assertCount(0, $response->json('data.businesses'));
    }

    public function test_an_admin_is_always_global(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/users', [
            'name' => 'Second Admin',
            'email' => 'admin2@example.com',
            'password' => 'password123',
            'role' => 'admin',
            'scope' => 'restricted',
        ])->assertStatus(201);

        $this->assertSame('global', $response->json('data.scope'));
    }

    public function test_creating_a_user_validates_the_payload(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/users', [
            'name' => '',
            'email' => 'not-an-email',
            'password' => 'short',
            'role' => 'wizard',
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['name', 'email', 'password', 'role']]);
    }

    public function test_email_must_be_unique(): void
    {
        $this->actingAsAdmin();
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/v1/users', [
            'name' => 'Duplicate',
            'email' => 'taken@example.com',
            'password' => 'password123',
            'role' => 'manager',
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['email']]);
    }

    public function test_assigning_applications_to_a_manager(): void
    {
        $this->actingAsAdmin();
        $manager = User::factory()->create();
        $businesses = Business::factory()->count(3)->create();

        $response = $this->putJson("/api/v1/users/{$manager->id}/businesses", [
            'scope' => 'restricted',
            'business_ids' => $businesses->take(2)->pluck('id')->all(),
        ])->assertOk();

        $this->assertCount(2, $response->json('data.businesses'));

        // Switching to global drops the explicit assignments.
        $response = $this->putJson("/api/v1/users/{$manager->id}/businesses", ['scope' => 'global'])->assertOk();

        $this->assertCount(0, $response->json('data.businesses'));
        $this->assertSame('global', $manager->fresh()->scope);
    }

    public function test_updating_a_user(): void
    {
        $this->actingAsAdmin();
        $manager = User::factory()->create(['name' => 'Before']);

        $this->putJson("/api/v1/users/{$manager->id}", ['name' => 'After', 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.name', 'After')
            ->assertJsonPath('data.is_active', false);
    }

    public function test_a_disabled_user_loses_access_immediately(): void
    {
        $admin = $this->actingAsAdmin();
        $manager = $this->globalManager();

        $this->putJson("/api/v1/users/{$manager->id}", ['is_active' => false])->assertOk();

        $this->actingAsUser($manager->fresh())
            ->getJson('/api/v1/businesses')
            ->assertStatus(401);

        $this->assertNotNull($admin);
    }

    public function test_the_last_admin_cannot_be_demoted(): void
    {
        $admin = $this->actingAsAdmin();

        $this->putJson("/api/v1/users/{$admin->id}", ['role' => 'manager'])
            ->assertStatus(422);

        $this->assertSame('admin', $admin->fresh()->role);
    }

    public function test_the_last_admin_cannot_be_disabled(): void
    {
        $admin = $this->actingAsAdmin();

        $this->putJson("/api/v1/users/{$admin->id}", ['is_active' => false])->assertStatus(422);
    }

    public function test_an_admin_cannot_delete_their_own_account(): void
    {
        $admin = $this->actingAsAdmin();

        $this->deleteJson("/api/v1/users/{$admin->id}")->assertStatus(422);
    }

    public function test_deleting_a_manager(): void
    {
        $this->actingAsAdmin();
        $manager = User::factory()->create();

        $this->deleteJson("/api/v1/users/{$manager->id}")->assertOk();
        $this->assertSoftDeleted('users', ['id' => $manager->id]);
    }

    public function test_an_admin_can_reset_a_user_two_factor(): void
    {
        $this->actingAsAdmin();
        $manager = User::factory()->twoFactorEnabled()->create();

        $this->postJson("/api/v1/users/{$manager->id}/reset-two-factor")->assertOk();

        $this->assertFalse($manager->fresh()->hasTwoFactorEnabled());
    }

    public function test_business_options_are_listed_for_the_assignment_picker(): void
    {
        $this->actingAsAdmin();
        Business::factory()->count(2)->create();

        $this->getJson('/api/v1/users/options/businesses')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['id', 'name', 'status']]]);
    }
}
