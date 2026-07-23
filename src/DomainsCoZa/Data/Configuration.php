<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\DomainsCoZa\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * @property-read string $api_key API Key
 * @property-read bool|null $sandbox Make API requests against the sandbox/dev environment
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'api_key' => ['required', 'string', 'min:20'],
            'sandbox' => ['nullable', 'boolean'],
        ]);
    }
}
