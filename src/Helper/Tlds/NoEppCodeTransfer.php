<?php

declare(strict_types = 1);

namespace Upmind\ProvisionProviders\DomainNames\Helper\Tlds;

use Upmind\ProvisionProviders\DomainNames\Helper\Utils;

class NoEppCodeTransfer
{
    private static array $supported = [
        'lu',
    ];

    public static function tldIsSupported(string $tld): bool
    {
        return in_array(Utils::normalizeTld($tld), self::$supported, true)
            || in_array(Utils::getRootTld($tld), self::$supported, true);
    }
}
