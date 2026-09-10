<div
    class="hidden"
    aria-hidden="true"
    @if ($pollRequired)
        wire:poll.5s="poll"
    @endif
></div>
