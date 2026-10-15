<?php

declare(strict_types = 1);

namespace Upmind\ProvisionProviders\DomainNames\RealtimeRegister\Helper\Tlds;

use Upmind\ProvisionProviders\DomainNames\Helper\Utils;

class AllowsOrganisationTechnicalContact
{
    private static array $unsupported = [
        'si',
    ];

    public static function tldIsSupported(string $tld): bool
    {
        return !in_array(Utils::normalizeTld($tld), self::$unsupported, true)
            && !in_array(Utils::getRootTld($tld), self::$unsupported, true);
    }
}
