<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\AcceptHeader;
use Throwable;

class SetLocaleFromAcceptLanguage
{
    /**
     * @var array<int, string>
     */
    private const SUPPORTED_LOCALES = ['ar', 'en'];

    public function handle(Request $request, Closure $next): mixed
    {
        $defaultLocale = $this->defaultLocale();
        $header = $request->header('Accept-Language');

        app()->setLocale($defaultLocale);

        if (is_string($header) && trim($header) !== '') {
            app()->setLocale($this->preferredLocale($header, $defaultLocale));
        }

        return $next($request);
    }

    private function preferredLocale(string $header, string $defaultLocale): string
    {
        try {
            foreach (AcceptHeader::fromString($header)->all() as $item) {
                if ($item->getQuality() <= 0) {
                    continue;
                }

                $locale = strtolower(str_replace('_', '-', trim($item->getValue())));
                $primaryLocale = explode('-', $locale, 2)[0];

                if (in_array($primaryLocale, self::SUPPORTED_LOCALES, true)) {
                    return $primaryLocale;
                }
            }
        } catch (Throwable) {
            // Invalid client headers must fall back safely.
        }

        return $defaultLocale;
    }

    private function defaultLocale(): string
    {
        $fallback = (string) config('app.fallback_locale', 'en');
        $configured = (string) config('app.locale', $fallback);

        return in_array($configured, self::SUPPORTED_LOCALES, true)
            ? $configured
            : (in_array($fallback, self::SUPPORTED_LOCALES, true) ? $fallback : 'en');
    }
}
