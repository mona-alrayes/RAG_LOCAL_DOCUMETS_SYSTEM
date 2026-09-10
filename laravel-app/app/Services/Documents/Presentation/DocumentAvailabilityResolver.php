<?php

namespace App\Services\Documents\Presentation;

use App\Enums\DocumentAvailability;
use App\Enums\DocumentStatus;
use App\Enums\ProcessingRunStatus;
use App\Models\Document;
use LogicException;

/**
 * Resolves the effective presentation availability of a document.
 *
 * هذا الـResolver مسؤول عن تحديد الحالة الفعلية للوثيقة من منظور العرض،
 * وليس فقط إعادة قيمة document.status كما هي.
 *
 * القاعدة الأساسية:
 *
 * إذا كانت الوثيقة تملك active processing run صالحة وحالتها Indexed،
 * فالوثيقة تعتبر Ready حتى لو كانت هناك محاولة processing أحدث
 * ما زالت تعمل أو حتى لو فشلت.
 *
 * لذلك:
 * - active run تمثل النسخة الحالية القابلة للاستخدام.
 * - document.status يمثل aggregate state.
 * - latest attempt لا تستخدم وحدها لتحديد جاهزية الوثيقة.
 *
 * هذا الفصل مهم جدًا خصوصًا أثناء Safe Reprocessing.
 *
 * Example:
 *
 * Active Run A = Indexed
 * Latest Run B = Processing
 *
 * النتيجة:
 * Document Availability = Ready
 *
 * لأن المستخدم ما زال يستطيع استخدام النسخة A أثناء معالجة B.
 */
final class DocumentAvailabilityResolver
{
    /**
     * Resolve the effective availability of the document.
     *
     * آلية العمل:
     *
     * 1. إذا كان هناك active_processing_run_id:
     *    نتأكد أن relation محملة مسبقًا.
     *
     * 2. نتحقق أن الـactive run:
     *    - موجودة فعلًا.
     *    - تابعة لنفس الوثيقة.
     *    - حالتها Indexed.
     *
     * 3. إذا تحققت هذه الشروط، فالوثيقة تعتبر Ready.
     *
     * 4. إذا لم يوجد active run، يتم الاعتماد على DocumentStatus
     *    لتحديد availability أثناء أول processing أو عند الفشل.
     *
     * @throws LogicException
     *                        إذا كانت حالة البيانات غير متوافقة مع lifecycle invariants.
     */
    public function resolve(Document $document): DocumentAvailability
    {
        /*
         * وجود active_processing_run_id يعني أن النظام يعتبر هناك
         * نسخة processing حالية صالحة للوثيقة.
         *
         * لذلك يجب التحقق من صحة هذه العلاقة قبل إعلان الوثيقة Ready.
         */
        if ($document->active_processing_run_id !== null) {
            $this->ensureActiveRunIsLoaded($document);

            $activeRun = $document->activeProcessingRun;

            /*
             * إذا كان foreign key موجودًا لكن relation لم تعد موجودة،
             * فهذه inconsistency في البيانات ولا يجب إخفاؤها.
             */
            if ($activeRun === null) {
                throw new LogicException(
                    'The document references an active processing run that does not exist.'
                );
            }

            /*
             * الـactive run يجب أن تنتمي لنفس الوثيقة.
             *
             * هذا invariant مهم لمنع اعتبار run تابعة لوثيقة أخرى
             * كنسخة فعالة للوثيقة الحالية.
             */
            if ($activeRun->document_id !== $document->id) {
                throw new LogicException(
                    'The active processing run does not belong to the document.'
                );
            }

            /*
             * لا يمكن اعتبار processing run فعالة إلا بعد نجاح indexing.
             *
             * Pending / Processing / Indexing / Failed
             * لا تصلح لتكون active version للوثيقة.
             */
            if ($activeRun->status !== ProcessingRunStatus::Indexed) {
                throw new LogicException(
                    'The active processing run must be indexed.'
                );
            }

            /*
             * وجود active Indexed run صالح له الأولوية في تحديد الجاهزية.
             *
             * حتى لو كانت هناك reprocessing attempt جديدة،
             * تبقى الوثيقة Ready باستخدام النسخة الحالية.
             */
            return DocumentAvailability::Ready;
        }

        /*
         * DocumentStatus::Ready بدون active indexed run تعتبر
         * حالة غير صحيحة حسب lifecycle الخاص بالمشروع.
         *
         * Ready يجب دائمًا أن تكون مدعومة بـactive processing run صالحة.
         */
        if ($document->status === DocumentStatus::Ready) {
            throw new LogicException(
                'A ready document must have a valid active indexed processing run.'
            );
        }

        /*
         * في حال عدم وجود active run، تصبح document status هي المصدر
         * المستخدم لتحديد presentation availability.
         *
         * هذا يحدث غالبًا أثناء أول processing للوثيقة:
         *
         * Pending
         * → Scanning
         * → Queued
         * → Processing
         * → Indexing
         * → Ready
         *
         * أو Failed عند فشل المعالجة.
         */
        return match ($document->status) {
            DocumentStatus::Pending => DocumentAvailability::Pending,
            DocumentStatus::Scanning => DocumentAvailability::Scanning,
            DocumentStatus::Infected => DocumentAvailability::Infected,
            DocumentStatus::Queued => DocumentAvailability::Queued,
            DocumentStatus::Processing => DocumentAvailability::Processing,
            DocumentStatus::Indexing => DocumentAvailability::Indexing,
            DocumentStatus::Failed => DocumentAvailability::Failed,

            /*
             * هذه الحالة يفترض أنها تمت معالجتها أعلاه.
             *
             * إبقاؤها هنا بشكل explicit يجعل الـmatch exhaustive
             * ويحمي الكود إذا تغير التدفق مستقبلًا.
             */
            DocumentStatus::Ready => throw new LogicException(
                'A ready document must have a valid active indexed processing run.'
            ),
        };
    }

    /**
     * Ensure the active processing run relation was eager loaded.
     *
     * هذا الـResolver لا يجب أن ينفذ database query بشكل مخفي.
     *
     * لذلك لا نستخدم:
     *
     * $document->load('activeProcessingRun')
     *
     * داخل الـResolver.
     *
     * المسؤولية تقع على DocumentReadService ليقوم بتحميل العلاقة مسبقًا.
     * بهذه الطريقة نحافظ على query behavior واضح ونتجنب N+1 queries.
     *
     * @throws LogicException
     *                        إذا تم استدعاء resolver بدون eager loading للعلاقة المطلوبة.
     */
    private function ensureActiveRunIsLoaded(Document $document): void
    {
        if (! $document->relationLoaded('activeProcessingRun')) {
            throw new LogicException(
                'The activeProcessingRun relation must be eager loaded before resolving document availability.'
            );
        }
    }
}
