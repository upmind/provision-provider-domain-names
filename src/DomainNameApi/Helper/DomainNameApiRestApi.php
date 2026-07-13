<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\DomainNameApi\Helper;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Str;
use JsonException;
use Throwable;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionProviders\DomainNames\Data\ContactResult;
use Upmind\ProvisionProviders\DomainNames\Data\DacDomain;
use Upmind\ProvisionProviders\DomainNames\Data\DacParams;
use Upmind\ProvisionProviders\DomainNames\Data\DacResult;
use Upmind\ProvisionProviders\DomainNames\Data\DomainInfoParams;
use Upmind\ProvisionProviders\DomainNames\Data\DomainResult;
use Upmind\ProvisionProviders\DomainNames\Data\EppCodeResult;
use Upmind\ProvisionProviders\DomainNames\Data\EppParams;
use Upmind\ProvisionProviders\DomainNames\Data\LockParams;
use Upmind\ProvisionProviders\DomainNames\Data\NameserversResult;
use Upmind\ProvisionProviders\DomainNames\Data\RegisterDomainParams;
use Upmind\ProvisionProviders\DomainNames\Data\RenewParams;
use Upmind\ProvisionProviders\DomainNames\Data\TransferParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateNameserversParams;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;

class DomainNameApiRestApi implements DomainNameApiInterface
{
    public const PRODUCTION_URL = 'https://api.domainresellerapi.com/api/v1';
    public const OTE_URL = 'https://ote.domainresellerapi.com/api/v1';

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
            fn ($tld) => Utils::getDomain($params->sld, $tld),
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

            $status = strtolower($item['status'] ?? '');
            $isAvailable = in_array($status, ['available', '1', 'true']);

            $dacDomains[] = DacDomain::create([
                'domain' => $domain['domainName'],
                'tld' => $domain['tld'],
                'can_register' => $isAvailable,
                'can_transfer' => !$isAvailable,
                'is_premium' => isset($domain['isPremium']) && (bool) $domain['isPremium'] === true,
                'description' => $domain['reason'] ?? '', // ToDo: Replace message with availability?
            ]);
        }

        return DacResult::create([
            'domains' => $dacDomains
        ]);
    }

    public function registerWithContactInfo(RegisterDomainParams $params): DomainResult
    {
        // TODO: Implement registerWithContactInfo() method.
    }

    public function transfer(TransferParams $params): DomainResult
    {
        // TODO: Implement transfer() method.
    }

    public function renew(RenewParams $params): DomainResult
    {
        // TODO: Implement renew() method.
    }

    public function getDetails(DomainInfoParams $params): DomainResult
    {
        // TODO: Implement getDetails() method.
    }

    public function modifyNameServer(UpdateNameserversParams $params): NameserversResult
    {
        // TODO: Implement modifyNameServer() method.
    }

    public function getEppCode(EppParams $params): EppCodeResult
    {
        // TODO: Implement getEppCode() method.
    }

    public function saveContacts(UpdateContactParams $params): ContactResult
    {
        // TODO: Implement saveContacts() method.
    }

    public function toggleTheftProtectionLock(LockParams $params): DomainResult
    {
        // TODO: Implement toggleTheftProtectionLock() method.
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
            try {
                $params['body'] = json_encode($body, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw ProvisionFunctionError::create('Invalid JSON Request Body')
                    ->withData([
                        'params' => $params,
                    ]);
            }
        }

        // Update endpoint to include leading `/` if it doesn't.
        $endpoint = Str::startsWith($endpoint, '/') ? $endpoint : sprintf('/%s', $endpoint);

        // Make API Call & handle errors.
        try {
            $response = $this->client->request($method, $endpoint, $params);
        } catch (RequestException $ex) {
            $response = $ex->getResponse();
            $statusCode = $response !== null ? $response->getStatusCode() : 0;

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
                ->withData([
                    'response' => $result,
                ]);
        }
    }
}
