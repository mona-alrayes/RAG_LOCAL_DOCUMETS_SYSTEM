@props([
    'availability',
    'showLabel' => true,
    'label' => null,
])

@php
    $status = $availability instanceof \App\Enums\DocumentAvailability
        ? $availability->value
        : (string) $availability;

    $classes = match ($status) {
        'ready' =>
            'border-emerald-400/25 bg-emerald-400/10 text-emerald-300',

        'failed' =>
            'border-red-400/25 bg-red-400/10 text-red-300',

        'infected' =>
            'border-rose-500/30 bg-rose-500/10 text-rose-300',

        'processing' =>
            'border-blue-400/25 bg-blue-400/10 text-blue-300',

        'indexing' =>
            'border-violet-400/25 bg-violet-400/10 text-violet-300',

        'scanning' =>
            'border-cyan-400/25 bg-cyan-400/10 text-cyan-300',

        'queued' =>
            'border-amber-400/25 bg-amber-400/10 text-amber-300',

        'pending' =>
            'border-slate-400/25 bg-slate-400/10 text-slate-300',

        default =>
            'border-slate-400/25 bg-slate-400/10 text-slate-300',
    };

    $dotClasses = match ($status) {
        'ready' => 'bg-emerald-400',
        'failed' => 'bg-red-400',
        'infected' => 'bg-rose-400',
        'processing' => 'bg-blue-400',
        'indexing' => 'bg-violet-400',
        'scanning' => 'bg-cyan-400',
        'queued' => 'bg-amber-400',
        'pending' => 'bg-slate-400',
        default => 'bg-slate-400',
    };
@endphp

<span
    {{ $attributes->class([
        'inline-flex min-w-0 items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold leading-5',
        $classes,
    ]) }}
>
    <span
        class="size-2 shrink-0 rounded-full {{ $dotClasses }}"
        aria-hidden="true"
    ></span>

    @if ($showLabel)
        <span class="min-w-0 break-words">
            {{ $label ?? __('documents.availability.' . $status) }}
        </span>
    @endif
</span>