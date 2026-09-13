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
            if (WorkflowEngine::isExecuting($model)) {
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
            if (WorkflowEngine::isExecuting($model)) {
                return;
            }

            $changedFields = array_keys($model->getDirty());
            if (empty($changedFields)) {
                return;
            }

            // Only snapshot fields the host registered as watched for THIS
            // model. Prevents sensitive attributes (password hashes, tokens,
            // PII) from being serialized into queue/failed_jobs payloads, and
            // avoids dispatch amplification for fields no workflow observes.
            // A model with no registered fields watches nothing — it must not
            // fall back to serializing every changed attribute.
            $watched = array_keys(WorkflowEngine::getModelFields($model::class));
            $changedFields = array_values(array_intersect($changedFields, $watched));

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

            // Dispatch a generic field.changed trigger once per update. This is
            // the key FieldChangedTrigger should be registered under — it reads
            // the watched field from its own config and inspects changed_fields
            // in the context, so a single dispatch covers any watched field.
            EvaluateWorkflowJob::dispatch(
                $model::class,
                $model->getKey(),
                'field.changed',
                $context,
                $projectId,
            );

            // Also dispatch field-specific triggers (field.{name}.changed) for
            // hosts that register triggers keyed by an explicit field name.
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
