# Changelog

All notable changes to `codenzia/filament-workflow` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.3.2] - 2026-09-13

### Fixed
- `TriggerInterface` and `ActionHandlerInterface` imported `Filament\Forms\Components\Component` for their `@return array<Component>` contracts. That class has not existed since Filament v4 moved the schema base class to `Filament\Schemas\Components\Component`, so the published contract pointed implementers at a missing class and broke static analysis. Both imports now name the real class; the runtime contract is unchanged.

### Added
- `tests/Feature/FilamentImportsResolveTest` resolves every `use Filament\...;` import in `src/` against the installed Filament.

## [0.3.1] - 2026-09-08

### Changed
- Accept `codenzia/filament-diagrammer` ^0.3.

### Fixed
- The workflow designer now overrides `canEditDiagram()`. Diagrammer 0.3 fails
  closed by default, so without the override the canvas would have rendered
  read-only for everyone; it now follows the workflow's own edit ability and
  additionally requires a selected workflow.
- `onNodeCreate` accepts a null node type. Diagrammer 0.3 also fires the
  creation callback for duplicated nodes, passing `null` for a copy of an
  inline node; the designer records such a node as an action node instead of
  raising a type error.

## [0.3.0] - 2026-09-08

### Changed (breaking)
- `WorkflowEngine::getModelFields($modelClass)` now returns an **empty array**
  for a model with no registration instead of the fields merged from every
  other registered model. The per-model list is a security allow-list, so a
  field registered on one model can no longer be written on an unrelated one.
  Call `getModelFields()` with no argument for the designer-wide catalog.
- `HasWorkflows` no longer falls back to serialising *every* changed attribute
  when a model has no registered fields. An unregistered model now dispatches
  no `model.updated` / `field.changed` evaluation at all — register its watched
  fields with `WorkflowEngine::registerModelFields()` to restore dispatch.
- `WorkflowEngine::evaluate()` and `executeNodeById()` now throw
  `WorkflowLockTimeoutException` when the per-model lock cannot be acquired,
  instead of logging and returning. `EvaluateWorkflowJob` and
  `ExecuteDelayedNodeJob` catch it and release themselves back onto the queue
  with backoff, so a contended event is retried rather than lost. Callers that
  invoke the engine synchronously must handle the exception.
- Action nodes are no longer logged as `success` when the handler reported a
  no-op. A handler returning `skipped => true` logs `skipped`, and one
  returning an `error` key logs the new `error` result. An errored action stops
  its branch; a deliberate skip still continues downstream.
- `SendNotificationAction` reports `skipped` when no notification class is
  configured — previously it reported success without sending anything.
- `ProcessTimeTriggersCommand::findMatchingModels()` returns a query builder (or
  `null` when the trigger type is unregistered) instead of a collection, so
  candidates can be chunked. Deduplication now suppresses a dispatch only after
  an accepted run (`success` / `delayed`); a skipped or failed attempt stays
  eligible.

### Added
- Durable run identity. Every run carries a `run_id`, start time, hop count and
  visited-node set, all stamped onto its execution log rows and carried into
  each delayed continuation.
- `max_run_hops` (default 100) and `max_run_days` (default 30) config keys
  bounding how far a run may travel across delays. Reaching either ends the run
  with the reason recorded in the execution log.
- `time_trigger_chunk_size` (default 500) config key for the time-trigger
  command's candidate query.
- `lock_retry_base_seconds` / `lock_retry_max_seconds` (defaults 5 / 300)
  config keys for the queued-job backoff on lock contention.
- `error` execution result rendered in the designer's execution history.

### Fixed
- FW-01: a cycle passing through a delay node scheduled itself forever. The
  visited-node set and node budget now survive the delay, so the resumed run
  detects the cycle and stops.
- FW-02: notifications and dispatched events escaped a workflow run that later
  rolled back. External effects and queued continuations now run through
  `DB::afterCommit()`, so a rolled-back run emits nothing.
- FW-03: after a rollback the in-memory model still carried the uncommitted
  writes, which the next workflow then evaluated conditions against. The model
  (and its loaded relationships) are refreshed before evaluation continues.
- FW-04: skipped and errored actions were recorded as successful executions.
- FW-05: lock contention silently discarded the event.
- FW-06: an unregistered model borrowed another model's field allow-list, and
  serialised every changed attribute — including sensitive ones — into queue
  payloads.
- FW-07: the time-trigger scheduler dispatched without the workflow id, so one
  due trigger node re-evaluated every workflow sharing its trigger type.
  Candidates are now chunked and the recent-run lookup is batched per chunk.

## [0.2.0] - 2026-07-13

### Changed (breaking)
- Engine refactored: action execution now supports rollback via a dedicated
  rollback exception; engine classes are instantiated through a hardened,
  allowlisted resolver instead of arbitrary class strings.
- `run_count` and `last_run_at` are no longer mass-assignable on the `Workflow`
  model (dropped from `$fillable`); they are managed by the engine.
- `codenzia/filament-diagrammer` dependency moved from `dev-main` to `^0.2.0`
  (first stable line with Livewire 4 support); Filament constraint widened to
  `^4.0 || ^5.0`.

### Added
- `ApprovalRequestAction` workflow node.
- Assignment action nodes.
- Execution-log retention pruning in the time-trigger command (configurable;
  disabled when retention is null).

### Security
- WORKFLOW-SEC-01/02/03/04: hardened workflow designer authorization and
  record scoping.

### Fixed
- WORKFLOW-QUAL-01/PERF-01/DEAD-01: command return type and log-retention fixes.
- WORKFLOW-STD-01/STD-02/DUP-01/PERF-02: Blade cleanups; node-type class and
  color centralized on the enum.

### Upgrade notes — database migrations (v0.1.0 → v0.2.0)
- **Changed** `2026_03_16_000001_create_workflows_table.php`:
  `workflows.project_id` no longer declares an unconditional foreign key to a
  `projects` table. It is now a plain nullable, indexed foreign id; the FK is
  attached only when the host app actually has a `projects` table. Existing
  installs keep their current schema (create-table migrations don't re-run);
  fresh installs on hosts without a `projects` table now migrate cleanly on
  sqlite/mysql/pgsql.
- **New** `2026_07_03_000001_add_executed_at_index_to_workflow_execution_logs_table.php`:
  adds an index on `workflow_execution_logs.executed_at` (supports log-retention
  pruning). Run `php artisan migrate` after upgrading.

## [0.1.0] - 2026-05-20

### Added
- First tracked release. Early beta. Earlier history not recorded in this changelog — see git log for changes prior to release-tracker adoption.
