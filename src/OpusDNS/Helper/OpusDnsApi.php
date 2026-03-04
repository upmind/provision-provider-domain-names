<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\OpusDNS\Helper;

use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionProviders\DomainNames\Data\ContactData;
use Upmind\ProvisionProviders\DomainNames\Data\ContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\DacDomain;
use Upmind\ProvisionProviders\DomainNames\Data\GlueRecord;
use Upmind\ProvisionProviders\DomainNames\Data\NameserversResult;
use Upmind\ProvisionProviders\DomainNames\Data\Enums\ContactType;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\OpusDNS\Data\Configuration;

/**
 * OpusDNS REST API client.
 */
class OpusDnsApi
{
    /**
     * Contact type constants.
     */
    const CONTACT_TYPE_REGISTRANT = 'registrant';
    const CONTACT_TYPE_ADMIN = 'admin';
    const CONTACT_TYPE_TECH = 'tech';
    const CONTACT_TYPE_BILLING = 'billing';

    /**
     * @var Configuration
     */
    protected $configuration;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var Client
     */
    protected $httpClient;

    /**
     * @var string|null
     */
    protected $accessToken;

    /**
     * @var int|null
     */
    protected $tokenExpiry;

    public function __construct(Configuration $configuration, LoggerInterface $logger, ?Client $httpClient = null)
    {
        $this->configuration = $configuration;
        $this->logger = $logger;
        $this->httpClient = $httpClient ?: new Client([
            'base_uri' => $configuration->getBaseUrl(),
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'timeout' => 120,
            'connect_timeout' => 30,
        ]);
    }

    /**
     * Obtain or return a cached OAuth2 access token.
     *
     * @throws ProvisionFunctionError
     */
    protected function getAccessToken(): string
    {
        if ($this->accessToken !== null && $this->tokenExpiry !== null && time() < $this->tokenExpiry) {
            return $this->accessToken;
        }

        $this->logger->debug('OpusDNS: Authenticating via OAuth2 client_credentials');

        try {
            $response = $this->httpClient->post('/v1/auth/token', [
                'form_params' => [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->configuration->client_id,
                    'client_secret' => $this->configuration->client_secret,
                ],
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Accept' => 'application/json',
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw ProvisionFunctionError::create('Failed to decode auth response: ' . json_last_error_msg());
            }

            $this->accessToken = $data['access_token'] ?? null;
            $expiresIn = $data['expires_in'] ?? 3600;
            // Expire 60 seconds early to avoid edge cases
            $this->tokenExpiry = time() + $expiresIn - 60;

            if (!$this->accessToken) {
                throw ProvisionFunctionError::create('No access token received from OpusDNS')
                    ->withData(['response' => $data]);
            }

            return $this->accessToken;
        } catch (ProvisionFunctionError $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ProvisionFunctionError::create('OpusDNS authentication failed: ' . $e->getMessage(), $e)
                ->withData(['exception' => get_class($e)]);
        }
    }

    /**
     * Clear the cached access token.
     */
    public function clearAccessToken(): void
    {
        $this->accessToken = null;
        $this->tokenExpiry = null;
    }

    /**
     * Make an authenticated API request.
     *
     * @param string $method HTTP method (GET, POST, PATCH, PUT, DELETE)
     * @param string $path API path (without /v1 prefix)
     * @param array $options Guzzle request options
     * @param bool $retry Whether to retry on 401 (token refresh)
     *
     * @return array Decoded JSON response
     *
     * @throws ProvisionFunctionError
     */
    public function makeRequest(string $method, string $path, array $options = [], bool $retry = true): array
    {
        $token = $this->getAccessToken();
        $fullPath = '/v1/' . ltrim($path, '/');

        $requestOptions = array_merge_recursive($options, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ],
        ]);

        $this->logger->debug('OpusDNS API Request', [
            'method' => $method,
            'path' => $fullPath,
            'options' => $this->redactSensitive($requestOptions),
        ]);

        try {
            $response = $this->httpClient->request($method, $fullPath, $requestOptions);
            $body = (string) $response->getBody();
            $responseData = json_decode($body, true);

            $this->logger->debug('OpusDNS API Response', [
                'status' => $response->getStatusCode(),
                'body' => $responseData,
            ]);

            if ($responseData === null && $body !== '' && $body !== 'null') {
                throw ProvisionFunctionError::create('Failed to decode API response')
                    ->withData(['raw_body' => Str::limit($body, 500)]);
            }

            return $responseData ?: [];
        } catch (ClientException $e) {
            // Retry once on 401 with a fresh token
            if ($e->getResponse()->getStatusCode() === 401 && $retry) {
                $this->clearAccessToken();
                return $this->makeRequest($method, $path, $options, false);
            }

            throw $this->handleApiException($e);
        } catch (ServerException $e) {
            throw $this->handleApiException($e);
        } catch (ConnectException $e) {
            throw ProvisionFunctionError::create('OpusDNS API connection failed: ' . $e->getMessage(), $e)
                ->withData(['exception' => get_class($e)]);
        } catch (RequestException $e) {
            throw $this->handleApiException($e);
        } catch (ProvisionFunctionError $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ProvisionFunctionError::create('OpusDNS API error: ' . $e->getMessage(), $e)
                ->withData(['exception' => get_class($e)]);
        }
    }

    /**
     * Convert a Guzzle exception into a ProvisionFunctionError.
     */
    protected function handleApiException(RequestException $e): ProvisionFunctionError
    {
        $response = $e->getResponse();
        $statusCode = $response ? $response->getStatusCode() : 0;
        $responseBody = $response ? json_decode((string) $response->getBody(), true) : null;

        $errorMessage = 'Provider API Error';
        if (is_array($responseBody)) {
            $errorMessage = $responseBody['message']
                ?? $responseBody['error']['message']
                ?? $responseBody['error']
                ?? $errorMessage;
        }

        return ProvisionFunctionError::create(sprintf('Provider API Error [%d]: %s', $statusCode, $errorMessage), $e)
            ->withData([
                'error' => [
                    'status_code' => $statusCode,
                    'response' => $responseBody,
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                ],
            ]);
    }

    /**
     * Redact sensitive fields from request data for logging.
     */
    protected function redactSensitive(array $data): array
    {
        if (isset($data['headers']['Authorization'])) {
            $data['headers']['Authorization'] = 'Bearer [REDACTED]';
        }

        return $data;
    }

    // =========================================================================
    // Domain Availability
    // =========================================================================

    /**
     * Check domain availability for one or more domains.
     *
     * @param string[] $domains
     *
     * @return DacDomain[]
     */
    public function checkDomains(array $domains): array
    {
        $response = $this->makeRequest('GET', 'domains/check', [
            'query' => ['domains' => $domains],
        ]);

        $dacDomains = [];

        foreach ($response['data'] ?? $response['domains'] ?? [] as $result) {
            $domain = $result['domain'] ?? $result['name'] ?? '';
            $available = !empty($result['available']);

            $dacDomains[] = DacDomain::create([
                'domain' => $domain,
                'description' => sprintf(
                    'Domain is %s to register',
                    $available ? 'available' : 'not available'
                ),
                'tld' => Utils::getTld($domain),
                'can_register' => $available,
                'can_transfer' => !$available,
                'is_premium' => boolval($result['premium'] ?? false),
            ]);
        }

        return $dacDomains;
    }

    // =========================================================================
    // Domain Info
    // =========================================================================

    /**
     * Get full domain information.
     *
     * @param string $domainName The full domain name (e.g., example.com)
     * @param bool $minimal When true, skip extra API calls
     *
     * @return array Normalised domain info array
     */
    public function getDomainInfo(string $domainName, bool $minimal = false): array
    {
        $response = $this->makeRequest('GET', 'domains/' . urlencode($domainName));

        $domain = $response['data'] ?? $response;

        $glueRecords = $minimal ? [] : $this->listGlueRecords($domainName);

        return [
            'id' => $domain['id'] ?? $domain['reference'] ?? null,
            'domain' => $domain['domain'] ?? $domain['name'] ?? $domainName,
            'statuses' => $this->parseStatuses($domain),
            'locked' => $this->isDomainLocked($domain),
            ContactType::REGISTRANT => $this->parseContactFromDomain($domain, 'registrant'),
            ContactType::BILLING => $this->parseContactFromDomain($domain, 'billing'),
            ContactType::TECH => $this->parseContactFromDomain($domain, 'tech'),
            ContactType::ADMIN => $this->parseContactFromDomain($domain, 'admin'),
            'ns' => NameserversResult::create($this->parseNameservers($domain)),
            'created_at' => Utils::formatDate($domain['created_at'] ?? $domain['createdAt'] ?? null),
            'updated_at' => Utils::formatDate($domain['updated_at'] ?? $domain['updatedAt'] ?? null),
            'expires_at' => Utils::formatDate($domain['expires_at'] ?? $domain['expiresAt'] ?? null),
            'glue_records' => $glueRecords,
        ];
    }

    /**
     * Extract statuses from the domain API response.
     *
     * @return string[]
     */
    protected function parseStatuses(array $domain): array
    {
        $statuses = [];

        if (isset($domain['status'])) {
            $statuses[] = strtoupper((string) $domain['status']);
        }

        if (isset($domain['statuses']) && is_array($domain['statuses'])) {
            foreach ($domain['statuses'] as $status) {
                $statuses[] = strtoupper((string) $status);
            }
        }

        if (isset($domain['epp_statuses']) && is_array($domain['epp_statuses'])) {
            foreach ($domain['epp_statuses'] as $status) {
                $statuses[] = strtoupper((string) $status);
            }
        }

        return array_values(array_unique($statuses));
    }

    /**
     * Determine if the domain is locked.
     */
    protected function isDomainLocked(array $domain): bool
    {
        if (isset($domain['locked'])) {
            return (bool) $domain['locked'];
        }

        if (isset($domain['transfer_lock'])) {
            return (bool) $domain['transfer_lock'];
        }

        $statuses = $this->parseStatuses($domain);
        return in_array('CLIENTTRANSFERPROHIBITED', $statuses)
            || in_array('CLIENT_TRANSFER_PROHIBITED', $statuses);
    }

    /**
     * Parse nameservers from a domain response.
     */
    protected function parseNameservers(array $domain): array
    {
        $nameservers = $domain['nameservers'] ?? $domain['name_servers'] ?? [];
        $result = [];
        $i = 1;

        foreach ($nameservers as $ns) {
            $host = is_array($ns) ? ($ns['hostname'] ?? $ns['host'] ?? $ns['name'] ?? '') : (string) $ns;
            if ($host) {
                $result['ns' . $i] = ['host' => $host];
                $i++;
            }
        }

        return $result;
    }

    /**
     * Parse a contact from the domain info response.
     */
    protected function parseContactFromDomain(array $domain, string $type): ?ContactData
    {
        $contacts = $domain['contacts'] ?? [];
        $contact = $contacts[$type] ?? null;

        if (!$contact) {
            return null;
        }

        // If we get a reference instead of full data, fetch it
        if (is_string($contact)) {
            try {
                return $this->getContact($contact);
            } catch (Throwable $e) {
                return null;
            }
        }

        return $this->parseContact($contact);
    }

    /**
     * Parse a raw contact array into ContactData.
     */
    protected function parseContact(array $contact): ContactData
    {
        $name = $contact['name']
            ?? trim(($contact['first_name'] ?? $contact['firstname'] ?? '') . ' ' . ($contact['last_name'] ?? $contact['lastname'] ?? ''))
            ?: null;

        return ContactData::create([
            'organisation' => $contact['organisation'] ?? $contact['organization'] ?? $contact['company'] ?? null,
            'name' => $name,
            'address1' => $contact['address1'] ?? $contact['address'] ?? $contact['street'] ?? null,
            'city' => $contact['city'] ?? $contact['suburb'] ?? null,
            'state' => $contact['state'] ?? $contact['province'] ?? null,
            'postcode' => $contact['postcode'] ?? $contact['postal_code'] ?? $contact['zip'] ?? null,
            'country_code' => Utils::normalizeCountryCode(
                $contact['country_code'] ?? $contact['country'] ?? null
            ),
            'email' => $contact['email'] ?? null,
            'phone' => $contact['phone'] ?? null,
        ]);
    }

    // =========================================================================
    // Domain Registration
    // =========================================================================

    /**
     * Register a new domain.
     *
     * @param string $domainName
     * @param int $years
     * @param array $contacts Keyed by contact type constant
     * @param string[] $nameservers
     *
     * @return array API response
     */
    public function registerDomain(
        string $domainName,
        int $years,
        array $contacts,
        array $nameservers
    ): array {
        $params = [
            'domain' => $domainName,
            'period' => $years,
            'nameservers' => $nameservers,
        ];

        // Map contacts
        foreach ($contacts as $type => $contact) {
            if ($contact instanceof ContactParams) {
                $params['contacts'][$type] = $this->formatContactParams($contact);
            }
        }

        return $this->makeRequest('POST', 'domains', [
            'json' => $params,
        ]);
    }

    /**
     * Format ContactParams into an array for the API.
     */
    protected function formatContactParams(ContactParams $contact): array
    {
        $nameParts = $this->getNameParts($contact->name ?? $contact->organisation);

        return [
            'first_name' => $nameParts['firstName'],
            'last_name' => $nameParts['lastName'] ?: $nameParts['firstName'],
            'organisation' => $contact->organisation ?: '',
            'address' => $contact->address1 ?? '',
            'city' => $contact->city ?? '',
            'state' => $contact->state ?: ($contact->city ?? ''),
            'country_code' => Utils::normalizeCountryCode($contact->country_code),
            'postcode' => $contact->postcode ?? '',
            'phone' => Utils::internationalPhoneToEpp($contact->phone),
            'email' => $contact->email ?? '',
        ];
    }

    /**
     * Format ContactData into an array for the API.
     */
    protected function formatContactData(ContactData $contact): array
    {
        $nameParts = $this->getNameParts($contact->name ?? $contact->organisation);

        return [
            'first_name' => $nameParts['firstName'],
            'last_name' => $nameParts['lastName'] ?: $nameParts['firstName'],
            'organisation' => $contact->organisation ?: '',
            'address' => $contact->address1 ?? '',
            'city' => $contact->city ?? '',
            'state' => $contact->state ?: ($contact->city ?? ''),
            'country_code' => Utils::normalizeCountryCode($contact->country_code),
            'postcode' => $contact->postcode ?? '',
            'phone' => Utils::internationalPhoneToEpp($contact->phone),
            'email' => $contact->email ?? '',
        ];
    }

    /**
     * Split a full name into first and last name parts.
     *
     * @param string|null $name
     *
     * @return array{firstName: string, lastName: string}
     */
    protected function getNameParts(?string $name): array
    {
        $nameParts = explode(' ', $name ?: '');
        $firstName = array_shift($nameParts) ?: '';
        $lastName = implode(' ', $nameParts);

        return compact('firstName', 'lastName');
    }

    // =========================================================================
    // Domain Transfer
    // =========================================================================

    /**
     * Initiate a domain transfer.
     *
     * @return array API response
     */
    public function transferDomain(
        string $domainName,
        string $eppCode,
        ContactParams $registrant
    ): array {
        return $this->makeRequest('POST', 'domains/transfer', [
            'json' => [
                'domain' => $domainName,
                'auth_code' => $eppCode,
                'contacts' => [
                    self::CONTACT_TYPE_REGISTRANT => $this->formatContactParams($registrant),
                ],
            ],
        ]);
    }

    // =========================================================================
    // Domain Renewal
    // =========================================================================

    /**
     * Renew a domain.
     */
    public function renewDomain(string $domainName, int $years): array
    {
        return $this->makeRequest('POST', 'domains/' . urlencode($domainName) . '/renew', [
            'json' => [
                'period' => $years,
            ],
        ]);
    }

    // =========================================================================
    // Nameservers
    // =========================================================================

    /**
     * Update domain nameservers. Returns parsed nameserver data.
     *
     * @param string[] $nameservers
     */
    public function updateDomainNameservers(string $domainName, array $nameservers): array
    {
        $this->makeRequest('PATCH', 'domains/' . urlencode($domainName), [
            'json' => [
                'nameservers' => $nameservers,
            ],
        ]);

        // Fetch updated domain info and return nameserver data
        $response = $this->makeRequest('GET', 'domains/' . urlencode($domainName));
        $domain = $response['data'] ?? $response;

        return $this->parseNameservers($domain);
    }

    // =========================================================================
    // Domain Lock
    // =========================================================================

    /**
     * Lock or unlock a domain.
     */
    public function setDomainLock(string $domainName, bool $lock): array
    {
        return $this->makeRequest('PATCH', 'domains/' . urlencode($domainName), [
            'json' => [
                'transfer_lock' => $lock,
            ],
        ]);
    }

    /**
     * Get the current lock status of a domain.
     */
    public function getDomainLockStatus(string $domainName): bool
    {
        $response = $this->makeRequest('GET', 'domains/' . urlencode($domainName));
        $domain = $response['data'] ?? $response;

        return $this->isDomainLocked($domain);
    }

    // =========================================================================
    // Auto Renew
    // =========================================================================

    /**
     * Set auto-renewal for a domain.
     */
    public function setDomainAutoRenew(string $domainName, bool $autoRenew): array
    {
        return $this->makeRequest('PATCH', 'domains/' . urlencode($domainName), [
            'json' => [
                'auto_renew' => $autoRenew,
            ],
        ]);
    }

    // =========================================================================
    // EPP Code
    // =========================================================================

    /**
     * Get the EPP/auth code for a domain.
     */
    public function getDomainEppCode(string $domainName): string
    {
        $response = $this->makeRequest('GET', 'domains/' . urlencode($domainName) . '/auth-code');
        $data = $response['data'] ?? $response;

        return $data['auth_code']
            ?? $data['epp_code']
            ?? $data['authorization_code']
            ?? '';
    }

    // =========================================================================
    // Contacts
    // =========================================================================

    /**
     * Get a contact by reference.
     */
    public function getContact(string $contactRef): ContactData
    {
        $response = $this->makeRequest('GET', 'contacts/' . urlencode($contactRef));
        $contact = $response['data'] ?? $response;

        return $this->parseContact($contact);
    }

    /**
     * Update a contact by reference.
     */
    public function updateContactByRef(string $contactRef, ContactParams $contact): ContactData
    {
        $response = $this->makeRequest('PATCH', 'contacts/' . urlencode($contactRef), [
            'json' => $this->formatContactParams($contact),
        ]);

        $updated = $response['data'] ?? $response;
        return $this->parseContact($updated);
    }

    /**
     * Update the registrant contact for a domain.
     */
    public function updateDomainRegistrant(string $domainName, ContactParams $registrant): ContactData
    {
        $response = $this->makeRequest('PATCH', 'domains/' . urlencode($domainName), [
            'json' => [
                'contacts' => [
                    self::CONTACT_TYPE_REGISTRANT => $this->formatContactParams($registrant),
                ],
            ],
        ]);

        // Refetch to get updated contact
        $info = $this->getDomainInfo($domainName);
        return $info[ContactType::REGISTRANT];
    }

    /**
     * Update a specific contact type for a domain.
     */
    public function updateDomainContact(
        string $domainName,
        ContactParams $contact,
        string $contactType
    ): ContactData {
        $response = $this->makeRequest('PATCH', 'domains/' . urlencode($domainName), [
            'json' => [
                'contacts' => [
                    $contactType => $this->formatContactParams($contact),
                ],
            ],
        ]);

        // Refetch to get updated contact
        $info = $this->getDomainInfo($domainName);
        return $info[$this->mapContactTypeToCategory($contactType)] ?? $this->parseContact([]);
    }

    /**
     * Map provider contact type string to the ContactType enum value.
     */
    protected function mapContactTypeToCategory(string $type): string
    {
        $map = [
            self::CONTACT_TYPE_REGISTRANT => ContactType::REGISTRANT,
            self::CONTACT_TYPE_ADMIN => ContactType::ADMIN,
            self::CONTACT_TYPE_TECH => ContactType::TECH,
            self::CONTACT_TYPE_BILLING => ContactType::BILLING,
        ];

        return $map[$type] ?? $type;
    }

    /**
     * Map a ContactType enum to our provider contact type string.
     */
    public function getProviderContactTypeValue(ContactType $contactType): string
    {
        if ($contactType->equals(ContactType::REGISTRANT())) {
            return self::CONTACT_TYPE_REGISTRANT;
        }
        if ($contactType->equals(ContactType::ADMIN())) {
            return self::CONTACT_TYPE_ADMIN;
        }
        if ($contactType->equals(ContactType::BILLING())) {
            return self::CONTACT_TYPE_BILLING;
        }
        if ($contactType->equals(ContactType::TECH())) {
            return self::CONTACT_TYPE_TECH;
        }

        throw ProvisionFunctionError::create('Invalid contact type: ' . $contactType->getValue());
    }

    // =========================================================================
    // Glue Records
    // =========================================================================

    /**
     * List glue records for a domain.
     *
     * @return GlueRecord[]
     */
    public function listGlueRecords(string $domainName): array
    {
        $glueRecords = [];

        try {
            $response = $this->makeRequest('GET', 'domains/' . urlencode($domainName) . '/glue-records');
            $records = $response['data'] ?? $response['glue_records'] ?? [];

            foreach ($records as $record) {
                $glueRecords[] = GlueRecord::create([
                    'hostname' => $record['hostname'] ?? $record['host'] ?? '',
                    'ips' => $record['ips'] ?? $record['ip_addresses'] ?? [],
                ]);
            }
        } catch (Throwable $e) {
            // Domain may not support glue records - ignore
        }

        return $glueRecords;
    }

    /**
     * Set (create/update) a glue record for a domain.
     */
    public function setGlueRecord(string $domainName, string $hostname, array $ips): array
    {
        return $this->makeRequest('PUT', 'domains/' . urlencode($domainName) . '/glue-records', [
            'json' => [
                'hostname' => $hostname,
                'ips' => $ips,
            ],
        ]);
    }

    /**
     * Remove a glue record from a domain.
     */
    public function removeGlueRecord(string $domainName, string $hostname): array
    {
        return $this->makeRequest('DELETE', 'domains/' . urlencode($domainName) . '/glue-records', [
            'json' => [
                'hostname' => $hostname,
            ],
        ]);
    }

    // =========================================================================
    // Verification
    // =========================================================================

    /**
     * Get domain verification status.
     */
    public function getDomainVerificationInfo(string $domainName): array
    {
        $info = $this->getDomainInfo($domainName);
        $response = $this->makeRequest('GET', 'domains/' . urlencode($domainName));
        $domain = $response['data'] ?? $response;

        $icannStatus = $domain['icann_verification_status']
            ?? $domain['verification_status']
            ?? null;

        return [
            'icann_verification_status' => $icannStatus,
            'cctld_verification_status' => $domain['cctld_verification_status'] ?? null,
            'verification_deadline' => $domain['verification_deadline'] ?? null,
        ];
    }

    /**
     * Resend verification email for a domain.
     */
    public function resendVerificationEmail(string $domainName): array
    {
        return $this->makeRequest('POST', 'domains/' . urlencode($domainName) . '/resend-verification');
    }
}
