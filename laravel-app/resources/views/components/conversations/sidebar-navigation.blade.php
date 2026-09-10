@props([
    'conversations',
    'activeConversation' => null,
])

@php
    $activeConversationId = $activeConversation?->getKey();
@endphp

<details
    class="group"
    @if (request()->routeIs('conversations.*')) open @endif
>
    <summary
        class="flex min-h-10 cursor-pointer list-none items-center gap-3 rounded-lg px-3 py-2 text-sm transition hover:bg-white/5 hover:text-ice-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-400 [&::-webkit-details-marker]:hidden {{ request()->routeIs('conversations.*') ? 'text-ice-100' : 'text-mist-300' }}"
    >
        <svg
            viewBox="0 0 24 24"
            class="size-5 shrink-0"
            fill="none"
            stroke="currentColor"
            stroke-width="1.7"
            aria-hidden="true"
        >
            <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M8.625 9.75a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm3.75 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm3.75 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"
            />
            <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M2.25 12c0 4.142 4.365 7.5 9.75 7.5 1.09 0 2.138-.138 3.116-.393.78.421 1.858.846 3.309.846.346 0 .686-.024 1.018-.07a.75.75 0 0 0 .507-1.173 8.954 8.954 0 0 1-.978-1.77C20.685 15.593 21.75 13.891 21.75 12c0-4.142-4.365-7.5-9.75-7.5S2.25 7.858 2.25 12Z"
            />
        </svg>

        <span class="min-w-0 flex-1 truncate">
            المحادثات
        </span>

        <svg
            viewBox="0 0 20 20"
            class="size-4 shrink-0 transition-transform duration-200 group-open:rotate-180"
            fill="currentColor"
            aria-hidden="true"
        >
            <path
                fill-rule="evenodd"
                d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.51a.75.75 0 0 1-1.08 0l-4.25-4.51a.75.75 0 0 1 .02-1.06Z"
                clip-rule="evenodd"
            />
        </svg>
    </summary>

    <div class="mt-1 space-y-1 pe-2 ps-3">
        <form
            method="POST"
            action="{{ route('conversations.store') }}"
            class="mb-2"
        >
            @csrf

            <button
                type="submit"
                class="flex min-h-10 w-full items-center gap-2 rounded-lg px-3 py-2 text-xs font-semibold text-cyan-300 transition hover:bg-white/5 hover:text-cyan-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-400"
            >
                <svg
                    viewBox="0 0 24 24"
                    class="size-4 shrink-0"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    aria-hidden="true"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M12 6v12m6-6H6"
                    />
                </svg>

                <span>محادثة جديدة</span>
            </button>
        </form>

        @error('conversation_delete')
            <p
                role="alert"
                class="mb-2 rounded-lg border border-red-400/20 bg-red-400/10 px-3 py-2 text-xs leading-5 text-red-200"
            >
                {{ $message }}
            </p>
        @enderror

        @error('title')
            <p
                role="alert"
                class="mb-2 rounded-lg border border-red-400/20 bg-red-400/10 px-3 py-2 text-xs leading-5 text-red-200"
            >
                {{ $message }}
            </p>
        @enderror

        @forelse ($conversations as $conversation)
            @php
                $isActive =
                    $activeConversationId === $conversation->getKey();

                $conversationTitle =
                    $conversation->title ?: 'محادثة بدون عنوان';
            @endphp

            <div
                x-data="{
                    menuOpen: false,
                    renameOpen: false,
                    deleteOpen: false,
                }"
                class="relative flex min-w-0 items-center rounded-lg transition {{ $isActive ? 'bg-cyan-400/10' : 'hover:bg-white/5' }}"
            >
                <a
                    href="{{ route('conversations.show', $conversation) }}"
                    wire:navigate
                    @if ($isActive)
                        aria-current="page"
                    @endif
                    class="min-w-0 flex-1 truncate px-3 py-2 text-xs font-medium {{ $isActive ? 'text-cyan-200' : 'text-mist-300 hover:text-ice-100' }}"
                >
                    {{ $conversationTitle }}

                    @if ($isActive)
                        <span class="sr-only">
                            — المحادثة الحالية
                        </span>
                    @endif
                </a>

                <button
                    type="button"
                    @click="menuOpen = ! menuOpen"
                    @click.outside="menuOpen = false"
                    :aria-expanded="menuOpen.toString()"
                    aria-haspopup="menu"
                    aria-label="خيارات {{ $conversationTitle }}"
                    class="inline-flex size-8 shrink-0 items-center justify-center rounded-lg text-mist-500 transition hover:bg-white/5 hover:text-ice-100 focus-visible:outline-2 focus-visible:outline-cyan-400"
                >
                    <svg
                        viewBox="0 0 24 24"
                        class="size-4"
                        fill="currentColor"
                        aria-hidden="true"
                    >
                        <circle cx="5" cy="12" r="1.5" />
                        <circle cx="12" cy="12" r="1.5" />
                        <circle cx="19" cy="12" r="1.5" />
                    </svg>
                </button>

                <div
                    x-show="menuOpen"
                    x-transition.opacity
                    @keydown.escape.window="menuOpen = false"
                    role="menu"
                    class="absolute end-1 top-9 z-40 w-36 rounded-xl border border-white/10 bg-navy-900 p-1 shadow-2xl"
                    style="display: none;"
                >
                    <button
                        type="button"
                        role="menuitem"
                        @click="
                            menuOpen = false;
                            renameOpen = true;
                        "
                        class="flex w-full items-center rounded-lg px-3 py-2 text-start text-xs text-mist-200 hover:bg-white/5 hover:text-ice-100"
                    >
                        إعادة تسمية
                    </button>

                    <button
                        type="button"
                        role="menuitem"
                        @click="
                            menuOpen = false;
                            deleteOpen = true;
                        "
                        class="flex w-full items-center rounded-lg px-3 py-2 text-start text-xs text-red-300 hover:bg-red-400/10"
                    >
                        حذف
                    </button>
                </div>

                <template x-teleport="body">
                    <div
                        x-show="renameOpen"
                        x-transition.opacity
                        @keydown.escape.window="renameOpen = false"
                        class="fixed inset-0 z-[100] flex items-center justify-center p-4"
                        style="display: none;"
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="rename-conversation-title-{{ $conversation->id }}"
                    >
                        <button
                            type="button"
                            class="absolute inset-0 bg-black/70 backdrop-blur-sm"
                            aria-label="إغلاق"
                            @click="renameOpen = false"
                        ></button>

                        <form
                            method="POST"
                            action="{{ route('conversations.update', $conversation) }}"
                            class="relative z-10 w-full max-w-sm rounded-2xl border border-white/10 bg-navy-900 p-5 shadow-2xl"
                        >
                            @csrf
                            @method('PATCH')

                            <h2
                                id="rename-conversation-title-{{ $conversation->id }}"
                                class="text-base font-semibold text-ice-100"
                            >
                                إعادة تسمية المحادثة
                            </h2>

                            <p class="mt-1 text-xs leading-5 text-mist-400">
                                اختر اسمًا مختصرًا يوضح محتوى المحادثة.
                            </p>

                            <label
                                for="conversation-title-{{ $conversation->id }}"
                                class="mt-5 block text-xs font-medium text-mist-300"
                            >
                                اسم المحادثة
                            </label>

                            <input
                                id="conversation-title-{{ $conversation->id }}"
                                name="title"
                                type="text"
                                required
                                maxlength="255"
                                value="{{ $conversation->title }}"
                                class="mt-2 min-h-11 w-full rounded-xl border border-white/10 bg-navy-950 px-3 py-2 text-sm text-ice-100 outline-none transition focus:border-cyan-400/60 focus:ring-2 focus:ring-cyan-400/10"
                            >

                            <div class="mt-5 flex items-center justify-end gap-2">
                                <button
                                    type="button"
                                    @click="renameOpen = false"
                                    class="min-h-10 rounded-xl border border-white/10 px-4 py-2 text-sm text-mist-300 transition hover:bg-white/5 hover:text-ice-100"
                                >
                                    إلغاء
                                </button>

                                <button
                                    type="submit"
                                    class="min-h-10 rounded-xl bg-cyan-400 px-4 py-2 text-sm font-semibold text-navy-950 transition hover:bg-cyan-300"
                                >
                                    حفظ
                                </button>
                            </div>
                        </form>
                    </div>
                </template>

                <template x-teleport="body">
                    <div
                        x-show="deleteOpen"
                        x-transition.opacity
                        @keydown.escape.window="deleteOpen = false"
                        class="fixed inset-0 z-[100] flex items-center justify-center p-4"
                        style="display: none;"
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="delete-conversation-title-{{ $conversation->id }}"
                    >
                        <button
                            type="button"
                            class="absolute inset-0 bg-black/70 backdrop-blur-sm"
                            aria-label="إغلاق"
                            @click="deleteOpen = false"
                        ></button>

                        <div
                            class="relative z-10 w-full max-w-sm rounded-2xl border border-white/10 bg-navy-900 p-5 shadow-2xl"
                        >
                            <h2
                                id="delete-conversation-title-{{ $conversation->id }}"
                                class="text-base font-semibold text-ice-100"
                            >
                                حذف المحادثة؟
                            </h2>

                            <p class="mt-3 text-sm leading-7 text-mist-300">
                                سيتم حذف المحادثة ورسائلها ومصادر الإجابات المحفوظة.
                                لن يتم حذف الوثائق أو بيانات Qdrant.
                            </p>

                            <div class="mt-5 flex items-center justify-end gap-2">
                                <button
                                    type="button"
                                    @click="deleteOpen = false"
                                    class="min-h-10 rounded-xl border border-white/10 px-4 py-2 text-sm text-mist-300 transition hover:bg-white/5 hover:text-ice-100"
                                >
                                    إلغاء
                                </button>

                                <form
                                    method="POST"
                                    action="{{ route('conversations.destroy', $conversation) }}"
                                >
                                    @csrf
                                    @method('DELETE')

                                    <button
                                        type="submit"
                                        class="min-h-10 rounded-xl bg-red-500 px-4 py-2 text-sm font-semibold text-white transition hover:bg-red-400"
                                    >
                                        حذف نهائي
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        @empty
            <p class="px-3 py-3 text-xs leading-5 text-mist-400">
                لا توجد محادثات بعد.
            </p>
        @endforelse

        <a
            href="{{ route('conversations.index') }}"
            wire:navigate
            class="mt-2 flex min-h-9 items-center rounded-lg px-3 py-2 text-xs font-semibold text-cyan-300 transition hover:bg-white/5 hover:text-cyan-200"
        >
            عرض كل المحادثات
        </a>
    </div>
</details>
