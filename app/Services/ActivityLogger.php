<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

/**
 * Audit log writer. Any key that looks like a secret is removed before saving,
 * so tokens / passwords can never end up in the activity log.
 */
class ActivityLogger
{
    private const SECRET_KEY_PATTERN = '/(token|secret|password|authorization|signature|api_key)/i';

    /**
     * @param  array<string, mixed>  $properties
     */
    public function log(
        string $action,
        string $description,
        ?Model $subject = null,
        array $properties = [],
        ?int $accountId = null,
        ?User $user = null,
    ): ?ActivityLog {
        try {
            $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();
            $user ??= auth()->user();

            // Done by the Super Admin while "logged in as" this user → keep who really did it.
            if ($request && \App\Support\Impersonation::active()) {
                $properties['impersonated_by'] = session(\App\Support\Impersonation::SESSION_KEY);
            }

            return ActivityLog::create([
                'user_id' => $user?->id,
                'whatsapp_account_id' => $accountId,
                'action' => $action,
                'description' => Str::limit($description, 250),
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'properties' => self::redact($properties) ?: null,
                'ip_address' => $request?->ip(),
                'user_agent' => Str::limit((string) $request?->userAgent(), 250, ''),
            ]);
        } catch (Throwable $e) {
            // Logging must never break the user's action.
            report($e);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function redact(array $data): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEY_PATTERN, $key)) {
                $clean[$key] = '[redacted]';

                continue;
            }

            $clean[$key] = is_array($value) ? self::redact($value) : $value;
        }

        return $clean;
    }
}
