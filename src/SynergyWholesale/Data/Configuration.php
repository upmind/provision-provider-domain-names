<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\SynergyWholesale\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * SynergyWholesale configuration
 *
 * @property-read string $reseller_id resellerID
 * @property-read string $api_key apiKey
 * @property-read string|null $custom_api_hostname Custom API hostname
 * @property-read bool|null $use_registrant_contact_for_admin_tech_billing Use registrant contact for admin/tech/billing
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'reseller_id' => ['required', 'string'],
            'api_key' => ['required', 'string'],
            'custom_api_hostname' => ['nullable', 'domain_name'],
            'use_registrant_contact_for_admin_tech_billing' => ['nullable', 'boolean'],
        ]);
    }
}
