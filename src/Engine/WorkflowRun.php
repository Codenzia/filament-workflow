<?php

declare(strict_types=1);

/**
 * WorkflowRun
 *
 * Durable identity and budget for a single workflow run. The same run
 * survives delay nodes: its id, start time, hop count and visited-node set
 * travel with the queued continuation, so cycle detection and node budgets
 * apply across delays instead of resetting on every resume.
 *
 * @author Codenzia
 */

namespace Codenzia\FilamentWorkflow\Engine;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class WorkflowRun
{
    /**
     * @param  array<int, true>  $visited  Node IDs already executed in this run.
     */
    protected function __construct(
        public readonly string $id,
        public readonly Carbon $startedAt,
        public readonly int $hop,
        protected array $visited = [],
    ) {}

    /**
     * Begin a fresh run at hop 0.
     */
    public static function start(): self
    {
        return new self((string) Str::uuid(), Carbon::now(), 0);
    }

    /**
     * Rebuild a run from a queued continuation payload. An empty or
     * unrecognised payload starts a fresh run so continuations queued by an
     * older package version stay runnable.
     */
    public static function fromArray(array $payload): self
    {
        if (! isset($payload['id'], $payload['started_at'])) {
            return static::start();
        }

        return new self(
            (string) $payload['id'],
            Carbon::parse((string) $payload['started_at']),
            (int) ($payload['hop'] ?? 0),
            array_fill_keys(array_map('intval', (array) ($payload['visited'] ?? [])), true),
        );
    }

    /**
     * The payload the next delayed continuation of this run carries.
     *
     * @return array{id: string, started_at: string, hop: int, visited: array<int, int>}
     */
    public function toContinuationArray(): array
    {
        return [
            'id' => $this->id,
            'started_at' => $this->startedAt->toIso8601String(),
            'hop' => $this->hop + 1,
            'visited' => array_keys($this->visited),
        ];
    }

    public function hasVisited(int $nodeId): bool
    {
        return isset($this->visited[$nodeId]);
    }

    public function markVisited(int $nodeId): void
    {
        $this->visited[$nodeId] = true;
    }

    /**
     * Nodes executed so far in this run, across every delayed continuation.
     */
    public function executedCount(): int
    {
        return count($this->visited);
    }

    public function exceedsHopLimit(int $maxHops): bool
    {
        return $maxHops > 0 && $this->hop > $maxHops;
    }

    public function exceedsDuration(int $maxDays): bool
    {
        return $maxDays > 0 && $this->startedAt->copy()->addDays($maxDays)->isPast();
    }
}
