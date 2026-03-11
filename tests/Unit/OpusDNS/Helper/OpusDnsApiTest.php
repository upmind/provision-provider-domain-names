<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Tests\Unit\OpusDNS\Helper;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Psr\Log\NullLogger;
use Upmind\ProvisionProviders\DomainNames\OpusDNS\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\OpusDNS\Helper\OpusDnsApi;
use Upmind\ProvisionProviders\DomainNames\Tests\TestCase;

class OpusDnsApiTest extends TestCase
{
    /**
     * Create an OpusDnsApi with a mocked Guzzle client.
     *
     * @param Response[] $responses
     */
    protected function createApiWithMockedResponses(array $responses): OpusDnsApi
    {
        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        $httpClient = new Client(['handler' => $handlerStack]);

        $config = new Configuration([
            'client_id' => 'test_client_id',
            'client_secret' => 'test_client_secret',
            'sandbox' => true,
        ]);

        return new OpusDnsApi($config, new NullLogger(), $httpClient);
    }

    /**
     * Create a mock token response.
     */
    protected function tokenResponse(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'access_token' => 'test_access_token_123',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ]));
    }

    public function test_authenticate_obtains_bearer_token(): void
    {
        $api = $this->createApiWithMockedResponses([
            $this->tokenResponse(),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'data' => ['domain' => 'test.com'],
            ])),
        ]);

        // Making any request should trigger authentication
        $result = $api->makeRequest('GET', 'domains/test.com');

        $this->assertIsArray($result);
    }

    public function test_authenticate_caches_token(): void
    {
        $api = $this->createApiWithMockedResponses([
            // Only one token response — second request reuses the cached token
            $this->tokenResponse(),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'data' => ['domain' => 'test.com'],
            ])),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'data' => ['domain' => 'test2.com'],
            ])),
        ]);

        // First request triggers auth
        $api->makeRequest('GET', 'domains/test.com');
        // Second request should reuse the cached token (no new auth request needed)
        $result = $api->makeRequest('GET', 'domains/test2.com');

        $this->assertIsArray($result);
    }

    public function test_check_domains_returns_dac_domains(): void
    {
        $api = $this->createApiWithMockedResponses([
            $this->tokenResponse(),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'data' => [
                    [
                        'domain' => 'example.com',
                        'available' => true,
                        'premium' => false,
                    ],
                    [
                        'domain' => 'example.net',
                        'available' => false,
                        'premium' => false,
                    ],
                ],
            ])),
        ]);

        $results = $api->checkDomains(['example.com', 'example.net']);

        $this->assertCount(2, $results);
        $this->assertTrue($results[0]->can_register);
        $this->assertFalse($results[0]->can_transfer);
        $this->assertFalse($results[1]->can_register);
        $this->assertTrue($results[1]->can_transfer);
    }

    public function test_get_domain_info_parses_response(): void
    {
        $api = $this->createApiWithMockedResponses([
            $this->tokenResponse(),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'data' => [
                    'id' => 'domain_test123',
                    'domain' => 'example.com',
                    'status' => 'active',
                    'locked' => true,
                    'nameservers' => ['ns1.example.com', 'ns2.example.com'],
                    'contacts' => [
                        'registrant' => [
                            'name' => 'John Doe',
                            'email' => 'john@example.com',
                            'phone' => '+1.5551234567',
                            'address' => '123 Main St',
                            'city' => 'New York',
                            'state' => 'NY',
                            'postcode' => '10001',
                            'country_code' => 'US',
                        ],
                    ],
                    'created_at' => '2024-01-01T00:00:00Z',
                    'expires_at' => '2025-01-01T00:00:00Z',
                ],
            ])),
        ]);

        $result = $api->getDomainInfo('example.com', true);

        $this->assertEquals('domain_test123', $result['id']);
        $this->assertEquals('example.com', $result['domain']);
        $this->assertTrue($result['locked']);
        $this->assertNotNull($result['registrant']);
    }

    public function test_make_request_throws_on_error_response(): void
    {
        $api = $this->createApiWithMockedResponses([
            $this->tokenResponse(),
            new Response(404, ['Content-Type' => 'application/json'], json_encode([
                'message' => 'Domain not found',
            ])),
        ]);

        $this->expectException(\Upmind\ProvisionBase\Exception\ProvisionFunctionError::class);

        $api->makeRequest('GET', 'domains/nonexistent.com');
    }

    public function test_make_request_retries_on_token_expiry(): void
    {
        $api = $this->createApiWithMockedResponses([
            // Initial token
            $this->tokenResponse(),
            // First request gets 401
            new Response(401, ['Content-Type' => 'application/json'], json_encode([
                'message' => 'Token expired',
            ])),
            // Re-authentication
            $this->tokenResponse(),
            // Retry succeeds
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'data' => ['domain' => 'test.com'],
            ])),
        ]);

        $result = $api->makeRequest('GET', 'domains/test.com');

        $this->assertIsArray($result);
    }

    public function test_renew_domain(): void
    {
        $api = $this->createApiWithMockedResponses([
            $this->tokenResponse(),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'data' => [
                    'domain' => 'example.com',
                    'renewed_until' => '2026-01-01T00:00:00Z',
                ],
            ])),
        ]);

        $result = $api->renewDomain('example.com', 1);

        $this->assertIsArray($result);
    }

    public function test_get_domain_epp_code(): void
    {
        $api = $this->createApiWithMockedResponses([
            $this->tokenResponse(),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'data' => [
                    'auth_code' => 'ABC123XYZ',
                ],
            ])),
        ]);

        $eppCode = $api->getDomainEppCode('example.com');

        $this->assertEquals('ABC123XYZ', $eppCode);
    }

    public function test_set_domain_lock(): void
    {
        $api = $this->createApiWithMockedResponses([
            $this->tokenResponse(),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'data' => [
                    'domain' => 'example.com',
                    'transfer_lock' => true,
                ],
            ])),
        ]);

        $result = $api->setDomainLock('example.com', true);

        $this->assertIsArray($result);
    }

    public function test_set_domain_auto_renew(): void
    {
        $api = $this->createApiWithMockedResponses([
            $this->tokenResponse(),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'data' => [
                    'domain' => 'example.com',
                    'auto_renew' => true,
                ],
            ])),
        ]);

        $result = $api->setDomainAutoRenew('example.com', true);

        $this->assertIsArray($result);
    }
}
