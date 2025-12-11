<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Data\Enums;

enum ContactType: string
{
    case REGISTRANT = 'registrant';
    case ADMIN = 'admin';
    case BILLING = 'billing';
    case TECH = 'tech';
}
