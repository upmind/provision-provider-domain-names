<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Helper\Tlds;

use Illuminate\Support\Str;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;

class FreeTransfer
{
    private static array $supported = [
        'uk',
        'au',
        'es',
        'nl',
        'nu',
        'ch',
        'cz',
        'ee',
        'fi',
        'gr',
        'hr',
        'no',
        'am',
        'at',
        'fm',
        'fo',
        'gd',
        'gl',
        'ac.nz',
        'pw',
        'vg',
        'co.com',
        'br.com',
        'cn.com',
        'eu.com',
        'gb.net',
        'uk.com',
        'uk.net',
        'us.com',
        'ru.com',
        'sa.com',
        'se.net',
        'za.com',
        'de.com',
        'jpn.com',
        'ae.org',
        'us.org',
        'gr.com',
        'com.de',
        'jp.net',
        'hu.net',
        'in.net',
        'mex.com',
        'com.se',
    ];

    public static function tldIsSupported(string $tld): bool
    {
        return in_array(Utils::normalizeTld($tld), self::$supported)
            || in_array(Utils::getRootTld($tld), self::$supported);
    }
}
