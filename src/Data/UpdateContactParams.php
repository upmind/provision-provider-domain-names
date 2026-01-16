<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;
use Upmind\ProvisionProviders\DomainNames\Data\Enums\ContactType;

/**
 * Params for setting domain renewing.
 *
 * @property-read string $sld Domain SLD
 * @property-read string $tld Domain TLD
 * @property-read ContactParams $contact Contact data
 * @property-read string $contact_type Contact type, one of ContactType enum constant values
 */
class UpdateContactParams extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'sld' => ['required', 'alpha-dash'],
            'tld' => ['required', 'alpha-dash-dot'],
            'contact' => ['required', ContactParams::class],
            'contact_type' => ['required', 'string', 'in:' . ContactType::stringifyValues()],
        ]);
    }

    /**
     * @throws \UnexpectedValueException
     */
    public function getContactTypeEnum(): ContactType
    {
        return ContactType::from($this->contact_type);
    }
}
