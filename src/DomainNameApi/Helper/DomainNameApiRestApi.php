<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\DomainNameApi\Helper;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use UnexpectedValueException;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionProviders\DomainNames\Data\ContactData;
use Upmind\ProvisionProviders\DomainNames\Data\ContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\ContactResult;
use Upmind\ProvisionProviders\DomainNames\Data\DacDomain;
use Upmind\ProvisionProviders\DomainNames\Data\DacParams;
use Upmind\ProvisionProviders\DomainNames\Data\DacResult;
use Upmind\ProvisionProviders\DomainNames\Data\DomainInfoParams;
use Upmind\ProvisionProviders\DomainNames\Data\DomainResult;
use Upmind\ProvisionProviders\DomainNames\Data\Enums\ContactType;
use Upmind\ProvisionProviders\DomainNames\Data\EppCodeResult;
use Upmind\ProvisionProviders\DomainNames\Data\EppParams;
use Upmind\ProvisionProviders\DomainNames\Data\LockParams;
use Upmind\ProvisionProviders\DomainNames\Data\NameserversResult;
use Upmind\ProvisionProviders\DomainNames\Data\RegisterDomainParams;
use Upmind\ProvisionProviders\DomainNames\Data\RenewParams;
use Upmind\ProvisionProviders\DomainNames\Data\TransferParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateNameserversParams;
use Upmind\ProvisionProviders\DomainNames\DomainNameApi\Provider;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;

class DomainNameApiRestApi implements DomainNameApiInterface
{
    public const PRODUCTION_URL = 'https://api.domainresellerapi.com';
    public const OTE_URL = 'https://ote.domainresellerapi.com';

    /**
     * Rest API Contact Types
     */
    public const CONTACT_TYPE_REGISTRANT = 'Registrant';
    public const CONTACT_TYPE_ADMIN = 'Admin';
    public const CONTACT_TYPE_TECH = 'Tech';
    public const CONTACT_TYPE_BILLING = 'Billing';

    private Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function checkAvailability(DacParams $params): DacResult
    {
        $bodyParams = [];

        // Get domain list
        $domains = array_map(
            static fn ($tld) => Utils::getDomain($params->sld, $tld),
            $params->tlds
        );

        // Add domains to request body params
        foreach ($domains as $domain) {
            $bodyParams[] = ['domainName' => $domain];
        }

        $result = $this->makeRequest('domains/bulk-search', null, $bodyParams, 'POST');

        if (!isset($result['success']) || (bool) $result['success'] === false) {
            throw ProvisionFunctionError::create('Provider API Error - Domain Availability check failed')
                ->withData([
                    'response' => $result
                ]);
        }

        $dacDomains = [];

        // If not an array returned in the expected parsed response, return empty result.
        if (empty($result['infos']) || !is_array($result['infos'])) {
            return DacResult::create([
                'domains' => $dacDomains
            ]);
        }

        foreach ($result['infos'] as $domain) {
            if (!is_array($domain)) {
                continue;
            }

            $status = strtolower(isset($domain['status']) ? (string) $domain['status'] : '');
            $isAvailable = in_array($status, ['available', '1', 'true'], true);

            $dacDomains[] = DacDomain::create([
                'domain' => $domain['domainName'],
                'tld' => mb_strtolower($domain['tld']),
                'can_register' => $isAvailable,
                'can_transfer' => !$isAvailable,
                'is_premium' => isset($domain['isPremium']) && (bool) $domain['isPremium'] === true,
                'description' => $result['reason'] ?? sprintf(
                    'Domain is %s to register',
                    $isAvailable ? 'available' : 'not available'
                    ),
            ]);
        }

        return DacResult::create([
            'domains' => $dacDomains
        ]);
    }

    /**
     * @throws \libphonenumber\NumberParseException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function registerWithContactInfo(RegisterDomainParams $params): DomainResult
    {
        if ($params->registrant->register === null) {
            throw ProvisionFunctionError::create('Registrant contact data is required for domain registration');
        }

        $domainName = Utils::getDomain($params->sld, $params->tld);

        // Prepare POST payload
        $bodyParams = [
            'domainName' => $domainName,
            'period' => $params->renew_years,
            'privacyEnabled' => $params->shouldWhoisPrivacy(),
        ];

        // Add additional attributes if any to payload.
        if (!empty($params->additional_fields)) {
            $bodyParams['tldAttributes'] = $params->additional_fields;
        }

        // Add nameservers to payload
        $nameServers = [];
        for ($i = 1; $i <= Provider::MAX_CUSTOM_NAMESERVERS; $i++) {
            if (Arr::has($params, 'nameservers.ns' . $i)) {
                $nameServers[] = Arr::get($params, 'nameservers.ns' . $i)['host'];
            }
        }

        if (empty($nameServers)) {
            foreach (Provider::NAMESERVERS as $defaultNameserver) {
                $nameServers[] = $defaultNameserver['host'];
            }
        }

        $bodyParams['nameServers'] = $nameServers;

        // Add contacts to payload
        $contacts = [];
        $contacts[] = $this->mapContactParamsToProviderContact(
            $params->registrant->register,
            ContactType::REGISTRANT()
        );

        if ($params->admin->register !== null) {
            $contacts[] = $this->mapContactParamsToProviderContact(
                $params->admin->register,
                ContactType::ADMIN()
            );
        }

        if ($params->billing->register !== null) {
            $contacts[] = $this->mapContactParamsToProviderContact(
                $params->billing->register,
                ContactType::BILLING()
            );
        }

        if ($params->tech->register !== null) {
            $contacts[] = $this->mapContactParamsToProviderContact(
                $params->tech->register,
                ContactType::TECH()
            );
        }

        $bodyParams['contacts'] = $contacts;

        $result = $this->makeRequest('domains/register-with-contacts', null, $bodyParams, 'POST');

        if (empty($result)) {
            throw ProvisionFunctionError::create(sprintf(
                'Provider API Error - Domain Registration failed for %s',
                $domainName
            ));
        }

        return $this->getDetails(DomainInfoParams::create([
            'sld' => $params->sld,
            'tld' => $params->tld,
        ]))->setMessage('Domain registered successfully');
    }

    /**
     * @throws \libphonenumber\NumberParseException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function transfer(TransferParams $params): DomainResult
    {
        if ($params->epp_code === null) {
            throw ProvisionFunctionError::create('EPP code is required for domain transfer');
        }

        $domainName = Utils::getDomain($params->sld, $params->tld);

        try {
            return $this->getDetails(DomainInfoParams::create([
                'sld' => $params->sld,
                'tld' => $params->tld,
            ]))->setMessage('Domain active in registrar account');
        } catch (ProvisionFunctionError $e) {
            // initiate transfer ...
        }

        $bodyParams = [
            'domainName' => $domainName,
            'authCode' => $params->epp_code,
            'period' => $params->renew_years ?? 1,
        ];

        // Add contacts to payload
        $contacts = [];

        if ($params->registrant !== null && $params->registrant->register !== null) {
            $contacts[] = $this->mapContactParamsToProviderContact(
                $params->registrant->register,
                ContactType::REGISTRANT()
            );
        }

        if ($params->admin !== null && $params->admin->register !== null) {
            $contacts[] = $this->mapContactParamsToProviderContact(
                $params->admin->register,
                ContactType::ADMIN()
            );
        }

        if ($params->billing !== null && $params->billing->register !== null) {
            $contacts[] = $this->mapContactParamsToProviderContact(
                $params->billing->register,
                ContactType::BILLING()
            );
        }

        if ($params->tech !== null && $params->tech->register !== null) {
            $contacts[] = $this->mapContactParamsToProviderContact(
                $params->tech->register,
                ContactType::TECH()
            );
        }

        if (!empty($contacts)) {
            $bodyParams['contacts'] = $contacts;
        }

        $result = $this->makeRequest('domains/transfer', null, $bodyParams, 'POST');

        if (empty($result)) {
            throw ProvisionFunctionError::create(sprintf('Could not transfer domain %s', $domainName));
        }

        throw ProvisionFunctionError::create('Domain transfer initiated');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function renew(RenewParams $params): DomainResult
    {
        $domain = Utils::getDomain($params->sld, $params->tld);

        $result = $this->makeRequest('domains/renew', null, [
            'domainName' => $domain,
            'period' => $params->renew_years,
        ], 'POST');

        if (empty($result) || !isset($result['success']) || (bool) $result['success'] !== true) {
            throw ProvisionFunctionError::create(sprintf('Could not renew domain %s', $domain));
        }

        return $this->getDetails(DomainInfoParams::create([
            'sld' => $params->sld,
            'tld' => $params->tld,
        ]))->setMessage('Domain renewed successfully');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getDetails(DomainInfoParams $params): DomainResult
    {
        $domain = Utils::getDomain($params->sld, $params->tld);

        try {
            $result = $this->makeRequest('domains/info', ['DomainName' => $domain]);

            if (empty($result)) {
                throw ProvisionFunctionError::create('Domain not found');
            }
        } catch (ProvisionFunctionError $e) {
            if ($e->getCode() === 404) {
                throw ProvisionFunctionError::create('Domain not found');
            }

            throw $e;
        }

        return $this->parseDomainInfo($domain, $result)->setMessage('Domain information retrieved');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function modifyNameServer(UpdateNameserversParams $params): NameserversResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        $nameservers = [];

        for ($i = 1; $i <= Provider::MAX_CUSTOM_NAMESERVERS; $i++) {
            if (Arr::has($params, 'ns' . $i)) {
                $nameservers[] = Arr::get($params, 'ns' . $i)['host'];
            }
        }

        // Returns empty result
        $this->makeRequest('domains/dns/name-server', null, [
            'domainName' => $domainName,
            'nameServers' => $nameservers,
        ], 'PUT');

        // Set nameservers
        $nameServersCollection = Collection::make($nameservers);
        $returnNameservers = $nameServersCollection
            ->mapWithKeys(fn ($ns, $i) => ['ns' . ($i + 1) => $ns])
            ->toArray();

        return NameserversResult::create($returnNameservers)
            ->setMessage('Nameservers are changed');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getEppCode(EppParams $params): EppCodeResult
    {
        $domain = Utils::getDomain($params->sld, $params->tld);

        $domainResult = $this->getDetails(DomainInfoParams::create([
            'sld' => $params->sld,
            'tld' => $params->tld,
        ]));

        // epp_code attribute is set via a magic setter when getting domain info.
        if (!isset($domainResult->auth_code)) {
            throw ProvisionFunctionError::create(sprintf('Could not retrieve Auth Code for %s', $domain));
        }

        return EppCodeResult::create([
            'epp_code' => $domainResult->auth_code,
        ]);
    }

    /**
     * @throws \libphonenumber\NumberParseException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function saveContacts(UpdateContactParams $params): ContactResult
    {
        try {
            $contactType = $params->getContactTypeEnum();
        } catch (UnexpectedValueException $ex) {
            throw ProvisionFunctionError::create(sprintf(
                'Invalid contact type:  %s',
                $params->contact_type
            ));
        }

        $domain = Utils::getDomain($params->sld, $params->tld);

        $domainInfo = $this->getDetails(DomainInfoParams::create([
            'sld' => $params->sld,
            'tld' => $params->tld,
        ]));

        // Get the current registrant details to use as fallback
        $currentRegistrantDetails = $domainInfo->registrant;

        if ($currentRegistrantDetails === null) {
            throw ProvisionFunctionError::create(sprintf(
                'Please contact the registrant. Could not find registrant for domain: %s',
                $domain
            ));
        }

        // Build the contacts array for each contact type, depending on the contact we want to update
        // Fetch the existing contacts for the contact type we don't want to update, but force sync if missing.
        // Contact list is always set as registrant, admin, tech, billing
        switch ($contactType) {
            case $contactType->equals(ContactType::REGISTRANT()):
                $contacts = [
                    $this->mapContactParamsToProviderContact($params->contact, ContactType::REGISTRANT()),
                    $this->mapContactParamsToProviderContact(
                        isset($domainInfo->admin)
                            ? ContactParams::create($domainInfo->admin->all())
                            : ContactParams::create($params->contact),
                        ContactType::ADMIN()
                    ),
                    $this->mapContactParamsToProviderContact(
                        isset($domainInfo->tech)
                            ? ContactParams::create($domainInfo->tech->all())
                            : ContactParams::create($params->contact),
                        ContactType::TECH()
                    ),
                    $this->mapContactParamsToProviderContact(
                        isset($domainInfo->billing)
                            ? ContactParams::create($domainInfo->billing->all())
                            : ContactParams::create($params->contact),
                        ContactType::BILLING()
                    )
                ];
                break;
            case $contactType->equals(ContactType::ADMIN()):
                $contacts = [
                    $this->mapContactParamsToProviderContact(
                        ContactParams::create($currentRegistrantDetails->all()),
                        ContactType::REGISTRANT()
                    ),
                    $this->mapContactParamsToProviderContact($params->contact, ContactType::ADMIN()),
                    $this->mapContactParamsToProviderContact(
                        isset($domainInfo->tech)
                            ? ContactParams::create($domainInfo->tech->all())
                            : ContactParams::create($currentRegistrantDetails->all()),
                        ContactType::TECH()
                    ),
                    $this->mapContactParamsToProviderContact(
                        isset($domainInfo->billing)
                            ? ContactParams::create($domainInfo->billing->all())
                            : ContactParams::create($currentRegistrantDetails->all()),
                        ContactType::BILLING()
                    )
                ];
                break;
            case $contactType->equals(ContactType::TECH()):
                $contacts = [
                    $this->mapContactParamsToProviderContact(
                        ContactParams::create($currentRegistrantDetails->all()),
                        ContactType::REGISTRANT()
                    ),
                    $this->mapContactParamsToProviderContact(
                        isset($domainInfo->admin)
                            ? ContactParams::create($domainInfo->admin->all())
                            : ContactParams::create($currentRegistrantDetails->all()),
                        ContactType::ADMIN()
                    ),
                    $this->mapContactParamsToProviderContact($params->contact, ContactType::TECH()),
                    $this->mapContactParamsToProviderContact(
                        isset($domainInfo->billing)
                            ? ContactParams::create($domainInfo->billing->all())
                            : ContactParams::create($currentRegistrantDetails->all()),
                        ContactType::BILLING()
                    ),
                ];
                break;
            case $contactType->equals(ContactType::BILLING()):
                $contacts = [
                    $this->mapContactParamsToProviderContact(
                        ContactParams::create($currentRegistrantDetails->all()),
                        ContactType::REGISTRANT()
                    ),
                    $this->mapContactParamsToProviderContact(
                        isset($domainInfo->admin)
                            ? ContactParams::create($domainInfo->admin->all())
                            : ContactParams::create($currentRegistrantDetails->all()),
                        ContactType::ADMIN()
                    ),
                    $this->mapContactParamsToProviderContact(
                        isset($domainInfo->tech)
                            ? ContactParams::create($domainInfo->tech->all())
                            : ContactParams::create($currentRegistrantDetails->all()),
                        ContactType::TECH()
                    ),
                    $this->mapContactParamsToProviderContact($params->contact, ContactType::BILLING()),
                ];
                break;
            default:
                throw ProvisionFunctionError::create(sprintf('Invalid contact type: %s', $params->contact_type));
        }

        // Returns 204 No Content response.
        $this->makeRequest('domains/contacts/update', null, [
            'domainName' => $domain,
            'contacts' => $contacts,
        ], 'PUT');

        $updatedDomainInfo = $this->getDetails(DomainInfoParams::create([
            'sld' => $params->sld,
            'tld' => $params->tld,
        ]));

        switch ($contactType) {
            case $contactType->equals(ContactType::REGISTRANT()):
                $updatedContact = $updatedDomainInfo->registrant;
                break;
            case $contactType->equals(ContactType::ADMIN()):
                $updatedContact = $updatedDomainInfo->admin;
                break;
            case $contactType->equals(ContactType::TECH()):
                $updatedContact = $updatedDomainInfo->tech;
                break;
            case $contactType->equals(ContactType::BILLING()):
                $updatedContact = $updatedDomainInfo->billing;
                break;
            default:
                throw ProvisionFunctionError::create(sprintf('Invalid contact type: %s', $params->contact_type));
        }

        return ContactResult::create($updatedContact->all())
            ->setMessage(sprintf('%s contact updated successfully', ucfirst($params->contact_type)));
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function toggleTheftProtectionLock(LockParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        $domainInfo = $this->getDetails(DomainInfoParams::create([
            'sld' => $params->sld,
            'tld' => $params->tld,
        ]));

        if ($params->shouldLock() === $domainInfo->locked) {
            return $domainInfo
                ->setMessage(sprintf('Domain already %s', $params->shouldLock() ? 'locked' : 'unlocked'));
        }

        $bodyParams = [
            'domainName' => $domainName,
            'lockStatus' => $params->shouldLock()
        ];

        // Returns 204 No Content response.
        $this->makeRequest('domains/lock', null, $bodyParams, 'POST');

        $domainInfo->setLocked($params->shouldLock());

        return $domainInfo
            ->setMessage(sprintf('Domain successfully %s', $params->shouldLock() ? 'locked' : 'unlocked'));
    }

    private function parseDomainInfo(string $domainName, array $data): DomainResult
    {
        $domainInfo = [
            'id' => (string) ($data['id'] ?? ($data['objectId'] ?? 0)),
            'domain' => (string) ($data['domainName'] ?? ($data['name'] ?? $domainName)),
            'statuses' => [$this->mapProviderDomainStatus((string) ($data['status'] ?? ''))],
            'locked' => !empty($data['lockStatus']),
            'whois_privacy' => !empty($data['privacyProtectionStatus']),
            'auto_renew' => isset($data['renewalMode']) && mb_strtolower($data['renewalMode']) === 'autorenew',
            'glue_records' => null,
            'created_at' => isset($data['startDate']) ? Utils::formatDate($data['startDate']): null,
            'updated_at' => isset($data['updatedDate']) ? Utils::formatDate($data['updatedDate']) : null,
            'expires_at' => isset($data['expirationDate']) ? Utils::formatDate($data['expirationDate']): null,
            'operation_status' => DomainResult::OPERATION_COMPLETE,
        ];

        // Set contacts
        foreach ($data['contacts'] ?? [] as $contact) {
            try {
                $contactType = ContactType::from(mb_strtolower(
                    $contact['ContactType'] ?? ($contact['contactType'] ?? '')
                ));
            } catch (UnexpectedValueException $e) {
                if (!isset($contact['handle'])) {
                    continue;
                }

                // Use fallback by checking the contact handle first 3 characters
                switch (mb_strtolower(mb_substr($contact['handle'], 0, 3))) {
                    case 'd-r':
                        $contactType = ContactType::REGISTRANT();
                        break;
                    case 'd-a':
                        $contactType = ContactType::ADMIN();
                        break;
                    case 'd-b':
                        $contactType = ContactType::BILLING();
                        break;
                    case 'd-t':
                        $contactType = ContactType::TECH();
                        break;
                    default:
                        // If no matching prefix found, continue the loop
                        continue 2;
                }
            }

            $domainInfo[$contactType->getValue()] = $this->mapProviderContactToContactData($contact);
        }

        // Set nameservers
        $nameServersCollection = Collection::make($data['nameServers'] ?? ($data['nameservers'] ?? []));
        $nameservers = $nameServersCollection->mapWithKeys(fn ($host, $i) => ['ns' . ($i + 1) => ['host' => $host]]);

        $domainInfo['ns'] = $nameservers->all();

        // Set auth_code as well via magic method setter
        $domainInfo['auth_code'] = $data['authCode'] ?? null;

        return DomainResult::create($domainInfo);
    }

    /**
     * @throws \libphonenumber\NumberParseException
     */
    private function mapContactParamsToProviderContact(ContactParams $params, ContactType $contactType): array
    {
        $name = $params->name ?: $params->organisation;
        @[$firstName, $lastName] = explode(' ', $name, 2);

        $firstName = trim($firstName);
        $lastName = trim($lastName);

        $eppPhone = Utils::internationalPhoneToEpp($params->phone);
        $phoneDiallingCode = trim(Str::before($eppPhone, '.'), '+');
        $phoneNumber = Str::after($eppPhone, '.');

        return [
            'contactType' => $this->getProviderContactTypeValue($contactType),
            'firstName' => $firstName,
            'lastName' => empty($lastName) ? $firstName : $lastName,
            'companyName' => $params->organisation,
            'eMail' => $params->email,
            'address' => $params->address1,
            'city' => $params->city,
            'state' => $params->state ?? '',
            'country' => Utils::normalizeCountryCode($params->country_code),
            'postalCode' => $params->postcode,
            'phoneCountryCode' => $phoneDiallingCode,
            'phone' => $phoneNumber,
        ];
    }

    private function mapProviderContactToContactData(array $contact): ContactData
    {
        // Set contact ID
        $contactId = isset($contact['handle']) ? (string) $contact['handle'] : null;

        if (!isset($contactId)) {
            $contactId = isset($contact['id']) ? (string) $contact['id'] : null;
        }

        $contactName = implode(' ', array_filter([
            isset($contact['firstName']) ? trim($contact['firstName']) : null,
            isset($contact['lastName']) ? trim($contact['lastName']) : null,
        ]));

        if (empty($contactName)) {
            $contactName = $contact['companyName'] ?? null;
        }

        // Don't fail for invalid phone number.
        try {
            $phoneNumber = isset($contact['phoneCountryCode'], $contact['phone'])
                ? Utils::eppPhoneToInternational(sprintf(
                    '+%s.%s',
                    $contact['phoneCountryCode'],
                    $contact['phone']
                ))
                : null;
        } catch (Throwable $e) {
            $phoneNumber = null;
        }

        return ContactData::create([
            'id' => $contactId,
            'name' => $contactName,
            'organisation' => $contact['companyName'] ?? null,
            'email' => $contact['eMail'] ?? null,
            'phone' => $phoneNumber,
            'address1' => $contact['address'] ?? null,
            'city' => $contact['city'] ?? null,
            'state' => $contact['state'] ?? null,
            'postcode' => $contact['postalCode'] ?? null,
            'country_code' => isset($contact['country']) ? Utils::normalizeCountryCode($contact['country']) : null,
        ]);
    }

    private function mapProviderDomainStatus(string $status): string
    {
        if (is_numeric($status) && array_key_exists((int)$status, self::DOMAIN_STATUS_MAP)) {
            return self::DOMAIN_STATUS_MAP[(int) $status];
        }

        return $status;
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

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function makeRequest(
        string $endpoint,
        ?array $query = null,
        ?array $body = null,
        ?string $method = 'GET'
    ): array {
        $params = [];

        if ($query !== null) {
            $params['query'] = $query;
        }

        if ($body !== null) {
            $params['json'] = $body;
        }

        // Set the path with the api/v1 prefix
        $path = sprintf('/api/v1/%s', trim($endpoint, '/'));

        // Make API Call & handle errors.
        try {
            $response = $this->client->request($method, $path, $params);
        } catch (RequestException $ex) {
            $response = $ex->getResponse();

            $statusCode = $response !== null ? $response->getStatusCode() : 0;

            // Cases where status code is set, the response exists in the exception.
            switch ($statusCode) {
                case 400: $this->handleValidationError($response, $ex);
                case 403: $this->handleError($response, $ex);
                case 409: $this->handleError($response, $ex);
                case 500: $this->handleError($response, $ex);
            }

            // All other cases continue
            throw ProvisionFunctionError::create(
                sprintf(
                    'DomainNameAPI Provider Rest API Error [%d]: %s',
                    $statusCode,
                    $ex->getMessage()
                ),
                $ex
            );
        } catch (Throwable $ex) {
            if ($ex instanceof ProvisionFunctionError) {
                throw $ex;
            }

            throw ProvisionFunctionError::create('DomainNameAPI Rest API error: ' . $ex->getMessage(), $ex);
        }

        // Reset pointer to start of stream and get content.
        $result = $response->getBody()->__toString();

        $response->getBody()->close();

        if ($result === '') {
            return [];
        }

        try {
            return json_decode($result, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw ProvisionFunctionError::create('Invalid JSON Response')
                ->withDebug([
                    'response' => $response,
                    'result' => $result,
                ]);
        }
    }

    /**
     * @return no-return
     */
    private function handleValidationError(ResponseInterface $response, RequestException $requestEx): void
    {
        // Reset pointer to start of stream and get content.
        $result = $response->getBody()->__toString();

        $response->getBody()->close();

        try {
            $error = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw ProvisionFunctionError::create('DomainNameAPI Rest API Validation Error', $requestEx)
                ->withDebug([
                    'result' => $result,
                ]);
        }

        $errorMessages = [];

        if (isset($error['error']['validationErrors'])) {
            foreach ($error['error']['validationErrors'] as $validationError) {
                if (isset($validationError['message'])) {
                    $errorMessages[] = $validationError['message'];
                }
            }
        }

        $errorMessagePlaceholder = 'DomainNameAPI Provider Rest API Error [%d]: %s';

        $errorMessage = !empty($errorMessages)
            ? sprintf($errorMessagePlaceholder, $response->getStatusCode(), implode(', ', $errorMessages))
            : sprintf($errorMessagePlaceholder, $response->getStatusCode(), 'N/A');

        throw ProvisionFunctionError::create($errorMessage, $requestEx)
            ->withDebug([
                'result' => $error,
            ]);
    }

    /**
     * @return no-return
     */
    private function handleError(ResponseInterface $response, RequestException $requestEx): void
    {
        // Reset pointer to start of stream and get content.
        $result = $response->getBody()->__toString();

        $response->getBody()->close();

        try {
            $error = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw ProvisionFunctionError::create('DomainNameAPI Rest API Error', $requestEx)
                ->withDebug([
                    'error' => $response,
                ]);
        }

        $errorMessagePlaceholder = 'DomainNameAPI Provider Rest API Error [%d]: %s';

        $errorMessage = isset($error['error']['message'])
            ? sprintf($errorMessagePlaceholder, $response->getStatusCode(), $error['error']['message'])
            : sprintf($errorMessagePlaceholder, $response->getStatusCode(), 'N/A');

        throw ProvisionFunctionError::create($errorMessage, $requestEx)
            ->withDebug([
                'result' => $error,
            ]);
    }
}
