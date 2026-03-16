<?php

declare(strict_types=1);

/**
 * HasWorkflows Trait
 *
 * Add this trait to Eloquent models that should trigger workflow
 * evaluation on create/update/delete events.
 *
 * @author Codenzia
 */

namespace Codenzia\FilamentWorkflow\Concerns;

use Codenzia\FilamentWorkflow\Engine\WorkflowEngine;
use Codenzia\FilamentWorkflow\Jobs\EvaluateWorkflowJob;

trait HasWorkflows
{
    public static function bootHasWorkflows(): void
    {
        static::created(function ($model): void {
            if (WorkflowEngine::isExecuting()) {
                return;
            }

            $projectId = method_exists($model, 'getWorkflowProjectId')
                ? $model->getWorkflowProjectId()
                : ($model->project_id ?? null);

            EvaluateWorkflowJob::dispatch(
                $model::class,
                $model->getKey(),
                'model.created',
                ['event' => 'created'],
                $projectId,
            );
        });

        static::updated(function ($model): void {
            if (WorkflowEngine::isExecuting()) {
                return;
            }

            $changedFields = array_keys($model->getDirty());
            if (empty($changedFields)) {
                return;
            }

            $oldValues = [];
            $newValues = [];
            foreach ($changedFields as $field) {
                $oldValues[$field] = $model->getOriginal($field);
                $newValues[$field] = $model->getAttribute($field);
            }

            $projectId = method_exists($model, 'getWorkflowProjectId')
                ? $model->getWorkflowProjectId()
                : ($model->project_id ?? null);

            $context = [
                'event' => 'updated',
                'changed_fields' => $changedFields,
                'old' => $oldValues,
                'new' => $newValues,
            ];

            // Dispatch general updated trigger
            EvaluateWorkflowJob::dispatch(
                $model::class,
                $model->getKey(),
                'model.updated',
                $context,
                $projectId,
            );

            // Dispatch field-specific triggers
            foreach ($changedFields as $field) {
                EvaluateWorkflowJob::dispatch(
                    $model::class,
                    $model->getKey(),
                    "field.{$field}.changed",
                    $context,
                    $projectId,
                );
            }
        });
    }
}
