<?php

namespace App\Jobs;

use App\Services\Infrastructure\LocalHeavyResourceLock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Symfony\Component\Process\Process;

class UpdateClamAvSignaturesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 25;

    public function __construct()
    {
        $this->onQueue(
            (string) config('security.clamav.queue', 'security-scan'),
        );
    }

    public function handle(LocalHeavyResourceLock $lock): void
    {
        $token = null;

        if ($lock->enabled()) {
            $token = $lock->acquire();

            if ($token === null) {
                $this->release(30);

                return;
            }
        }

        try {
            $process = new Process(['freshclam']);

            $process->setTimeout(
                (float) config('security.clamav.timeout', 300),
            );

            $process->mustRun();
        } finally {
            if ($token !== null) {
                $lock->release($token);
            }
        }
    }
}
