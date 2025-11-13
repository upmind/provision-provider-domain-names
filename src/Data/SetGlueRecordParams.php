<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Set glue record parameters.
 *
 * @property-read string $sld Domain SLD
 * @property-read string $tld Domain TLD
 * @property-read string $hostname Glue record hostname
 * @property-read string $ip_1 First IP address
 * @property-read string|null $ip_2 Second IP address
 * @property-read string|null $ip_3 Third IP address
 * @property-read string|null $ip_4 Fourth IP address
 */
class SetGlueRecordParams extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'sld' => ['required', 'alpha-dash'],
            'tld' => ['required', 'alpha-dash-dot'],
            'hostname' => ['required', 'string'],
            'ip_1' => ['required', 'ip'],
            'ip_2' => ['nullable', 'ip'],
            'ip_3' => ['nullable', 'ip'],
            'ip_4' => ['nullable', 'ip'],
        ]);
    }
}
