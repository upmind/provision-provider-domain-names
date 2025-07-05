<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\OpenProvider\Helper;

class AddressUtils
{
    /**
     * City and State/Province allowed special characters.
     */
    private static array $cityAndStateAllowedCharacters = ['_', '\-', ',', '\'', '\.',];

    public static function sanitiseCityOrState(string $value): string
    {
        // Remove any non-alphanumeric characters except for the allowed special characters
        $sanitised = preg_replace(
            '/[^a-zA-Z0-9 ' . implode('', self::$cityAndStateAllowedCharacters) . ']/',
            '',
            $value
        );

        // Return the sanitised city or state
        return $sanitised ?: '';
    }

    public static function sanitisePostCode(string $value): string
    {
        // Remove any non-alphanumeric and space characters from the post code
        $sanitised = preg_replace('/[^a-zA-Z0-9 ]/', '', $value);

        // Return the sanitised post code
        return $sanitised ?: '';
    }
}
