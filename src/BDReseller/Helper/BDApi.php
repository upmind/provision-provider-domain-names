<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\BDReseller\Helper;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\Utils as PromiseUtils;
use GuzzleHttp\Psr7\Response;
use JsonException;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionProviders\DomainNames\Data\ContactData;
use Upmind\ProvisionProviders\DomainNames\Data\ContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\DacDomain;
use Upmind\ProvisionProviders\DomainNames\Data\NameserversResult;
use Upmind\ProvisionProviders\DomainNames\Helper\Countries;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\BDReseller\Data\Configuration;

/**
 * BD Reseller API client.
 */
class BDApi
{
    public const MAX_CUSTOM_NAMESERVERS = 3;

    protected Client $client;

    protected Configuration $configuration;

    public function __construct(Client $client, Configuration $configuration)
    {
        $this->client = $client;
        $this->configuration = $configuration;
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function asyncRequest(
        string $command,
        ?array $query = null,
        ?array $body = null,
        string $method = 'POST'
    ): Promise {
        $requestParams = [];
        if ($query) {
            $requestParams = ['query' => $query];
        }

        if ($body) {
            $requestParams['json'] = $body;
        }

        /** @var \GuzzleHttp\Promise\Promise $promise */
        $promise = $this->client->requestAsync($method, "/rsdom/{$command}.do", $requestParams)
            ->then(function (Response $response) {
                $result = $response->getBody()->getContents();
                $response->getBody()->close();

                if ($result === '') {
                    return null;
                }

                return $this->parseResponseData($result);
            });

        return $promise;
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function makeRequest(
        string $command,
        ?array $query = null,
        ?array $body = null,
        string $method = 'POST'
    ): ?array
    {
        return $this->asyncRequest($command, $query, $body, $method)->wait();
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function parseResponseData(string $result): array
    {
        try {
            $parsedResult = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw ProvisionFunctionError::create('Invalid Provider API Response: ' . $e->getMessage())
                ->withData([
                    'response' => $result,
                ]);
        }

        if (!$parsedResult) {
            throw ProvisionFunctionError::create('Unknown Provider API Error')
                ->withData([
                    'response' => $result,
                ]);
        }

        return $parsedResult;
    }

    /**
     * @param string[] $domains
     *
     * @return DacDomain[]
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function checkMultipleDomains(array $domains): array
    {
        $promises = array_map(function ($domain) {
            return $this->asyncRequest('domain_availability', null, [
                'domain' => $domain
            ])
                ->then(function (array $result) {
                    $canRegister = isset($result['message']) && $result['message'] === 'Domain is available';

                    return DacDomain::create([
                        'domain' => $result['domain'],
                        'description' => $result['message'] ?? sprintf(
                                'Domain is %s to register',
                                $canRegister ? 'available' : 'not available',
                            ),
                        'tld' => Utils::getTld($result['domain']),
                        'can_register' => $canRegister,
                        'can_transfer' => false, // Domain transfer is not supported
                        'is_premium' => false,
                    ]);
                });
        }, $domains);

        return PromiseUtils::all($promises)->wait();
    }

    /**
     * @param string $domainName
     *
     * @return array
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getDomainInfo(string $domainName): array
    {
        $response = $this->makeRequest('domain_info', null, ['domain' => $domainName]);

        if ($response['status'] !== 'success') {
            throw ProvisionFunctionError::create(sprintf('Domain info failed: %s', $response['message'] ?? $response['domain'] ?? 'Unknown error'))
                ->withData([
                    'response' => $response,
                ]);
        }

        $nameservers = [];
        if (isset($response['primaryDns'])) {
            $nameservers[] = $response['primaryDns'];
        }

        if (isset($response['secondaryDns'])) {
            $nameservers [] = $response['secondaryDns'];
        }

        if (isset($response['tertiaryDns'])) {
            $nameservers [] = $response['tertiaryDns'];
        }

        return [
            'id' => 'N/A',
            'domain' => (string)$response['domain'],
            'statuses' => [],
            'registrant' => $this->parseContactInfo($response),
            'billing' => null,
            'tech' => null,
            'admin' => null,
            'ns' => NameserversResult::create($this->parseNameservers($nameservers)),
            'created_at' => isset($response['activationDate'])
                ? DateTimeImmutable::createFromFormat('d/m/Y', $response['activationDate'])
                    ->setTime(0, 0)
                    ->format('Y-m-d H:i:s')
                : null,
            'updated_at' => null,
            'expires_at' => isset($response['expiryDate'])
                ? DateTimeImmutable::createFromFormat('d/m/Y', $response['expiryDate'])
                    ->setTime(0, 0)
                    ->format('Y-m-d H:i:s')
                : null
        ];
    }

    /**
     * @param array $response
     *
     * @return ContactData
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function parseContactInfo(array $response): ContactData
    {
        return ContactData::create([
            'name' => $response['clientFullName'],
            'organisation' => isset($response['clientNid']) ? (string)$response['clientNid'] : null,
            'address1' => $response['clientContactAddress'],
            'email' => isset($response['clientEmail']) ? (string)$response['clientEmail'] : null,
            'phone' => isset($response['clientContactNumber']) ? (string)$response['clientContactNumber'] : null,
        ]);
    }

    /**
     * @param array $nameservers
     *
     * @return array
     */
    private function parseNameservers(array $nameservers): array
    {
        $result = [];

        if (count($nameservers) > 0) {
            foreach ($nameservers as $i => $ns) {
                if ($ns === '') {
                    continue;
                }
                $result['ns' . ($i + 1)] = ['host' => $ns];
            }
        }

        return $result;
    }

    /**
     * @param string $domainName
     * @param int $period
     * @param ContactParams $registrant
     * @param array $nameServers
     *
     * @return void
     *
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function register(string $domainName, int $period, ContactParams $registrant, array $nameServers): void
    {
        $contactAddress = [
            $registrant->address1,
            $registrant->city,
            Countries::codeToName(Countries::normalizeCode($registrant->country_code)),
            $registrant->postcode,
        ];

        $body = [
            'domain' => $domainName,
            'year' => $period,
            'nameServers' => array_slice($nameServers, 0, self::MAX_CUSTOM_NAMESERVERS),
            'fullName' => $registrant->name ?? $registrant->organisation,
            'nid' => $registrant->organisation, // National ID: 10 or 13 or 17-digit number
            'email' => $registrant->email,
            'contactAddress' => implode(', ', $contactAddress),
            'contactNumber' => Utils::localPhoneToInternational($registrant->phone, null, false)
        ];

        $response = $this->makeRequest('domain_buy', null, $body);
        if ($response['message'] !== 'Domain registered successfully') {
            throw ProvisionFunctionError::create(sprintf('Domain registration failed: %s', $response['message'] ?? 'Unknown error'))
                ->withData([
                    'response' => $response,
                ]);
        }
    }

    /**
     * @param string $domainName
     * @param array $nameServers
     *
     * @return void
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateNameservers(string $domainName, array $nameServers): void
    {
        $body = [
            'domain' => $domainName,
            'nameServers' => array_slice($nameServers, 0, self::MAX_CUSTOM_NAMESERVERS),
        ];

        $response = $this->makeRequest('domain_update_ns', null, $body);
        if ($response['message'] !== 'NS Record updated') {
            throw ProvisionFunctionError::create(sprintf("Domain nameservers update failed: %s", $response['message'] ?? 'Unknown error'))
                ->withData([
                    'response' => $response,
                ]);
        }
    }

    /**
     * @param string $domainName
     * @param int $period
     *
     * @return void
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function renew(string $domainName, int $period): void
    {
        $body = [
            'domain' => $domainName,
            'year' => $period,
        ];

        $response = $this->makeRequest('domain_renew', null, $body);

        if (!$response || $response['status'] !== 'success') {
            throw ProvisionFunctionError::create(sprintf('Domain renew failed: %s', $response['message'] ?? 'Unknown error'))
                ->withData([
                    'response' => $response,
                ]);
        }
    }
}
