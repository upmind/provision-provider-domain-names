<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Data\Enums;

use MyCLabs\Enum\Enum;

/**
 * Enum representing different types of domain contacts.
 *
 * @extends Enum<ContactType::*>
 *
 * @method static ContactType REGISTRANT()
 * @method static ContactType ADMIN()
 * @method static ContactType BILLING()
 * @method static ContactType TECH()
 */
final class ContactType extends Enum
{
    public const REGISTRANT = 'registrant';
    public const ADMIN = 'admin';
    public const BILLING = 'billing';
    public const TECH = 'tech';

    public static function toValues(): array
    {
        return array_values(self::toArray());
    }

    public static function stringifyValues(string $separator = ','): string
    {
        return implode($separator, self::toValues());
    }

    public function isEqualValue(string $value): bool
    {
        return $this->getValue() === $value;
    }
}
