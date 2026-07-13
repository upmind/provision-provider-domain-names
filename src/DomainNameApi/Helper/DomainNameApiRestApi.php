<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\DomainNameApi\Helper;

use GuzzleHttp\Client;
use JsonException;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionProviders\DomainNames\Data\ContactResult;
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
use Upmind\ProvisionProviders\DomainNames\DomainNameApi\Data\DomainNameApiConfiguration;

class DomainNameApiRestApi implements DomainNameApiInterface
{
    public const PRODUCTION_URL = 'https://api.domainresellerapi.com/api/v1';
    public const OTE_URL = 'https://ote.domainresellerapi.com/api/v1';

    protected Client $client;
    protected DomainNameApiConfiguration $configuration;

    public function __construct(Client $client, DomainNameApiConfiguration $configuration)
    {
        $this->client = $client;
        $this->configuration = $configuration;
    }

    public function checkAvailability(DacParams $params): DacResult
    {
        // ToDo: Implement checkAvailability() method.
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
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function makeRequest(
        string $endpoint,
        ?array $params = null,
        ?array $body = null,
        ?string $method = 'GET'
    ): ?array {
        $requestParams = [];

        if ($params !== null) {
            $requestParams['query'] = $params;
        }

        if ($body !== null) {
            $requestParams['body'] = json_encode($body, JSON_THROW_ON_ERROR);
        }

        $response = $this->client->request($method, $endpoint, $requestParams);

        // Reset pointer to start of stream and get content.
        $result = $response->getBody()->__toString();

        $response->getBody()->close();

        if ($result === '') {
            return null;
        }

        return $this->parseResponseData($result);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function parseResponseData(string $result): array
    {
        try {
            $parsedResult = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw ProvisionFunctionError::create('Invalid JSON Response')
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

        if ($error = $this->getResponseErrorMessage($parsedResult)) {
            throw ProvisionFunctionError::create($error)
                ->withData([
                    'response' => $parsedResult,
                ]);
        }

        return $parsedResult;
    }

    private function getBaseUrl(): string
    {
        return $this->configuration->isSandbox() ? self::OTE_URL : self::PRODUCTION_URL;
    }
}
