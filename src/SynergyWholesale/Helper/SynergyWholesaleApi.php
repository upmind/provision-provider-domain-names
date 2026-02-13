<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\SynergyWholesale\Helper;

use Carbon\Carbon;
use SoapClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionBase\Provider\DataSet\SystemInfo;
use Upmind\ProvisionProviders\DomainNames\Data\ContactData;
use Upmind\ProvisionProviders\DomainNames\Data\ContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\DacDomain;
use Upmind\ProvisionProviders\DomainNames\Data\Enums\ContactType;
use Upmind\ProvisionProviders\DomainNames\Data\GlueRecord;
use Upmind\ProvisionProviders\DomainNames\Data\Nameserver;
use Upmind\ProvisionProviders\DomainNames\Data\NameserversResult;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\SynergyWholesale\Data\Configuration;

/**
 * SynergyWholesale Domains API client.
 */
class SynergyWholesaleApi
{
    /**
     * Contact Types
     */
    public const CONTACT_TYPE_REGISTRANT = 'registrant';
    public const CONTACT_TYPE_TECH = 'technical';
    public const CONTACT_TYPE_ADMIN = 'admin';
    public const CONTACT_TYPE_BILLING = 'billing';

    protected SoapClient $client;

    protected Configuration $configuration;

    protected LoggerInterface $logger;

    public function __construct(SoapClient $client, Configuration $configuration, LoggerInterface $logger)
    {
        $this->client = $client;
        $this->configuration = $configuration;
        $this->logger = $logger;
    }


    public function makeRequest(string $command, ?array $params = null): ?array
    {
        if ($params) {
            $requestParams = $params;
        }

        $requestParams['apiKey'] = $this->configuration->api_key;
        $requestParams['resellerID'] = $this->configuration->reseller_id;

        $this->logger->debug('SynergyWholesale API Request', [
            'command' => $command,
            'params' => $requestParams,
        ]);

        $response = $this->client->__soapCall($command, array($requestParams));
        $responseData = json_decode(json_encode($response), true);

        $this->logger->debug('SynergyWholesale API Response', [
            'result' => $responseData,
        ]);

        return $this->parseResponseData($command, $response);
    }


    /**
     * @param array|\stdClass $result
     */
    private function parseResponseData(string $command, $result): array
    {
        $parsedResult = is_array($result) ? $result : json_decode(json_encode($result), true);

        if (!$parsedResult) {
            throw ProvisionFunctionError::create('Unknown Provider API Error')
                ->withData([
                    'response' => $parsedResult,
                ]);
        }

        if ($error = $this->getResponseErrorMessage($command, $parsedResult)) {
            throw ProvisionFunctionError::create(sprintf('Provider API Error: %s', $error))
                ->withData([
                    'response' => $parsedResult,
                ]);
        }

        return $parsedResult;
    }


    private function getResponseErrorMessage(string $command, array $responseData)
    {
        if (!in_array((string)$responseData['status'], ['OK', 'OK_ELIGIBILITY', 'OK_PENDING_COR'])) {
            $errorMessage = 'Unknown error';
            if (isset($responseData['errorMessage'])) {
                $errorMessage = $responseData['errorMessage'];
            }

            $prettyCommand = ucwords(str_replace('_', ' ', Str::snake($command)));
            if (Str::startsWith($errorMessage, $prettyCommand . ' Failed - ')) {
                $errorMessage = Str::after($errorMessage, $prettyCommand . ' Failed - ');
            }
        }

        return $errorMessage ?? null;
    }

    public function getDomainEligibilityFields(string $tld): array
    {
        $command = 'getDomainEligibilityFields';
        $params = [
            'extension' => $tld,
        ];

        $response = $this->makeRequest($command, $params);

        return $response['fields'] ?? [];
    }

    /**
     * @param string $domainName
     * @param bool $minimal When true, skip extra API calls (glue records, etc.)
     */
    public function getDomainInfo(string $domainName, bool $minimal = false): array
    {
        $command = 'bulkDomainInfo';
        $params = [
            'domainList' => [$domainName],
        ];
        $response = $this->makeRequest($command, $params)['domainList'][0];
        $this->parseResponseData($command, $response);

        /** @var \Illuminate\Support\Collection $statusesCollection */
        $statusesCollection = collect([$response['status'], $response['domain_status']]);

        $glueRecords = $minimal ? [] : $this->listGlueRecords($domainName);

        return [
            'id' => $response['domainRoid'],
            'domain' => (string)$response['domainName'],
            'statuses' => $statusesCollection
                ->map(fn ($status) => strtoupper($status))
                ->unique()
                ->values()
                ->toArray(),
            'locked' => $response['domain_status'] == 'clientTransferProhibited',
            ContactType::REGISTRANT => isset($response['contacts']['registrant'])
                ? $this->parseContact($response['contacts']['registrant'])
                : null,
            ContactType::BILLING => isset($response['contacts']['billing'])
                ? $this->parseContact($response['contacts']['billing'])
                : null,
            ContactType::TECH => isset($response['contacts']['tech'])
                ? $this->parseContact($response['contacts']['tech'])
                : null,
            ContactType::ADMIN => isset($response['contacts']['admin'])
                ? $this->parseContact($response['contacts']['admin'])
                : null,
            'ns' => NameserversResult::create($this->parseNameservers($response['nameServers'])),
            'created_at' => Utils::formatDate((string)$response['createdDate']),
            'updated_at' => null,
            'expires_at' => isset($response['domain_expiry']) ? Utils::formatDate($response['domain_expiry']) : null,
            'glue_records' => $glueRecords,
        ];
    }

    /**
     * @return array<GlueRecord>
     */
    public function listGlueRecords(string $domainName): array
    {
        // Fetch glue records
        $glueRecords = [];

        try {
            $hostsResponse = $this->makeRequest('listAllHosts', [
                'domainName' => $domainName,
            ]);
            foreach ($hostsResponse['hosts'] ?? [] as $host) {
                $glueRecords[] = GlueRecord::create([
                    'hostname' => $host['hostName'],
                    'ips' => $host['ip'] ?? [],
                ]);
            }
        } catch (Throwable $e) {
            // Domain may not have hosts - ignore
        }

        return $glueRecords;
    }

    private function parseNameservers(array $nameservers): array
    {
        $result = [];
        $i = 1;

        foreach ($nameservers as $ns) {
            $result['ns' . $i] = ['host' => (string)$ns];
            $i++;
        }

        return $result;
    }


    public function checkMultipleDomains(array $domains)
    {
        $command = 'bulkCheckDomain';
        $params = [
            'domainList' => $domains,
        ];

        $response = $this->makeRequest($command, $params);

        $dacDomains = [];

        foreach ($response['domainList'] ?? [] as $result) {
            $available = $result['available'] == 1;

            $dacDomains[] = DacDomain::create([
                'domain' => $result['domain'],
                'description' => sprintf(
                    'Domain is %s to register',
                    $available ? 'available' : 'not available'
                ),
                'tld' => Utils::getTld($result['domain']),
                'can_register' => $available,
                'can_transfer' => !$available,
                'is_premium' => boolval($result["premium"] ?? false),
            ]);
        }

        return $dacDomains;
    }


    public function renew(string $domainName, int $period)
    {
        $command = 'renewDomain';

        $params = [
            'domainName' => $domainName,
            'years' => $period
        ];

        $this->makeRequest($command, $params);
    }


    /**
     * @param string[] $nameservers
     */
    public function updateNameservers(string $domainName, array $nameservers): array
    {
        $command = 'updateNameServers';

        $params = [
            'domainName' => $domainName,
            'nameServers' => $nameservers
        ];

        $this->makeRequest($command, $params);

        $command = 'domainInfo';
        $params = [
            'domainName' => $domainName,
        ];
        $response = $this->makeRequest($command, $params);

        return $this->parseNameservers($response['nameServers']);
    }


    public function setRenewalMode(string $domainName, bool $autoRenew)
    {
        if ($autoRenew) {
            $command = 'enableAutoRenewal';
        } else {
            $command = 'disableAutoRenewal';
        }

        $params = [
            'domainName' => $domainName,
        ];

        $this->makeRequest($command, $params);
    }


    public function getRegistrarLockStatus(string $domainName): bool
    {
        $command = 'domainInfo';
        $params = [
            'domainName' => $domainName,
        ];
        $response = $this->makeRequest($command, $params);

        return $response['domain_status'] == 'clientTransferProhibited';
    }


    public function setRegistrarLock(string $domainName, bool $lock)
    {
        if ($lock) {
            $command = 'lockDomain';
        } else {
            $command = 'unlockDomain';
        }

        $params = [
            'domainName' => $domainName,
        ];

        $this->makeRequest($command, $params);
    }


    public function getDomainEppCode(string $domainName)
    {
        $command = 'domainInfo';
        $params = [
            'domainName' => $domainName,
        ];
        $response = $this->makeRequest($command, $params);
        return $response['domainPassword'];
    }


    public function register(
        string $domainName,
        int $years,
        array $contacts,
        array $nameServers,
        bool $idProtect = false,
        ?array $eligibilityFieldValues = null
    ) {
        $command = 'domainRegister';

        $params = [
            'domainName' => $domainName,
            'years' => $years,
            'nameServers' => $nameServers,
            'idProtect' => $idProtect,
            'eligibility' => $eligibilityFieldValues ? json_encode($eligibilityFieldValues) : null,
        ];

        foreach ($contacts as $type => $contact) {
            if ($contact) {
                $contactParams = $this->setContactParams($contact, $type);
                $params = array_merge($params, $contactParams);
            }
        }

        $this->makeRequest($command, $params);
    }


    private function setContactParams(ContactParams $contactParams, string $type): array
    {
        $nameParts = $this->getNameParts($contactParams->name ?? $contactParams->organisation);

        return [
            "{$type}_firstname" => $nameParts['firstName'],
            "{$type}_lastname" => $nameParts['lastName'] ?: $nameParts['firstName'],
            "{$type}_organisation" => $contactParams->organisation ?: '',
            "{$type}_address" => [$contactParams->address1],
            "{$type}_suburb" => $contactParams->city,
            "{$type}_state" => $contactParams->state ?: $contactParams->city,
            "{$type}_country" => Utils::normalizeCountryCode($contactParams->country_code),
            "{$type}_postcode" => $contactParams->postcode,
            "{$type}_phone" => Utils::internationalPhoneToEpp($contactParams->phone),
            "{$type}_email" => $contactParams->email,
            "{$type}_fax" => '',
        ];
    }


    private function setContactData(ContactData $contactData, string $type): array
    {
        $nameParts = $this->getNameParts($contactData->name ?? $contactData->organisation);

        return [
            "{$type}_firstname" => $nameParts['firstName'],
            "{$type}_lastname" => $nameParts['lastName'] ?: $nameParts['firstName'],
            "{$type}_organisation" => $contactData->organisation ?: '',
            "{$type}_address" => [$contactData->address1],
            "{$type}_suburb" => $contactData->city,
            "{$type}_state" => $contactData->state ?: $contactData->city,
            "{$type}_country" => Utils::normalizeCountryCode($contactData->country_code),
            "{$type}_postcode" => $contactData->postcode,
            "{$type}_phone" => Utils::internationalPhoneToEpp($contactData->phone),
            "{$type}_email" => $contactData->email,
            "{$type}_fax" => '',
        ];
    }


    /**
     * @param string|null $name
     *
     * @return array
     */
    private function getNameParts(?string $name): array
    {
        $nameParts = explode(" ", $name);
        $firstName = array_shift($nameParts);
        $lastName = implode(" ", $nameParts);

        return compact('firstName', 'lastName');
    }


    public function updateRegistrantContact(string $domainName, ContactParams $registrantParams): ContactData
    {
        $command = 'updateContact';

        $params = [
            'domainName' => $domainName,
            "appPurpose" => "",
            "nexusCategory" => "",
        ];

        $info = $this->getDomainInfo($domainName);

        // If we force use of registrant contact for admin/tech/billing, we will use the same contact data.
        // Otherwise, we will use the existing admin/tech/billing contacts.
        $useRegistrantContact = (bool) $this->configuration->use_registrant_contact_for_admin_tech_billing;

        $contacts = [
            self::CONTACT_TYPE_ADMIN => $useRegistrantContact ? $registrantParams : $info['admin'],
            self::CONTACT_TYPE_TECH => $useRegistrantContact ? $registrantParams : $info['tech'],
            self::CONTACT_TYPE_BILLING => $useRegistrantContact ? $registrantParams : $info['billing'],
        ];

        foreach ($contacts as $type => $contact) {
            if (empty($contact)) {
                continue;
            }

            // Different parsing methods for ContactParams and ContactData
            $contactParams = $useRegistrantContact
                ? $this->setContactParams($contact, $type)
                : $this->setContactData($contact, $type);

            $contacts[$type] = $contactParams;
        }

        // Set the different contact types in the params, filtering out empty contacts.
        $params = array_merge($params, array_values(array_filter($contacts)));
        $params = array_merge($params, $this->setContactParams($registrantParams, self::CONTACT_TYPE_REGISTRANT));

        $this->makeRequest($command, $params);

        return $this->getDomainInfo($domainName)['registrant'];
    }

    public function updateContact(
        string $domainName,
        ContactParams $contactParams,
        ContactType $contactType
    ): ContactData {
        $command = 'updateContact';

        $params = [
            'domainName' => $domainName,
            "appPurpose" => "",
            "nexusCategory" => "",
        ];

        $info = $this->getDomainInfo($domainName);

        $contacts = [
            self::CONTACT_TYPE_REGISTRANT => $info[ContactType::REGISTRANT],
            self::CONTACT_TYPE_ADMIN => $info[ContactType::ADMIN],
            self::CONTACT_TYPE_TECH => $info[ContactType::TECH],
            self::CONTACT_TYPE_BILLING => $info[ContactType::BILLING],
        ];

        // Unset the contact we will update.
        unset($contacts[$this->getProviderContactTypeValue($contactType)]);

        foreach ($contacts as $type => $contact) {
            if (empty($contact)) {
                continue;
            }

            $params = array_merge($params, $this->setContactData($contact, $type));
        }

        // Set the different contact types in the params, filtering out empty contacts.
        $params = array_merge($params, $this->setContactParams(
            $contactParams,
            $this->getProviderContactTypeValue($contactType)
        ));

        $this->makeRequest($command, $params);

        return $this->getDomainInfo($domainName)[$contactType->getValue()];
    }


    private function parseContact(array $contact): ContactData
    {
        return ContactData::create([
            'organisation' => $contact['organisation'] ?? null,
            'name' => implode(' ', [$contact['firstname'] ?? '', $contact['lastname'] ?? '']),
            'address1' => $contact['address1'] ?? null,
            'city' => $contact['suburb'],
            'state' => $contact['state'] ?? null,
            'postcode' => $contact['postcode'] ?? null,
            'country_code' => Utils::normalizeCountryCode($contact['country'] ?? null),
            'email' => $contact['email'] ?? null,
            'phone' => $contact['phone'] ?? null,
        ]);
    }


    public function initiateTransfer(string $domainName, string $eppCode, ContactParams $contactParams)
    {
        $command = 'transferDomain';

        $nameParts = $this->getNameParts($contactParams->name ?? $contactParams->organisation);

        $params = [
            'domainName' => $domainName,
            'authInfo' => $eppCode,
            'firstname' => $nameParts['firstName'],
            'organisation' => $contactParams->organisation ?: '',
            'lastname' => $nameParts['lastName'] ?: $nameParts['firstName'],
            'address' => [$contactParams->address1],
            'suburb' => $contactParams->city,
            'state' => $contactParams->state ?: $contactParams->city,
            'country' => Utils::normalizeCountryCode($contactParams->country_code),
            'postcode' => $contactParams->postcode,
            'phone' => Utils::internationalPhoneToEpp($contactParams->phone),
            'email' => $contactParams->email,
            'fax' => '',
            'doRenewal' => false,
            'idProtect' => false,
        ];

        $this->makeRequest($command, $params);
    }

    /**
     * Get domain verification info (ICANN and AU eligibility).
     *
     * @param string $domainName Full domain name (e.g., example.com)
     * @return array Verification status data
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getDomainVerificationInfo(string $domainName): array
    {
        $response = $this->makeRequest('domainInfo', [
            'domainName' => $domainName,
        ]);

        $isAuDomain = Str::endsWith($domainName, '.au');

        // Map ICANN status - set to null if "N/A" (not applicable for AU domains)
        $icannStatus = $response['icannStatus'] ?? 'unknown';
        $icannVerificationStatus = ($icannStatus === 'N/A') ? null : $icannStatus;

        // Map AU eligibility boolean to status string
        $cctldVerificationStatus = null;
        if ($isAuDomain && isset($response['au_valid_eligibility'])) {
            $cctldVerificationStatus = $response['au_valid_eligibility'] ? 'verified' : 'unverified';
        }

        return [
            'icann_verification_status' => $icannVerificationStatus,
            'cctld_verification_status' => $cctldVerificationStatus,
            'verification_deadline' => $response['icannVerificationDateEnd'] ?? null,
            'provider_specific_data' => [
                'au_eligibility_last_check' => $response['au_eligibility_last_check'] ?? null,
            ],
        ];
    }

    /**
     * Resend verification email for a domain.
     *
     * @param string $domainName Full domain name (e.g., example.com)
     * @return array Success result
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function resendVerificationEmail(string $domainName): array
    {
        $response = $this->makeRequest('resendVerificationEmail', [
            'domainName' => $domainName,
        ]);

        return [
            'success' => true,
            'message' => $response['message'] ?? 'Verification email sent successfully',
        ];
    }

    /**
     * Add a glue record (host) for a domain.
     *
     * @param string $domainName Full domain name (e.g., example.com)
     * @param string $host Host prefix (e.g., "ns1")
     * @param array $ips Array of IP addresses
     * @return array API response
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function addRegistryHost(string $domainName, string $host, array $ips): array
    {
        return $this->makeRequest('addHost', [
            'domainName' => $domainName,
            'host' => $host,
            'ipAddress' => $ips,
        ]);
    }

    /**
     * Delete a glue record (host) for a domain.
     *
     * @param string $domainName Full domain name (e.g., example.com)
     * @param string $host Host prefix (e.g., "ns1")
     * @return array API response
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function deleteRegistryHost(string $domainName, string $host): array
    {
        return $this->makeRequest('deleteHost', [
            'domainName' => $domainName,
            'host' => $host,
        ]);
    }

    /**
     * Get info for a specific glue record (host).
     *
     * @param string $domainName Full domain name (e.g., example.com)
     * @param string $host Host prefix (e.g., "ns1")
     * @return array API response with host info
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function listRegistryHost(string $domainName, string $host): array
    {
        return $this->makeRequest('listHost', [
            'domainName' => $domainName,
            'host' => $host,
        ]);
    }

    /**
     * List all glue records (hosts) for a domain.
     *
     * @param string $domainName Full domain name (e.g., example.com)
     * @return array API response with all hosts
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function listAllRegistryHosts(string $domainName): array
    {
        return $this->makeRequest('listAllHosts', [
            'domainName' => $domainName,
        ]);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function getProviderContactTypeValue(ContactType $contactType): string
    {
        switch ($contactType) {
            case $contactType->equals(ContactType::REGISTRANT()):
                return self::CONTACT_TYPE_REGISTRANT;
            case $contactType->equals(ContactType::ADMIN()):
                return self::CONTACT_TYPE_ADMIN;
            case $contactType->equals(ContactType::BILLING()):
                return self::CONTACT_TYPE_BILLING;
            case $contactType->equals(ContactType::TECH()):
                return self::CONTACT_TYPE_TECH;
            default:
                throw ProvisionFunctionError::create('Invalid contact type: ' . $contactType->getValue());
        }
    }
}
