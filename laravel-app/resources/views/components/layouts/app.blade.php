{{-- القالب المشترك لجميع صفحات المستخدم بعد تسجيل الدخول --}}
@props([
    'title',
    'fullBleed' => false,
    'conversations' => null,
    'activeConversation' => null,
])

@php
    $routeDocument = request()->route('document');

    $activeDocumentId = $routeDocument instanceof \App\Models\Document
        ? $routeDocument->id
        : (is_numeric($routeDocument) ? (int) $routeDocument : null);
    $documentPollingScope = match (true) {
        request()->routeIs('workspace') => 'workspace',
        request()->routeIs('documents.index') => 'library',
        request()->routeIs('documents.show')
            && $activeDocumentId !== null => 'document',
        default => null,
    };
@endphp

<!DOCTYPE html>
<html lang="ar" dir="rtl" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $title }} | {{ config('app.name') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>

    <body
        data-authenticated-shell
        class="authenticated-shell min-h-screen bg-navy-950 text-ice-100"
    >
        {{-- Sidebar الرئيسي --}}
        <flux:sidebar
            sticky
            collapsible="mobile"
            data-authenticated-sidebar
            class="authenticated-sidebar border-e border-white/10 bg-navy-900"
        >
            <flux:sidebar.header>
                <a
                    href="{{ route('workspace') }}"
                    class="min-w-0"
                    aria-label="الانتقال إلى مساحة العمل"
                >
                    <x-brand compact />
                </a>

                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            {{-- التنقل الرئيسي --}}
            <flux:sidebar.nav aria-label="التنقل الرئيسي">
                <flux:sidebar.item
                    icon="home"
                    href="{{ route('workspace') }}"
                    :current="request()->routeIs('workspace')"
                >
                    مساحة العمل
                </flux:sidebar.item>

                @if ($conversations !== null)
                    <x-conversations.sidebar-navigation
                        :conversations="$conversations"
                        :active-conversation="$activeConversation"
                    />
                @else
                    <flux:sidebar.item
                        icon="chat-bubble-left-right"
                        href="{{ route('conversations.index') }}"
                        :current="request()->routeIs('conversations.*')"
                    >
                        المحادثات
                    </flux:sidebar.item>
                @endif

                {{-- الوثائق --}}
                <details
                    class="group"
                    @if (request()->routeIs('documents.*')) open @endif
                >
                    <summary
                        class="flex min-h-10 cursor-pointer list-none items-center gap-3 rounded-lg px-3 py-2 text-sm text-mist-300 transition hover:bg-white/5 hover:text-ice-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-400 [&::-webkit-details-marker]:hidden"
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
                                d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5A3.375 3.375 0 0 0 10.125 2.25H8.25m0 12.75h7.5m-7.5 3h4.5m-4.5-6h7.5m-9-9h3.375c.621 0 1.125.504 1.125 1.125V7.5c0 .621.504 1.125 1.125 1.125h3.375c.621 0 1.125.504 1.125 1.125v9.375c0 1.243-1.007 2.25-2.25 2.25H6.75a2.25 2.25 0 0 1-2.25-2.25V5.25A2.25 2.25 0 0 1 6.75 3Z"
                            />
                        </svg>

                        <span class="min-w-0 flex-1 truncate">
                            الوثائق
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

                    <livewire:documents.sidebar-documents
                        :active-document-id="$activeDocumentId"
                        :poll-enabled="$documentPollingScope === null"
                    />
                </details>
            </flux:sidebar.nav>

            <flux:sidebar.spacer />

            {{-- إعدادات المستخدم --}}
            <flux:sidebar.nav aria-label="إعدادات المستخدم">
                @if(auth()->user()?->is_admin && auth()->user()->hasVerifiedEmail())
                    <flux:sidebar.item href="{{ route('filament.admin.pages.dashboard') }}" icon="chart-bar">
                        لوحة الإدارة والتقييم
                    </flux:sidebar.item>
                @endif
                <flux:sidebar.item
                    icon="cog-6-tooth"
                    href="{{ route('settings.account') }}"
                    :current="request()->routeIs('settings.*')"
                >
                    إعدادات الحساب
                </flux:sidebar.item>
            </flux:sidebar.nav>

            {{-- هوية المستخدم وتسجيل الخروج --}}
            <div class="mt-4 border-t border-white/10 pt-4">
                <div class="mb-3 min-w-0 px-2">
                    <p class="truncate text-sm font-medium text-ice-100">
                        {{ auth()->user()->name }}
                    </p>

                    <p class="mt-1 text-xs text-mist-300">
                        حساب المستخدم
                    </p>
                </div>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf

                    <button
                        type="submit"
                        class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm text-mist-300 transition hover:bg-white/5 hover:text-ice-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-400"
                    >
                        <svg
                            viewBox="0 0 24 24"
                            class="size-5 shrink-0"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.7"
                            aria-hidden="true"
                        >
                            <path d="M14.25 8.25 18 12m0 0-3.75 3.75M18 12H7.5" />
                            <path d="M10.5 5.25V4.5A1.5 1.5 0 0 0 9 3H4.5A1.5 1.5 0 0 0 3 4.5v15A1.5 1.5 0 0 0 4.5 21H9a1.5 1.5 0 0 0 1.5-1.5v-.75" />
                        </svg>

                        <span>تسجيل الخروج</span>
                    </button>
                </form>
            </div>
        </flux:sidebar>

        {{-- Header يظهر فقط على الشاشات الصغيرة --}}
        <flux:header class="border-b border-white/10 bg-navy-900 lg:hidden">
            <flux:sidebar.toggle
                class="lg:hidden"
                icon="bars-2"
                inset="left"
            />

            <p class="min-w-0 truncate text-sm font-semibold text-ice-100">
                {{ $title }}
            </p>

            <flux:spacer />

            <a
                href="{{ route('workspace') }}"
                aria-label="الانتقال إلى مساحة العمل"
            >
                <x-brand
                    compact
                    class="gap-0 [&>div]:hidden [&>span]:size-9 [&>span]:rounded-md"
                />
            </a>
        </flux:header>

        {{-- محتوى الصفحة --}}
        @if ($documentPollingScope !== null)
            <livewire:documents.document-state-poller
                :scope="$documentPollingScope"
                :document-id="
                    $documentPollingScope === 'document'
                        ? $activeDocumentId
                        : null
                "
            />
        @endif

        <flux:main
            class="authenticated-main min-w-0 overflow-x-clip {{ $fullBleed ? 'p-0!' : '' }}"
        >
            @if ($fullBleed)
                {{ $slot }}
            @else
                <div class="authenticated-content mx-auto w-full max-w-7xl">
                    {{ $slot }}
                </div>
            @endif
        </flux:main>

        @livewireScripts
        @fluxScripts
    </body>
</html>