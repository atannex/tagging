<?php

namespace Atannex\Tagging\Support;

use Atannex\Tagging\Exceptions\PolicyRejected;

final class TagAttributes
{
    public static function validate(array $attributes): void
    {
        if (array_key_exists('active', $attributes) && ! is_bool($attributes['active'])) {
            throw new PolicyRejected('Tag active state must be boolean.');
        }
        $translations = $attributes['translations'] ?? [];
        if (! is_array($translations)) {
            throw new PolicyRejected('Translations must map locale strings to display labels.');
        }
        foreach ($translations as $locale => $label) {
            if (! is_string($locale) || strlen($locale) > 64 || ! is_string($label) || mb_strlen($label) > 4096) {
                throw new PolicyRejected('Translation locales and labels must be bounded strings.');
            }
        }
    }
}
