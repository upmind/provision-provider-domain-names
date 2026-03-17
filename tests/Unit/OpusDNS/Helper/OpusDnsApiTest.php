<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Tests\Unit\OpusDNS\Helper;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Log\NullLogger;
use Upmind\ProvisionProviders\DomainNames\OpusDNS\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\OpusDNS\Helper\OpusDnsApi;
use Upmind\ProvisionProviders\DomainNames\Tests\TestCase;
use Upmind\ProvisionProviders\DomainNames\Tests\Unit\OpusDNS\Mocks\OpusDnsResponseFactory;

class OpusDnsApiTest extends TestCase
{
    /**
     * @var array History of requests made (for request inspection)
     */
    protected array $requestHistory = [];

    /**
     * Create an OpusDnsApi with a mocked Guzzle client.
     *
     * @param Response[] $responses
     */
    protected function createApiWithMockedResponses(array $responses): OpusDnsApi
    {
        $this->requestHistory = [];

        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);

        // Add history middleware to capture requests
        $history = Middleware::history($this->requestHistory);
        $handlerStack->push($history);

        $httpClient = new Client(['handler' => $handlerStack]);

        $config = new Configuration([
            'client_id' => 'test_client_id',
            'client_secret' => 'test_client_secret',
            'sandbox' => true,
        ]);

        return new OpusDnsApi($config, new NullLogger(), $httpClient);
    }

    /**
     * Get the last request body as decoded JSON.
     */
    protected function getLastRequestBody(): ?array
    {
        if (empty($this->requestHistory)) {
            return null;
        }

        $lastTransaction = end($this->requestHistory);
        /** @var RequestInterface $request */
        $request = $lastTransaction['request'];
        $body = (string) $request->getBody();

        return json_decode($body, true);
    }

    /**
     * Get a specific request from history (0-indexed).
     */
    protected function getRequestAt(int $index): ?RequestInterface
    {
        return $this->requestHistory[$index]['request'] ?? null;
    }

    /**
     * Get request body at specific index.
     */
    protected function getRequestBodyAt(int $index): ?array
    {
        $request = $this->getRequestAt($index);
        if (!$request) {
            return null;
        }

        $body = (string) $request->getBody();
        return json_decode($body, true);
    }

    /**
     * Create a mock token response.
     */
    protected function tokenResponse(): Response
    {
        return OpusDnsResponseFactory::tokenResponse();
    }

    // =========================================================================
    // Authentication Tests
    // =========================================================================

    public function test_authenticate_obtains_bearer_token(): void
    {
        $api = $this->createApiWithMockedResponses([
            $this->tokenResponse(),
            OpusDnsResponseFactory::jsonResponse(['data' => ['domain' => 'test.com']]),
        ]);

        $result = $api->makeRequest('GET', 'domains/test.com');

        $this->assertIsArray($result);
    }

    public function test_authenticate_caches_token(): void
    {
        $api = $this->createApiWithMockedResponses([
            $this->tokenResponse(),
            OpusDnsResponseFactory::jsonResponse(['data' => ['domain' => 'test.com']]),
            OpusDnsResponseFactory::jsonResponse(['data' => ['domain' => 'test2.com']]),
        ]);

        $api->makeRequest('GET', 'domains/test.com');
        $result = $api->makeRequest('GET', 'domains/test2.com');

        $this->assertIsArray($result);
        // Should be 3 requests: token + 2 domain requests
        $this->assertCount(3, $this->requestHistory);
    }

    public function test_make_request_retries_on_token_expiry(): void
    {
        $api = $this->createApiWithMockedResponses([
            $this->tokenResponse(),
            new Response(401, ['Content-Type' => 'application/json'], json_encode([
                'message' => 'Token expired',
            ])),
            $this->tokenResponse(),
            OpusDnsResponseFactory::jsonResponse(['data' => ['domain' => 'test.com']]),
        ]);

        $result = $api->makeRequest('GET', 'domains/test.com');

        $this->assertIsArray($result);
    }

    // =========================================================================
    // Domain Check Tests
    // =========================================================================

    public function test_check_domains_returns_dac_domains(): void
    {
        $api = $this->createApiWithMockedResponses([
            $this->tokenResponse(),
            OpusDnsResponseFactory::jsonResponse(
                OpusDnsResponseFactory::domainCheckResponse([
                    'example.com' => true,
                    'example.net' => false,
                ])
            ),
        ]);

        $results = $api->checkDomains(['example.com', 'example.net']);

        $this->assertCount(2, $results);
        $this->assertTrue($results[0]->can_register);
        $this->assertFalse($results[0]->can_transfer);
        $this->assertFalse($results[1]->can_register);
        $this->assertTrue($results[1]->can_transfer);
    }

    public function test_check_domains_uses_comma_separated_format(): void
    {
        $api = $this->createApiWithMockedResponses([
            $this->tokenResponse(),
            OpusDnsResponseFactory::jsonResponse(
                OpusDnsResponseFactory::domainCheckResponse([
                    'example.com' => true,
                    'example.net' => false,
                ])
            ),
        ]);

        $api->checkDomains(['example.com', 'example.net']);

        // Verify the query string uses comma-separated format, not array indices
        $request = $this->getRequestAt(1);
        $query = $request->getUri()->getQuery();

        // Should contain comma-separated domains
        $this->assertStringContainsString('domains=example.com%2Cexample.net', $query);
        // Should NOT contain PHP-style array indices
        $this->assertStringNotContainsString('domains%5B0%5D', $query); // domains[0]
        $this->assertStringNotContainsString('domains%5B1%5D', $query); // domains[1]
    }

    // =========================================================================
    // Domain Info Tests - Using Real API Response Format
    // =========================================================================

    public function test_get_domain_info_parses_api_response_format(): void
    {
        $domainResponse = OpusDnsResponseFactory::domainResponse([
            'name' => 'example.com',
            'transfer_lock' => true,
            'expires_on' => '2025-06-15T10:30:00Z',
        ]);

        $contactResponse = OpusDnsResponseFactory::contactResponse([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
        ]);

        $api = $this->createApiWithMockedResponses([
            $this->tokenResponse(),
            OpusDnsResponseFactory::jsonResponse($domainResponse),
            // For contact lookups
            OpusDnsResponseFactory::jsonResponse($contactResponse),
            OpusDnsResponseFactory::jsonResponse($contactResponse),
            OpusDnsResponseFactory::jsonResponse($contactResponse),
            OpusDnsResponseFactory::jsonResponse($contactResponse),
            // For glue records
            OpusDnsResponseFactory::jsonResponse(['data' => []]),
        ]);

        $result = $api->getDomainInfo('example.com');

        $this->assertEquals('example.com', $result['domain']);
        $this->assertTrue($result['locked']);
        $this->assertNotNull($result['expires_at']);
    }

    public function test_get_domain_info_parses_contacts_array_format(): void
    {
        // API returns contacts as array with contact_type field
        // When fetching contacts by ID, the API returns full contact details
        $contactId = OpusDnsResponseFactory::typeId('contact');
        $domainResponse = OpusDnsResponseFactory::domainResponse([
            'name' => 'example.com',
            'contacts' => [
                ['contact_id' => $contactId, 'contact_type' => 'registrant'],
                ['contact_id' => $contactId, 'contact_type' => 'admin'],
            ],
        ]);

        $contactResponse = OpusDnsResponseFactory::contactResponse([
            'contact_id' => $contactId,
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane@example.com',
        ]);

        $api = $this->createApiWithMockedResponses([
            $this->tokenResponse(),
            OpusDnsResponseFactory::jsonResponse($domainResponse),
            // Each contact_id in the array triggers a GET /contacts/{id} call
            OpusDnsResponseFactory::jsonResponse($contactResponse), // registrant lookup
            OpusDnsResponseFactory::jsonResponse($contactResponse), // admin lookup
            OpusDnsResponseFactory::jsonResponse(['data' => []]), // glue records
        ]);

        $result = $api->getDomainInfo('example.com');

        // Verify contacts were parsed from the array format
        $this->assertNotNull($result['registrant'], 'Registrant contact should be parsed from array format');
        $this->assertEquals('jane@example.com', $result['registrant']->email);
        $this->assertNotNull($result['admin'], 'Admin contact should be parsed from array format');
    }

    public function test_get_domain_info_minimal_skips_extra_calls(): void
    {
        $domainResponse = OpusDnsResponseFactory::domainResponse(['name' => 'example.com']);

        $api = $this->createApiWithMockedResponses([
            $this->tokenResponse(),
            OpusDnsResponseFactory::jsonResponse($domainResponse),
        ]);

        $result = $api->getDomainInfo('example.com', true);

        $this->assertEquals('example.com', $result['domain']);
        // Should only have 2 requests: token + domain info (no glue record fetch)
        $this->assertCount(2, $this->requestHistory);
    }

    // =========================================================================
    // Domain Renewal Tests
    // =========================================================================

    public function test_renew_domain_sends_period_object(): void
    {
        $api = $this->createApiWithMockedResponses([
            $this->tokenResponse(),
            OpusDnsResponseFactory::jsonResponse(
                OpusDnsResponseFactory::domainRenewResponse('example.com', 2)
            ),
        ]);

        $result = $api->renewDomain('example.com', 2);

        // Verify the request body contains period as object
        $requestBody = $this->getRequestBodyAt(1);
        $this->assertArrayHasKey('period', $requestBody);
        $this->assertEquals(['unit' => 'y', 'value' => 2], $requestBody['period']);
    }

    public function test_renew_domain_includes_current_expiry(): void
    {
        $api = $this->createApiWithMockedResponses([
            $this->tokenResponse(),
            OpusDnsResponseFactory::jsonResponse(
                OpusDnsResponseFactory::domainRenewResponse('example.com', 1)
            ),
        ]);

        $result = $api->renewDomain('example.com', 1, '2025-01-15T10:30:00Z');

        $requestBody = $this->getRequestBodyAt(1);
        $this->assertArrayHasKey('current_expiry_date', $requestBody);
        $this->assertEquals('2025-01-15T10:30:00Z', $requestBody['current_expiry_date']);
    }

    // =========================================================================
    // Domain Transfer Tests
    // =========================================================================

    public function test_transfer_domain_uses_name_field(): void
    {
        $api = $this->createApiWithMockedResponses([
            $this->tokenResponse(),
            // Contact creation
            OpusDnsResponseFactory::jsonResponse(
                OpusDnsResponseFactory::contactResponse()
            ),
            // Transfer response
            OpusDnsResponseFactory::jsonResponse(
                OpusDnsResponseFactory::domainTransferResponse('example.com')
            ),
        ]);

        $registrant = new \Upmind\ProvisionProviders\DomainNames\Data\ContactParams([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'phone' => '+1.5551234567',
            'address1' => '123 Main St',
            'city' => 'New York',
            'postcode' => '10001',
            'country_code' => 'US',
        ]);

        $result = $api->transferDomain('example.com', 'ABC123', ['registrant' => $registrant]);

        // Verify request uses 'name' not 'domain'
        $requestBody = $this->getRequestBodyAt(2);
        $this->assertArrayHasKey('name', $requestBody);
        $this->assertArrayNotHasKey('domain', $requestBody);
        $this->assertEquals('example.com', $requestBody['name']);
    }

    public function test_transfer_domain_includes_renewal_mode(): void
    {
        $api = $this->createApiWithMockedResponses([
            $this->tokenResponse(),
            OpusDnsResponseFactory::jsonResponse(
                OpusDnsResponseFactory::contactResponse()
            ),
            OpusDnsResponseFactory::jsonResponse(
                OpusDnsResponseFactory::domainTransferResponse('example.com')
            ),
        ]);

        $registrant = new \Upmind\ProvisionProviders\DomainNames\Data\ContactParams([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'phone' => '+1.5551234567',
            'address1' => '123 Main St',
            'city' => 'New York',
            'postcode' => '10001',
            'country_code' => 'US',
        ]);

        $result = $api->transferDomain('example.com', 'ABC123', ['registrant' => $registrant]);

        $requestBody = $this->getRequestBodyAt(2);
        $this->assertArrayHasKey('renewal_mode', $requestBody);
        $this->assertEquals('renew', $requestBody['renewal_mode']);
    }

    // =========================================================================
    // Auto-Renew Tests
    // =========================================================================

    public function test_set_auto_renew_uses_renewal_mode_enum(): void
    {
        $api = $this->createApiWithMockedResponses([
            $this->tokenResponse(),
            OpusDnsResponseFactory::jsonResponse(
                OpusDnsResponseFactory::domainResponse(['renewal_mode' => 'renew'])
            ),
        ]);

        $result = $api->setDomainAutoRenew('example.com', true);

        // Verify request uses 'renewal_mode' not 'auto_renew'
        $requestBody = $this->getRequestBodyAt(1);
        $this->assertArrayHasKey('renewal_mode', $requestBody);
        $this->assertArrayNotHasKey('auto_renew', $requestBody);
        $this->assertEquals('renew', $requestBody['renewal_mode']);
    }

    public function test_set_auto_renew_disabled_uses_expire(): void
    {
        $api = $this->createApiWithMockedResponses([
            $this->tokenResponse(),
            OpusDnsResponseFactory::jsonResponse(
                OpusDnsResponseFactory::domainResponse(['renewal_mode' => 'expire'])
            ),
        ]);

        $result = $api->setDomainAutoRenew('example.com', false);

        $requestBody = $this->getRequestBodyAt(1);
        $this->assertEquals('expire', $requestBody['renewal_mode']);
    }

    // =========================================================================
    // Nameserver Tests
    // =========================================================================

    public function test_update_nameservers_uses_object_format(): void
    {
        $domainResponse = OpusDnsResponseFactory::domainResponse([
            'nameservers' => OpusDnsResponseFactory::nameserversArray(['ns1.new.com', 'ns2.new.com']),
        ]);

        $api = $this->createApiWithMockedResponses([
            $this->tokenResponse(),
            OpusDnsResponseFactory::jsonResponse($domainResponse), // PATCH response
            OpusDnsResponseFactory::jsonResponse($domainResponse), // GET for verification
        ]);

        $result = $api->updateDomainNameservers('example.com', ['ns1.new.com', 'ns2.new.com']);

        // Verify request uses array of objects with 'hostname' key
        $requestBody = $this->getRequestBodyAt(1);
        $this->assertArrayHasKey('nameservers', $requestBody);
        $this->assertEquals([
            ['hostname' => 'ns1.new.com'],
            ['hostname' => 'ns2.new.com'],
        ], $requestBody['nameservers']);
    }

    // =========================================================================
    // Lock Tests
    // =========================================================================

    public function test_set_domain_lock(): void
    {
        $api = $this->createApiWithMockedResponses([
            $this->tokenResponse(),
            OpusDnsResponseFactory::jsonResponse(
                OpusDnsResponseFactory::domainResponse(['transfer_lock' => true])
            ),
        ]);

        $result = $api->setDomainLock('example.com', true);

        $requestBody = $this->getRequestBodyAt(1);
        $this->assertArrayHasKey('transfer_lock', $requestBody);
        $this->assertTrue($requestBody['transfer_lock']);
    }

    // =========================================================================
    // EPP Code Tests
    // =========================================================================

    public function test_get_domain_epp_code(): void
    {
        $api = $this->createApiWithMockedResponses([
            $this->tokenResponse(),
            OpusDnsResponseFactory::jsonResponse([
                'data' => ['auth_code' => 'ABC123XYZ'],
            ]),
        ]);

        $eppCode = $api->getDomainEppCode('example.com');

        $this->assertEquals('ABC123XYZ', $eppCode);
    }

    // =========================================================================
    // Error Handling Tests
    // =========================================================================

    public function test_make_request_throws_on_error_response(): void
    {
        $api = $this->createApiWithMockedResponses([
            $this->tokenResponse(),
            OpusDnsResponseFactory::jsonResponse(
                OpusDnsResponseFactory::domainNotFoundResponse('nonexistent.com'),
                404
            ),
        ]);

        $this->expectException(\Upmind\ProvisionBase\Exception\ProvisionFunctionError::class);
        $this->expectExceptionMessage('Domain name does not exist');

        $api->makeRequest('GET', 'domains/nonexistent.com');
    }

    public function test_validation_error_includes_field_details(): void
    {
        $api = $this->createApiWithMockedResponses([
            $this->tokenResponse(),
            OpusDnsResponseFactory::jsonResponse(
                OpusDnsResponseFactory::validationErrorResponse([
                    'name' => 'field required',
                    'contacts.registrant' => 'invalid contact id',
                ]),
                422
            ),
        ]);

        $this->expectException(\Upmind\ProvisionBase\Exception\ProvisionFunctionError::class);
        $this->expectExceptionMessage('Validation failed');

        $api->makeRequest('POST', 'domains');
    }
}
