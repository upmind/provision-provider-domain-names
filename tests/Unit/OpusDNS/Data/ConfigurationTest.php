<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Tests\Unit\OpusDNS\Data;

use Upmind\ProvisionProviders\DomainNames\OpusDNS\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\Tests\TestCase;

class ConfigurationTest extends TestCase
{
    public function test_valid_configuration_passes_validation(): void
    {
        $config = new Configuration([
            'client_id' => 'test_client_id',
            'client_secret' => 'test_client_secret',
        ]);

        $this->assertEquals('test_client_id', $config->client_id);
        $this->assertEquals('test_client_secret', $config->client_secret);
    }

    public function test_requires_client_id(): void
    {
        $this->expectException(\Throwable::class);

        $config = new Configuration([
            'client_secret' => 'test_client_secret',
        ]);

        $config->validateIfNotYetValidated();
    }

    public function test_requires_client_secret(): void
    {
        $this->expectException(\Throwable::class);

        $config = new Configuration([
            'client_id' => 'test_client_id',
        ]);

        $config->validateIfNotYetValidated();
    }

    public function test_is_sandbox_returns_true_when_sandbox_is_true(): void
    {
        $config = new Configuration([
            'client_id' => 'test_client_id',
            'client_secret' => 'test_client_secret',
            'sandbox' => true,
        ]);

        $this->assertTrue($config->isSandbox());
    }

    public function test_is_sandbox_returns_false_when_sandbox_is_false(): void
    {
        $config = new Configuration([
            'client_id' => 'test_client_id',
            'client_secret' => 'test_client_secret',
            'sandbox' => false,
        ]);

        $this->assertFalse($config->isSandbox());
    }

    public function test_is_sandbox_returns_false_when_sandbox_is_null(): void
    {
        $config = new Configuration([
            'client_id' => 'test_client_id',
            'client_secret' => 'test_client_secret',
            'sandbox' => null,
        ]);

        $this->assertFalse($config->isSandbox());
    }

    public function test_is_sandbox_returns_false_when_sandbox_is_not_set(): void
    {
        $config = new Configuration([
            'client_id' => 'test_client_id',
            'client_secret' => 'test_client_secret',
        ]);

        $this->assertFalse($config->isSandbox());
    }

    public function test_get_base_url_returns_production_url(): void
    {
        $config = new Configuration([
            'client_id' => 'test_client_id',
            'client_secret' => 'test_client_secret',
            'sandbox' => false,
        ]);

        $this->assertEquals('https://api.opusdns.com', $config->getBaseUrl());
    }

    public function test_get_base_url_returns_sandbox_url(): void
    {
        $config = new Configuration([
            'client_id' => 'test_client_id',
            'client_secret' => 'test_client_secret',
            'sandbox' => true,
        ]);

        $this->assertEquals('https://sandbox.opusdns.com', $config->getBaseUrl());
    }
}
