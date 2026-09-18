<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Mockery;
use Tests\TestCase;

/**
 * The queue worker health the console shows as a banner.
 */
class SystemStatusTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, object>  $masters
     * @param  array<int, array<string, mixed>>  $workload
     */
    private function fakeHorizon(array $masters, array $workload = [], int $failed = 0): void
    {
        $this->instance(
            MasterSupervisorRepository::class,
            Mockery::mock(MasterSupervisorRepository::class, fn ($mock) => $mock->shouldReceive('all')->andReturn($masters))
        );

        $this->instance(
            WorkloadRepository::class,
            Mockery::mock(WorkloadRepository::class, fn ($mock) => $mock->shouldReceive('get')->andReturn($workload))
        );

        $this->instance(
            JobRepository::class,
            Mockery::mock(JobRepository::class, fn ($mock) => $mock->shouldReceive('countRecentlyFailed')->andReturn($failed))
        );
    }

    private function master(string $status = 'running'): object
    {
        return (object) ['name' => 'master-1', 'status' => $status, 'pid' => 1234];
    }

    public function test_a_running_horizon_is_healthy(): void
    {
        $this->actingAsUser($this->globalManager());
        $this->fakeHorizon([$this->master()], [
            ['name' => 'emails', 'length' => 2, 'wait' => 3, 'processes' => 1],
        ]);

        $this->getJson('/api/v1/system/horizon')
            ->assertOk()
            ->assertJsonPath('data.status', 'running')
            ->assertJsonPath('data.healthy', true)
            ->assertJsonPath('data.supervisors', 1)
            ->assertJsonPath('data.pending_jobs', 2);
    }

    /**
     * The case the banner exists for: nothing is processing the queues, so
     * everything the applications send just sits there.
     */
    public function test_no_supervisor_means_horizon_is_down(): void
    {
        $this->actingAsUser($this->globalManager());
        $this->fakeHorizon([]);

        $this->getJson('/api/v1/system/horizon')
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.healthy', false)
            ->assertJsonPath('data.supervisors', 0)
            ->assertJsonFragment(['message' => 'Horizon is not running: queued messages will not be delivered.']);
    }

    public function test_a_paused_supervisor_is_reported(): void
    {
        $this->actingAsUser($this->globalManager());
        $this->fakeHorizon([$this->master('paused')], [
            ['name' => 'emails', 'length' => 10, 'wait' => 5, 'processes' => 1],
        ]);

        $this->getJson('/api/v1/system/horizon')
            ->assertOk()
            ->assertJsonPath('data.status', 'paused')
            ->assertJsonPath('data.healthy', false);
    }

    public function test_one_paused_master_among_several_is_enough(): void
    {
        $this->actingAsUser($this->globalManager());
        $this->fakeHorizon([$this->master('running'), $this->master('paused')]);

        $this->getJson('/api/v1/system/horizon')
            ->assertOk()
            ->assertJsonPath('data.status', 'paused')
            ->assertJsonPath('data.healthy', false);
    }

    /**
     * Running but falling behind is still worth surfacing.
     */
    public function test_a_queue_falling_behind_is_not_healthy(): void
    {
        $this->actingAsUser($this->globalManager());
        $this->fakeHorizon([$this->master()], [
            ['name' => 'emails', 'length' => 500, 'wait' => 900, 'processes' => 1],
        ]);

        $response = $this->getJson('/api/v1/system/horizon')->assertOk();

        $response->assertJsonPath('data.status', 'running')
            ->assertJsonPath('data.healthy', false)
            ->assertJsonPath('data.longest_wait_seconds', 900);

        $this->assertStringContainsString('falling behind', $response->json('data.message'));
        $this->assertStringContainsString('15 min', $response->json('data.message'));
    }

    public function test_the_longest_wait_across_queues_is_reported(): void
    {
        $this->actingAsUser($this->globalManager());
        $this->fakeHorizon([$this->master()], [
            ['name' => 'emails', 'length' => 1, 'wait' => 4, 'processes' => 1],
            ['name' => 'webhooks', 'length' => 7, 'wait' => 61, 'processes' => 1],
        ]);

        $this->getJson('/api/v1/system/horizon')
            ->assertOk()
            ->assertJsonPath('data.longest_wait_seconds', 61)
            ->assertJsonPath('data.pending_jobs', 8)
            ->assertJsonCount(2, 'data.queues');
    }

    public function test_recently_failed_jobs_are_reported(): void
    {
        $this->actingAsUser($this->globalManager());
        $this->fakeHorizon([$this->master()], [], failed: 12);

        $this->getJson('/api/v1/system/horizon')
            ->assertOk()
            ->assertJsonPath('data.failed_jobs', 12);
    }

    /**
     * An unreachable queue backend must not be mistaken for a healthy one.
     */
    public function test_an_unreachable_backend_answers_unknown(): void
    {
        $this->actingAsUser($this->globalManager());

        $this->instance(
            MasterSupervisorRepository::class,
            Mockery::mock(MasterSupervisorRepository::class, function ($mock) {
                $mock->shouldReceive('all')->andThrow(new \RuntimeException('Connection refused'));
            })
        );

        $response = $this->getJson('/api/v1/system/horizon')->assertOk();

        $response->assertJsonPath('data.status', 'unknown')
            ->assertJsonPath('data.healthy', false);

        $this->assertStringContainsString('Connection refused', $response->json('data.message'));
    }

    public function test_the_endpoint_requires_authentication(): void
    {
        $this->withHeader('Authorization', 'Bearer invalid')
            ->getJson('/api/v1/system/horizon')
            ->assertStatus(401);
    }

    public function test_an_application_token_cannot_read_it(): void
    {
        $business = \App\Models\Business::factory()->create();

        $token = $this->postJson('/api/v1/auth/token', [
            'app_id' => $business->app_id,
            'app_secret' => $business->getPlainAppSecret(),
        ])->json('data.access_token');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/system/horizon')
            ->assertStatus(401);
    }
}
