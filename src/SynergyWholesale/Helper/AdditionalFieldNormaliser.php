<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\SynergyWholesale\Helper;

use Upmind\ProvisionProviders\DomainNames\Data\ContactParams;
use Illuminate\Support\Str;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;

class AdditionalFieldNormaliser
{
    /**
     * Get normalised additional/eligibility fields for the given tld.
     *
     * @return array<string,string|null>|null
     */
    public function normalise(string $tld, ?array $fieldValues, ContactParams $registrant): ?array
    {
        if (empty($fieldValues)) {
            return null;
        }

        $tld = '.' . Utils::normalizeTld($tld);

        if (!Str::endsWith($tld, '.au')) {
            return $fieldValues;
        }

        return array_filter([
            'registrantName' => $fieldValues['registrantName'] ?? $registrant->organisation ?: $registrant->name ?: null,
            'registrantID' => $fieldValues['registrantID'] ?? $fieldValues['RegistrantID'] ?? null,
            'registrantIDType' => $fieldValues['registrantIDType'] ?? $this->normaliseRegistrantIdType($fieldValues['RegistrantIDType'] ?? null),
            'eligibilityType' => $fieldValues['eligibilityType'] ?? $this->normaliseEligibilityType($fieldValues['EligibilityType'] ?? null),
            'eligibilityName' => $fieldValues['eligibilityName'] ?? $fieldValues['EligibilityName'] ?? null,
            'eligibilityIDType' => $fieldValues['eligibilityIDType'] ?? $this->normaliseEligibilityIdType($fieldValues['EligibilityIDType'] ?? null),
            'eligibilityID' => $fieldValues['eligibilityID'] ?? $fieldValues['EligibilityID'] ?? null,
            'policyReason' => $fieldValues['policyReason'] ?? $this->normaliseEligibilityReason($fieldValues['EligibilityReason'] ?? null),
        ]);
    }

    /**
     * @param int|string|null $value
     */
    public function normaliseRegistrantIdType($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        $value = strtoupper(trim((string)$value));

        switch ($value) {
            case '1':
                return 'ACN';
            case '2':
                return 'ABN';
            case '3':
                return 'OTHER';
            default:
                return $value;
        }
    }

    /**
     * @param int|string|null $value
     */
    public function normaliseEligibilityType($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        $value = trim((string)$value);

        switch ($value) {
            case '5':
                return 'Company';
            case '14':
                return 'Registered Business';
            case '16':
                return 'Sole Trader';
            case '18':
                return 'Trademark Owner';
            case '12':
                return 'Pending TM Owner';
            case '6':
                return 'Incorporated Association';
            case '4':
                return 'Commercial Statutory Body';
            case '11':
                return 'Partnership';
            case '9':
                return 'Non-profit Organisation';
            case '1':
                return 'Charity';
            case '17':
                return 'Trade Union';
            case '2':
                return 'Citizen/Resident';
            case '15':
                return 'Religious/Church Groups';
            case '7':
                return 'Unincorporated Association';
            case '3':
                return 'Club';
            case '19':
                return 'Child Care Centre';
            case '20':
                return 'Government School';
            case '21':
                return 'Higher Education Institution';
            case '8':
                return 'Industry Body';
            case '22':
                return 'National Body';
            case '23':
                return 'Non-Government School';
            case '13':
                return 'Political Party';
            case '24':
                return 'Pre-school';
            case '25':
                return 'Research Organisation';
            case '26':
                return 'Training Organisation';
            case '10':
                return 'Other';
            default:
                return $value;
        }
    }

    /**
     * @param int|string|null $value
     */
    public function normaliseEligibilityIdType($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        $value = strtoupper(trim((string)$value));

        switch ($value) {
            case '1':
                return 'ACN';
            case '12':
                return 'ABN';
            case '10':
                return 'TM';
            case '2':
                return 'ACT BN';
            case '3':
                return 'NSW BN';
            case '4':
                return 'NT BN';
            case '5':
                return 'QLD BN';
            case '6':
                return 'SA BN';
            case '7':
                return 'TAS BN';
            case '8':
                return 'VIC BN';
            case '9':
                return 'WA BN';
            default:
                return 'OTHER';
        }
    }

    public function normaliseEligibilityReason($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        $value = trim((string)$value);

        switch ($value) {
            case '1':
                return '101'; // Exact match to a name of the applicant
            case '2':
                return '102'; // Closely and substantially connected to a name of the applicant
            case '3':
                return '103'; // An acronym of a name of the applicant
            case '4':
                return '104'; // An abbreviation of a name of the registrant
            case '5':
                return '105'; // Reference to the name of a program or program of the applicant
            case '6':
                return '106'; // Makes and has Ministerial approval to reference "university"
            default:
                return is_numeric($value)
                    ? Str::start($value, '10')
                    : $value;
        }
    }
}