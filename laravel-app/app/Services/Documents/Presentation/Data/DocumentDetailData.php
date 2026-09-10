<?php

namespace App\Services\Documents\Presentation\Data;

/**
 * Represents the complete presentation data for a single document.
 *
 * هذا الـDTO يجمع البيانات المطلوبة لصفحة تفاصيل الوثيقة.
 *
 * بدل إعادة Eloquent models مباشرة إلى presentation layer،
 * يتم تمرير بيانات واضحة ومحددة فقط:
 *
 * - summary:
 *   يحتوي المعلومات الأساسية للوثيقة مثل availability،
 *   active run، latest attempt، polling، allowed actions وغيرها.
 *
 * - timeline:
 *   يحتوي جميع محاولات معالجة الوثيقة بترتيب زمني،
 *   بحيث تستطيع الواجهة عرض processing history بشكل واضح.
 *
 * هذا الكلاس readonly لأنه يمثل snapshot للبيانات المقروءة،
 * ولا يفترض تعديل محتواه بعد إنشائه.
 */
final readonly class DocumentDetailData
{
    /**
     * Create the document detail presentation data.
     *
     * الـsummary يمثل الحالة الحالية للوثيقة.
     *
     * أما الـtimeline فيمثل تاريخ جميع processing runs،
     * بما فيها:
     * - initial processing
     * - reprocessing attempts
     * - successful runs
     * - failed runs
     *
     * فصل summary عن timeline مهم لأن:
     *
     * summary يجيب عن سؤال:
     * "ما هي حالة الوثيقة الآن؟"
     *
     * بينما timeline يجيب عن:
     * "ما الذي حصل لهذه الوثيقة عبر محاولات المعالجة؟"
     *
     * @param  list<ProcessingRunDetailData>  $timeline
     */
    public function __construct(
        public DocumentSummaryData $summary,
        public array $timeline,
    ) {}
}
