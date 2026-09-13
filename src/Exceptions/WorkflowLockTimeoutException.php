<?php

declare(strict_types=1);

/**
 * WorkflowLockTimeoutException
 *
 * Thrown by WorkflowEngine when the per-model cross-process lock could not
 * be acquired within the configured wait window. The queued jobs catch it
 * and release themselves back onto the queue with backoff, so a contended
 * event is retried rather than silently dropped.
 *
 * @author Codenzia
 */

namespace Codenzia\FilamentWorkflow\Exceptions;

use RuntimeException;

class WorkflowLockTimeoutException extends RuntimeException {}
