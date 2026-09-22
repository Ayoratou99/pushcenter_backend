<?php

namespace Tests\Unit;

use Illuminate\Support\Str;
use Laravel\Horizon\ProvisioningPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Horizon starts the supervisors of the first `environments` entry matching
 * APP_ENV, and none at all when nothing matches.
 */
class HorizonConfigTest extends TestCase
{
    /**
     * @return array<string, \Laravel\Horizon\SupervisorOptions>
     */
    private function supervisorsFor(string $environment): array
    {
        $plan = new ProvisioningPlan('aninfpush', config('horizon.environments'), config('horizon.defaults'));

        return collect(collect($plan->toSupervisorOptions())
            ->first(fn ($_, string $name) => Str::is($name, $environment)) ?? [])->all();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function environments(): array
    {
        return [
            'production' => ['production'],
            'local' => ['local'],
            'preprod' => ['preprod'],
            'staging' => ['staging'],
        ];
    }

    #[DataProvider('environments')]
    public function test_every_app_env_gets_workers_for_every_queue(string $environment): void
    {
        $supervisors = $this->supervisorsFor($environment);

        $this->assertNotEmpty($supervisors, "No Horizon supervisor for APP_ENV={$environment}");

        $queues = collect($supervisors)
            ->flatMap(fn ($options) => explode(',', $options->queue))
            ->sort()
            ->values()
            ->all();

        // Every queue a job is dispatched on (see app/Jobs).
        $this->assertSame(['default', 'emails', 'sms', 'telegram', 'webhooks', 'whatsapp'], $queues);
    }

    public function test_webhooks_never_share_workers_with_message_delivery(): void
    {
        $supervisors = $this->supervisorsFor('production');

        $this->assertSame('webhooks', $supervisors['supervisor-webhooks']->queue);
        $this->assertStringNotContainsString('webhooks', $supervisors['supervisor-1']->queue);
        $this->assertSame(1, (int) $supervisors['supervisor-1']->minProcesses);
        $this->assertSame(10, (int) $supervisors['supervisor-1']->maxProcesses);
        $this->assertSame(3, (int) $supervisors['supervisor-webhooks']->maxProcesses);
    }

    public function test_workers_are_killed_before_redis_hands_the_job_to_another_one(): void
    {
        $retryAfter = (int) config('queue.connections.redis.retry_after');

        foreach ($this->supervisorsFor('production') as $name => $options) {
            $this->assertLessThan($retryAfter, (int) $options->timeout, "{$name} timeout must stay below retry_after");
        }
    }
}
