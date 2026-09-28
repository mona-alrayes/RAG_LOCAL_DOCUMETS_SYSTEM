@props([
    'user',
    'size' => 'md',
])

@php
    $sizeClass = match ($size) {
        'sm' => 'size-8',
        'chat' => 'conversation-avatar',
        'lg' => 'size-16',
        default => 'size-10',
    };
@endphp

<img
    src="{{ $user->avatarUrl() }}"
    alt="صورة {{ $user->name }} الشخصية"
    {{ $attributes->class([
        $sizeClass,
        'shrink-0 rounded-full border border-white/10 bg-navy-950 object-cover',
    ]) }}
>
