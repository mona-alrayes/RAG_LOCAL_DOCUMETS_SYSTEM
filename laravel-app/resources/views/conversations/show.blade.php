<x-layouts.app
    title="{{ $conversation->title ?: 'محادثة بدون عنوان' }}"
    full-bleed
    :conversations="$conversations"
    :active-conversation="$conversation"
>
    <section
        class="flex h-[calc(100dvh-4rem)] min-h-0 w-full flex-col bg-navy-950 lg:h-dvh"
        aria-label="مساحة المحادثة"
    >
        <header
            class="flex min-h-14 shrink-0 items-center justify-between gap-4 border-b border-white/10 px-4 py-2.5 sm:px-6"
        >
            <h1 class="min-w-0 truncate text-sm font-semibold text-ice-100">
                {{ $conversation->title ?: 'محادثة بدون عنوان' }}
            </h1>

            <div class="shrink-0">
                <livewire:conversations.document-selector
                    :conversation-id="$conversation->id"
                />
            </div>
        </header>

        <livewire:conversations.chat
            :conversation-id="$conversation->id"
        />
    </section>
</x-layouts.app>
