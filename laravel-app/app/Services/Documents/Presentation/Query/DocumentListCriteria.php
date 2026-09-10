<?php

namespace App\Services\Documents\Presentation\Query;

use App\Enums\DocumentStatus;
use App\Enums\FileType;
use App\Enums\ProcessingProfile;
use InvalidArgumentException;

/**
 * Immutable criteria object used to describe a document list query.
 *
 * هذا الكلاس يمثل جميع خيارات البحث والفلترة والترتيب الخاصة بقائمة الوثائق
 * بشكل typed ومنظم بدل تمرير raw request values داخل الـService.
 *
 * Responsibilities:
 * - حمل قيمة البحث النصي.
 * - حمل status filter.
 * - حمل file type filter.
 * - حمل processing profile filter.
 * - تحديد sort field.
 * - تحديد sort direction.
 * - تحديد page size.
 *
 * وجود هذا الكلاس يفصل HTTP request عن query logic،
 * بحيث DocumentReadService لا يعتمد مباشرة على Request.
 *
 * الكلاس readonly لأن هذه القيم تمثل Query Criteria ثابتة
 * ولا يجب تعديلها بعد إنشائها.
 */
final readonly class DocumentListCriteria
{
    /**
     * Trusted database columns allowed for sorting.
     *
     * مهم جدًا عدم استخدام sort field قادم مباشرة من المستخدم
     * داخل orderBy، لأن أسماء الأعمدة لا يتم التعامل معها كـbound values.
     *
     * لذلك نستخدم allowlist صريحة للأعمدة التي نسمح بالترتيب عليها.
     */
    private const ALLOWED_SORT_FIELDS = [
        'created_at',
        'updated_at',
        'title',
        'original_name',
        'file_size',
    ];

    /**
     * Trusted sort directions.
     *
     * نسمح فقط بالاتجاهين المعروفين:
     * - asc
     * - desc
     */
    private const ALLOWED_SORT_DIRECTIONS = [
        'asc',
        'desc',
    ];

    /**
     * Create document list criteria.
     *
     * القيم القادمة هنا يفترض أنها تم validated مسبقًا بواسطة
     * DocumentIndexRequest، لكننا نعيد حماية أهم invariants داخل
     * هذا الكلاس نفسه أيضًا.
     *
     * هذا يجعل DocumentListCriteria آمنًا حتى لو تم إنشاؤه مستقبلًا
     * من مكان آخر غير HTTP request.
     *
     * @throws InvalidArgumentException
     *                                  إذا كان sort field أو direction غير مسموح،
     *                                  أو كانت قيمة perPage خارج الحدود المعتمدة.
     */
    public function __construct(
        /**
         * Optional free-text search.
         *
         * تستخدم حاليًا للبحث ضمن:
         * - title
         * - original_name
         */
        public ?string $search = null,

        /**
         * Optional aggregate document status filter.
         */
        public ?DocumentStatus $status = null,

        /**
         * Optional uploaded file type filter.
         */
        public ?FileType $fileType = null,

        /**
         * Optional processing profile filter.
         *
         * DocumentReadService يحدد لاحقًا هل تتم المقارنة
         * مع active run أو latest attempt حسب lifecycle.
         */
        public ?ProcessingProfile $profile = null,

        /**
         * Trusted database column used for sorting.
         */
        public string $sortBy = 'created_at',

        /**
         * Sort direction.
         *
         * القيمة الافتراضية desc حتى تظهر أحدث الوثائق أولًا.
         */
        public string $sortDirection = 'desc',

        /**
         * Number of documents returned per page.
         *
         * القيمة الافتراضية 10 والحد الأقصى 50
         * لمنع queries كبيرة غير ضرورية.
         */
        public int $perPage = 10,
    ) {
        /*
         * Defense in depth:
         *
         * حتى لو كان DocumentIndexRequest يتحقق من sort_by،
         * لا نسمح بإنشاء Criteria بقيمة غير موثوقة من مكان آخر.
         */
        if (! in_array($sortBy, self::ALLOWED_SORT_FIELDS, true)) {
            throw new InvalidArgumentException(
                'Unsupported document sort field.',
            );
        }

        /*
         * نمنع أي sort direction خارج allowlist المعروفة.
         */
        if (! in_array($sortDirection, self::ALLOWED_SORT_DIRECTIONS, true)) {
            throw new InvalidArgumentException(
                'Unsupported document sort direction.',
            );
        }

        /*
         * نحافظ على pagination ضمن نطاق معقول.
         *
         * الحد الأدنى 1 والحد الأعلى 50 حتى لا يستطيع أي caller
         * تحميل عدد ضخم من الوثائق في request واحد.
         */
        if ($perPage < 1 || $perPage > 50) {
            throw new InvalidArgumentException(
                'Document page size must be between 1 and 50.',
            );
        }
    }
}
