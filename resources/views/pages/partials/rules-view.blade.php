{{--
    Workflow Rules View - Form-based workflow editor
    Renders the workflow as a flat/branching step list with action cards.

    @package codenzia/filament-workflow
    @author Codenzia
--}}

@php
    $rulesData = $this->getRulesTree();
    $flows = $rulesData['flows'] ?? [];
    $orphans = $rulesData['orphans'] ?? [];
@endphp

<div class="wf-rules-view space-y-4">
    {{-- Header with Add Step --}}
    <div class="flex items-center justify-between">
        <p class="text-xs text-gray-500 dark:text-gray-400">
            {{ __('filament-workflow::rules.tab-rules') }}
        </p>
        {{ $this->addStepAction }}
    </div>

    {{-- Connected Flows --}}
    @if (! empty($flows))
        <div class="wf-rules-steps space-y-0">
            @foreach ($flows as $item)
                @include('filament-workflow::pages.partials.rules-step', ['item' => $item, 'depth' => 0, 'isLast' => $loop->last])
            @endforeach
        </div>
    @endif

    {{-- Unconnected Steps --}}
    @if (! empty($orphans))
        <div class="mt-6 rounded-lg border border-gray-200 dark:border-gray-700" x-data="{ orphansOpen: false }">
            <div class="flex items-center justify-between px-4 py-2.5">
                <button
                    type="button"
                    @click="orphansOpen = !orphansOpen"
                    class="flex items-center gap-2.5 cursor-pointer"
                >
                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-500/15 transition-transform duration-200" x-bind:class="orphansOpen ? '' : '-rotate-90'">
                        <x-filament::icon icon="heroicon-m-chevron-down" class="h-3.5 w-3.5 text-amber-600 dark:text-amber-400" />
                    </span>
                    <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-4 w-4 text-amber-500" />
                    <span class="text-xs font-semibold uppercase tracking-wider text-amber-500 dark:text-amber-400">
                        {{ __('filament-workflow::rules.unconnected-title') }}
                    </span>
                    <span class="text-xs text-gray-400 dark:text-gray-500">({{ count($orphans) }})</span>
                </button>
                {{ $this->deleteAllOrphansAction }}
            </div>
            <div x-show="orphansOpen" x-collapse x-cloak class="border-t border-gray-200 dark:border-gray-700 px-4 py-3 space-y-2">
                @foreach ($orphans as $orphanNode)
                    @php
                        $nodeType = $orphanNode->node_type;
                        $color = $nodeType->color();
                        $icon = $nodeType->icon();
                    @endphp
                    <div class="wf-rules-card wf-rules-card--orphan group">
                        <div class="wf-rules-card-bar" style="background: {{ $color }}"></div>
                        <div class="flex-1 min-w-0 px-3 py-2">
                            <div class="flex items-center justify-between gap-2">
                                <div class="flex items-center gap-2 min-w-0">
                                    <x-filament::icon :icon="$icon" class="h-4 w-4 shrink-0" style="color: {{ $color }}" />
                                    <span
                                        class="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                                        style="color: {{ $color }}; border: 1px solid {{ $color }}40; background: {{ $color }}10"
                                    >{{ $nodeType->label() }}</span>
                                    @if ($orphanNode->label)
                                        <span class="text-sm font-medium text-gray-900 dark:text-white truncate">{{ $orphanNode->label }}</span>
                                    @endif
                                </div>
                                <div class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                    <button
                                        wire:click="onRulesStepDoubleClick({{ $orphanNode->id }})"
                                        class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700 dark:hover:text-gray-300"
                                        title="{{ __('filament-workflow::rules.configure') }}"
                                    >
                                        <x-filament::icon icon="heroicon-m-cog-6-tooth" class="h-3.5 w-3.5" />
                                    </button>
                                    <button
                                        wire:click="mountAction('deleteStep', { nodeId: {{ $orphanNode->id }} })"
                                        class="rounded p-1 text-gray-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/20 dark:hover:text-red-400"
                                        title="{{ __('filament-workflow::rules.delete-step') }}"
                                    >
                                        <x-filament::icon icon="heroicon-m-trash" class="h-3.5 w-3.5" />
                                    </button>
                                </div>
                            </div>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $this->getStepSummary($orphanNode) }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Empty State (no flows and no orphans) --}}
    @if (empty($flows) && empty($orphans))
        <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-gray-300 p-12 dark:border-gray-600">
            <x-filament::icon icon="heroicon-o-bolt" class="mx-auto h-10 w-10 text-gray-300 dark:text-gray-600" />
            <h3 class="mt-3 text-sm font-semibold text-gray-700 dark:text-gray-300">
                {{ __('filament-workflow::rules.empty-title') }}
            </h3>
            <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                {{ __('filament-workflow::rules.empty-description') }}
            </p>
            <div class="mt-4">
                {{ $this->addStepAction }}
            </div>
        </div>
    @endif
</div>
