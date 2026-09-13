<?php

declare(strict_types=1);

/**
 * AssignUserAction
 *
 * Assign one or more users to a model — via a many-to-many relationship,
 * a one-to-many relationship, or a single foreign-key field.
 *
 * Inspired by RubixSmartNavigator-v2's WorkflowEngine::assignToUser().
 * Decoupled from any specific domain model.
 *
 * @author Codenzia
 */

namespace Codenzia\FilamentWorkflow\Actions;

use Codenzia\FilamentWorkflow\Engine\Contracts\ActionHandlerInterface;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Schema;

class AssignUserAction implements ActionHandlerInterface
{
    public static function label(): string
    {
        return 'Assign User';
    }

    /**
     * Config keys:
     * - user_ids: int[] — explicit users to assign.
     * - user_field: ?string — model attribute holding a single user FK (e.g. 'created_by').
     *   Used as the source for $user_ids when set.
     * - relationship: ?string — many-to-many or has-many relationship method on
     *   the model (e.g. 'assignees'). If absent, falls back to a BelongsTo
     *   foreign-key write via $relationship.'_id' or the conventional default.
     * - mode: 'sync' | 'sync_without_detaching' | 'attach' | 'replace'
     *   (default 'sync_without_detaching').
     * - only_if_unassigned: bool (default false) — skip if model already has
     *   any users on the relationship.
     */
    public function execute(Model $model, array $config, array $context): array
    {
        $userIds = $this->resolveUserIds($model, $config);
        if (empty($userIds)) {
            return ['action' => 'assign_user', 'skipped' => true, 'reason' => 'No users resolved'];
        }

        // Filter to only existing users to avoid orphaned relationship rows.
        $userIds = $this->filterExistingUsers($userIds);
        if (empty($userIds)) {
            return ['action' => 'assign_user', 'skipped' => true, 'reason' => 'No matching user records'];
        }

        $relationship = $config['relationship'] ?? null;
        $mode = $config['mode'] ?? 'sync_without_detaching';
        $onlyIfUnassigned = (bool) ($config['only_if_unassigned'] ?? false);

        if ($relationship && method_exists($model, $relationship)) {
            $relation = $model->{$relationship}();

            if ($onlyIfUnassigned && $relation->count() > 0) {
                return ['action' => 'assign_user', 'skipped' => true, 'reason' => 'Model already has assignees'];
            }

            return $this->assignViaRelationship($relation, $relationship, $userIds, $mode);
        }

        // No method-based relationship — try a single FK write.
        $fkField = $config['user_field'] ?? ($relationship ? $relationship.'_id' : null);
        if ($fkField && Schema::hasColumn($model->getTable(), $fkField)) {
            if ($onlyIfUnassigned && $model->getAttribute($fkField)) {
                return ['action' => 'assign_user', 'skipped' => true, 'reason' => 'Model FK already populated'];
            }

            $model->update([$fkField => $userIds[0]]);

            return [
                'action' => 'assign_user',
                'mode' => 'fk_write',
                'field' => $fkField,
                'user_id' => $userIds[0],
            ];
        }

        return [
            'action' => 'assign_user',
            'skipped' => true,
            'reason' => "No relationship '{$relationship}' or FK field on model",
        ];
    }

    /**
     * Resolve the list of user IDs from config (explicit list OR a single
     * value pulled from a model field).
     *
     * @return array<int>
     */
    protected function resolveUserIds(Model $model, array $config): array
    {
        $explicit = $config['user_ids'] ?? [];
        if (! empty($explicit)) {
            return array_values(array_filter(array_map('intval', (array) $explicit)));
        }

        $field = $config['user_field'] ?? null;
        if ($field) {
            $value = $model->getAttribute($field);
            if ($value) {
                return [(int) $value];
            }
        }

        return [];
    }

    /**
     * @param  array<int>  $ids
     * @return array<int>
     */
    protected function filterExistingUsers(array $ids): array
    {
        $userModel = config('auth.providers.users.model', 'App\\Models\\User');

        return $userModel::whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @param  BelongsToMany|HasMany|HasOne|BelongsTo|mixed  $relation
     * @param  array<int>  $userIds
     */
    protected function assignViaRelationship($relation, string $name, array $userIds, string $mode): array
    {
        if ($relation instanceof BelongsToMany) {
            match ($mode) {
                'sync' => $relation->sync($userIds),
                'attach' => $relation->attach($userIds),
                'replace' => $relation->sync($userIds),
                default => $relation->syncWithoutDetaching($userIds),
            };

            return [
                'action' => 'assign_user',
                'mode' => $mode,
                'relationship' => $name,
                'user_ids' => $userIds,
            ];
        }

        if ($relation instanceof BelongsTo) {
            $relation->associate($userIds[0]);
            $relation->getParent()->save();

            return [
                'action' => 'assign_user',
                'mode' => 'belongs_to',
                'relationship' => $name,
                'user_id' => $userIds[0],
            ];
        }

        if ($relation instanceof HasMany || $relation instanceof HasOne) {
            // HasMany/HasOne can't sync — caller should use belongsToMany or FK.
            return [
                'action' => 'assign_user',
                'skipped' => true,
                'reason' => "Relationship '{$name}' is HasMany/HasOne; use BelongsToMany or a user_field instead",
            ];
        }

        return [
            'action' => 'assign_user',
            'skipped' => true,
            'reason' => "Unsupported relationship type for '{$name}'",
        ];
    }

    public static function configSchema(): array
    {
        return [
            TagsInput::make('config.user_ids')
                ->label('User IDs')
                ->placeholder('Press Enter after each ID')
                ->helperText('Explicit users to assign'),
            TextInput::make('config.user_field')
                ->label('Or User Field on Model')
                ->placeholder('e.g., created_by, owner_id')
                ->helperText('Used as fallback or single-FK assignment target'),
            TextInput::make('config.relationship')
                ->label('Relationship Method')
                ->placeholder('e.g., assignees')
                ->helperText('Many-to-many relationship method on the model'),
            Select::make('config.mode')
                ->label('Mode')
                ->options([
                    'sync_without_detaching' => 'Sync without detaching (recommended)',
                    'sync' => 'Sync (detach others)',
                    'attach' => 'Attach (allows duplicates)',
                    'replace' => 'Replace (alias of sync)',
                ])
                ->default('sync_without_detaching'),
            Toggle::make('config.only_if_unassigned')
                ->label('Only If Unassigned')
                ->helperText('Skip if the model already has assignees on this relationship')
                ->default(false),
        ];
    }
}
