<?php

namespace App\Logging;

use App\Support\SecretRedactor;
use Illuminate\Log\Logger;
use Monolog\LogRecord;

/**
 * Monolog tap: scrubs credentials from every log message, context and extra
 * before it reaches a handler (configured on the file channels).
 */
class RedactSecrets
{
    public function __invoke(Logger $logger): void
    {
        $logger->getLogger()->pushProcessor(function (LogRecord $record): LogRecord {
            return $record->with(
                message: SecretRedactor::text($record->message),
                context: SecretRedactor::array($this->flatten($record->context)),
                extra: SecretRedactor::array($record->extra),
            );
        });
    }

    /** Exceptions in context become a redacted "class: message at file:line + trace" string. */
    private function flatten(array $context): array
    {
        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            $e = $context['exception'];
            $context['exception'] = sprintf(
                "%s: %s at %s:%d\n%s",
                $e::class,
                SecretRedactor::text($e->getMessage()),
                $e->getFile(),
                $e->getLine(),
                SecretRedactor::text($e->getTraceAsString()),
            );
        }

        return $context;
    }
}
