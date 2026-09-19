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
    public function execute(
        User $actor,
        int $id,
    ): void {
        AdminAccess::authorize($actor);

        DB::transaction(function () use (
            $actor,
            $id,
        ): void {
            $run = EvaluationRun::query()
                ->with('dataset')
                ->lockForUpdate()
                ->findOrFail($id);

            if (
                ! in_array(
                    $run->status,
                    ['completed', 'failed'],
                    true,
                )
            ) {
                throw ValidationException::withMessages([
                    'delete' => 'انتظر انتهاء التقييم الجاري قبل حذف سجله.',
                ]);
            }

            $dataset = $run->dataset;

            $datasetIsShared = $dataset
                ? $dataset->runs()
                    ->whereKeyNot($run->id)
                    ->exists()
                : false;

            $path = $dataset?->file_path
                ?? $run->dataset_path;

            if (! $datasetIsShared) {
                $disk = Storage::disk('local');

                if (
                    $disk->exists($path)
                    && ! $disk->delete($path)
                ) {
                    throw ValidationException::withMessages([
                        'delete' => 'تعذّر حذف ملف dataset الخاص بالتقييم. لم يحذف السجل؛ أعد المحاولة.',
                    ]);
                }
            }

            $run->delete();

            if (
                $dataset
                && ! $datasetIsShared
            ) {
                $dataset->delete();
            }

            AdminAudit::record(
                $actor->id,
                'evaluation.delete',
                'evaluation_run',
                $id,
            );
        });
    }
}
