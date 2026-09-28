<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class LogRedactionTest extends TestCase
{
    public function test_log_channels_remove_sensitive_payloads_and_keep_operational_ids(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'rag-log-redaction-');
        try {
            $config = config('logging.channels.single');
            $config['path'] = $path;
            config(['logging.channels.redaction_test' => $config]);
            $logger = Log::stack(['redaction_test']);
            $logger->error('Failed to release local heavy-resource lock after document processing.', [
                'processing_run_id' => 123,
                'exception' => new RuntimeException('private-exception-sentinel'),
                'authorization' => 'Bearer private-token-sentinel',
                'question' => 'private-question-sentinel',
                'document_content' => 'private-document-sentinel',
                'vectors' => [0.123456789],
                'nested' => ['password' => 'private-password-sentinel'],
            ]);
            $logger->error('private-prompt-message-sentinel');
            $contents = file_get_contents($path);
            $this->assertStringNotContainsString('private-', $contents);
            $this->assertStringNotContainsString('0.123456789', $contents);
            $this->assertStringNotContainsString('[stacktrace]', $contents);
            $this->assertStringContainsString('processing_run_id', $contents);
            $this->assertStringContainsString('123', $contents);
            $this->assertStringContainsString('RuntimeException', $contents);
            $this->assertStringContainsString('Failed to release local heavy-resource lock', $contents);
        } finally {
            Log::forgetChannel('redaction_test');
            @unlink($path);
        }
    }

    public function test_framework_reporting_does_not_write_exception_message_or_trace(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'rag-report-redaction-');
        try {
            config(['logging.default' => 'redaction_test', 'logging.channels.redaction_test' => [
                ...config('logging.channels.single'), 'path' => $path,
            ]]);
            report(new RuntimeException('private-framework-sentinel'));
            $contents = file_get_contents($path);
            $this->assertStringNotContainsString('private-framework-sentinel', $contents);
            $this->assertStringNotContainsString('[stacktrace]', $contents);
            $this->assertStringContainsString('RuntimeException', $contents);
        } finally {
            Log::forgetChannel('redaction_test');
            @unlink($path);
        }
    }
}
