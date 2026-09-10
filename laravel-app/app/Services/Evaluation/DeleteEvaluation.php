<?php

namespace App\Services\Evaluation;

use App\Models\EvaluationRun;
use App\Models\User;
use App\Services\Admin\AdminAccess;
use App\Services\Admin\AdminAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DeleteEvaluation
{
    public function execute(User $actor, int $id): void
    {
        AdminAccess::authorize($actor);
        DB::transaction(function () use ($actor, $id) {
            $run = EvaluationRun::query()->lockForUpdate()->findOrFail($id);
            if (! in_array($run->status, ['completed', 'failed'], true)) {
                throw ValidationException::withMessages(['delete' => 'انتظر انتهاء التقييم الجاري قبل حذف سجله.']);
            }
            $disk = Storage::disk('local');
            if ($disk->exists($run->dataset_path) && ! $disk->delete($run->dataset_path)) {
                throw ValidationException::withMessages(['delete' => 'تعذّر حذف ملف dataset الخاص بالتقييم. لم يحذف السجل؛ أعد المحاولة.']);
            }
            $run->delete();
            AdminAudit::record($actor->id, 'evaluation.delete', 'evaluation_run', $id);
        });
    }
}
