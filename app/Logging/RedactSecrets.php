<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\LogRecord;

/**
 * Monolog "tap": removes access tokens, app secrets, signatures and similar
 * values from every log message and its context before it is written.
 *
 * Enabled in config/logging.php ('tap' => [RedactSecrets::class]).
 */
class RedactSecrets
{
    /** Meta user / system-user access tokens start with EAA… */
    private const PATTERNS = [
        '/\bEAA[A-Za-z0-9]{20,}\b/' => 'EAA[redacted]',
        '/(Bearer\s+)[A-Za-z0-9\-._~+\/]{12,}=*/i' => '$1[redacted]',
        '/(appsecret_proof=)[a-f0-9]{16,}/i' => '$1[redacted]',
        '/(access_token=)[^&\s"]+/i' => '$1[redacted]',
        '/(sha256=)[a-f0-9]{32,}/i' => '$1[redacted]',
    ];

    private const SECRET_KEYS = '/(token|secret|password|authorization|signature|api_key|appsecret)/i';

    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getLogger()->getHandlers() as $handler) {
            $handler->pushProcessor(fn (LogRecord $record) => $record->with(
                message: self::scrub($record->message),
                context: self::scrubArray($record->context),
            ));
        }
    }

    public static function scrub(string $text): string
    {
        return (string) preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $text);
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function scrubArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEYS, $key) && ! is_array($value)) {
                $data[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$key] = self::scrubArray($value);
            } elseif (is_string($value)) {
                $data[$key] = self::scrub($value);
            } elseif ($value instanceof \Throwable) {
                $data[$key] = get_class($value).': '.self::scrub($value->getMessage());
            }
        }

        return $data;
    }
}
