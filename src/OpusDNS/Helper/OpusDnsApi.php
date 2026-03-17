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

        $errorMessage = $this->parseErrorMessage($responseBody);

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
     * Parse error message from API response, mapping known error types to user-friendly messages.
     */
    protected function parseErrorMessage(?array $responseBody): string
    {
        if (!is_array($responseBody)) {
            return 'Provider API Error';
        }

        $errorType = $responseBody['type'] ?? null;
        $errorCode = $responseBody['code'] ?? null;

        // Map known error types/codes to user-friendly messages
        switch ($errorType ?? $errorCode) {
            case 'domain-not-found':
            case 'ERROR_DOMAIN_NOT_FOUND':
                return 'Domain name does not exist in registrar account';

            case 'request-validation-failed':
                return $this->formatValidationError($responseBody);

            default:
                // Fall back to extracting message from response
                return $responseBody['detail']
                    ?? $responseBody['message']
                    ?? $responseBody['title']
                    ?? $responseBody['error']['message']
                    ?? $responseBody['error']
                    ?? 'Provider API Error';
        }
    }

    /**
     * Format validation error with field details.
     */
    protected function formatValidationError(array $responseBody): string
    {
        $errors = $responseBody['errors'] ?? [];
        if (empty($errors)) {
            return $responseBody['detail'] ?? 'Request validation failed';
        }

        $messages = [];
        foreach ($errors as $error) {
            $field = implode('.', $error['loc'] ?? []);
            $msg = $error['msg'] ?? 'Invalid';
            $messages[] = sprintf('%s: %s', $field, $msg);
        }

        return 'Validation failed: ' . implode('; ', $messages);
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

    /**
     * Build query parameters with proper array serialization.
     *
     * Guzzle serializes arrays as PHP-style indices (param[0]=a) which most REST APIs
     * don't understand. This method converts array values to comma-separated strings
     * which is the format expected by the OpusDNS API.
     *
     * @param array $params Query parameters (may include arrays)
     * @return array Query parameters with arrays converted to comma-separated strings
     */
    protected function buildQueryParams(array $params): array
    {
        $result = [];
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $result[$key] = implode(',', $value);
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
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
            'query' => $this->buildQueryParams(['domains' => $domains]),
        ]);

        $dacDomains = [];

        // API returns { results: [...] } per DomainCheckResponse schema
        $results = $response['results'] ?? $response['data'] ?? $response['domains'] ?? [];

        foreach ($results as $result) {
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

        // Parse contacts from array format (API returns [{contact_id, contact_type}, ...])
        $contacts = $this->parseContactsFromDomain($domain);

        return [
            'id' => $domain['domain_id'] ?? $domain['id'] ?? $domain['reference'] ?? null,
            'domain' => $domain['name'] ?? $domain['domain'] ?? $domainName,
            'statuses' => $this->parseStatuses($domain),
            'locked' => $this->isDomainLocked($domain),
            'registrant' => $contacts['registrant'] ?? null,
            'billing' => $contacts['billing'] ?? null,
            'tech' => $contacts['tech'] ?? null,
            'admin' => $contacts['admin'] ?? null,
            'ns' => NameserversResult::create($this->parseNameservers($domain)),
            // API uses expires_on, created_on, updated_on (not _at suffix)
            'created_at' => Utils::formatDate($domain['created_on'] ?? $domain['created_at'] ?? null),
            'updated_at' => Utils::formatDate($domain['updated_on'] ?? $domain['updated_at'] ?? null),
            'expires_at' => Utils::formatDate($domain['expires_on'] ?? $domain['expires_at'] ?? null),
            'glue_records' => $glueRecords,
        ];
    }

    /**
     * Extract statuses from the domain API response.
     *
     * API uses registry_statuses field per DomainResponse schema.
     *
     * @return string[]
     */
    protected function parseStatuses(array $domain): array
    {
        $statuses = [];

        if (isset($domain['status'])) {
            $statuses[] = strtoupper((string) $domain['status']);
        }

        // API spec uses registry_statuses
        if (isset($domain['registry_statuses']) && is_array($domain['registry_statuses'])) {
            foreach ($domain['registry_statuses'] as $status) {
                $statuses[] = strtoupper((string) $status);
            }
        }

        // Fallback for legacy format
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
     * Parse all contacts from domain response.
     *
     * API returns contacts as array: [{contact_id: "...", contact_type: "registrant"}, ...]
     *
     * @return array<string, ContactData|null> Keyed by contact type
     */
    protected function parseContactsFromDomain(array $domain): array
    {
        $contacts = $domain['contacts'] ?? [];
        $result = [
            ContactType::REGISTRANT => null,
            ContactType::ADMIN => null,
            ContactType::TECH => null,
            ContactType::BILLING => null,
        ];

        // Handle array format (API spec): [{contact_id, contact_type}, ...]
        if (isset($contacts[0]) && is_array($contacts[0])) {
            foreach ($contacts as $contactEntry) {
                $contactType = $contactEntry['contact_type'] ?? null;
                $contactId = $contactEntry['contact_id'] ?? null;

                // Use array_key_exists since isset() returns false for null values
                if ($contactType && $contactId && array_key_exists($contactType, $result)) {
                    try {
                        $result[$contactType] = $this->getContact($contactId);
                    } catch (Throwable $e) {
                        // Contact fetch failed, leave as null
                    }
                }
            }
        } else {
            // Handle legacy keyed object format: {registrant: {...}, admin: {...}}
            foreach (array_keys($result) as $type) {
                $contact = $contacts[$type] ?? null;
                if ($contact) {
                    if (is_string($contact)) {
                        try {
                            $result[$type] = $this->getContact($contact);
                        } catch (Throwable $e) {
                            // Contact fetch failed
                        }
                    } else {
                        $result[$type] = $this->parseContact($contact);
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Get contact IDs from domain response.
     *
     * API returns contacts as array: [{contact_id: "...", contact_type: "registrant"}, ...]
     *
     * @return array<string, string|null> Keyed by contact type (registrant, admin, tech, billing)
     */
    protected function getContactIdsFromDomain(array $domain): array
    {
        $contacts = $domain['contacts'] ?? [];
        $result = [
            ContactType::REGISTRANT => null,
            ContactType::ADMIN => null,
            ContactType::TECH => null,
            ContactType::BILLING => null,
        ];

        // Handle array format: [{contact_id, contact_type}, ...]
        if (isset($contacts[0]) && is_array($contacts[0])) {
            foreach ($contacts as $contactEntry) {
                $contactType = $contactEntry['contact_type'] ?? null;
                $contactId = $contactEntry['contact_id'] ?? null;

                if ($contactType && $contactId && array_key_exists($contactType, $result)) {
                    $result[$contactType] = $contactId;
                }
            }
        } else {
            // Handle keyed object format: {registrant: "contact_xxx", admin: "contact_xxx"}
            foreach (array_keys($result) as $type) {
                $contact = $contacts[$type] ?? null;
                if (is_string($contact)) {
                    $result[$type] = $contact;
                }
            }
        }

        return $result;
    }

    /**
     * Parse a contact from the domain info response.
     * @deprecated Use parseContactsFromDomain instead
     */
    protected function parseContactFromDomain(array $domain, string $type): ?ContactData
    {
        $contacts = $this->parseContactsFromDomain($domain);
        return $contacts[$type] ?? null;
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
            'name' => $domainName,
            'period' => [
                'unit' => 'y',
                'value' => $years,
            ],
            'nameservers' => array_map(fn($ns) => ['hostname' => $ns], $nameservers),
            'renewal_mode' => 'renew',
        ];

        // Map contacts - API expects ContactIdList format: {"registrant": "contact_xxx", ...}
        // Create contacts first, then reference by ID
        foreach ($contacts as $type => $contact) {
            if ($contact instanceof ContactParams) {
                $contactId = $this->createContact($contact);
                $params['contacts'][$type] = $contactId;
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

        $data = [
            'first_name' => $nameParts['firstName'],
            'last_name' => $nameParts['lastName'] ?: $nameParts['firstName'],
            'email' => $contact->email ?? '',
            'phone' => Utils::internationalPhoneToEpp($contact->phone),
            'street' => $contact->address1 ?? '',
            'city' => $contact->city ?? '',
            'postal_code' => $contact->postcode ?? '',
            'country' => Utils::normalizeCountryCode($contact->country_code),
            'disclose' => false,
        ];

        // Optional fields
        if ($contact->organisation) {
            $data['org'] = $contact->organisation;
        }
        if ($contact->state) {
            $data['state'] = $contact->state;
        }

        return $data;
    }

    /**
     * Format ContactData into an array for the API.
     */
    protected function formatContactData(ContactData $contact): array
    {
        $nameParts = $this->getNameParts($contact->name ?? $contact->organisation);

        $data = [
            'first_name' => $nameParts['firstName'],
            'last_name' => $nameParts['lastName'] ?: $nameParts['firstName'],
            'email' => $contact->email ?? '',
            'phone' => Utils::internationalPhoneToEpp($contact->phone),
            'street' => $contact->address1 ?? '',
            'city' => $contact->city ?? '',
            'postal_code' => $contact->postcode ?? '',
            'country' => Utils::normalizeCountryCode($contact->country_code),
            'disclose' => false,
        ];

        if ($contact->organisation) {
            $data['org'] = $contact->organisation;
        }
        if ($contact->state) {
            $data['state'] = $contact->state;
        }

        return $data;
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

    /**
     * Create a contact and return its ID.
     *
     * @param ContactParams $contact
     * @return string Contact ID
     */
    public function createContact(ContactParams $contact): string
    {
        $response = $this->makeRequest('POST', 'contacts', [
            'json' => $this->formatContactParams($contact),
        ]);

        $data = $response['data'] ?? $response;

        return $data['id'] ?? $data['contact_id'] ?? $data['reference'] ?? '';
    }

    // =========================================================================
    // Domain Transfer
    // =========================================================================

    /**
     * Initiate a domain transfer.
     *
     * @param array<string, ContactParams> $contacts Keyed by contact type
     * @return array API response
     */
    public function transferDomain(
        string $domainName,
        string $eppCode,
        array $contacts
    ): array {
        $params = [
            'name' => $domainName,
            'auth_code' => $eppCode,
            'renewal_mode' => 'renew',
        ];

        // Map contacts - API expects ContactIdList format: {"registrant": "contact_xxx", ...}
        foreach ($contacts as $type => $contact) {
            if ($contact instanceof ContactParams) {
                $contactId = $this->createContact($contact);
                $params['contacts'][$type] = $contactId;
            }
        }

        return $this->makeRequest('POST', 'domains/transfer', [
            'json' => $params,
        ]);
    }

    // =========================================================================
    // Domain Renewal
    // =========================================================================

    /**
     * Renew a domain.
     *
     * @param string $domainName
     * @param int $years
     * @param string|null $currentExpiryDate ISO8601 format (required by API)
     */
    public function renewDomain(string $domainName, int $years, ?string $currentExpiryDate = null): array
    {
        $params = [
            'period' => [
                'unit' => 'y',
                'value' => $years,
            ],
        ];

        if ($currentExpiryDate !== null) {
            $params['current_expiry_date'] = $currentExpiryDate;
        }

        return $this->makeRequest('POST', 'domains/' . urlencode($domainName) . '/renew', [
            'json' => $params,
        ]);
    }

    // =========================================================================
    // Nameservers
    // =========================================================================

    /**
     * Update domain nameservers. Returns parsed nameserver data.
     *
     * @param string[] $nameservers Array of hostname strings
     */
    public function updateDomainNameservers(string $domainName, array $nameservers): array
    {
        // API expects array of Nameserver objects: [{hostname: "ns1.example.com"}, ...]
        $nameserverObjects = array_map(fn($ns) => ['hostname' => $ns], $nameservers);

        $this->makeRequest('PATCH', 'domains/' . urlencode($domainName), [
            'json' => [
                'nameservers' => $nameserverObjects,
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
     *
     * API uses renewal_mode enum: 'renew' (auto-renew) or 'expire' (no auto-renew)
     */
    public function setDomainAutoRenew(string $domainName, bool $autoRenew): array
    {
        return $this->makeRequest('PATCH', 'domains/' . urlencode($domainName), [
            'json' => [
                'renewal_mode' => $autoRenew ? 'renew' : 'expire',
            ],
        ]);
    }

    // =========================================================================
    // EPP Code
    // =========================================================================

    /**
     * Get the EPP/auth code for a domain.
     *
     * The auth_code is returned as part of the DomainResponse from the
     * GET /domains/{domain_name} endpoint - there is no separate auth-code endpoint.
     */
    public function getDomainEppCode(string $domainName): string
    {
        $response = $this->makeRequest('GET', 'domains/' . urlencode($domainName));
        $domain = $response['data'] ?? $response;

        return $domain['auth_code'] ?? '';
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
     *
     * API requires contact IDs (ContactIdList format), not inline contact data.
     * API also requires ALL contacts when updating, so we preserve existing ones.
     */
    public function updateDomainRegistrant(string $domainName, ContactParams $registrant): ContactData
    {
        // Fetch existing domain to get current contact IDs
        $response = $this->makeRequest('GET', 'domains/' . urlencode($domainName));
        $domain = $response['data'] ?? $response;
        $existingContacts = $this->getContactIdsFromDomain($domain);

        // Create a new contact and get the ID
        $newContactId = $this->createContact($registrant);

        // Merge new contact with existing contacts (preserve all, update registrant)
        $contacts = array_filter(array_merge($existingContacts, [
            ContactType::REGISTRANT => $newContactId,
        ]));

        // Associate all contact IDs with the domain
        $this->makeRequest('PATCH', 'domains/' . urlencode($domainName), [
            'json' => [
                'contacts' => $contacts,
            ],
        ]);

        // Refetch to get updated contact
        $info = $this->getDomainInfo($domainName);
        return $info[ContactType::REGISTRANT];
    }

    /**
     * Update a specific contact type for a domain.
     *
     * API requires contact IDs (ContactIdList format), not inline contact data.
     * API also requires ALL contacts when updating, so we preserve existing ones.
     */
    public function updateDomainContact(
        string $domainName,
        ContactParams $contact,
        string $contactType
    ): ContactData {
        // Fetch existing domain to get current contact IDs
        $response = $this->makeRequest('GET', 'domains/' . urlencode($domainName));
        $domain = $response['data'] ?? $response;
        $existingContacts = $this->getContactIdsFromDomain($domain);

        // Create a new contact and get the ID
        $newContactId = $this->createContact($contact);

        // Merge new contact with existing contacts (preserve all, update target type)
        $contacts = array_filter(array_merge($existingContacts, [
            $contactType => $newContactId,
        ]));

        // Associate all contact IDs with the domain
        $this->makeRequest('PATCH', 'domains/' . urlencode($domainName), [
            'json' => [
                'contacts' => $contacts,
            ],
        ]);

        // Refetch to get updated contact
        $info = $this->getDomainInfo($domainName);
        return $info[$this->mapContactTypeToCategory($contactType)] ?? $this->parseContact([]);
    }

    /**
     * Map provider contact type string to the ContactType enum value.
     *
     * OpusDNS uses the same contact type strings as ContactType enum values.
     */
    protected function mapContactTypeToCategory(string $type): string
    {
        return $type;
    }

    /**
     * Map a ContactType enum to our provider contact type string.
     *
     * OpusDNS uses the same contact type strings as ContactType enum values.
     */
    public function getProviderContactTypeValue(ContactType $contactType): string
    {
        return $contactType->getValue();
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
     *
     * OpusDNS uses contact-level verification rather than domain-level ICANN verification.
     * This method checks the registrant contact's verification status.
     */
    public function getDomainVerificationInfo(string $domainName): array
    {
        $response = $this->makeRequest('GET', 'domains/' . urlencode($domainName));
        $domain = $response['data'] ?? $response;

        // Get the registrant contact ID to check its verification status
        $contactIds = $this->getContactIdsFromDomain($domain);
        $registrantContactId = $contactIds[ContactType::REGISTRANT] ?? null;

        $icannStatus = 'unknown';
        $verificationDeadline = null;

        if ($registrantContactId) {
            try {
                $verificationResponse = $this->makeRequest(
                    'GET',
                    'contacts/' . urlencode($registrantContactId) . '/verification'
                );
                $verification = $verificationResponse['data'] ?? $verificationResponse;

                // Map OpusDNS status to ICANN verification status
                $emailStatus = $verification['email_verification_status'] ?? null;
                if ($emailStatus === 'verified') {
                    $icannStatus = 'verified';
                } elseif ($emailStatus === 'pending') {
                    $icannStatus = 'pending';
                } elseif ($emailStatus === 'unverified') {
                    $icannStatus = 'unverified';
                }

                $verificationDeadline = $verification['expires_on'] ?? $verification['deadline'] ?? null;
            } catch (Throwable $e) {
                // Contact verification not found or not required - this is normal
                // Many contacts may not have active verification requests
                $icannStatus = 'verified';
            }
        }

        return [
            'icann_verification_status' => $icannStatus,
            'cctld_verification_status' => null,
            'verification_deadline' => $verificationDeadline,
        ];
    }

    /**
     * Resend verification email for a domain's registrant contact.
     *
     * OpusDNS uses contact-level verification rather than domain-level.
     * This method gets the registrant contact ID and starts a new email verification.
     */
    public function resendVerificationEmail(string $domainName): array
    {
        // Get raw domain response (not the transformed getDomainInfo result)
        $response = $this->makeRequest('GET', 'domains/' . urlencode($domainName));
        $domain = $response['data'] ?? $response;

        // Extract registrant contact ID from the raw contacts array
        $contactIds = $this->getContactIdsFromDomain($domain);
        $registrantContactId = $contactIds[ContactType::REGISTRANT] ?? null;

        if (!$registrantContactId) {
            throw new ProvisionFunctionError('Unable to find registrant contact for domain');
        }

        // Start a new email verification for the registrant contact
        return $this->makeRequest(
            'POST',
            'contacts/' . urlencode($registrantContactId) . '/verification',
            ['query' => ['type' => 'email']]
        );
    }

    // =========================================================================
    // Events (Polling)
    // =========================================================================

    /**
     * Fetch unacknowledged events from the API.
     *
     * @param int $limit Maximum number of events to return
     * @param Carbon|null $since Only return events created after this date
     *
     * @return array{count_remaining: int, events: array}
     */
    public function getEvents(int $limit = 100, ?Carbon $since = null): array
    {
        $queryParams = [
            'page_size' => min($limit, 1000),
            'acknowledged' => 'false',
            'sort_by' => 'created_on',
            'sort_order' => 'asc',
        ];

        $response = $this->makeRequest('GET', 'events', [
            'query' => $queryParams,
        ]);

        $events = $response['data'] ?? $response['items'] ?? [];
        $totalCount = $response['total'] ?? $response['total_count'] ?? count($events);
        $pageSize = $response['page_size'] ?? $limit;

        // Filter by date if provided
        if ($since !== null) {
            $events = array_filter($events, function ($event) use ($since) {
                $createdOn = $event['created_on'] ?? null;
                if (!$createdOn) {
                    return true;
                }
                return Carbon::parse($createdOn)->gte($since);
            });
        }

        return [
            'count_remaining' => max(0, $totalCount - count($events)),
            'events' => array_values($events),
        ];
    }

    /**
     * Acknowledge an event by ID.
     */
    public function acknowledgeEvent(string $eventId): void
    {
        $this->makeRequest('POST', 'events/' . urlencode($eventId) . '/acknowledge');
    }
}
