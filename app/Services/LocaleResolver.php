<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;

class LocaleResolver
{
    /** @return array<string, string> */
    public function supported(): array
    {
        /** @var array<string, string> $supported */
        $supported = config('localization.supported');

        return $supported;
    }

    public function default(): string
    {
        return (string) config('localization.default');
    }

    public function cookieName(): string
    {
        return (string) config('localization.cookie');
    }

    public function isSupported(?string $locale): bool
    {
        return $locale !== null && array_key_exists($locale, $this->supported());
    }

    /**
     * Pick the locale for a request: an explicit user preference, then a
     * remembered choice, then the browser's languages, then the default.
     */
    public function resolve(Request $request): string
    {
        $user = $request->user();

        if ($user instanceof User && $this->isSupported($user->locale)) {
            return (string) $user->locale;
        }

        $remembered = $request->cookie($this->cookieName());

        if (is_string($remembered) && $this->isSupported($remembered)) {
            return $remembered;
        }

        return $this->fromBrowser($request) ?? $this->default();
    }

    /**
     * The first supported locale mentioned in the Accept-Language header, if any.
     */
    public function fromBrowser(Request $request): ?string
    {
        $supported = array_keys($this->supported());

        foreach ($request->getLanguages() as $language) {
            $base = strtolower((string) str_replace('_', '-', $language));
            $base = explode('-', $base)[0];

            foreach ($supported as $locale) {
                if ($base === strtolower($locale)) {
                    return $locale;
                }
            }
        }

        return null;
    }
}
