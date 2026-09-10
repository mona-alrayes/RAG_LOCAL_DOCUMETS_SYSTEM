<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

/**
 * Authorizes document actions according to user ownership.
 *
 * تفويض عمليات الوثائق وفق ملكية المستخدم.
 */
class DocumentPolicy
{
    /**
     * Determine whether the user can view their document list.
     *
     * السماح بعرض قائمة وثائق المستخدم.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the document.
     *
     * السماح للمالك بعرض الوثيقة.
     */
    public function view(User $user, Document $document): bool
    {
        return $user->id === $document->user_id;
    }

    /**
     * Determine whether the user can create documents.
     *
     * السماح للمستخدم بإنشاء وثائق.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the document.
     *
     * السماح للمالك بتعديل الوثيقة.
     */
    public function update(User $user, Document $document): bool
    {
        return $user->id === $document->user_id;
    }

    /**
     * Determine whether the user can reprocess the document.
     *
     * السماح للمالك بإعادة معالجة الوثيقة.
     */
    public function reprocess(User $user, Document $document): bool
    {
        return $user->id === $document->user_id;
    }

    /**
     * Determine whether the user can delete the document.
     *
     * السماح للمالك بحذف الوثيقة.
     */
    public function delete(User $user, Document $document): bool
    {
        return $user->id === $document->user_id;
    }

    /**
     * Determine whether the user can download the document.
     *
     * السماح للمالك بتنزيل الوثيقة.
     */
    public function download(User $user, Document $document): bool
    {
        return $user->id === $document->user_id;
    }
}
