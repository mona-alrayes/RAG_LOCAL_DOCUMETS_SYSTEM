<?php

namespace App\Jobs;

use App\Services\Evaluation\EvaluationRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class EvaluateQuestionJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 330;

    public int $tries = 30;

    public bool $failOnTimeout = true;

    public function __construct(public int $evaluationRunId, public int $questionIndex) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('evaluation:'.$this->evaluationRunId))->shared()->releaseAfter(15)->expireAfter(360)];
    }

    public function handle(EvaluationRunner $runner): void
    {
        $runner->execute($this->evaluationRunId, $this->questionIndex);
    }

    public function failed(?\Throwable $exception): void
    {
        EvaluationRunner::fail($this->evaluationRunId, 'worker_failed', $this->questionIndex);
    }
}
