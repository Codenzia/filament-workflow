{{--
    Workflow Designer - Visual workflow automation builder
    Uses filament-diagrammer canvas with workflow list sidebar.

    @package codenzia/filament-workflow
    @author Codenzia
--}}

<x-filament-panels::page>
    @php
        $canvasConfig = $this->diagramCanvasConfig;
        $nodes = $this->diagramNodes;
        $connections = $this->diagramConnections;
        $livewireId = $this->getId();
        $canvasHeight = $canvasConfig['height'] ?? 'calc(100vh - 200px)';
    @endphp

    <div class="flex gap-4" style="height: {{ $canvasHeight }};">
        {{-- Workflow List Sidebar --}}
        <div class="w-72 shrink-0 space-y-3">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400">Workflows</h3>
                {{ $this->createWorkflowAction }}
            </div>

            <div class="space-y-2 overflow-y-auto" style="max-height: calc({{ $canvasHeight }} - 60px);">
                @forelse ($workflows as $workflow)
                    <div
                        wire:click="selectWorkflow({{ $workflow['id'] }})"
                        @class([
                            'group cursor-pointer rounded-lg border p-3 transition-colors',
                            'border-primary-500 bg-primary-50 dark:bg-primary-950/20' => $selectedWorkflowId === $workflow['id'],
                            'border-gray-200 bg-white hover:border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:hover:border-gray-600' => $selectedWorkflowId !== $workflow['id'],
                        ])
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-gray-900 dark:text-white">
                                    {{ $workflow['name'] }}
                                </p>
                                <div class="mt-1 flex items-center gap-2">
                                    <span @class([
                                        'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                        'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' => $workflow['status'] === 'active',
                                        'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400' => $workflow['status'] === 'draft',
                                        'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' => $workflow['status'] === 'inactive',
                                    ])>
                                        {{ $workflow['statusLabel'] }}
                                    </span>
                                    @if ($workflow['runCount'] > 0)
                                        <span class="text-xs text-gray-400 dark:text-gray-500">
                                            {{ $workflow['runCount'] }} {{ str('run')->plural($workflow['runCount']) }}
                                        </span>
                                    @endif
                                </div>
                            </div>

                            <div class="flex shrink-0 items-center gap-1 opacity-0 transition-opacity group-hover:opacity-100">
                                <button
                                    wire:click.stop="toggleWorkflowStatus({{ $workflow['id'] }})"
                                    class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700 dark:hover:text-gray-300"
                                    title="{{ $workflow['status'] === 'active' ? 'Deactivate' : 'Activate' }}"
                                >
                                    @if ($workflow['status'] === 'active')
                                        <x-filament::icon icon="heroicon-m-pause" class="h-4 w-4" />
                                    @else
                                        <x-filament::icon icon="heroicon-m-play" class="h-4 w-4" />
                                    @endif
                                </button>
                                <button
                                    wire:click.stop="deleteWorkflow({{ $workflow['id'] }})"
                                    wire:confirm="Are you sure you want to delete this workflow?"
                                    class="rounded p-1 text-gray-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/20 dark:hover:text-red-400"
                                    title="Delete"
                                >
                                    <x-filament::icon icon="heroicon-m-trash" class="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="rounded-lg border border-dashed border-gray-300 p-6 text-center dark:border-gray-600">
                        <x-filament::icon icon="heroicon-o-bolt" class="mx-auto h-8 w-8 text-gray-400" />
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">No workflows yet</p>
                        <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">Create your first workflow to automate tasks</p>
                    </div>
                @endforelse
            </div>
        </div>

        {{-- Main Content Area --}}
        <div class="flex min-w-0 flex-1 flex-col gap-4">
            {{-- Diagram Canvas --}}
            <div class="flex-1">
                @if ($selectedWorkflowId)
                    @include('filament-diagrammer::filament.components.diagram-canvas')
                @else
                    <div class="flex h-full items-center justify-center rounded-lg border border-dashed border-gray-300 dark:border-gray-600">
                        <div class="text-center">
                            <x-filament::icon icon="heroicon-o-cursor-arrow-rays" class="mx-auto h-12 w-12 text-gray-300 dark:text-gray-600" />
                            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Select a workflow to edit</p>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Monitoring Panel (below canvas) --}}
            @if ($selectedWorkflowId)
                @php
                    $schedulerStatus = $this->getSchedulerStatus();
                    $timeTriggerPreview = $this->getTimeTriggerPreview();
                    $executionHistory = $this->getExecutionHistory();
                @endphp

                <div
                    x-data="{ monitorOpen: false }"
                    class="shrink-0 rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800"
                >
                    {{-- Monitor Header (always visible) --}}
                    <button
                        @click="monitorOpen = !monitorOpen"
                        class="flex w-full items-center justify-between px-4 py-2.5"
                    >
                        <div class="flex items-center gap-3">
                            <x-filament::icon icon="heroicon-o-chart-bar-square" class="h-4 w-4 text-gray-400" />
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Monitor</span>

                            {{-- Quick stats --}}
                            <div class="flex items-center gap-3 text-xs text-gray-400 dark:text-gray-500">
                                <span>{{ $schedulerStatus['total_runs'] }} total runs</span>
                                <span class="text-gray-300 dark:text-gray-600">&middot;</span>
                                <span>{{ $schedulerStatus['last_24h_executions'] }} executions (24h)</span>
                                @if ($schedulerStatus['last_run_at'])
                                    <span class="text-gray-300 dark:text-gray-600">&middot;</span>
                                    <span title="{{ $schedulerStatus['last_run_at_full'] ?? '' }}">Last run {{ $schedulerStatus['last_run_at'] }}</span>
                                @endif
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            @if ($timeTriggerPreview['has_time_triggers'])
                                <button
                                    wire:click.stop="runTimeTriggers"
                                    wire:loading.attr="disabled"
                                    class="inline-flex items-center gap-1.5 rounded-md bg-primary-50 px-2.5 py-1 text-xs font-medium text-primary-700 transition-colors hover:bg-primary-100 dark:bg-primary-900/20 dark:text-primary-400 dark:hover:bg-primary-900/40"
                                    title="Manually process time-based triggers for this workflow"
                                >
                                    <x-filament::icon icon="heroicon-m-play" class="h-3.5 w-3.5" />
                                    <span wire:loading.remove wire:target="runTimeTriggers">Run Now</span>
                                    <span wire:loading wire:target="runTimeTriggers">Running...</span>
                                </button>
                            @endif

                            <x-filament::icon
                                icon="heroicon-m-chevron-down"
                                class="h-4 w-4 text-gray-400 transition-transform"
                                x-bind:class="monitorOpen ? 'rotate-180' : ''"
                            />
                        </div>
                    </button>

                    {{-- Expandable Monitor Content --}}
                    <div
                        x-show="monitorOpen"
                        x-collapse
                        class="border-t border-gray-200 dark:border-gray-700"
                    >
                        <div class="grid grid-cols-1 gap-4 p-4 lg:grid-cols-3">
                            {{-- Scheduler Status --}}
                            <div class="space-y-2">
                                <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Scheduler Status</h4>
                                <div class="space-y-1.5">
                                    <div class="flex items-center justify-between text-sm">
                                        <span class="text-gray-500 dark:text-gray-400">Total runs</span>
                                        <span class="font-medium text-gray-900 dark:text-white">{{ $schedulerStatus['total_runs'] }}</span>
                                    </div>
                                    <div class="flex items-center justify-between text-sm">
                                        <span class="text-gray-500 dark:text-gray-400">Last run</span>
                                        <span class="font-medium text-gray-900 dark:text-white" title="{{ $schedulerStatus['last_run_at_full'] ?? 'Never' }}">
                                            {{ $schedulerStatus['last_run_at'] ?? 'Never' }}
                                        </span>
                                    </div>
                                    <div class="flex items-center justify-between text-sm">
                                        <span class="text-gray-500 dark:text-gray-400">Executions (24h)</span>
                                        <span class="font-medium text-gray-900 dark:text-white">{{ $schedulerStatus['last_24h_executions'] }}</span>
                                    </div>
                                    <div class="flex items-center justify-between text-sm">
                                        <span class="text-gray-500 dark:text-gray-400">Dedup window</span>
                                        <span class="font-medium text-gray-900 dark:text-white">{{ $schedulerStatus['dedup_window'] }}h</span>
                                    </div>
                                </div>
                            </div>

                            {{-- Time Trigger Preview --}}
                            <div class="space-y-2">
                                <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Time Trigger Preview</h4>
                                @if ($timeTriggerPreview['has_time_triggers'])
                                    @if ($timeTriggerPreview['matching_count'] > 0)
                                        <p class="text-sm text-gray-600 dark:text-gray-300">
                                            <span class="font-semibold text-primary-600 dark:text-primary-400">{{ $timeTriggerPreview['matching_count'] }}</span>
                                            {{ str('model')->plural($timeTriggerPreview['matching_count']) }} would match right now
                                        </p>
                                        <ul class="space-y-0.5">
                                            @foreach ($timeTriggerPreview['models'] as $model)
                                                <li class="truncate text-xs text-gray-500 dark:text-gray-400">
                                                    #{{ $model['id'] }} — {{ $model['label'] }}
                                                </li>
                                            @endforeach
                                        </ul>
                                    @else
                                        <p class="text-sm text-gray-400 dark:text-gray-500">No models match right now</p>
                                    @endif
                                @else
                                    <p class="text-sm text-gray-400 dark:text-gray-500">No time-based triggers in this workflow</p>
                                @endif
                            </div>

                            {{-- Recent Execution History --}}
                            <div class="space-y-2">
                                <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Recent Executions</h4>
                                @if (count($executionHistory) > 0)
                                    <div class="max-h-40 space-y-1 overflow-y-auto">
                                        @foreach (array_slice($executionHistory, 0, 10) as $log)
                                            <div class="flex items-center justify-between gap-2 text-xs">
                                                <div class="flex min-w-0 items-center gap-1.5">
                                                    @if ($log['result'] === 'success')
                                                        <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-green-500"></span>
                                                    @elseif ($log['result'] === 'failure')
                                                        <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-red-500"></span>
                                                    @elseif ($log['result'] === 'delayed')
                                                        <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-blue-500"></span>
                                                    @else
                                                        <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-gray-400"></span>
                                                    @endif
                                                    <span class="truncate text-gray-600 dark:text-gray-300">
                                                        {{ $log['node_label'] ?? $log['trigger_type'] }}
                                                    </span>
                                                    <span class="shrink-0 text-gray-400">#{{ $log['model_id'] }}</span>
                                                </div>
                                                <span class="shrink-0 text-gray-400" title="{{ $log['executed_at_full'] ?? '' }}">
                                                    {{ $log['executed_at'] }}
                                                </span>
                                            </div>
                                        @endforeach
                                    </div>
                                @else
                                    <p class="text-sm text-gray-400 dark:text-gray-500">No executions yet</p>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Load CSS --}}
    @once
        @push('styles')
            <link rel="stylesheet" href="{{ asset('css/filament-diagrammer.css') }}">
        @endpush
    @endonce

    {{-- Load JS --}}
    @once
        @push('scripts')
            <script src="{{ asset('js/filament-diagrammer.js') }}"></script>
        @endpush
    @endonce

    {{-- Livewire Event Listeners --}}
    <script>
        document.addEventListener('livewire:navigated', () => {
            if (typeof window.__diagrammerInit === 'function') {
                window.__diagrammerInit();
            }
        });
    </script>

    <x-filament-actions::modals />
</x-filament-panels::page>
