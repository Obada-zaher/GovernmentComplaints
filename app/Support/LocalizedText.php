<?php

namespace App\Support;

class LocalizedText
{
    public static function resolve(?string $text): ?string
    {
        if ($text === null || app()->getLocale() !== 'ar') {
            return $text;
        }

        $messages = trans('api.messages');

        if (is_array($messages) && isset($messages[$text])) {
            return $messages[$text];
        }

        foreach (self::patterns() as $pattern => $translationKey) {
            if (preg_match($pattern, $text, $matches) === 1) {
                return trans($translationKey, array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY));
            }
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>  $errors
     * @return array<string, mixed>
     */
    public static function errors(array $errors): array
    {
        return array_map(function (mixed $value): mixed {
            if (is_array($value)) {
                return self::errors($value);
            }

            return is_string($value) ? self::resolve($value) : $value;
        }, $errors);
    }

    /**
     * @return array<string, string>
     */
    private static function patterns(): array
    {
        return [
            '/^Complaint (?<complaint_number>.+) was submitted by a citizen\.$/' => 'api.patterns.complaint_submitted',
            '/^Complaint (?<complaint_number>.+) is available for department review\.$/' => 'api.patterns.department_review',
            '/^Complaint (?<complaint_number>.+) has been assigned to you\.$/' => 'api.patterns.assigned_to_you',
            '/^Complaint (?<complaint_number>.+) has been assigned for processing\.$/' => 'api.patterns.assigned_for_processing',
            '/^Complaint (?<complaint_number>.+) status is now (?<status>.+)\.$/' => 'api.patterns.status_now',
            '/^Complaint (?<complaint_number>.+) was escalated by an employee\.$/' => 'api.patterns.escalated',
            '/^Complaint (?<complaint_number>.+) has breached its SLA deadline\.$/' => 'api.patterns.sla_breached',
            '/^Invalid status transition from (?<from_status>.+) to (?<to_status>.+)\.$/' => 'api.patterns.invalid_status_transition',
        ];
    }
}
