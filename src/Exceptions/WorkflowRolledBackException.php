<?php

declare(strict_types=1);

/**
 * WorkflowRolledBackException
 *
 * Sentinel exception thrown by WorkflowEngine inside a DB::transaction
 * to abort and roll back a workflow run when one of its actions failed.
 * Caught immediately by the engine — never propagates to the queue
 * worker as a job failure.
 *
 * @author Codenzia
 */

namespace Codenzia\FilamentWorkflow\Exceptions;

use RuntimeException;

class WorkflowRolledBackException extends RuntimeException {}
