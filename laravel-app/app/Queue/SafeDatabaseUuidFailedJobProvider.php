<?php

namespace App\Queue;

use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
use Throwable;

final class SafeDatabaseUuidFailedJobProvider extends DatabaseUuidFailedJobProvider
{
    public function log($connection, $queue, $payload, $exception)
    {
        // Keep the original replay payload/UUID; exception strings can contain
        // provider credentials, full prompts, chained errors and stack arguments.
        $summary = $exception instanceof Throwable ? $exception::class : 'Queue failure';

        return parent::log($connection, $queue, $payload, $summary.' (details redacted).');
    }
}
