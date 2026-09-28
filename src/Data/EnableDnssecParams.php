<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Parameters for enabling DNSSEC with a Delegation Signer (DS) record.
 *
 * @property-read string $sld Domain SLD
 * @property-read string $tld Domain TLD
 * @property-read int $ds_key_tag DS key tag
 * @property-read int $ds_algorithm DNSSEC algorithm identifier
 * @property-read int $ds_digest_type DS digest type identifier
 * @property-read string $ds_digest DS digest
 */
class EnableDnssecParams extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules(array_merge([
            'sld' => ['required', 'alpha-dash'],
            'tld' => ['required', 'alpha-dash-dot'],
        ], Dnssec::recordRules()->raw()));
    }
}
