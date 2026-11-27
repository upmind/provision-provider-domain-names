<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Tests\Unit\OpenSRS;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;
use Upmind\ProvisionProviders\DomainNames\Data\RegisterDomainParams;
use Upmind\ProvisionProviders\DomainNames\Data\TransferParams;
use Upmind\ProvisionProviders\DomainNames\OpenSRS\Data\OpenSrsConfiguration;
use Upmind\ProvisionProviders\DomainNames\OpenSRS\Helper\OpenSrsApi;
use Upmind\ProvisionProviders\DomainNames\OpenSRS\Provider;
use Upmind\ProvisionProviders\DomainNames\Tests\TestCase;

/**
 * Validates the f_whois_privacy value sent to OpenSRS on register and transfer:
 * - true + TLD supports privacy => 1
 * - true + TLD does not support privacy => 0
 * - false => 0
 * - null (not provided) => empty, so the reseller account default applies
 */
class ProviderWhoisPrivacyTest extends TestCase
{
    private const SUPPORTED_TLD = 'com';
    private const UNSUPPORTED_TLD = 'ac';

    /**
     * @var array<int, array{request: \Psr\Http\Message\RequestInterface}> Captured HTTP requests
     */
    private array $requestHistory = [];

    /**
     * @return array<string, array{0: string, 1: bool|null, 2: string}>
     */
    public static function whoisPrivacyProvider(): array
    {
        return [
            'true on supported tld sends 1' => [self::SUPPORTED_TLD, true, '1'],
            'false on supported tld sends 0' => [self::SUPPORTED_TLD, false, '0'],
            'null on supported tld sends empty' => [self::SUPPORTED_TLD, null, ''],
            'true on unsupported tld sends 0' => [self::UNSUPPORTED_TLD, true, '0'],
            'false on unsupported tld sends 0' => [self::UNSUPPORTED_TLD, false, '0'],
            'null on unsupported tld sends empty' => [self::UNSUPPORTED_TLD, null, ''],
        ];
    }

    /**
     * @dataProvider whoisPrivacyProvider
     */
    #[DataProvider('whoisPrivacyProvider')]
    public function test_register_sends_expected_whois_privacy(string $tld, ?bool $whoisPrivacy, string $expected): void
    {
        $provider = $this->createProvider([
            new RuntimeException('Stop after SW_REGISTER request'),
        ]);

        $this->runIgnoringErrors(function () use ($provider, $tld, $whoisPrivacy): void {
            $provider->register(RegisterDomainParams::create(array_merge($this->domainParams($tld, $whoisPrivacy), [
                'renew_years' => 1,
                'registrant' => ['register' => $this->contact()],
                'billing' => ['register' => $this->contact()],
                'admin' => ['register' => $this->contact()],
                'tech' => ['register' => $this->contact()],
            ])));
        });

        $this->assertWhoisPrivacySent($expected);
    }

    /**
     * @dataProvider whoisPrivacyProvider
     */
    #[DataProvider('whoisPrivacyProvider')]
    public function test_transfer_sends_expected_whois_privacy(string $tld, ?bool $whoisPrivacy, string $expected): void
    {
        $provider = $this->createProvider([
            new Response(200, [], $this->noPendingTransfersResponse()), // get_transfers_in
            new RuntimeException('Domain not in account'), // GET all_info
            new RuntimeException('Stop after SW_REGISTER request'), // SW_REGISTER
        ]);

        $this->runIgnoringErrors(function () use ($provider, $tld, $whoisPrivacy): void {
            $provider->transfer(TransferParams::create(array_merge($this->domainParams($tld, $whoisPrivacy), [
                'renew_years' => 1,
                'epp_code' => 'test-epp-code',
                'registrant' => ['register' => $this->contact()],
            ])));
        });

        $this->assertWhoisPrivacySent($expected);
    }

    /**
     * @param array<int, Response|Throwable> $responses Queued responses/exceptions for the mocked client
     */
    private function createProvider(array $responses): Provider
    {
        $this->requestHistory = [];

        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $handlerStack->push(Middleware::history($this->requestHistory));

        $configuration = OpenSrsConfiguration::create([
            'username' => 'test-user',
            'key' => 'test-api-key',
            'sandbox' => true,
        ]);

        $api = new OpenSrsApi(new Client(['handler' => $handlerStack]), $configuration);

        return new class ($configuration, $api) extends Provider {
            /**
             * @var OpenSrsApi
             */
            private $testApi;

            public function __construct(OpenSrsConfiguration $configuration, OpenSrsApi $api)
            {
                parent::__construct($configuration);
                $this->testApi = $api;
            }

            protected function api(): OpenSrsApi
            {
                return $this->testApi;
            }
        };
    }

    /**
     * Run the provider function; the mocked client deliberately fails after the
     * SW_REGISTER request so only the outgoing request is under test.
     */
    private function runIgnoringErrors(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            // expected
        }
    }

    private function assertWhoisPrivacySent(string $expected): void
    {
        $body = $this->findSwRegisterRequestBody();

        $this->assertSame(
            1,
            preg_match('#<item key="f_whois_privacy">([^<]*)</item>#', $body, $matches),
            'f_whois_privacy attribute not found in SW_REGISTER request'
        );
        $this->assertSame($expected, $matches[1]);
    }

    private function findSwRegisterRequestBody(): string
    {
        foreach ($this->requestHistory as $transaction) {
            $body = (string) $transaction['request']->getBody();

            if (strpos($body, '<item key="action">SW_REGISTER</item>') !== false) {
                return $body;
            }
        }

        $this->fail('SW_REGISTER request was not sent');
    }

    /**
     * @return array<string, mixed>
     */
    private function domainParams(string $tld, ?bool $whoisPrivacy): array
    {
        $params = [
            'sld' => 'example',
            'tld' => $tld,
        ];

        if ($whoisPrivacy !== null) {
            $params['whois_privacy'] = $whoisPrivacy;
        }

        return $params;
    }

    /**
     * @return array<string, string>
     */
    private function contact(): array
    {
        return [
            'name' => 'John Doe',
            'organisation' => 'Example Ltd',
            'email' => 'john@example.com',
            'phone' => '+442071234567',
            'address1' => '1 Example Street',
            'city' => 'London',
            'postcode' => 'SW1A 1AA',
            'country_code' => 'GB',
        ];
    }

    private function noPendingTransfersResponse(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="no" ?>'
            . '<!DOCTYPE OPS_envelope SYSTEM "ops.dtd">'
            . '<OPS_envelope><header><version>0.9</version></header><body><data_block><dt_assoc>'
            . '<item key="is_success">1</item>'
            . '<item key="response_code">200</item>'
            . '<item key="response_text">Query successful</item>'
            . '<item key="attributes"><dt_assoc><item key="transfers"><dt_array></dt_array></item></dt_assoc></item>'
            . '</dt_assoc></data_block></body></OPS_envelope>';
    }
}
