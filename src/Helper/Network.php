<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Helper;

/**
 * NetworkHelper provides utility functions for validating IP addresses and networks.
 *
 * The class is inspired and uses logic from the Laravel Validation Network Rules package.
 * We could not use the package directly due to its dependency on Laravel's version 10 & above.
 *
 * @see https://github.com/miken32/NetworkRules
 */
class Network
{
    public static function isValidIpv4Address(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    public static function isValidIpv6Address(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }
}
