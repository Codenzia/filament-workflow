<?php

declare(strict_types=1);

/**
 * HandlesLockContention
 *
 * Shared backoff schedule for workflow jobs that lose the per-model lock.
 * Contention means another worker is running workflows for the same record
 * right now — the work is still due, so the job is released back onto the
 * queue with an exponential, capped delay instead of being dropped.
 *
 * @author Codenzia
 */

namespace Codenzia\FilamentWorkflow\Jobs\Concerns;

trait HandlesLockContention
{
    /**
     * Seconds to wait before the next attempt at this job.
     */
    protected function contentionDelay(): int
    {
        $base = (int) config('filament-workflow.lock_retry_base_seconds', 5);
        $max = (int) config('filament-workflow.lock_retry_max_seconds', 300);

        return max(1, min($base * (2 ** max(0, $this->attempts() - 1)), $max));
    }
}
