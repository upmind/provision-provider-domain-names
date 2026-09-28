<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Tests\Unit\SynergyWholesale;

use Upmind\ProvisionProviders\DomainNames\Data\DisableDnssecParams;
use Upmind\ProvisionProviders\DomainNames\Data\Dnssec;
use Upmind\ProvisionProviders\DomainNames\Data\EnableDnssecParams;
use Upmind\ProvisionProviders\DomainNames\SynergyWholesale\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\SynergyWholesale\Helper\SynergyWholesaleApi;
use Upmind\ProvisionProviders\DomainNames\SynergyWholesale\Provider;
use Upmind\ProvisionProviders\DomainNames\Tests\TestCase;

class ProviderDnssecTest extends TestCase
{
    public function test_enable_dnssec_adds_missing_record(): void
    {
        $api = $this->createMock(SynergyWholesaleApi::class);
        $api->expects($this->once())
            ->method('listDnssecRecords')
            ->with('example.com')
            ->willReturn([]);
        $api->expects($this->once())
            ->method('addDnssecRecord')
            ->with('example.com', 12345, 13, 2, '0123456789ABCDEF')
            ->willReturn('174');
        $api->expects($this->once())
            ->method('getDomainInfo')
            ->with('example.com')
            ->willReturn($this->domainInfo($this->dnssecRecord()));

        $result = $this->createProvider($api)->enableDnssec($this->enableParams());

        $this->assertInstanceOf(Dnssec::class, $result->dnssec);
        $this->assertSame(12345, $result->dnssec->ds_key_tag);
    }

    public function test_enable_dnssec_is_idempotent_for_existing_record(): void
    {
        $api = $this->createMock(SynergyWholesaleApi::class);
        $api->expects($this->once())
            ->method('listDnssecRecords')
            ->with('example.com')
            ->willReturn([
                $this->providerDnssecRecord('174', [
                    'ds_digest' => '0123 4567 89ab cdef',
                ]),
            ]);
        $api->expects($this->never())->method('addDnssecRecord');
        $api->expects($this->once())
            ->method('getDomainInfo')
            ->with('example.com')
            ->willReturn($this->domainInfo($this->dnssecRecord()));

        $result = $this->createProvider($api)->enableDnssec($this->enableParams());

        $this->assertSame('DNSSEC record already exists', $result->getMessage());
    }

    public function test_enable_dnssec_replaces_existing_records(): void
    {
        $removedRecordIds = [];

        $api = $this->createMock(SynergyWholesaleApi::class);
        $api->expects($this->once())
            ->method('listDnssecRecords')
            ->with('example.com')
            ->willReturn([
                $this->providerDnssecRecord('174', [
                    'ds_key_tag' => 54321,
                ]),
                $this->providerDnssecRecord('175', [
                    'ds_algorithm' => 8,
                ]),
            ]);
        $api->expects($this->exactly(2))
            ->method('removeDnssecRecord')
            ->willReturnCallback(function (string $domainName, string $recordId) use (&$removedRecordIds): void {
                $this->assertSame('example.com', $domainName);
                $removedRecordIds[] = $recordId;
            });
        $api->expects($this->once())
            ->method('addDnssecRecord')
            ->with('example.com', 12345, 13, 2, '0123456789ABCDEF')
            ->willReturn('176');
        $api->expects($this->once())
            ->method('getDomainInfo')
            ->with('example.com')
            ->willReturn($this->domainInfo($this->dnssecRecord()));

        $result = $this->createProvider($api)->enableDnssec($this->enableParams());

        $this->assertSame(['174', '175'], $removedRecordIds);
        $this->assertSame('DNSSEC record updated successfully', $result->getMessage());
        $this->assertInstanceOf(Dnssec::class, $result->dnssec);
    }

    public function test_enable_dnssec_removes_extra_records_when_target_exists(): void
    {
        $api = $this->createMock(SynergyWholesaleApi::class);
        $api->expects($this->once())
            ->method('listDnssecRecords')
            ->with('example.com')
            ->willReturn([
                $this->providerDnssecRecord('174'),
                $this->providerDnssecRecord('175', [
                    'ds_key_tag' => 54321,
                ]),
            ]);
        $api->expects($this->never())->method('addDnssecRecord');
        $api->expects($this->once())
            ->method('removeDnssecRecord')
            ->with('example.com', '175');
        $api->expects($this->once())
            ->method('getDomainInfo')
            ->with('example.com')
            ->willReturn($this->domainInfo($this->dnssecRecord()));

        $result = $this->createProvider($api)->enableDnssec($this->enableParams());

        $this->assertSame('DNSSEC record updated successfully', $result->getMessage());
        $this->assertInstanceOf(Dnssec::class, $result->dnssec);
    }

    public function test_disable_dnssec_removes_every_record(): void
    {
        $records = [
            $this->providerDnssecRecord('174'),
            $this->providerDnssecRecord('175', [
                'ds_key_tag' => 54321,
            ]),
        ];
        $removedRecordIds = [];

        $api = $this->createMock(SynergyWholesaleApi::class);
        $api->expects($this->once())
            ->method('listDnssecRecords')
            ->with('example.com')
            ->willReturn($records);
        $api->expects($this->exactly(2))
            ->method('removeDnssecRecord')
            ->willReturnCallback(function (string $domainName, string $recordId) use (&$removedRecordIds): void {
                $this->assertSame('example.com', $domainName);
                $removedRecordIds[] = $recordId;
            });
        $api->expects($this->once())
            ->method('getDomainInfo')
            ->with('example.com')
            ->willReturn($this->domainInfo(null));

        $result = $this->createProvider($api)->disableDnssec(DisableDnssecParams::create([
            'sld' => 'example',
            'tld' => '.com',
        ]));

        $this->assertSame(['174', '175'], $removedRecordIds);
        $this->assertNull($result->dnssec);
    }

    private function createProvider(SynergyWholesaleApi $api): Provider
    {
        $configuration = Configuration::create([
            'reseller_id' => 'test-reseller-id',
            'api_key' => 'test-api-key',
        ]);

        return new class ($configuration, $api) extends Provider {
            private SynergyWholesaleApi $testApi;

            public function __construct(Configuration $configuration, SynergyWholesaleApi $api)
            {
                parent::__construct($configuration);
                $this->testApi = $api;
            }

            protected function api(): SynergyWholesaleApi
            {
                return $this->testApi;
            }
        };
    }

    private function enableParams(): EnableDnssecParams
    {
        return EnableDnssecParams::create([
            'sld' => 'example',
            'tld' => '.com',
            'ds_key_tag' => 12345,
            'ds_algorithm' => 13,
            'ds_digest_type' => 2,
            'ds_digest' => '0123456789ABCDEF',
        ]);
    }

    private function dnssecRecord(array $overrides = []): array
    {
        return array_merge([
            'ds_key_tag' => 12345,
            'ds_algorithm' => 13,
            'ds_digest_type' => 2,
            'ds_digest' => '0123456789ABCDEF',
        ], $overrides);
    }

    private function providerDnssecRecord(string $recordId, array $overrides = []): array
    {
        return [
            'uuid' => $recordId,
            'record' => Dnssec::create($this->dnssecRecord($overrides)),
        ];
    }

    private function domainInfo(?array $dnssec): array
    {
        return [
            'id' => 'domain-id',
            'domain' => 'example.com',
            'statuses' => ['OK'],
            'ns' => [],
            'glue_records' => [],
            'dnssec' => $dnssec,
            'created_at' => null,
            'updated_at' => null,
            'expires_at' => null,
        ];
    }
}
