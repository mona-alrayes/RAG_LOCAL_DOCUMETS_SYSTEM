<?php

namespace App\Services\Documents;

use App\Enums\DocumentSecurityScanStatus;
use App\Services\Infrastructure\LocalHeavyResourceLock;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

class DocumentSecurityService
{
    public function __construct(
        private readonly LocalHeavyResourceLock $lock,
    ) {}

    public function scan(string $filePath): DocumentSecurityScanStatus
    {
        if (! is_file($filePath) || ! is_readable($filePath)) {
            Log::error('ClamAV document scan could not start.', [
                'file' => basename($filePath),
                'reason' => 'file_not_readable',
            ]);

            return DocumentSecurityScanStatus::ScanFailed;
        }

        $token = null;

        $signatureDir = (string) config(
            'security.clamav.signature_dir',
            '/var/lib/clamav',
        );

        try {
            if ($this->lock->enabled()) {
                $token = $this->lock->acquire();

                if ($token === null) {
                    Log::warning('ClamAV document scan could not acquire resource lock.', [
                        'file' => basename($filePath),
                    ]);

                    return DocumentSecurityScanStatus::ScanFailed;
                }
            }

            $process = new Process([
                'clamscan',
                '--database='.$signatureDir,
                $filePath,
            ]);

            $process->setTimeout(
                (float) config('security.clamav.timeout', 300),
            );

            $process->run();

            $result = match ($process->getExitCode()) {
                0 => DocumentSecurityScanStatus::Clean,
                1 => DocumentSecurityScanStatus::Infected,
                default => DocumentSecurityScanStatus::ScanFailed,
            };

            $output = str_replace(
                [$filePath, $signatureDir],
                [basename($filePath), basename($signatureDir)],
                trim($process->getOutput()),
            );

            $errorOutput = str_replace(
                [$filePath, $signatureDir],
                [basename($filePath), basename($signatureDir)],
                trim($process->getErrorOutput()),
            );

            $context = [
                'file' => basename($filePath),
                'result' => $result->value,
                'exit_code' => $process->getExitCode(),
                'summary' => $output,
                'stderr' => $errorOutput !== '' ? $errorOutput : null,
            ];

            match ($result) {
                DocumentSecurityScanStatus::Clean => Log::info('ClamAV document scan completed.', $context),

                DocumentSecurityScanStatus::Infected => Log::warning('ClamAV detected an infected document.', $context),

                DocumentSecurityScanStatus::ScanFailed => Log::error('ClamAV document scan failed.', $context),
            };

            return $result;
        } catch (Throwable $exception) {
            Log::error('ClamAV document scan failed unexpectedly.', [
                'file' => basename($filePath),
                'exception' => $exception::class,
                'message' => str_replace(
                    [$filePath, $signatureDir],
                    [basename($filePath), basename($signatureDir)],
                    $exception->getMessage(),
                ),
            ]);

            return DocumentSecurityScanStatus::ScanFailed;
        } finally {
            if ($token !== null) {
                try {
                    $this->lock->release($token);
                } catch (Throwable $exception) {
                    Log::error(
                        'Failed to release local heavy-resource lock after ClamAV scan.',
                        [
                            'exception' => $exception::class,
                            'message' => $exception->getMessage(),
                        ],
                    );
                }
            }
        }
    }
}
