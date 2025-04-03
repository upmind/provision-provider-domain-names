<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Netistrar\Helper;

use libphonenumber\PhoneNumberUtil;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use InvalidArgumentException;
use RuntimeException;
use SimpleXMLElement;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionProviders\DomainNames\Data\ContactData;
use Upmind\ProvisionProviders\DomainNames\Data\ContactParams;
use Upmind\ProvisionProviders\DomainNames\Netistrar\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\Data\RenewParams;
use Upmind\ProvisionProviders\DomainNames\Data\AutoRenewParams;
use Upmind\ProvisionProviders\DomainNames\Data\RegisterDomainParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateNameserversParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateDomainContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\TransferParams;
use Upmind\ProvisionProviders\DomainNames\Data\EppCodeResult;
use Upmind\ProvisionProviders\DomainNames\Data\DomainResult;
use Upmind\ProvisionProviders\DomainNames\Netistrar\Provider;

class NetistrarApi
{
    protected Client $client;
    protected Configuration $configuration; 

    enum NominetRegistrantType: string
    {
        case LTD = "LTD"; // UK Limited Company
        case PLC = "PLC"; // UK Public Limited Company
        case PTNR = "PTNR"; // UK Partnership
        case STRA = "STRA"; // UK Sole Trader
        case LLP = "LLP"; // UK Limited Liability Partnership
        case IP = "IP"; // UK Industrial/Provident Company
        case IND = "IND"; // UK Individual (natural person)
        case SCH = "SCH"; // UK School
        case RCHAR = "RCHAR"; // UK Registered Charity
        case GOV = "GOV"; // UK Government Body
        case CRC = "CRC"; // UK Corporation by Royal Charter
        case STAT = "STAT"; // UK Statutory Body
        case OTHER = "OTHER"; // Other entities (clubs, associations, etc.)
    }

    public function __construct(Client $client, Configuration $configuration)
    {
        $this->client = $client;
        $this->configuration = $configuration;
    }

    /**
     * Returns an array of Nominet registrant types that are valid for UK domains, utilizing enums.
     */
    private static function getNominentRegistrantTypes() : array {
        return array_map(fn($type) => $type->value, NominetRegistrantType::cases());
    }

    /**
     * Checks if the provided domain name is a UK domain.
     * 
     * @params string $domainName The domain name to check
     */
    public static function is_uk_domain(string $domainName) : bool {
        // Check if the domain is a UK domain
        return \Str::endsWith($domainName, '.uk');
    }

    /**
     * @param string $endpoint API endpoint
     * @param mixed[] $query Query params
     * @param mixed[] $data Body params
     * @param string $method Request method type
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    private function apiCall(string $endpoint, array $query = [], array $data = [], string $method = 'GET'): object|bool
    {
        try {
            $requestParams = [];

            $default_query['apiKey'] = $this->configuration->api_key;
            $default_query['apiSecret'] = $this->configuration->api_secret;
            $requestParams[RequestOptions::QUERY] = array_merge($query, $default_query);

            if (!empty($data)) {
                $requestParams[RequestOptions::JSON] = $data;
            }

            $response = $this->client->request($method, $endpoint, $requestParams);

            // Check for 204 No Content
            if ($response->getStatusCode() == 204) {
                return true;
            }

            $result = $response->getBody()->getContents();
            $response->getBody()->close();

            if ($result === '') {
                $this->throwError('Unknown Provider API Error', ['response' => $response]);
            }

            if ($result === "[]") {
                $result = "{}";
            }

            $parsedResult = json_decode($result);
            if (empty($parsedResult)) {
                $this->throwError('Unknown Provider API Error', ['response' => $response]);
            }

            if (isset($parsedResult->transactionStatus) && $parsedResult->transactionStatus === 'ALL_ELEMENTS_FAILED'){
                // Handle transaction failure
                $errorMessages = [];
                foreach($parsedResult->transactionElements as $element) {
                    foreach($element->elementErrors as $error) {
                        $errorMessages[] = $error->message; // Concatenate all error messages for each element
                    }
                }
                $this->throwError(implode(" ", $errorMessages), ['response' => $parsedResult]);
            }
            
            return $parsedResult;
        } catch (ConnectException $e) {
            $errorMessage = 'Provider API connection failed';
            if (Str::contains($e->getMessage(), ['timeout', 'timed out'])) {
                $errorMessage = 'Provider API request timeout';
            }

            $this->throwError($errorMessage, [], [], $e);
        }
    }

    public function liveAvailability(string $domainName) : object|bool {
        $endpoint = "domains/available/{$domainName}";
        return $this->apiCall($endpoint, []);
    }

    public function getDomainInfo(string $domainName, array $additionalFields = []) : DomainResult {
        $endpoint = "domains/{$domainName}";
        $apiResult = $this->apiCall($endpoint, []);

        if (isset($apiResult->exceptionClass)) {
            $this->throwError('Unable to get domain info', ['response' => $apiResult->message]);
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

        if (in_array('tags', $additionalFields)) {
            $domainResultData['tags'] = $apiResult->tags; // Include tags if requested
        }

        return DomainResult::create($domainResultData);
    }

    public function getEppCode(string $domainName) : EppCodeResult {
        $endpoint = "domains/{$domainName}";
        $apiResult = $this->apiCall($endpoint, []);

        if (isset($apiResult->exceptionClass)) {
            $this->throwError('Unable to get domain info', ['response' => $apiResult->message]);
        }
        
        return EppCodeResult::create([
            'epp_code' => $apiResult->authCode,
        ]);
    }

    private function transformContactToNetistrarContact(ContactParams $contact, bool $is_uk_domain) : array {
        $phone = PhoneNumberUtil::getInstance()->parse($contact->phone, null);
        $contact = [
            'name' => $contact->name,
            'emailAddress' => $contact->email,
            'organisation' => $contact->organisation,
            'telephoneDiallingCode' => "+". (string)$phone->getCountryCode(),
            'telephone' => $phone->getNationalNumber(),
            'street1' => $contact->address1,
            'city' => $contact->city,
            'county' => $contact->state,
            'postcode' => $contact->postcode,
            'country' => $contact->country_code,
        ];

        if ($is_uk_domain && isset($contact->type) 
            && in_array($contact->type, self::getNominentRegistrantTypes(), true)) {
            $contact['additionalData']['nominetRegistrantType'] = $contact->type;
        }

        return $contact;
    }
    
    private function transformNetisrarContactToContact(\stdClass $contact) : ContactParams {
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

    public function renewDomain(RenewParams $params) : object|bool {
        $domainName = Provider::getDomainName($params);
        $endpoint = "domains/renew/{$domainName}/{$params->renew_years}/";

        $query = [];
        return $this->apiCall($endpoint, $query, [], 'GET');//yes, new is a GET request
    }

    public function createDomain(RegisterDomainParams $params) : object|bool {
        
        $endpoint = "domains";
        $domainName = Provider::getDomainName($params);
        $is_uk_domain = self::is_uk_domain($domainName);

        $nameservers = [];
        $keys = ['ns1', 'ns2', 'ns3', 'ns4', 'ns5'];  // List of nameserver keys
        foreach ($keys as $key) {
            if (isset($params->nameservers->$key->host)) {
                $nameservers[] = $params->nameservers->$key->host;
            }
        }

        $data = [
            'domainNames' => [ $domainName ],
            'registrationYears' => (int)$params->renew_years,
            'ownerContact' => $this->transformContactToNetistrarContact($params->registrant->register, $is_uk_domain ),
            'nameservers' => $nameservers,
            'privacyProxy' => $params->whois_privacy ? 0 : 1,
        ];

        if (isset($params->admin->register)) {
            $data['adminContact'] = $this->transformContactToNetistrarContact($params->admin->register, $is_uk_domain);
        }
        if (isset($params->tech->register)) {
            $data['technicalContact'] = $this->transformContactToNetistrarContact($params->tech->register, $is_uk_domain);
        }
        if (isset($params->billing->register)) {
            $data['billingContact'] = $this->transformContactToNetistrarContact($params->billing->register, $is_uk_domain);
        }

        $results = $this->apiCall($endpoint, [], $data, 'POST');

        if ($results->transactionStatus == "ALL_ELEMENTS_FAILED") {
            $this->throwError('Unable to register domain', ['response' => $results->transactionElements->{$params->sld.".".$params->tld}->elementErrors]);
        }

        return $results;
    }

    private function validateIncomingTransferDomains(array $data) : object|bool {
        $endpoint = "/domains/transfer/validate/";
        $validationResults = $this->apiCall($endpoint, [], $data, 'POST');

        if (isset($validationResults->transactionErrors)) {
            $reasonMessages = [];
            foreach( $validationResults->transactionErrors as $err) {
                $reasonMessages[] = $err->message;
            }
            $this->throwError('Unable to validate transfer domains', ['response' => implode(' ',$reasonMessages)]);
        }

        return true;
    }

    public function transferDomain(TransferParams $params) : object|bool {
        $domainName = Provider::getDomainName($params);

        $transferIdentifier = $domainName;
        $is_uk_domain = self::is_uk_domain($domainName);
        
        if (!$is_uk_domain) {
            $transferIdentifier .= "," .$params->epp_code;
        }
        
        $data = [
            'transferIdentifiers' => [ $transferIdentifier ],
            'ownerContact' => $this->transformContactToNetistrarContact($params->registrant->register, $is_uk_domain),
            'privacyProxy' => $params->whois_privacy ? 0 : 1,
        ];

        if (isset($params->admin->register)) {
            $data['adminContact'] = $this->transformContactToNetistrarContact($params->admin->register, $is_uk_domain);
        }
        if (isset($params->tech->register)) {
            $data['technicalContact'] = $this->transformContactToNetistrarContact($params->tech->register, $is_uk_domain);
        }
        if (isset($params->billing->register)) {
            $data['billingContact'] = $this->transformContactToNetistrarContact($params->billing->register, $is_uk_domain);
        }

        $validateResults = $this->validateIncomingTransferDomains($data);
        
        $endpoint = "domains/transfer/";
        $results = $this->apiCall($endpoint, [], $data, 'POST');

        if ($results->transactionStatus == "ALL_ELEMENTS_FAILED") {
            $this->throwError('Unable to transfer domain', ['response' => $results->transactionElements->{$domainName}->elementErrors]);
        }

        return $results;
    }

    public function updateIpsTag(string $domainName, string $addTags, array $removeTags) : object|bool {
        $data = [
            'domainNames' => [ $domainName ],
            'addTags' => $addTags,
            'removeTags' => $removeTags,
        ];
        return $this->updateDomain($data);
    }

    public function updateRegistrantContact(string $domainName, UpdateDomainContactParams $params) : object|bool {
        $is_uk_domain = self::is_uk_domain($domainName);
        $data = [
            'domainNames' => [ $domainName ],
            'ownerContact' => $this->transformContactToNetistrarContact($params->contact, $is_uk_domain),
        ];
        return $this->updateDomain($data);
    }

    public function updateDomainLock(string $domainName, bool $lock) : object|bool {
        $data = [
            'domainNames' => [ $domainName ],
            'locked' => $lock,
        ];
        return $this->updateDomain($data);
    }

    public function updateAutoRenew(string $domainName, bool $autoRenew) : object|bool {
        $data = [
            'domainNames' => [ $domainName ],
            'autoRenew' => $autoRenew,
        ];
        return $this->updateDomain($data);
    }

    public function updateDomainNameservers(string $domainName, UpdateNameserversParams $params) : object|bool {
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
        
        if ($results->transactionStatus == "ALL_ELEMENTS_FAILED") {
            $this->throwError('Unable to register domain', ['response' => $results->transactionElements->{$params->sld.".".$params->tld}->elementErrors]);
        }

        return $results;
    }

    public function updateDomain(array $data) : object|bool{
        $endpoint = "domains/";
        $results = $this->apiCall($endpoint, [], $data, 'PATCH');

        if ($results->transactionStatus == "ALL_ELEMENTS_FAILED") {
            $this->throwError('Unable to register domain', ['response' => $results->transactionElements->{$params->sld.".".$params->tld}->elementErrors]);
        }

        return $results;
    }
    
    /**
     * @return no-return
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function throwError(string $message, array $data = [], array $debug = [], ?Throwable $e = null): void
    {
        throw ProvisionFunctionError::create($message, $e)
            ->withData($data)
            ->withDebug($debug);
    }
}