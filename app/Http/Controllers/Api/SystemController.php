<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Laravel\Horizon\WaitTimeCalculator;

/**
 * Health of the pieces the console depends on.
 *
 * @OA\Tag(name="System", description="Runtime health")
 */
class SystemController extends BaseController
{
    /**
     * A queue waiting longer than this is worth warning about.
     */
    private const SLOW_QUEUE_SECONDS = 120;

    /**
     * @OA\Get(
     *     path="/api/v1/system/horizon",
     *     tags={"System"},
     *     security={{"bearerAuth":{}}},
     *     summary="Queue worker status",
     *     description="Whether Horizon is processing jobs. When it is down, messages pile up in the queue and nothing is delivered.",
     *     @OA\Response(response=200, description="Horizon status and workload")
     * )
     */
    public function horizon(
        MasterSupervisorRepository $masters,
        WorkloadRepository $workload,
        JobRepository $jobs,
        WaitTimeCalculator $waitTime,
    ): JsonResponse {
        try {
            $supervisors = collect($masters->all());
        } catch (\Throwable $e) {
            // Redis unreachable: we cannot tell, and saying "running" would be
            // worse than saying "unknown".
            Log::warning('Horizon status unavailable', ['error' => $e->getMessage()]);

            return $this->successResponse([
                'status' => 'unknown',
                'healthy' => false,
                'message' => 'Could not reach the queue backend: ' . $e->getMessage(),
                'supervisors' => 0,
                'queues' => [],
                'pending_jobs' => 0,
                'failed_jobs' => 0,
                'longest_wait_seconds' => 0,
            ]);
        }

        if ($supervisors->isEmpty()) {
            return $this->successResponse([
                'status' => 'inactive',
                'healthy' => false,
                'message' => 'Horizon is not running: queued messages will not be delivered.',
                'supervisors' => 0,
                'queues' => [],
                'pending_jobs' => 0,
                'failed_jobs' => $this->failedCount($jobs),
                'longest_wait_seconds' => 0,
            ]);
        }

        // A single paused master is enough to stop the whole pipeline.
        $paused = $supervisors->contains(fn ($master) => ($master->status ?? null) === 'paused');

        $queues = collect($workload->get())->map(fn ($queue) => [
            'name' => $queue['name'] ?? '',
            'length' => (int) ($queue['length'] ?? 0),
            'wait_seconds' => (int) ($queue['wait'] ?? 0),
            'processes' => (int) ($queue['processes'] ?? 0),
        ])->values();

        $pending = $queues->sum('length');
        $longestWait = (int) ($queues->max('wait_seconds') ?? 0);

        $status = $paused ? 'paused' : 'running';
        $healthy = ! $paused && $longestWait < self::SLOW_QUEUE_SECONDS;

        return $this->successResponse([
            'status' => $status,
            'healthy' => $healthy,
            'message' => $this->describe($status, $longestWait),
            'supervisors' => $supervisors->count(),
            'queues' => $queues,
            'pending_jobs' => $pending,
            'failed_jobs' => $this->failedCount($jobs),
            'longest_wait_seconds' => $longestWait,
            // Kept for a possible future use of the calculator.
            'measured_at' => now()->toIso8601String(),
        ]);
    }

    private function describe(string $status, int $longestWait): string
    {
        if ($status === 'paused') {
            return 'Horizon is paused: queued messages are waiting.';
        }

        if ($longestWait >= self::SLOW_QUEUE_SECONDS) {
            return 'The queue is falling behind: the oldest job has been waiting '
                . $this->humanize($longestWait) . '.';
        }

        return 'Horizon is processing jobs normally.';
    }

    private function humanize(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' s';
        }

        if ($seconds < 3600) {
            return intdiv($seconds, 60) . ' min';
        }

        return round($seconds / 3600, 1) . ' h';
    }

    private function failedCount(JobRepository $jobs): int
    {
        try {
            return (int) $jobs->countRecentlyFailed();
        } catch (\Throwable) {
            return 0;
        }
    }
}
