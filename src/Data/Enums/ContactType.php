<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Data\Enums;

use Upmind\ProvisionProviders\DomainNames\Enom\Helper\EnomApi;
use Upmind\ProvisionProviders\DomainNames\SynergyWholesale\Helper\SynergyWholesaleApi;

enum ContactType: string
{
    case REGISTRANT = 'registrant';
    case ADMIN = 'admin';
    case BILLING = 'billing';
    case TECH = 'tech';

    public function isEqualValue(string $value): bool
    {
        return $this->value === $value;
    }

    public function isNotEqualValue(string $value): bool
    {
        return $this->isEqualValue($value) === false;
    }

    public function providerEnomValue(): string
    {
        return match ($this) {
            self::REGISTRANT => mb_strtolower(EnomApi::CONTACT_TYPE_REGISTRANT),
            self::ADMIN => mb_strtolower(EnomApi::CONTACT_TYPE_ADMIN),
            self::BILLING => mb_strtolower(EnomApi::CONTACT_TYPE_BILLING),
            self::TECH => mb_strtolower(EnomApi::CONTACT_TYPE_TECH),
        };
    }

    public function providerSynergyWholesaleValue(): string
    {
        return match ($this) {
            self::REGISTRANT => mb_strtolower(SynergyWholesaleApi::CONTACT_TYPE_REGISTRANT),
            self::ADMIN => mb_strtolower(SynergyWholesaleApi::CONTACT_TYPE_ADMIN),
            self::BILLING => mb_strtolower(SynergyWholesaleApi::CONTACT_TYPE_BILLING),
            self::TECH => mb_strtolower(SynergyWholesaleApi::CONTACT_TYPE_TECH),
        };
    }
}
