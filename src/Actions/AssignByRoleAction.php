<?php

declare(strict_types=1);

/**
 * AssignByRoleAction
 *
 * Assign users with a given role to a model's relationship. Supports
 * multiple distribution strategies: assign all matching, round-robin
 * one, or pick the user with the lowest current load.
 *
 * Inspired by RubixSmartNavigator-v2's WorkflowEngine::getUsersFromAction()
 * (the role branch). Decoupled from any specific domain model.
 *
 * Requires spatie/laravel-permission for role lookup.
 *
 * @author Codenzia
 */

namespace Codenzia\FilamentWorkflow\Actions;

use Codenzia\FilamentWorkflow\Engine\Contracts\ActionHandlerInterface;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AssignByRoleAction implements ActionHandlerInterface
{
    public static function label(): string
    {
        return 'Assign by Role';
    }

    /**
     * Config keys:
     * - roles: string[] (required) — Spatie role names to resolve users from.
     * - relationship: string (required) — relationship method on the model.
     * - mode: 'sync' | 'sync_without_detaching' | 'attach' (default 'sync_without_detaching').
     * - limit: ?int — cap recipients (e.g. round-robin to first N).
     * - strategy: 'all' | 'round_robin' | 'least_loaded' (default 'all').
     * - team_scope_field: ?string — model attribute holding a team ID;
     *   only users on that team are eligible.
     */
    public function execute(Model $model, array $config, array $context): array
    {
        $roles = (array) ($config['roles'] ?? []);
        $relationship = $config['relationship'] ?? null;

        if (empty($roles)) {
            return ['action' => 'assign_by_role', 'skipped' => true, 'reason' => 'No roles configured'];
        }

        if (! $relationship) {
            return ['action' => 'assign_by_role', 'skipped' => true, 'reason' => 'No relationship configured'];
        }

        $userModel = config('auth.providers.users.model', 'App\\Models\\User');
        if (! method_exists($userModel, 'scopeRole')) {
            return [
                'action' => 'assign_by_role',
                'skipped' => true,
                'reason' => 'User model does not support role() scope (install spatie/laravel-permission)',
            ];
        }

        $candidates = $userModel::role($roles);

        if (! empty($config['team_scope_field'])) {
            $teamId = $model->getAttribute($config['team_scope_field']);
            if (! $teamId) {
                return ['action' => 'assign_by_role', 'skipped' => true, 'reason' => 'Team scope field is empty on model'];
            }

            // Only constrain by team if the User model exposes a `teams` relationship.
            $candidates = $candidates->whereHas('teams', fn ($q) => $q->where('teams.id', $teamId));
        }

        $candidates = $candidates->get();
        if ($candidates->isEmpty()) {
            return [
                'action' => 'assign_by_role',
                'skipped' => true,
                'reason' => 'No eligible users for the configured role(s)',
                'roles' => $roles,
            ];
        }

        $strategy = $config['strategy'] ?? 'all';
        $limit = isset($config['limit']) ? (int) $config['limit'] : null;
        $userIds = $this->pickRecipients($candidates, $strategy, $limit, $model);

        if (empty($userIds)) {
            return ['action' => 'assign_by_role', 'skipped' => true, 'reason' => 'Strategy returned no recipients'];
        }

        if (! method_exists($model, $relationship)) {
            return [
                'action' => 'assign_by_role',
                'skipped' => true,
                'reason' => "Relationship '{$relationship}' does not exist on model",
            ];
        }

        $relation = $model->{$relationship}();
        if (! $relation instanceof BelongsToMany) {
            return [
                'action' => 'assign_by_role',
                'skipped' => true,
                'reason' => "Relationship '{$relationship}' is not BelongsToMany",
            ];
        }

        $mode = $config['mode'] ?? 'sync_without_detaching';

        match ($mode) {
            'sync' => $relation->sync($userIds),
            'attach' => $relation->attach($userIds),
            default => $relation->syncWithoutDetaching($userIds),
        };

        return [
            'action' => 'assign_by_role',
            'roles' => $roles,
            'relationship' => $relationship,
            'mode' => $mode,
            'strategy' => $strategy,
            'resolved_user_ids' => $userIds,
        ];
    }

    /**
     * @param  Collection<int, Model>  $candidates
     * @return array<int>
     */
    protected function pickRecipients($candidates, string $strategy, ?int $limit, Model $model): array
    {
        $picked = match ($strategy) {
            'round_robin' => $this->roundRobin($candidates, $model),
            'least_loaded' => $this->leastLoaded($candidates),
            default => $candidates,
        };

        if ($strategy === 'round_robin' || $strategy === 'least_loaded') {
            $picked = collect([$picked])->filter();
        }

        $ids = $picked->pluck('id')->map(fn ($id) => (int) $id);

        if ($limit !== null && $limit > 0) {
            $ids = $ids->take($limit);
        }

        return $ids->all();
    }

    /**
     * Pick a single user via round-robin keyed off the model's id —
     * deterministic so retries pick the same user.
     */
    protected function roundRobin($candidates, Model $model): ?Model
    {
        $count = $candidates->count();
        if ($count === 0) {
            return null;
        }

        $modelId = (int) $model->getKey();

        return $candidates->values()->get($modelId % $count);
    }

    /**
     * Pick the user with the lowest current load. Requires the User model
     * to expose a `getAssigneeLoad(): int` method; otherwise falls back
     * to the first candidate.
     */
    protected function leastLoaded($candidates): ?Model
    {
        $first = $candidates->first();
        if (! $first || ! method_exists($first, 'getAssigneeLoad')) {
            return $first;
        }

        return $candidates->sortBy(fn (Model $u) => $u->getAssigneeLoad())->first();
    }

    public static function configSchema(): array
    {
        return [
            TagsInput::make('config.roles')
                ->label('Roles')
                ->placeholder('Press Enter after each role name')
                ->helperText('Spatie role names; users matching ANY of these are eligible')
                ->required(),
            TextInput::make('config.relationship')
                ->label('Relationship Method')
                ->placeholder('e.g., assignees')
                ->helperText('Many-to-many relationship method on the model')
                ->required(),
            Select::make('config.strategy')
                ->label('Strategy')
                ->options([
                    'all' => 'Assign all eligible users',
                    'round_robin' => 'Round-robin one user',
                    'least_loaded' => 'Least loaded user (requires getAssigneeLoad())',
                ])
                ->default('all'),
            TextInput::make('config.limit')
                ->label('Limit')
                ->numeric()
                ->minValue(1)
                ->helperText('Cap on number of users assigned (applies to "all" strategy)'),
            TextInput::make('config.team_scope_field')
                ->label('Team Scope Field')
                ->placeholder('e.g., team_id')
                ->helperText('Only users belonging to this team are eligible'),
            Select::make('config.mode')
                ->label('Mode')
                ->options([
                    'sync_without_detaching' => 'Sync without detaching (recommended)',
                    'sync' => 'Sync (detach others)',
                    'attach' => 'Attach (allows duplicates)',
                ])
                ->default('sync_without_detaching'),
        ];
    }
}
