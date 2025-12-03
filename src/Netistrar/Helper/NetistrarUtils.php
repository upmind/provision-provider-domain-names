<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Netistrar\Helper;

use Upmind\ProvisionProviders\DomainNames\Helper\Utils;

class NetistrarUtils
{
    public static function isUkTld(string $tld): bool
    {
        return Utils::getRootTld($tld) === 'uk';
    }
}
