<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Netim\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * @property-read string $customer_reference Login id
 * @property-read string $api_password API token
 * @property-read bool|null $sandbox Make API requests against the sandbox environment
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'customer_reference' => ['required', 'string', 'min:3'],
            'api_password' => ['required', 'string', 'min:6'],
            'sandbox' => ['nullable', 'boolean'],
        ]);
    }
}
