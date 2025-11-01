<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Netistrar\Helper;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Str;
use libphonenumber\PhoneNumberUtil;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Throwable;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionProviders\DomainNames\Data\ContactParams;
use Upmind\ProvisionProviders\DomainNames\Netistrar\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\Data\RenewParams;
use Upmind\ProvisionProviders\DomainNames\Data\RegisterDomainParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateNameserversParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateDomainContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\TransferParams;
use Upmind\ProvisionProviders\DomainNames\Data\EppCodeResult;
use Upmind\ProvisionProviders\DomainNames\Data\DomainResult;

class NetistrarApi
{
    protected Client $client;
    protected Configuration $configuration;

    public const CONTACT_LTD="LTD"; // UK Limited Company
    public const CONTACT_PLC = "PLC"; // UK Public Limited Company
    public const CONTACT_PTNR = "PTNR"; // UK Partnership
    public const CONTACT_STRA = "STRA"; // UK Sole Trader
    public const CONTACT_LLP = "LLP"; // UK Limited Liability Partnership
    public const CONTACT_IP = "IP"; // UK Industrial/Provident Company
    public const CONTACT_IND = "IND"; // UK Individual (natural person)
    public const CONTACT_SCH = "SCH"; // UK School
    public const CONTACT_RCHAR = "RCHAR"; // UK Registered Charity
    public const CONTACT_GOV = "GOV"; // UK Government Body
    public const CONTACT_CRC = "CRC"; // UK Corporation by Royal Charter
    public const CONTACT_STAT = "STAT"; // UK Statutory Body
    public const CONTACT_OTHER = "OTHER"; // UK Entity that does not fit into any of the above (clubs, associations, etc)

    public function __construct(Client $client, Configuration $configuration)
    {
        $this->client = $client;
        $this->configuration = $configuration;
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function liveAvailability(string $domainName): PromiseInterface
    {
        return $this->apiCallAsync(
            "domains/available/{$domainName}",
            [],
            [],
            'GET',
            ['timeout' => 10]
        );
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function getDomainInfo(string $domainName, array $additionalFields = []): DomainResult
    {
        $endpoint = "domains/{$domainName}";
        $apiResult = $this->apiCall($endpoint, []);

        if (isset($apiResult->exceptionClass)) {
            $this->errorResult('Unable to get domain info', ['response' => $apiResult->message]);
        }

        $nameservers = [];
        for ($i=0; $i<6; $i++) {
            if (isset($apiResult->nameservers[$i])) {
                $nameservers["ns" . ($i+1)] = $apiResult->nameservers[$i];
            } else {
                break;
            }
        }

        $domainResultData = [
            'id' => strtolower($apiResult->domainName),
            'domain' => $apiResult->domainName,
            'statuses' => [$apiResult->status],
            'whois_privacy' => $apiResult->privacyProxy,
            'auto_renew' => $apiResult->autoRenew,
            'registrant' => $this->transformNetisrarContactToContact($apiResult->ownerContact),
            'billing' => $this->transformNetisrarContactToContact($apiResult->billingContact),
            'tech' => $this->transformNetisrarContactToContact($apiResult->technicalContact),
            'admin' => $this->transformNetisrarContactToContact($apiResult->adminContact),
            'ns' => $nameservers,
            'created_at' => is_null($apiResult->registeredDate) ? null : Carbon::createFromFormat('d/m/Y H:i:s', $apiResult->registeredDate)->format('Y-m-d H:i:s'),
            'locked' => $apiResult->locked,
            'expires_at' => is_null($apiResult->expiryDate) ? null : Carbon::createFromFormat('d/m/Y H:i:s', $apiResult->expiryDate)->format('Y-m-d H:i:s'),
            'updated_at' => null,
        ];

        if (in_array('tags', $additionalFields, true)) {
            $domainResultData['tags'] = $apiResult->tags; // Include tags if requested
        }

        return DomainResult::create($domainResultData);
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function getEppCode(string $domainName): EppCodeResult
    {
        $endpoint = "domains/{$domainName}";
        $apiResult = $this->apiCall($endpoint);

        if (isset($apiResult->exceptionClass)) {
            $this->errorResult('Unable to get domain info', ['response' => $apiResult->message]);
        }

        return EppCodeResult::create([
            'epp_code' => $apiResult->authCode,
        ]);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    private function transformContactToNetistrarContact(ContactParams $contact, bool $is_uk_domain): array
    {
        $phone = PhoneNumberUtil::getInstance()->parse($contact->phone, null);
        $contactData = [
            'name' => $contact->name,
            'emailAddress' => $contact->email,
            'organisation' => $contact->organisation,
            'telephoneDiallingCode' => "+". $phone->getCountryCode(),
            'telephone' => $phone->getNationalNumber(),
            'street1' => $contact->address1,
            'city' => $contact->city,
            'county' => $contact->state,
            'postcode' => $contact->postcode,
            'country' => $contact->country_code,
        ];

        if ($is_uk_domain && isset($contact->type)
            && in_array($contact->type, $this->getNominentRegistrantTypes(), true)) {
            $contactData['additionalData']['nominetRegistrantType'] = $contact->type;
        }

        return $contactData;
    }

    private function transformNetisrarContactToContact(\stdClass $contact): ContactParams
    {
        return ContactParams::create([
            'name' => $contact->name,
            'email' => $contact->emailAddress,
            'organisation' => $contact->organisation,
            'phone' => $contact->telephoneDiallingCode . $contact->telephone,
            'address1' => $contact->street1,
            'city' => $contact->city,
            'state' => $contact->county,
            'postcode' => $contact->postcode,
            'country_code' => $contact->country,
        ]);
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function renewDomain(RenewParams $params)
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);
        $endpoint = "domains/renew/{$domainName}/{$params->renew_years}/";

        $query = [];
        return $this->apiCall($endpoint, $query, [], 'GET');//yes, new is a GET request
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function createDomain(RegisterDomainParams $params)
    {

        $endpoint = "domains";
        $domainName = Utils::getDomain($params->sld, $params->tld);
        $isUkDomain = NetistrarUtils::isUkTld($params->tld);

        $nameservers = [];
        $keys = ['ns1', 'ns2', 'ns3', 'ns4', 'ns5'];  // List of nameserver keys
        foreach ($keys as $key) {
            if (isset($params->nameservers->$key->host)) {
                $nameservers[] = $params->nameservers->$key->host;
            }
        }

        $data = [
            'domainNames' => [ $domainName ],
            'registrationYears' => (int) $params->renew_years,
            'ownerContact' => $this->transformContactToNetistrarContact($params->registrant->register, $isUkDomain),
            'nameservers' => $nameservers,
            'privacyProxy' => $params->whois_privacy ? 0 : 1,
        ];

        if (isset($params->admin->register)) {
            $data['adminContact'] = $this->transformContactToNetistrarContact($params->admin->register, $isUkDomain);
        }
        if (isset($params->tech->register)) {
            $data['technicalContact'] = $this->transformContactToNetistrarContact($params->tech->register, $isUkDomain);
        }
        if (isset($params->billing->register)) {
            $data['billingContact'] = $this->transformContactToNetistrarContact($params->billing->register, $isUkDomain);
        }

        $results = $this->apiCall($endpoint, [], $data, 'POST');

        if ($results->transactionStatus === "ALL_ELEMENTS_FAILED") {
            $this->errorResult('Unable to register domain', ['response' => $results->transactionElements->{$params->sld.".".$params->tld}->elementErrors]);
        }

        return $results;
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    private function validateIncomingTransferDomains(array $data)
    {
        $endpoint = "/domains/transfer/validate/";
        $validationResults = $this->apiCall($endpoint, [], $data, 'POST');

        if (isset($validationResults->transactionErrors)) {
            $reasonMessages = [];
            foreach( $validationResults->transactionErrors as $err) {
                $reasonMessages[] = $err->message;
            }
            $this->errorResult('Unable to validate transfer domains', ['response' => implode(' ',$reasonMessages)]);
        }

        return true;
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function transferDomain(TransferParams $params)
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        $transferIdentifier = $domainName;
        $isUkDomain = NetistrarUtils::isUkTld($params->tld);

        if (!$isUkDomain) {
            $transferIdentifier .= "," .$params->epp_code;
        }

        $data = [
            'transferIdentifiers' => [ $transferIdentifier ],
            'ownerContact' => $this->transformContactToNetistrarContact($params->registrant->register, $isUkDomain),
            'privacyProxy' => $params->whois_privacy ? 0 : 1,
        ];

        if (isset($params->admin->register)) {
            $data['adminContact'] = $this->transformContactToNetistrarContact($params->admin->register, $isUkDomain);
        }
        if (isset($params->tech->register)) {
            $data['technicalContact'] = $this->transformContactToNetistrarContact($params->tech->register, $isUkDomain);
        }
        if (isset($params->billing->register)) {
            $data['billingContact'] = $this->transformContactToNetistrarContact($params->billing->register, $isUkDomain);
        }

        $validateResults = $this->validateIncomingTransferDomains($data);

        $endpoint = "domains/transfer/";
        $results = $this->apiCall($endpoint, [], $data, 'POST');

        if ($results->transactionStatus === "ALL_ELEMENTS_FAILED") {
            $this->errorResult('Unable to transfer domain', ['response' => $results->transactionElements->{$domainName}->elementErrors]);
        }

        return $results;
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function updateIpsTag(string $domainName, string $addTags, array $removeTags)
    {
        $data = [
            'domainNames' => [ $domainName ],
            'addTags' => $addTags,
            'removeTags' => $removeTags,
        ];
        return $this->updateDomain($data);
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function updateRegistrantContact(string $domainName, UpdateDomainContactParams $params)
    {
        $data = [
            'domainNames' => [$domainName],
            'ownerContact' => $this->transformContactToNetistrarContact(
                $params->contact,
                NetistrarUtils::isUkTld($params->tld)
            )
        ];

        return $this->updateDomain($data);
    }

    public function updateDomainLock(string $domainName, bool $lock)
    {
        $data = [
            'domainNames' => [ $domainName ],
            'locked' => $lock,
        ];
        return $this->updateDomain($data);
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function updateAutoRenew(string $domainName, bool $autoRenew)
    {
        $data = [
            'domainNames' => [ $domainName ],
            'autoRenew' => $autoRenew,
        ];
        return $this->updateDomain($data);
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function updateDomainNameservers(string $domainName, UpdateNameserversParams $params)
    {
        $nameservers = [];
        $keys = ['ns1', 'ns2', 'ns3', 'ns4', 'ns5'];  // List of nameserver keys

        foreach ($keys as $key) {
            if (isset($params->$key->host)) {
                $nameservers[] = $params->$key->host;
            }
        }

        $data = [
            'domainNames' => [ $domainName ],
            'nameservers' => $nameservers,
        ];
        $results = $this->updateDomain($data);

        if ($results->transactionStatus === "ALL_ELEMENTS_FAILED") {
            $this->errorResult('Unable to register domain', ['response' => $results->transactionElements->{$params->sld.".".$params->tld}->elementErrors]);
        }

        return $results;
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function updateDomain(array $data)
    {
        $endpoint = "domains/";
        $results = $this->apiCall($endpoint, [], $data, 'PATCH');

        if ($results->transactionStatus === "ALL_ELEMENTS_FAILED") {
            $this->errorResult('Unable to register domain', ['response' => $results->transactionElements->{$params->sld.".".$params->tld}->elementErrors]);
        }

        return $results;
    }

    /**
     * Returns an array of Nominet registrant types that are valid for UK domains
     */
    private function getNominentRegistrantTypes() : array {
        // Nominet registrant types
        return [
            self::CONTACT_LTD,
            self::CONTACT_PLC,
            self::CONTACT_PTNR,
            self::CONTACT_STRA,
            self::CONTACT_LLP,
            self::CONTACT_IP,
            self::CONTACT_IND,
            self::CONTACT_SCH,
            self::CONTACT_RCHAR,
            self::CONTACT_GOV,
            self::CONTACT_CRC,
            self::CONTACT_STAT,
            self::CONTACT_OTHER
        ];
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    private function apiCall(
        string $endpoint,
        array $query = [],
        array $data = [],
        string $method = 'GET',
        array $requestOptions = []
    ) {
        return $this->apiCallAsync($endpoint, $query, $data, $method, $requestOptions)->wait();
    }

    /***
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    private function apiCallAsync(
        string $endpoint,
        array $query = [],
        array $data = [],
        string $method = 'GET',
        array $requestOptions = []
    ): PromiseInterface {
        $requestParams = [];

        $requestParams['query'] = array_merge($query, [
            'apiKey' => $this->configuration->getApiKey(),
            'apiSecret' => $this->configuration->getApiSecret(),
        ]);

        if (!empty($data)) {
            $requestParams['json'] = $data;
        }

        return $this->client->requestAsync($method, $endpoint, array_merge($requestParams, $requestOptions))
            ->then(function (Response $response) {
                // Check for 204 No Content
                if ($response->getStatusCode() === 204) {
                    return true;
                }

                $result = $response->getBody()->getContents();
                $response->getBody()->close();

                if ($result === '') {
                    $this->errorResult('Unknown Provider API Error', ['response' => $response]);
                }

                if ($result === "[]") {
                    $result = "{}";
                }

                $parsedResult = json_decode($result, false, 512, JSON_THROW_ON_ERROR);

                if (empty($parsedResult)) {
                    $this->errorResult('Unknown Provider API Error', ['response' => $response]);
                }

                if (isset($parsedResult->transactionStatus)
                    && $parsedResult->transactionStatus === 'ALL_ELEMENTS_FAILED'
                ) {
                    // Handle transaction failure
                    $errorMessages = [];
                    foreach($parsedResult->transactionElements as $element) {
                        foreach($element->elementErrors as $error) {
                            $errorMessages[] = $error->message; // Concatenate all error messages for each element
                        }
                    }

                    $this->errorResult(implode(" ", $errorMessages), ['response' => $parsedResult]);
                }

                return $parsedResult;
            })->otherwise(function (Throwable $t) {
                // Only handle GuzzleHttp TransferExceptions
                if (!$t instanceof TransferException) {
                    throw $t;
                }

                $errorMessage = 'Provider API Connection Error: ' . $t->getMessage();

                if (Str::contains($t->getMessage(), ['timeout', 'timed out'])) {
                    $errorMessage = 'Provider API request timeout';
                }

                $errorData = [];

                // If request exception
                if ($t instanceof RequestException && ($response = $t->getResponse())) {
                    $errorMessage = 'Provider API Response Error: ' . $response->getReasonPhrase();
                    $errorData['http_code'] = $response->getStatusCode();
                    $errorData['response_body'] = $response->getBody()->__toString();
                }

                // If Connection error, set simple error message
                if ($t instanceof ConnectException && Str::contains($t->getMessage(), ['timeout', 'timed out'])) {
                    $errorMessage = 'Provider API request timeout';
                }

                $this->errorResult($errorMessage, $errorData, [], $t);
            });
    }

    /**
     * @return no-return
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function errorResult(string $message, array $data = [], array $debug = [], ?Throwable $e = null): void
    {
        throw ProvisionFunctionError::create($message, $e)
            ->withData($data)
            ->withDebug($debug);
    }
}
