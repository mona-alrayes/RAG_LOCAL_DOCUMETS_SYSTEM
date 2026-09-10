@props([
    'document',
    'availableProcessingProfiles' => [],
])

@php
    $reprocessModal = 'reprocess-document-' . $document->id;
    $deleteModal = 'delete-document-' . $document->id;

    $activeProfile = $document->activeRun?->profile;

    $activeProfileIsAvailable =
        $activeProfile !== null
        && in_array(
            $activeProfile,
            $availableProcessingProfiles,
            true,
        );

    $canReprocess =
        $document->canReprocess
        && $activeProfileIsAvailable;

    $canPreview =
        $document->canDownload
        && in_array(
            $document->fileType,
            [
                \App\Enums\FileType::Pdf,
                \App\Enums\FileType::Txt,
            ],
            true,
        );
@endphp

<div class="shrink-0">
    <flux:dropdown position="bottom" align="end">
        <flux:button
            icon="ellipsis-horizontal"
            variant="ghost"
            size="sm"
            square
            tooltip="إجراءات الوثيقة"
            aria-label="إجراءات {{ $document->title ?: $document->originalName }}"
        />

        <flux:menu>
            {{-- Document details --}}
            <flux:menu.item
                icon="eye"
                href="{{ route('documents.show', $document->id) }}"
            >
                عرض التفاصيل
            </flux:menu.item>

            {{-- Browser preview --}}
            @if ($canPreview)
                <flux:menu.item
                    icon="eye"
                    href="{{ route('documents.preview', $document->id) }}"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    عرض الملف
                    <span class="sr-only">
                        في تبويب جديد
                    </span>
                </flux:menu.item>
            @endif

            {{-- Download --}}
            @if ($document->canDownload)
                <flux:menu.item
                    icon="arrow-down-tray"
                    href="{{ route('documents.download', $document->id) }}"
                >
                    تحميل الملف
                </flux:menu.item>
            @else
                <flux:menu.item
                    icon="arrow-down-tray"
                    disabled
                >
                    التحميل غير متاح
                </flux:menu.item>
            @endif

            <flux:menu.separator />

            {{-- Reprocess --}}
            @if ($canReprocess)
                <flux:modal.trigger :name="$reprocessModal">
                    <flux:menu.item icon="arrow-path">
                        إعادة المعالجة
                    </flux:menu.item>
                </flux:modal.trigger>
            @else
                <flux:menu.item
                    icon="arrow-path"
                    disabled
                >
                    إعادة المعالجة غير متاحة
                </flux:menu.item>
            @endif

            {{-- Delete --}}
            @if ($document->canDelete)
                <flux:modal.trigger :name="$deleteModal">
                    <flux:menu.item
                        icon="trash"
                        variant="danger"
                    >
                        حذف الوثيقة
                    </flux:menu.item>
                </flux:modal.trigger>
            @else
                <flux:menu.item
                    icon="trash"
                    variant="danger"
                    disabled
                >
                    الحذف غير متاح
                </flux:menu.item>
            @endif
        </flux:menu>
    </flux:dropdown>

    {{-- Reprocess confirmation modal --}}
    @if ($canReprocess)
        <flux:modal
            :name="$reprocessModal"
            class="w-[calc(100vw-2rem)] max-w-md md:w-96"
        >
            <div class="min-w-0 space-y-6">
                <div class="min-w-0">
                    <flux:heading size="lg">
                        إعادة معالجة الوثيقة؟
                    </flux:heading>

                    <flux:text class="mt-2 break-words">
                        سيتم بدء معالجة جديدة للوثيقة

                        <span class="break-all font-medium">
                            "{{ $document->title ?: $document->originalName }}"
                        </span>

                        باستخدام نفس طريقة المعالجة الحالية:

                        <span class="font-semibold text-ice-100">
                            {{ __('documents.processing_run.profile.' . $activeProfile->value) }}
                        </span>
                    </flux:text>
                </div>

                <form
                    method="POST"
                    action="{{ route('documents.reprocess', $document->id) }}"
                    class="grid gap-3 sm:flex sm:flex-wrap sm:justify-end"
                >
                    @csrf

                    <input
                        type="hidden"
                        name="processing_profile"
                        value="{{ $activeProfile->value }}"
                    >

                    <flux:modal.close>
                        <flux:button
                            type="button"
                            variant="ghost"
                            class="w-full sm:w-auto"
                        >
                            إلغاء
                        </flux:button>
                    </flux:modal.close>

                    <flux:button
                        type="submit"
                        variant="primary"
                        icon="arrow-path"
                        class="w-full sm:w-auto"
                    >
                        بدء إعادة المعالجة
                    </flux:button>
                </form>
            </div>
        </flux:modal>
    @endif

    {{-- Delete confirmation modal --}}
    @if ($document->canDelete)
        <flux:modal
            :name="$deleteModal"
            class="w-[calc(100vw-2rem)] max-w-md md:w-96"
        >
            <div class="min-w-0 space-y-6">
                <div class="min-w-0">
                    <flux:heading size="lg">
                        حذف الوثيقة؟
                    </flux:heading>

                    <flux:text class="mt-2 break-words">
                        سيتم حذف

                        <span class="break-all font-medium">
                            "{{ $document->title ?: $document->originalName }}"
                        </span>

                        وبياناتها المرتبطة.
                    </flux:text>

                    <flux:text class="mt-2">
                        لا يمكن التراجع عن هذه العملية بعد تنفيذها.
                    </flux:text>
                </div>

                <form
                    method="POST"
                    action="{{ route('documents.destroy', $document->id) }}"
                    class="grid gap-3 sm:flex sm:flex-wrap sm:justify-end"
                >
                    @csrf
                    @method('DELETE')

                    <flux:modal.close>
                        <flux:button
                            type="button"
                            variant="ghost"
                            class="w-full sm:w-auto"
                        >
                            إلغاء
                        </flux:button>
                    </flux:modal.close>

                    <flux:button
                        type="submit"
                        variant="danger"
                        icon="trash"
                        class="w-full sm:w-auto"
                    >
                        حذف الوثيقة
                    </flux:button>
                </form>
            </div>
        </flux:modal>
    @endif
</div>