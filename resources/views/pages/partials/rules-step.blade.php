{{--
    Workflow Rules Step Card - Recursive
    Renders a single step with its children (handles condition branching).

    Variables:
    - $item: array{node: WorkflowNode, children: array, yes_children: array, no_children: array, is_cycle: bool}
    - $depth: int
    - $isLast: bool

    @package codenzia/filament-workflow
    @author Codenzia
--}}

@php
    $node = $item['node'];
    $nodeType = $node->node_type;
    $color = $nodeType->color();
    $icon = $nodeType->icon();
    $typeLabel = $nodeType->label();
    $summary = $this->getStepSummary($node);
    $isCondition = $nodeType === \Codenzia\FilamentWorkflow\Enums\NodeTypeEnum::CONDITION;
    $isCycle = $item['is_cycle'] ?? false;

    // Resolve node type class for description
    $nodeTypeClass = $nodeType->nodeTypeClass();
    $nodeDescription = $nodeTypeClass::description();
@endphp

<div class="wf-rules-step" style="--step-color: {{ $color }}">
    {{-- Connection Arrow (not on first root node) --}}
    @if ($depth > 0 || ! $loop->first)
        <div class="wf-rules-connector">
            <div class="wf-rules-connector-line"></div>
            <div class="wf-rules-connector-arrow"></div>
        </div>
    @endif

    {{-- Step Card --}}
    <div class="wf-rules-card group" wire:dblclick="onRulesStepDoubleClick({{ $node->id }})" x-data="{ hovered: false }" @mouseenter="hovered = true" @mouseleave="hovered = false">
        {{-- Left Color Bar --}}
        <div class="wf-rules-card-bar" style="background: var(--step-color)"></div>

        <div class="flex-1 min-w-0 px-3 py-2.5">
            {{-- Header Row --}}
            <div class="flex items-center justify-between gap-2">
                <div class="flex items-center gap-2 min-w-0">
                    <x-filament::icon :icon="$icon" class="h-4 w-4 shrink-0" style="color: var(--step-color)" />
                    <span
                        class="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                        style="color: var(--step-color); border: 1px solid color-mix(in srgb, var(--step-color) 25%, transparent); background: color-mix(in srgb, var(--step-color) 10%, transparent)"
                    >{{ $typeLabel }}</span>
                    @if ($node->label)
                        <span class="text-sm font-medium text-gray-900 dark:text-white truncate">{{ $node->label }}</span>
                    @endif
                </div>

                {{-- Actions --}}
                <div x-show="hovered" x-cloak class="flex items-center gap-1">
                    @if ($nodeDescription)
                        <div class="shrink-0" x-data="{ showHelp: false, pos: { top: 0, left: 0 } }">
                            <button
                                type="button"
                                x-ref="helpBtn"
                                @mouseenter="
                                    const r = $refs.helpBtn.getBoundingClientRect();
                                    pos = { top: r.top + (r.height / 2), left: r.right + 8 };
                                    showHelp = true;
                                "
                                @mouseleave="showHelp = false"
                                class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700 dark:hover:text-gray-300 cursor-help"
                            >
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01" />
                                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2" fill="none" />
                                </svg>
                            </button>
                            <template x-teleport="body">
                                <div
                                    x-show="showHelp"
                                    x-transition.opacity.duration.150ms
                                    x-cloak
                                    class="fixed z-[9999] w-56 p-3 rounded-lg shadow-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 text-xs text-gray-600 dark:text-gray-300 leading-relaxed pointer-events-none"
                                    :style="`top: ${pos.top}px; left: ${pos.left}px; transform: translateY(-50%);`"
                                >
                                    <div class="font-semibold text-gray-900 dark:text-white mb-1">{{ $typeLabel }}</div>
                                    {{ $nodeDescription }}
                                </div>
                            </template>
                        </div>
                    @endif
                    <button
                        wire:click="mountAction('editNode', { nodeId: 'wf-node-{{ $node->id }}' })"
                        class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700 dark:hover:text-gray-300"
                        title="{{ __('filament-workflow::rules.configure') }}"
                    >
                        <x-filament::icon icon="heroicon-m-cog-6-tooth" class="h-3.5 w-3.5" />
                    </button>
                    <button
                        wire:click="mountAction('deleteStep', { nodeId: {{ $node->id }} })"
                        class="rounded p-1 text-gray-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/20 dark:hover:text-red-400"
                        title="{{ __('filament-workflow::rules.delete-step') }}"
                    >
                        <x-filament::icon icon="heroicon-m-trash" class="h-3.5 w-3.5" />
                    </button>
                </div>
            </div>

            {{-- Summary --}}
            @if ($summary)
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400 truncate">{{ $summary }}</p>
            @endif
        </div>
    </div>

    @if ($isCycle)
        <div class="ml-4 mt-1 text-xs text-amber-500 dark:text-amber-400 italic">
            {{ __('filament-workflow::rules.cycle-detected') }}
        </div>
    @endif

    {{-- Inline Add Step button (between steps) --}}
    @if (! $isCondition && ! $isCycle)
        <div class="wf-rules-insert-btn-wrapper">
            <button
                wire:click="mountAction('addStepAfter', { afterNodeId: {{ $node->id }} })"
                class="wf-rules-insert-btn"
                title="{{ __('filament-workflow::rules.add-step') }}"
            >
                <x-filament::icon icon="heroicon-o-plus" class="h-3 w-3" />
            </button>
        </div>
    @endif

    {{-- Condition Branches --}}
    @if ($isCondition && (! empty($item['yes_children']) || ! empty($item['no_children'])))
        <div class="wf-rules-connector">
            <div class="wf-rules-connector-line"></div>
        </div>
        <div class="wf-rules-branches grid grid-cols-2 gap-4">
            {{-- Yes Branch --}}
            <div class="wf-rules-branch">
                <div class="wf-rules-branch-label wf-rules-branch-label--yes">
                    {{ __('filament-workflow::rules.yes-branch') }}
                </div>
                <div class="wf-rules-branch-content">
                    @forelse ($item['yes_children'] as $child)
                        @include('filament-workflow::pages.partials.rules-step', ['item' => $child, 'depth' => $depth + 1, 'isLast' => $loop->last])
                    @empty
                        <button
                            wire:click="addRulesStep('action', null, null, {{ $node->id }}, 'Yes')"
                            class="wf-rules-add-branch-btn"
                        >
                            <x-filament::icon icon="heroicon-o-plus" class="h-3.5 w-3.5" />
                            {{ __('filament-workflow::rules.add-step') }}
                        </button>
                    @endforelse
                </div>
            </div>

            {{-- No Branch --}}
            <div class="wf-rules-branch">
                <div class="wf-rules-branch-label wf-rules-branch-label--no">
                    {{ __('filament-workflow::rules.no-branch') }}
                </div>
                <div class="wf-rules-branch-content">
                    @forelse ($item['no_children'] as $child)
                        @include('filament-workflow::pages.partials.rules-step', ['item' => $child, 'depth' => $depth + 1, 'isLast' => $loop->last])
                    @empty
                        <button
                            wire:click="addRulesStep('action', null, null, {{ $node->id }}, 'No')"
                            class="wf-rules-add-branch-btn"
                        >
                            <x-filament::icon icon="heroicon-o-plus" class="h-3.5 w-3.5" />
                            {{ __('filament-workflow::rules.add-step') }}
                        </button>
                    @endforelse
                </div>
            </div>
        </div>
    @endif

    {{-- Linear Children (non-condition) --}}
    @if (! $isCondition && ! empty($item['children']))
        @foreach ($item['children'] as $child)
            @include('filament-workflow::pages.partials.rules-step', ['item' => $child, 'depth' => $depth, 'isLast' => $loop->last])
        @endforeach
    @endif
</div>
