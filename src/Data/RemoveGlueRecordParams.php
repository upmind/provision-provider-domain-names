<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Remove glue record parameters.
 *
 * @property-read string $sld Domain SLD
 * @property-read string $tld Domain TLD
 * @property-read string $hostname Glue record hostname to remove
 */
class RemoveGlueRecordParams extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'sld' => ['required', 'alpha-dash'],
            'tld' => ['required', 'alpha-dash-dot'],
            'hostname' => ['required', 'string'],
        ]);
    }
}
