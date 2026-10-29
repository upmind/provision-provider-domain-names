<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Tests\Unit\NameSilo;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionProviders\DomainNames\Data\DacDomain;
use Upmind\ProvisionProviders\DomainNames\Data\DacParams;
use Upmind\ProvisionProviders\DomainNames\NameSilo\Data\NameSiloConfiguration;
use Upmind\ProvisionProviders\DomainNames\NameSilo\Provider;
use Upmind\ProvisionProviders\DomainNames\Tests\TestCase;

class ProviderDomainAvailabilityCheckTest extends TestCase
{
    private const SAMPLE_DIR = __DIR__ . '/../../data/namesilo';

    /**
     * @var array History of requests made (for request inspection)
     */
    protected array $requestHistory = [];

    public function test_domain_availability_check_maps_sample_responses(): void
    {
        $provider = $this->createProviderWithMockedResponses(
            $this->sampleResponse('domain-registration-availability-response.xml'),
            $this->sampleResponse('domain-transfer-availability-response.xml')
        );

        $result = $provider->domainAvailabilityCheck($this->dacParams('namesilo', ['com', 'net', 'org', 'asia', 'eu']));

        $domains = $this->keyByDomain($result->domains);

        $this->assertSame(
            ['namesilo.com', 'namesilo.net', 'namesilo.org', 'namesilo.asia', 'namesilo.eu'],
            array_keys($domains)
        );

        $this->assertDacDomain($domains['namesilo.com'], 'com', true, false, false, 'Domain is available to register');
        $this->assertDacDomain($domains['namesilo.org'], 'org', true, false, false, 'Domain is available to register');
        $this->assertDacDomain(
            $domains['namesilo.net'],
            'net',
            false,
            true,
            false,
            'Domain is registered and available to transfer'
        );
        $this->assertDacDomain(
            $domains['namesilo.eu'],
            'eu',
            false,
            true,
            true,
            'Premium domain is registered and available to transfer'
        );
        $this->assertDacDomain(
            $domains['namesilo.asia'],
            'asia',
            false,
            false,
            false,
            'Sorry, we do not currently support the .ASIA extension/TLD'
        );
    }

    public function test_domain_availability_check_sends_normalized_unique_domains_to_both_endpoints(): void
    {
        $provider = $this->createProviderWithMockedResponses(
            $this->sampleResponse('domain-registration-availability-response.xml'),
            $this->sampleResponse('domain-transfer-availability-response.xml')
        );

        $result = $provider->domainAvailabilityCheck($this->dacParams('NameSilo', ['com', '.COM', 'net']));

        $this->assertCount(2, $result->domains);
        $this->assertCount(2, $this->requestHistory);

        $paths = [];

        foreach ($this->requestHistory as $transaction) {
            /** @var RequestInterface $request */
            $request = $transaction['request'];
            parse_str($request->getUri()->getQuery(), $query);

            $paths[] = $request->getUri()->getPath();

            $this->assertSame('GET', $request->getMethod());
            $this->assertSame('namesilo.com,namesilo.net', $query['domains']);
            $this->assertSame('xml', $query['type']);
            $this->assertSame('1', $query['version']);
            $this->assertSame('test_api_key', $query['key']);
        }

        sort($paths);

        $this->assertSame(['/api/checkRegisterAvailability', '/api/checkTransferAvailability'], $paths);
    }

    public function test_domain_availability_check_handles_premium_registration_and_missing_domains(): void
    {
        $provider = $this->createProviderWithMockedResponses(
            $this->replyXml(
                'checkRegisterAvailability',
                '<available><domain price="2500.00" premium="1" duration="1">Example.IO</domain></available>'
            ),
            $this->replyXml('checkTransferAvailability', '')
        );

        $result = $provider->domainAvailabilityCheck($this->dacParams('example', ['io', 'xyz']));

        $domains = $this->keyByDomain($result->domains);

        $this->assertDacDomain(
            $domains['example.io'],
            'io',
            true,
            false,
            true,
            'Premium domain is available to register'
        );
        $this->assertDacDomain(
            $domains['example.xyz'],
            'xyz',
            false,
            false,
            false,
            'Domain availability could not be determined'
        );
    }

    public function test_domain_availability_check_handles_invalid_and_unavailable_domains(): void
    {
        $provider = $this->createProviderWithMockedResponses(
            $this->replyXml(
                'checkRegisterAvailability',
                '<unavailable><domain>example.com</domain></unavailable>'
                . '<invalid><domain>example.zzz</domain></invalid>'
            ),
            $this->replyXml('checkTransferAvailability', '<unavailable><domain>example.com</domain></unavailable>')
        );

        $result = $provider->domainAvailabilityCheck($this->dacParams('example', ['com', 'zzz']));

        $domains = $this->keyByDomain($result->domains);

        $this->assertDacDomain(
            $domains['example.com'],
            'com',
            false,
            false,
            false,
            'Domain is not available to register or transfer'
        );
        $this->assertDacDomain(
            $domains['example.zzz'],
            'zzz',
            false,
            false,
            false,
            'Domain name or TLD is invalid or not supported'
        );
    }

    public function test_domain_availability_check_prefers_registration_when_also_available_to_transfer(): void
    {
        $provider = $this->createProviderWithMockedResponses(
            $this->replyXml(
                'checkRegisterAvailability',
                '<available><domain price="9.99" premium="0" duration="1">example.com</domain></available>'
            ),
            $this->replyXml(
                'checkTransferAvailability',
                '<available><domain price="9999.99" premium="1">example.com</domain></available>'
            )
        );

        $result = $provider->domainAvailabilityCheck($this->dacParams('example', ['com']));

        $domains = $this->keyByDomain($result->domains);

        $this->assertDacDomain(
            $domains['example.com'],
            'com',
            true,
            false,
            false,
            'Domain is available to register'
        );
    }

    public function test_domain_availability_check_throws_on_api_error(): void
    {
        $provider = $this->createProviderWithMockedResponses(
            '<namesilo><request><operation>checkRegisterAvailability</operation><ip>55.555.55.55</ip></request>'
            . '<reply><code>110</code><detail>Invalid API Key</detail></reply></namesilo>',
            $this->sampleResponse('domain-transfer-availability-response.xml')
        );

        $this->expectException(ProvisionFunctionError::class);
        $this->expectExceptionMessage('Provider API Error: Invalid API Key');

        $provider->domainAvailabilityCheck($this->dacParams('namesilo', ['com']));
    }

    /**
     * Create a NameSilo provider whose HTTP client returns the given XML bodies, matched by API operation path.
     */
    protected function createProviderWithMockedResponses(string $registerXml, string $transferXml): Provider
    {
        $this->requestHistory = [];

        $respond = function (RequestInterface $request) use ($registerXml, $transferXml): Response {
            $path = $request->getUri()->getPath();

            if ($path === '/api/checkRegisterAvailability') {
                return new Response(200, [], $registerXml);
            }

            if ($path === '/api/checkTransferAvailability') {
                return new Response(200, [], $transferXml);
            }

            $this->fail(sprintf('Unexpected request path %s', $path));
        };

        $handlerStack = HandlerStack::create(new MockHandler([$respond, $respond]));
        $handlerStack->push(Middleware::history($this->requestHistory));

        $client = new Client([
            'base_uri' => 'https://www.namesilo.com/api/',
            'handler' => $handlerStack,
        ]);

        return new class (new NameSiloConfiguration(['api_key' => 'test_api_key']), $client) extends Provider {
            public function __construct(NameSiloConfiguration $configuration, Client $client)
            {
                parent::__construct($configuration);
                $this->client = $client;
            }
        };
    }

    /**
     * @param string[] $tlds
     */
    protected function dacParams(string $sld, array $tlds): DacParams
    {
        return new DacParams([
            'sld' => $sld,
            'tlds' => $tlds,
        ]);
    }

    protected function sampleResponse(string $fileName): string
    {
        return file_get_contents(self::SAMPLE_DIR . '/' . $fileName);
    }

    protected function replyXml(string $operation, string $replyBody): string
    {
        return sprintf(
            '<namesilo><request><operation>%s</operation><ip>55.555.55.55</ip></request>'
            . '<reply><code>300</code><detail>success</detail>%s</reply></namesilo>',
            $operation,
            $replyBody
        );
    }

    /**
     * @param DacDomain[] $dacDomains
     *
     * @return array<string, DacDomain>
     */
    protected function keyByDomain(array $dacDomains): array
    {
        $domains = [];

        foreach ($dacDomains as $dacDomain) {
            $domains[$dacDomain->domain] = $dacDomain;
        }

        return $domains;
    }

    protected function assertDacDomain(
        DacDomain $dacDomain,
        string $tld,
        bool $canRegister,
        bool $canTransfer,
        bool $isPremium,
        string $description
    ): void {
        $this->assertSame($tld, $dacDomain->tld);
        $this->assertSame($canRegister, $dacDomain->can_register);
        $this->assertSame($canTransfer, $dacDomain->can_transfer);
        $this->assertSame($isPremium, $dacDomain->is_premium);
        $this->assertSame($description, $dacDomain->description);
    }
}
