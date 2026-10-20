<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Tests\Unit\SynergyWholesale\Helper;

use Psr\Log\NullLogger;
use SoapClient;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionProviders\DomainNames\Data\Dnssec;
use Upmind\ProvisionProviders\DomainNames\SynergyWholesale\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\SynergyWholesale\Helper\SynergyWholesaleApi;
use Upmind\ProvisionProviders\DomainNames\Tests\TestCase;

class SynergyWholesaleApiDnssecTest extends TestCase
{
    public function test_adds_dnssec_record_and_returns_provider_uuid(): void
    {
        $client = $this->createMock(SoapClient::class);
        $client->expects($this->once())
            ->method('__soapCall')
            ->with(
                'DNSSECAddDS',
                $this->callback(function (array $arguments): bool {
                    return $arguments === [[
                        'domainName' => 'example.com',
                        'algorithm' => 13,
                        'digestType' => 2,
                        'digest' => '0123456789ABCDEF',
                        'keyTag' => 12345,
                        'apiKey' => 'test-api-key',
                        'resellerID' => 'test-reseller-id',
                    ]];
                })
            )
            ->willReturn([
                'status' => 'OK',
                'UUID' => 3969020,
            ]);

        $recordId = $this->createApi($client)->addDnssecRecord(
            'example.com',
            12345,
            13,
            2,
            '0123456789ABCDEF'
        );

        $this->assertSame('3969020', $recordId);
    }

    public function test_lists_multiple_dnssec_records_using_provider_uuids(): void
    {
        $client = $this->createMock(SoapClient::class);
        $client->expects($this->once())
            ->method('__soapCall')
            ->with(
                'DNSSECListDS',
                $this->callback(function (array $arguments): bool {
                    return $arguments === [[
                        'domainName' => 'example.com',
                        'apiKey' => 'test-api-key',
                        'resellerID' => 'test-reseller-id',
                    ]];
                })
            )
            ->willReturn([
                'status' => 'OK',
                'DSData' => [
                    174 => [
                        'keyTag' => 5214,
                        'algorithm' => 5,
                        'digest' => '4761674BFF957211D129B0DFE9410AF753559D4B',
                        'digestType' => 1,
                    ],
                    175 => [
                        'keyTag' => 5215,
                        'algorithm' => 13,
                        'digest' => '0123456789ABCDEF',
                        'digestType' => 2,
                    ],
                ],
            ]);

        $records = $this->createApi($client)->listDnssecRecords('example.com');

        $this->assertCount(2, $records);
        $this->assertSame('174', $records[0]['uuid']);
        $this->assertInstanceOf(Dnssec::class, $records[0]['record']);
        $this->assertSame(5214, $records[0]['record']->ds_key_tag);
        $this->assertSame('175', $records[1]['uuid']);
        $this->assertInstanceOf(Dnssec::class, $records[1]['record']);
        $this->assertSame(13, $records[1]['record']->ds_algorithm);
    }

    public function test_removes_dnssec_record_by_provider_uuid(): void
    {
        $client = $this->createMock(SoapClient::class);
        $client->expects($this->once())
            ->method('__soapCall')
            ->with(
                'DNSSECRemoveDS',
                $this->callback(function (array $arguments): bool {
                    return $arguments === [[
                        'domainName' => 'example.com',
                        'UUID' => '174',
                        'apiKey' => 'test-api-key',
                        'resellerID' => 'test-reseller-id',
                    ]];
                })
            )
            ->willReturn(['status' => 'OK']);

        $this->createApi($client)->removeDnssecRecord('example.com', '174');
    }

    public function test_domain_info_maps_embedded_dnssec_data(): void
    {
        $client = $this->createMock(SoapClient::class);
        $client->expects($this->exactly(2))
            ->method('__soapCall')
            ->willReturnCallback(function (string $command): array {
                if ($command === 'bulkDomainInfo') {
                    return [
                        'status' => 'OK',
                        'domainList' => [[
                            'status' => 'OK',
                            'domain_status' => 'ok',
                            'domainRoid' => 'domain-id',
                            'domainName' => 'example.com',
                            'nameServers' => ['ns1.example.com', 'ns2.example.com'],
                            'createdDate' => '2025-01-01 00:00:00',
                            'domain_expiry' => '2026-01-01 00:00:00',
                            'DSData' => [
                                'keyTag' => 5214,
                                'Algoirthm' => 5,
                                'Digest' => '4761674BFF957211D129B0DFE9410AF753559D4B',
                                'DigestType' => 1,
                                'UUID' => 174,
                            ],
                        ]],
                    ];
                }

                return [
                    'status' => 'OK',
                    'hosts' => [],
                ];
            });

        $domainInfo = $this->createApi($client)->getDomainInfo('example.com');

        $this->assertInstanceOf(Dnssec::class, $domainInfo['dnssec']);
        $this->assertSame(5, $domainInfo['dnssec']->ds_algorithm);
    }

    public function test_rejects_unsupported_dnssec_algorithm_before_request(): void
    {
        $client = $this->createMock(SoapClient::class);
        $client->expects($this->never())->method('__soapCall');

        $this->expectException(ProvisionFunctionError::class);
        $this->expectExceptionMessage('Synergy Wholesale does not support DNSSEC algorithm 15 (Ed25519)');

        $this->createApi($client)->addDnssecRecord(
            'example.com',
            12345,
            15,
            2,
            '0123456789ABCDEF'
        );
    }

    public function test_rejects_unsupported_dnssec_digest_type_before_request(): void
    {
        $client = $this->createMock(SoapClient::class);
        $client->expects($this->never())->method('__soapCall');

        $this->expectException(ProvisionFunctionError::class);
        $this->expectExceptionMessage(
            'Synergy Wholesale does not support DNSSEC digest type 5 (GOST R 34.11-2012)'
        );

        $this->createApi($client)->addDnssecRecord(
            'example.com',
            12345,
            13,
            5,
            '0123456789ABCDEF'
        );
    }

    private function createApi(SoapClient $client): SynergyWholesaleApi
    {
        return new SynergyWholesaleApi(
            $client,
            Configuration::create([
                'reseller_id' => 'test-reseller-id',
                'api_key' => 'test-api-key',
            ]),
            new NullLogger()
        );
    }
}
