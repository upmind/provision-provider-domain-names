<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Tests\Unit\Data;

use Upmind\ProvisionProviders\DomainNames\Data\DisableDnssecParams;
use Upmind\ProvisionProviders\DomainNames\Data\Dnssec;
use Upmind\ProvisionProviders\DomainNames\Data\DomainResult;
use Upmind\ProvisionProviders\DomainNames\Data\EnableDnssecParams;
use Upmind\ProvisionProviders\DomainNames\Tests\TestCase;

class DnssecTest extends TestCase
{
    public function test_every_supported_value_has_a_human_readable_name(): void
    {
        $this->assertSame(Dnssec::ALGORITHMS, array_keys(Dnssec::ALGORITHM_NAMES));
        $this->assertSame(Dnssec::DIGEST_TYPES, array_keys(Dnssec::DIGEST_TYPE_NAMES));
        $this->assertSame('ECDSA Curve P-256 with SHA-256', Dnssec::ALGORITHM_NAMES[13]);
        $this->assertSame('SHA-384', Dnssec::DIGEST_TYPE_NAMES[4]);
    }

    public function test_accepts_every_supported_algorithm(): void
    {
        foreach (Dnssec::ALGORITHMS as $algorithm) {
            $dnssec = Dnssec::create($this->validDnssecData([
                'ds_algorithm' => $algorithm,
            ]));

            $this->assertSame($algorithm, $dnssec->ds_algorithm);
        }
    }

    public function test_accepts_every_supported_digest_type(): void
    {
        foreach (Dnssec::DIGEST_TYPES as $digestType) {
            $dnssec = Dnssec::create($this->validDnssecData([
                'ds_digest_type' => $digestType,
            ]));

            $this->assertSame($digestType, $dnssec->ds_digest_type);
        }
    }

    public function test_rejects_unknown_algorithm_and_digest_type(): void
    {
        $errors = Dnssec::create($this->validDnssecData([
            'ds_algorithm' => 4,
            'ds_digest_type' => 7,
        ]))->errors();

        $this->assertArrayHasKey('ds_algorithm', $errors);
        $this->assertArrayHasKey('ds_digest_type', $errors);
    }

    public function test_enforces_key_tag_boundaries(): void
    {
        $this->assertSame(0, Dnssec::create($this->validDnssecData([
            'ds_key_tag' => 0,
        ]))->ds_key_tag);
        $this->assertSame(65534, Dnssec::create($this->validDnssecData([
            'ds_key_tag' => 65534,
        ]))->ds_key_tag);

        $this->assertArrayHasKey('ds_key_tag', Dnssec::create($this->validDnssecData([
            'ds_key_tag' => -1,
        ]))->errors());
        $this->assertArrayHasKey('ds_key_tag', Dnssec::create($this->validDnssecData([
            'ds_key_tag' => 65535,
        ]))->errors());
    }

    public function test_enable_params_include_domain_and_ds_record_validation(): void
    {
        $params = EnableDnssecParams::create(array_merge([
            'sld' => 'example',
            'tld' => '.com',
        ], $this->validDsRecordData()));

        $this->assertSame('example', $params->sld);
        $this->assertSame('.com', $params->tld);
        $this->assertSame(12345, $params->ds_key_tag);
    }

    public function test_disable_params_require_domain(): void
    {
        $this->assertSame([], DisableDnssecParams::create([
            'sld' => 'example',
            'tld' => '.com',
        ])->errors());

        $errors = DisableDnssecParams::create()->errors();
        $this->assertArrayHasKey('sld', $errors);
        $this->assertArrayHasKey('tld', $errors);
    }

    public function test_domain_result_defaults_dnssec_to_null(): void
    {
        $result = DomainResult::create($this->validDomainResultData());

        $this->assertSame([], $result->errors());
        $this->assertArrayHasKey('dnssec', $result->toArray());
        $this->assertNull($result->dnssec);
    }

    public function test_domain_result_casts_dnssec_record_to_data_set(): void
    {
        $result = DomainResult::create($this->validDomainResultData([
            'dnssec' => $this->validDnssecData(),
        ]));

        $this->assertInstanceOf(Dnssec::class, $result->dnssec);
        $this->assertSame(12345, $result->dnssec->ds_key_tag);
    }

    public function test_domain_result_rejects_multiple_dnssec_records(): void
    {
        $errors = DomainResult::create($this->validDomainResultData([
            'dnssec' => [
                $this->validDnssecData(),
                $this->validDnssecData([
                    'ds_key_tag' => 54321,
                ]),
            ],
        ]))->errors();

        $this->assertNotSame([], $errors);
    }

    private function validDnssecData(array $overrides = []): array
    {
        return $this->validDsRecordData($overrides);
    }

    private function validDsRecordData(array $overrides = []): array
    {
        return array_merge([
            'ds_key_tag' => 12345,
            'ds_algorithm' => 13,
            'ds_digest_type' => 2,
            'ds_digest' => '0123456789ABCDEF',
        ], $overrides);
    }

    private function validDomainResultData(array $overrides = []): array
    {
        return array_merge([
            'id' => 'domain-id',
            'domain' => 'example.com',
            'statuses' => [],
            'ns' => [],
            'created_at' => null,
            'updated_at' => null,
            'expires_at' => null,
        ], $overrides);
    }
}
