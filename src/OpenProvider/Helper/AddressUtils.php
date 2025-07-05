<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\OpenProvider\Helper;

class AddressUtils
{
    public static function sanitisePostCode(string $value): string
    {
        // Remove any non-alphanumeric and space characters from the post code
        $sanitised = preg_replace('/[^a-zA-Z0-9 ]/', '', $value);

        // Return the sanitised post code
        return $sanitised ?: '';
    }
}
