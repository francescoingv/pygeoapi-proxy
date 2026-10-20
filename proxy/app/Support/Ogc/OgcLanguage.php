<?php

namespace App\Support\Ogc;

class OgcLanguage
{
    /**
     * @var list<string>
     */
    public const Supported = ['it', 'en'];

    public static function normalize(string $language): string
    {
        return in_array($language, self::Supported, true) ? $language : 'it';
    }
}
