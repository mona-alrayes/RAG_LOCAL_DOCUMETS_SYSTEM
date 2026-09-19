<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\LogRecord;
use Throwable;

/** Logging is an operational metadata boundary, never a payload sink. */
final class RedactSensitiveLogs
{
    private const MESSAGES = [
        'ClamAV document scan could not start.',
        'ClamAV document scan could not acquire resource lock.',
        'ClamAV document scan completed.',
        'ClamAV detected an infected document.',
        'ClamAV document scan failed.',
        'ClamAV document scan failed unexpectedly.',
        'Failed to release local heavy-resource lock after ClamAV scan.',
        'Local heavy-resource lock was no longer owned when processing completed.',
        'Failed to release local heavy-resource lock after document processing.',
        'Failed to persist safe conversation answer failure state.',
        'Failed to publish conversation answer failure event.',
        'Failed to publish conversation answer completion event.',
    ];

    public function __invoke(Logger $logger): void
    {
        // Monolog runs newly pushed processors first, before placeholder expansion.
        $logger->pushProcessor(function (LogRecord $record): LogRecord {
            $context = [];
            foreach (['processing_run_id', 'assistant_message_id', 'document_id', 'conversation_id', 'user_id', 'exit_code'] as $key) {
                if (isset($record->context[$key]) && is_int($record->context[$key])) {
                    $context[$key] = $record->context[$key];
                }
            }

            $exception = $record->context['exception'] ?? null;
            if ($exception instanceof Throwable) {
                $context['error_class'] = $exception::class;
            } elseif (is_string($exception) && is_a($exception, Throwable::class, true)) {
                $context['error_class'] = $exception;
            } elseif (isset($record->context['error_class']) && is_string($record->context['error_class']) && is_a($record->context['error_class'], Throwable::class, true)) {
                $context['error_class'] = $record->context['error_class'];
            }

            foreach (['result' => ['clean', 'infected', 'scan_failed'], 'reason' => ['file_not_readable']] as $key => $values) {
                if (in_array($record->context[$key] ?? null, $values, true)) {
                    $context[$key] = $record->context[$key];
                }
            }

            return $record->with(
                message: in_array($record->message, self::MESSAGES, true)
                    ? $record->message : 'Application event (details redacted).',
                context: $context,
                extra: [],
            );
        });
    }
}
