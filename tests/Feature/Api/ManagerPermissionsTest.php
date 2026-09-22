<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A manager edits its own applications and manages the managers attached to
 * them; creating or deleting applications and handing out admin or global
 * access stay with administrators.
 */
class ManagerPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private Business $mine;

    private Business $other;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mine = Business::factory()->create(['name' => 'Guichet ANINF']);
        $this->other = Business::factory()->create(['name' => 'Autre application']);
        $this->manager = $this->restrictedManager([$this->mine->id]);
        $this->actingAsUser($this->manager);
    }

    private function newUser(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Nouvel agent',
            'email' => 'agent@aninf.test',
            'password' => 'Secret123',
            'role' => 'manager',
            'business_ids' => [$this->mine->id],
        ], $overrides);
    }

    /* ---------------------------- Applications -------------------------- */

    public function test_a_manager_cannot_create_an_application(): void
    {
        $this->postJson('/api/v1/businesses', ['name' => 'Nouvelle', 'email' => 'x@aninf.test'])->assertForbidden();

        $this->assertDatabaseCount('businesses', 2);
    }

    public function test_a_manager_cannot_delete_its_application(): void
    {
        $this->deleteJson("/api/v1/businesses/{$this->mine->id}")->assertForbidden();

        $this->assertNotSoftDeleted($this->mine);
    }

    public function test_a_manager_edits_its_own_application_only(): void
    {
        $this->putJson("/api/v1/businesses/{$this->mine->id}", ['name' => 'Guichet ANINF 2'])->assertOk();
        $this->putJson("/api/v1/businesses/{$this->other->id}", ['name' => 'Piratée'])->assertForbidden();

        $this->assertSame('Guichet ANINF 2', $this->mine->fresh()->name);
        $this->assertSame('Autre application', $this->other->fresh()->name);
    }

    /* -------------------------------- Users ----------------------------- */

    public function test_a_manager_adds_a_user_to_its_application(): void
    {
        $this->postJson('/api/v1/users', $this->newUser())
            ->assertCreated()
            ->assertJsonPath('data.role', 'manager')
            ->assertJsonPath('data.scope', 'restricted')
            ->assertJsonPath('data.businesses.0.id', $this->mine->id);
    }

    public function test_a_manager_cannot_attach_a_user_to_another_application(): void
    {
        $this->postJson('/api/v1/users', $this->newUser(['business_ids' => [$this->mine->id, $this->other->id]]))
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'agent@aninf.test']);
    }

    public function test_a_new_user_must_be_attached_to_one_of_its_applications(): void
    {
        $this->postJson('/api/v1/users', $this->newUser(['business_ids' => []]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('business_ids');
    }

    public function test_a_manager_cannot_create_admins_or_global_managers(): void
    {
        $this->postJson('/api/v1/users', $this->newUser(['role' => 'admin']))->assertForbidden();
        $this->postJson('/api/v1/users', $this->newUser(['scope' => 'global']))->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'agent@aninf.test']);
    }

    public function test_a_manager_manages_the_users_of_its_application(): void
    {
        $agent = $this->restrictedManager([$this->mine->id]);

        $this->putJson("/api/v1/users/{$agent->id}", ['name' => 'Renommé'])->assertOk();
        $this->postJson("/api/v1/users/{$agent->id}/reset-two-factor")->assertOk();
        $this->deleteJson("/api/v1/users/{$agent->id}")->assertOk();

        $this->assertSoftDeleted($agent);
    }

    public function test_a_user_shared_with_another_application_is_read_only(): void
    {
        $shared = $this->restrictedManager([$this->mine->id, $this->other->id]);

        $this->getJson("/api/v1/users/{$shared->id}")->assertOk()->assertJsonPath('data.can_manage', false);
        $this->putJson("/api/v1/users/{$shared->id}", ['name' => 'Renommé'])->assertForbidden();
        $this->putJson("/api/v1/users/{$shared->id}/businesses", ['business_ids' => [$this->mine->id]])->assertForbidden();
        $this->deleteJson("/api/v1/users/{$shared->id}")->assertForbidden();
    }

    public function test_admins_and_other_applications_users_are_out_of_reach(): void
    {
        $admin = $this->adminUser();
        $stranger = $this->restrictedManager([$this->other->id]);

        $this->getJson("/api/v1/users/{$admin->id}")->assertNotFound();
        $this->getJson("/api/v1/users/{$stranger->id}")->assertNotFound();
        $this->putJson("/api/v1/users/{$admin->id}", ['name' => 'x'])->assertForbidden();
        $this->deleteJson("/api/v1/users/{$stranger->id}")->assertForbidden();
    }

    public function test_a_manager_cannot_promote_a_user(): void
    {
        $agent = $this->restrictedManager([$this->mine->id]);

        $this->putJson("/api/v1/users/{$agent->id}", ['role' => 'admin'])->assertForbidden();
        $this->putJson("/api/v1/users/{$agent->id}", ['scope' => 'global'])->assertForbidden();

        $this->assertSame('manager', $agent->fresh()->role);
        $this->assertSame('restricted', $agent->fresh()->scope);
    }

    public function test_a_manager_cannot_change_its_own_access(): void
    {
        $this->putJson("/api/v1/users/{$this->manager->id}", ['business_ids' => [$this->mine->id, $this->other->id]])
            ->assertForbidden();
    }

    public function test_only_its_applications_are_offered(): void
    {
        $this->getJson('/api/v1/users/options/businesses')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->mine->id);
    }

    public function test_an_admin_keeps_full_control(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/users', $this->newUser(['role' => 'admin', 'email' => 'admin2@aninf.test']))->assertCreated();
        $this->postJson('/api/v1/businesses', [
            'name' => 'Nouvelle application',
            'email' => 'nouvelle@aninf.test',
        ])->assertCreated();
    }
}
