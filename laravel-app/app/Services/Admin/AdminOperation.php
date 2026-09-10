<?php

namespace App\Services\Admin;

use App\Exceptions\AiServiceException;
use App\Exceptions\DocumentDeletionException;
use App\Exceptions\DocumentReprocessingException;
use App\Exceptions\DuplicateDocumentException;
use Illuminate\Validation\ValidationException;

class AdminOperation
{
    public static function run(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (DuplicateDocumentException) {
            throw ValidationException::withMessages(['document' => 'هذا الملف موجود مسبقاً لدى المستخدم المحدد.']);
        } catch (DocumentReprocessingException) {
            throw ValidationException::withMessages(['processing_profile' => 'تعذّرت إعادة المعالجة. تأكد من وجود نسخة مفهرسة وعدم وجود معالجة جارية.']);
        } catch (DocumentDeletionException) {
            throw ValidationException::withMessages(['delete' => 'تعذّر الحذف: توجد معالجة جارية أو لم يكتمل تنظيف التخزين. حاول بعد انتهاء المعالجة.']);
        } catch (AiServiceException) {
            throw ValidationException::withMessages(['processing_profile' => 'خدمة المعالجة أو المسار المختار غير متاح حالياً. لم تكتمل العملية.']);
        }
    }
}
