<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Ascio\Helper;

use Carbon\Carbon;
use libphonenumber\NumberParseException;
use SoapClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use SoapHeader;
use SoapVar;
use Throwable;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionBase\Provider\DataSet\SystemInfo;
use Upmind\ProvisionProviders\DomainNames\Data\ContactData;
use Upmind\ProvisionProviders\DomainNames\Data\ContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\DacDomain;
use Upmind\ProvisionProviders\DomainNames\Data\DomainNotification;
use Upmind\ProvisionProviders\DomainNames\Data\GlueRecord;
use Upmind\ProvisionProviders\DomainNames\Data\Nameserver;
use Upmind\ProvisionProviders\DomainNames\Data\NameserversResult;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\Ascio\Data\Configuration;

/**
 * Ascio Domains API client.
 */
class AscioApi
{
    /**
     * Contact Types
     */
    public const CONTACT_TYPE_REGISTRANT = 'Owner';
    public const CONTACT_TYPE_TECH = 'Tech';
    public const CONTACT_TYPE_ADMIN = 'Admin';
    public const CONTACT_TYPE_BILLING = 'Billing';

    const MAX_CUSTOM_NAMESERVERS = 12;

    protected SoapClient $client;

    protected Configuration $configuration;

    protected LoggerInterface $logger;

    public function __construct(SoapClient $client, Configuration $configuration, LoggerInterface $logger)
    {
        $this->client = $client;
        $this->configuration = $configuration;
        $this->logger = $logger;
    }

    /**
     * @throws \SoapFault
     */
    public function makeRequest(string $command, ?array $params = null, string $responseField = null): ?array
    {
        if ($params) {
            if ($command === 'createOrder') {
                $requestParams = [
                    'request' =>
                        new SoapVar($params,
                            SOAP_ENC_OBJECT,
                            "DomainOrderRequest",
                            "http://www.ascio.com/2013/02",
                            "request",
                            "http://www.ascio.com/2013/02/AscioService"),
                ];
            } else {
                $requestParams = [
                    'request' =>
                        $params,
                ];
            }
        }

        $requestParams = $requestParams ?? [];

        $this->logger->debug('Ascio API Request', [
            'command' => $command,
            'params' => $requestParams,
            'pass' => $this->client->__getLastRequestHeaders(),
        ]);

        $response = $this->client->__soapCall($command, array($requestParams));
        $responseData = json_decode(json_encode($response), true);

        $this->logger->debug('Ascio API Response', [
            'result' => $responseData,
        ]);

        $this->logger->debug('Ascio API header', [
            'pass' => $this->client->__getLastRequest(),
        ]);

        return $this->parseResponseData($command, $response, $responseField)[$responseField];
    }


    /**
     * @param array|\stdClass $result
     */
    private function parseResponseData(string $command, $result, string $responseField): array
    {
        $parsedResult = is_array($result) ? $result : json_decode(json_encode($result), true);

        if (!$parsedResult) {
            throw ProvisionFunctionError::create('Unknown Provider API Error')
                ->withData([
                    'response' => $parsedResult,
                ]);
        }

        if ($error = $this->getResponseErrorMessage($command, $parsedResult[$responseField])) {
            throw ProvisionFunctionError::create(sprintf('Provider API Error: %s', $error))
                ->withData([
                    'response' => $parsedResult,
                ]);
        }

        return $parsedResult;
    }


    private function getResponseErrorMessage(string $command, array $responseData)
    {
        if ($responseData['ResultCode'] !== 200 && $responseData['ResultCode'] !== 201) {
            $errorMessage = $responseData['ResultMessage'];
        }

        return $errorMessage ?? null;
    }


    /**
     * @throws \SoapFault
     */
    public function getDomains(string $domainName)
    {
        $command = 'getDomains';
        $params = [
            'ObjectNames' => [$domainName],
            "PageInfo" => [
                "PageIndex" => 1,
                "PageSize" => 1
            ],
            "OrderSort" => "CreatedDesc",
        ];

        $info = $this->makeRequest($command, $params, 'GetDomainsResult');

        try {
            return $info['DomainInfos']['DomainInfo'];
        } catch (\Exception $e) {
            throw ProvisionFunctionError::create("Domain info not found for $domainName")
                ->withData([
                    'response' => $info,
                ]);
        }
    }


    public function getDomainInfo(string $domainName): array
    {
        $response = $this->getDomains($domainName);

        return $this->parseDomainData($response);
    }


    public function parseDomainData(array $response): array
    {
        return [
            'id' => $response['DomainHandle'],
            'domain' => (string)$response['DomainName'],
            'statuses' => [$response['Status']],
            'locked' => $response['TransferLock'] == 'Lock' && $response['UpdateLock'] == 'Lock',
            'registrant' => isset($response['Owner'])
                ? $this->parseContact($response['Owner'])
                : null,
            'billing' => isset($response['Billing'])
                ? $this->parseContact($response['Billing'])
                : null,
            'tech' => isset($response['Tech'])
                ? $this->parseContact($response['Tech'])
                : null,
            'admin' => isset($response['Admin'])
                ? $this->parseContact($response['Admin'])
                : null,
            'ns' => NameserversResult::create($this->parseNameservers($response['NameServers'])),
            'created_at' => isset($response['Created']) ? Utils::formatDate($response['Created']) : null,
            'updated_at' => null,
            'expires_at' => isset($response['Expires']) ? Utils::formatDate($response['Expires']) : null,
        ];
    }


    private function parseNameservers(array $nameservers): array
    {
        $result = [];

        for ($i = 1; $i <= self::MAX_CUSTOM_NAMESERVERS; $i++) {
            if ($nameservers["NameServer{$i}"]['HostName'] !== null) {
                $result['ns' . $i] = [
                    'host' => (string)$nameservers["NameServer{$i}"]['HostName'],
                    'ip' => (string)$nameservers["NameServer{$i}"]['IpAddress']
                ];
            }
        }

        return $result;
    }


    public function checkMultipleDomains(array $domains): array
    {
        $command = 'availabilityInfo';
        $dacDomains = [];

        foreach ($domains as $domain) {
            $params = [
                "DomainName" => $domain,
                "Quality" => "QualityTest"
            ];

            $response = $this->makeRequest($command, $params, 'AvailabilityInfoResult');

            $available = $response['ResultCode'] == 200 || $response['ResultCode'] == 203;

            $dacDomains[] = DacDomain::create([
                'domain' => $response['DomainName'],
                'description' => sprintf(
                    'Domain is %s to register',
                    $available ? 'available' : 'not available'
                ),
                'tld' => Utils::getTld($response['DomainName']),
                'can_register' => $available,
                'can_transfer' => !$available,
                'is_premium' => $response["DomainType"] == "Premium",
            ]);
        }

        return $dacDomains;
    }


    public function renew(string $domainName, int $period): void
    {
        $command = 'createOrder';

        $params = [
            'Type' => "Renew",
            'Domain' => [
                'Name' => $domainName,
            ],
            'Period' => $period,
        ];

        $this->makeRequest($command, $params, 'CreateOrderResult');
    }


    /**
     * @param string $domainName
     * @param array $nameservers
     * @return array
     * @throws \SoapFault
     */
    public function updateNameservers(string $domainName, array $nameservers): array
    {
        $command = 'createOrder';

        $params = [
            'Type' => "NameserverUpdate",
            'Domain' => [
                'Name' => $domainName,
                "NameServers" => $nameservers
            ],
        ];

        $this->makeRequest($command, $params, 'CreateOrderResult');

        $response = $this->getDomains($domainName);
        return $this->parseNameservers($response['NameServers']);
    }


    /**
     * @param string $domainName
     * @return void
     * @throws \SoapFault
     */
    public function setRenewalMode(string $domainName): void
    {
        $command = 'createOrder';

        $params = [
            'Type' => "AutoRenew",
            'Domain' => [
                'Name' => $domainName,
            ],
        ];

        $this->makeRequest($command, $params, 'CreateOrderResult');
    }


    public function setRegistrarLock(string $domainName, bool $lock): void
    {
        $command = 'createOrder';

        $status = $lock ? 'Lock' : 'Unlock';

        $params = [
            'Type' => "ChangeLocks",
            'Domain' => [
                'Name' => $domainName,
                "TransferLock" => $status,
                "UpdateLock" => $status
            ],
        ];

        $this->makeRequest($command, $params, 'CreateOrderResult');

    }


    /**
     * @param string $domainName
     * @return string|null
     * @throws \SoapFault
     */
    public function getDomainEppCode(string $domainName): ?string
    {
        $response = $this->getDomains($domainName);

        return $response['AuthInfo'] ?? null;
    }


    /**
     * @throws \SoapFault
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     */
    public function register(
        string $domainName,
        int    $period,
        array  $contacts,
        array  $nameServers
    ): void
    {
        $command = 'createOrder';

        $nameserverParams = [];
        $i = 1;
        foreach ($nameServers as $ns) {
            $nameserverParams["NameServer{$i}"] = [
                'HostName' => $ns,
            ];
            $i++;
        }

        $params = [
            'Type' => 'Register',
            'Domain' => (object) [
                'Name' => $domainName,
                'NameServers' => $nameserverParams,
                'RenewPeriod' => $period,
                'Owner' => new SoapVar(
                    (object) ['Handle' => $contacts[self::CONTACT_TYPE_REGISTRANT]],
                    SOAP_ENC_OBJECT,
                    'Registrant',
                    'http://www.ascio.com/2013/02'
                ),
                'Admin' => new SoapVar(
                    (object) ['Handle' => $contacts[self::CONTACT_TYPE_ADMIN]],
                    SOAP_ENC_OBJECT,
                    'Contact',
                    'http://www.ascio.com/2013/02'
                ),
                'Tech' => new SoapVar(
                    (object) ['Handle' => $contacts[self::CONTACT_TYPE_TECH]],
                    SOAP_ENC_OBJECT,
                    'Contact',
                    'http://www.ascio.com/2013/02'
                ),
                'Billing' => new SoapVar(
                    (object) ['Handle' => $contacts[self::CONTACT_TYPE_BILLING]],
                    SOAP_ENC_OBJECT,
                    'Contact',
                    'http://www.ascio.com/2013/02'
                ),
            ],
        ];

        $this->makeRequest($command, $params, 'CreateOrderResult');
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


    /**
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws NumberParseException|\SoapFault
     */
    public function updateRegistrantContact(string $domainName, ContactParams $registrantParams): ContactData
    {
        $command = 'createOrder';

        $params = [
            'Type' => "OwnerChange",
            'Domain' => [
                'Name' => $domainName,
                "Owner" => [
                    'Handle' => $this->createRegistrant($registrantParams),
                ],
            ],
        ];

        $this->makeRequest($command, $params, 'CreateOrderResult');

        return $this->getDomainInfo($domainName)['registrant'];
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws NumberParseException
     */
    private function setContactParams(ContactParams $contactParams): array
    {
        $nameParts = $this->getNameParts($contactParams->name ?? $contactParams->organisation);

        return array_filter([
            'FirstName' => $nameParts['firstName'],
            'LastName' => $nameParts['lastName'] ?: $nameParts['firstName'],
            'OrgName' => $contactParams->organisation ?: '',
            'Address1' => $contactParams->address1,
            'City' => $contactParams->city,
            'State' => $contactParams->state,
            'PostalCode' => $contactParams->postcode,
            'CountryCode' => Utils::normalizeCountryCode($contactParams->country_code),
            'Phone' => Utils::internationalPhoneToEpp($contactParams->phone),
            'Email' => $contactParams->email,
        ], fn($value) => $value !== null);
    }

    private function parseContact(array $contact): ContactData
    {
        return ContactData::create([
            'organisation' => $contact['OrgName'] ?? null,
            'name' => implode(' ', [$contact['FirstName'] ?? '', $contact['LastName'] ?? '']),
            'address1' => $contact['Address1'] ?? null,
            'city' => $contact['City'],
            'state' => $contact['State'] ?? null,
            'postcode' => $contact['PostalCode'] ?? null,
            'country_code' => Utils::normalizeCountryCode($contact['CountryCode'] ?? null),
            'email' => $contact['Email'] ?? null,
            'phone' => $contact['Phone'] ?? null,
        ]);
    }


    /**
     * @throws \SoapFault
     * @throws NumberParseException
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     */
    public function initiateTransfer(string $domainName, string $eppCode, array $contacts, array $nameServers): void
    {
        $command = 'createOrder';

        $nameserverParams = [];
        $i = 1;
        foreach ($nameServers as $ns) {
            $nameserverParams["NameServer{$i}"] = [
                'HostName' => $ns,
            ];
            $i++;
        }

        $params = [
            'Type' => 'Transfer',
            'Domain' => (object) [
                'Name' => $domainName,
                'NameServers' => $nameserverParams,
                'AuthInfo' => $eppCode,
                'Owner' => new SoapVar(
                    (object) $this->setContactParams($contacts[self::CONTACT_TYPE_REGISTRANT]),
                    SOAP_ENC_OBJECT,
                    'Registrant',
                    'http://www.ascio.com/2013/02'
                ),
                'Admin' => new SoapVar(
                    (object) $this->setContactParams($contacts[self::CONTACT_TYPE_ADMIN]),
                    SOAP_ENC_OBJECT,
                    'Contact',
                    'http://www.ascio.com/2013/02'
                ),
                'Tech' => new SoapVar(
                    (object) $this->setContactParams($contacts[self::CONTACT_TYPE_TECH]),
                    SOAP_ENC_OBJECT,
                    'Contact',
                    'http://www.ascio.com/2013/02'
                ),
                'Billing' => new SoapVar(
                    (object) $this->setContactParams($contacts[self::CONTACT_TYPE_BILLING]),
                    SOAP_ENC_OBJECT,
                    'Contact',
                    'http://www.ascio.com/2013/02'
                ),
            ],
        ];

        $this->makeRequest($command, $params, 'CreateOrderResult');
    }

    /**
     * Resend verification email for a domain.
     *
     * @param string $domainName Full domain name (e.g., example.com)
     * @return array Success result
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError|\SoapFault
     */
    public function resendVerificationEmail(string $domainName): array
    {
        $domainData = $this->getDomains($domainName);
        $email = $domainData['Owner']['Email'];

        $command = 'startRegistrantVerification';
        $params = [
            "Email" => $email,
        ];

        $response = $this->makeRequest($command, $params, 'StartRegistrantVerificationResult');

        return [
            'success' => true,
            'message' => $response['ResultMessage'] ?? 'Verification email sent successfully',
        ];

    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError|\SoapFault
     */
    public function poll(int $limit, ?Carbon $since): array
    {
        $notifications = [];
        $countRemaining = 0;

        /**
         * Start a timer because there may be 1000s of irrelevant messages and we should try and avoid a timeout.
         */
        $timeLimit = 60; // 60 seconds
        $startTime = time();

        while (count($notifications) < $limit && (time() - $startTime) < $timeLimit) {
            $pollResponse = $this->makeRequest('pollQueue', [
                'MessageType' => 'MessageToPartner',
                'ObjectType' => 'DomainType'
            ], 'PollQueueResult');

            $countRemaining = $pollResponse['TotalCount'] ?? 0;

            if ($countRemaining == 0) {
                break;
            }

            $messageId = $pollResponse['Message']['Id'] ?? null;

            if ($messageId == null) {
                continue;
            }

            $message = $pollResponse['Message']['Message'] ?: 'Domain Notification';
            $domain = $pollResponse['Message']['ObjectName'];

            $type = "";

            switch ($pollResponse['Message']['OrderType']) {
                case 'Transfer':
                case 'Register':
                    $type = DomainNotification::TYPE_TRANSFER_IN;
                    break;
                case 'TransferAway':
                    $type = DomainNotification::TYPE_TRANSFER_OUT;
                    break;
                case 'Delete':
                    $type = DomainNotification::TYPE_DELETED;
                    break;
                case 'Renew':
                    $type = DomainNotification::TYPE_RENEWED;
                    break;
            }

            try {
                $this->makeRequest('ackQueueMessage', [
                    "MessageId" => $messageId,
                ], 'AckQueueMessageResult');
            } catch (Throwable $e) {
                continue;
            }

            $messageDateTime = carbon::now();

            $orderId = $pollResponse['Message']['OrderId'];
            try {
                $order = $this->makeRequest('getMessages', [
                    "OrderId" => $orderId,
                ], 'GetMessagesResult');

                $messageDateTime = Carbon::parse($order['Messages']['Message'][0]['Created']);
            } catch (Throwable $e) {
                continue;
            }

            if ($type == "") {
                continue;
            }

            if (isset($since) && $messageDateTime->lessThan($since)) {
                // this message is too old
                continue;
            }


            $notifications[] = DomainNotification::create()
                ->setId($messageId)
                ->setType($type)
                ->setMessage($message)
                ->setDomains([$domain])
                ->setCreatedAt($messageDateTime)
                ->setExtra(['response' => json_encode($pollResponse['Message'])]);
        }

        return [
            'count_remaining' => $countRemaining,
            'notifications' => $notifications,
        ];
    }

    /**
     * @throws \SoapFault
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws NumberParseException
     */
    public function createContact(ContactParams $contactParams): ?string
    {
        $command = 'createContact';

        $params = [
            'Contact' => $this->setContactParams($contactParams)
        ];

        return $this->makeRequest($command, $params, 'CreateContactResult')['Contact']['Handle'] ?? null;
    }


    /**
     * @throws \SoapFault
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws NumberParseException
     */
    public function createRegistrant(ContactParams $contactParams): ?string
    {
        $command = 'createRegistrant';

        $params = [
            'Registrant' => $this->setContactParams($contactParams)
        ];

        return $this->makeRequest($command, $params, 'CreateRegistrantResult')['Registrant']['Handle'] ?? null;
    }
}
