<?php

declare(strict_types=1);

/**
 * ApprovalRequestAction
 *
 * Workflow action node that initiates a human-approval step.
 *
 * Execution flow:
 *  1. Sets a configurable status field on the model to the "awaiting" value
 *     (default: "pending_review"). This lets condition nodes in the same
 *     workflow branch on the field later.
 *  2. Sends a Filament database notification to each configured approver
 *     user ID, with a link back to the model's admin page if an edit URL
 *     is resolvable.
 *  3. Returns an `approval_requested` result for the execution log.
 *
 * Resuming after approval:
 *  Wire a separate workflow triggered by the field changing *from* the
 *  awaiting value (e.g., status changed → 'approved') to pick up the
 *  remaining actions. The ApprovalRequestAction intentionally does not
 *  block execution — it delegates decision-making to humans and lets the
 *  workflow continue asynchronously.
 *
 * @author Codenzia
 */

namespace Codenzia\FilamentWorkflow\Actions;

use Codenzia\FilamentWorkflow\Engine\Contracts\ActionHandlerInterface;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class ApprovalRequestAction implements ActionHandlerInterface
{
    public static function label(): string
    {
        return 'Request Approval';
    }

    /**
     * Config keys:
     * - status_field:    string    — attribute on the model to update (default: 'status')
     * - awaiting_value:  string    — value to write (default: 'pending_review')
     * - approver_ids:    int[]     — user IDs to notify
     * - message:         string    — notification body (supports :model_title placeholder)
     * - notification_title: string — notification heading (default: 'Approval Required')
     * - skip_if_field:   string    — skip the whole action if this field is non-empty/non-null
     */
    public function execute(Model $model, array $config, array $context): array
    {
        $statusField   = $config['status_field']   ?? 'status';
        $awaitingValue = $config['awaiting_value'] ?? 'pending_review';
        $approverIds   = array_filter(array_map('intval', (array) ($config['approver_ids'] ?? [])));
        $skipIfField   = $config['skip_if_field']  ?? null;

        // Guard: skip if the configured field already has a value.
        if ($skipIfField && ! empty($model->getAttribute($skipIfField))) {
            return [
                'action'  => 'approval_request',
                'skipped' => true,
                'reason'  => "Field '{$skipIfField}' is already set — approval not re-requested.",
            ];
        }

        // 1. Update the status field if the model has that column.
        $fieldUpdated = false;
        if (Schema::hasColumn($model->getTable(), $statusField)) {
            $model->update([$statusField => $awaitingValue]);
            $fieldUpdated = true;
        }

        // 2. Notify approvers.
        $notified = 0;
        if (! empty($approverIds)) {
            $title = $config['notification_title'] ?? 'Approval Required';
            $body  = $config['message']            ?? ':model_title requires your approval.';

            $modelTitle = $model->getAttribute('title')
                ?? $model->getAttribute('name')
                ?? class_basename($model).'#'.$model->getKey();

            $body = str_replace(':model_title', $modelTitle, $body);

            $userModel = config('auth.providers.users.model', 'App\\Models\\User');
            $users     = $userModel::whereIn('id', $approverIds)->get();

            foreach ($users as $user) {
                Notification::make()
                    ->title($title)
                    ->body($body)
                    ->warning()
                    ->sendToDatabase($user);

                $notified++;
            }
        }

        return [
            'action'        => 'approval_request',
            'status_field'  => $statusField,
            'awaiting_value'=> $awaitingValue,
            'field_updated' => $fieldUpdated,
            'approvers_notified' => $notified,
        ];
    }

    public static function configSchema(): array
    {
        return [
            TextInput::make('config.status_field')
                ->label('Status Field')
                ->placeholder('status')
                ->default('status')
                ->helperText('Model attribute to set to the awaiting value'),

            TextInput::make('config.awaiting_value')
                ->label('Awaiting Value')
                ->placeholder('pending_review')
                ->default('pending_review')
                ->helperText('Value written to the status field when approval is requested'),

            TagsInput::make('config.approver_ids')
                ->label('Approver User IDs')
                ->placeholder('Press Enter after each ID')
                ->helperText('User IDs who receive the approval notification'),

            TextInput::make('config.notification_title')
                ->label('Notification Title')
                ->placeholder('Approval Required')
                ->default('Approval Required'),

            TextInput::make('config.message')
                ->label('Notification Message')
                ->placeholder(':model_title requires your approval.')
                ->default(':model_title requires your approval.')
                ->helperText('Use :model_title as a placeholder for the model\'s title/name'),

            Select::make('config.skip_if_field')
                ->label('Skip If Field Has Value')
                ->placeholder('(never skip)')
                ->options([
                    'status'             => 'status',
                    'approved_at'        => 'approved_at',
                    'approved_by_user_id'=> 'approved_by_user_id',
                ])
                ->helperText('Skip this action if the specified field is already populated'),
        ];
    }
}
