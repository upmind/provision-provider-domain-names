<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Tests\Unit\BDReseller;

use GuzzleHttp\Client;
use Upmind\ProvisionProviders\DomainNames\BDReseller\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\BDReseller\Provider;
use Upmind\ProvisionProviders\DomainNames\Tests\TestCase;
use ReflectionClass;

class ProviderTest extends TestCase
{
    public function test_api_client_uses_sandbox_base_url_when_sandbox_is_true(): void
    {
        $config = new Configuration([
            'username' => 'test_username',
            'password' => 'test_password',
            'sandbox' => true,
        ]);

        $baseUri = $this->getApiClientBaseUri($config);

        $this->assertEquals('https://141.lyre.us', $baseUri, 'Sandbox base URL should be used when sandbox is true');
    }

    public function test_api_client_uses_production_base_url_when_sandbox_is_false(): void
    {
        $config = new Configuration([
            'username' => 'test_username',
            'password' => 'test_password',
            'sandbox' => false,
        ]);

        $baseUri = $this->getApiClientBaseUri($config);

        $this->assertEquals('https://bdia.btcl.com.bd', $baseUri, 'Production base URL should be used when sandbox is false');
    }

    public function test_api_client_uses_production_base_url_when_sandbox_is_null(): void
    {
        $config = new Configuration([
            'username' => 'test_username',
            'password' => 'test_password',
            'sandbox' => null,
        ]);

        $baseUri = $this->getApiClientBaseUri($config);

        $this->assertEquals('https://bdia.btcl.com.bd', $baseUri, 'Production base URL should be used when sandbox is null');
    }

    public function test_api_client_uses_production_base_url_when_sandbox_is_not_set(): void
    {
        $config = new Configuration([
            'username' => 'test_username',
            'password' => 'test_password',
        ]);

        $baseUri = $this->getApiClientBaseUri($config);

        $this->assertEquals('https://bdia.btcl.com.bd', $baseUri, 'Production base URL should be used when sandbox is not set');
    }

    /**
     * Helper method to extract the base URI from the API client.
     */
    private function getApiClientBaseUri(Configuration $config): string
    {
        $provider = new Provider($config);

        // Use reflection to access the protected api method
        $providerReflection = new ReflectionClass($provider);
        $apiMethod = $providerReflection->getMethod('api');
        $apiMethod->setAccessible(true);

        $bdApi = $apiMethod->invoke($provider);

        // Use reflection to access the protected client property from BDApi
        $bdApiReflection = new ReflectionClass($bdApi);
        $clientProperty = $bdApiReflection->getProperty('client');
        $clientProperty->setAccessible(true);

        $client = $clientProperty->getValue($bdApi);

        // Get the base URI from the client configuration
        $clientConfig = $client->getConfig();
        $baseUri = $clientConfig['base_uri'] ?? null;

        return (string) $baseUri;
    }
}
