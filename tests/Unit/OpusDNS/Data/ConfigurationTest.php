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
            'api_key' => 'test_api_key',
        ]);

        $this->assertEquals('test_api_key', $config->api_key);
    }

    public function test_requires_api_key(): void
    {
        $this->expectException(\Throwable::class);

        $config = new Configuration([
            // No api_key provided
        ]);

        $config->validateIfNotYetValidated();
    }

    public function test_is_sandbox_returns_true_when_sandbox_is_true(): void
    {
        $config = new Configuration([
            'api_key' => 'test_api_key',
            'sandbox' => true,
        ]);

        $this->assertTrue($config->isSandbox());
    }

    public function test_is_sandbox_returns_false_when_sandbox_is_false(): void
    {
        $config = new Configuration([
            'api_key' => 'test_api_key',
            'sandbox' => false,
        ]);

        $this->assertFalse($config->isSandbox());
    }

    public function test_is_sandbox_returns_false_when_sandbox_is_null(): void
    {
        $config = new Configuration([
            'api_key' => 'test_api_key',
            'sandbox' => null,
        ]);

        $this->assertFalse($config->isSandbox());
    }

    public function test_is_sandbox_returns_false_when_sandbox_is_not_set(): void
    {
        $config = new Configuration([
            'api_key' => 'test_api_key',
        ]);

        $this->assertFalse($config->isSandbox());
    }

    public function test_get_base_url_returns_production_url(): void
    {
        $config = new Configuration([
            'api_key' => 'test_api_key',
            'sandbox' => false,
        ]);

        $this->assertEquals('https://api.opusdns.com', $config->getBaseUrl());
    }

    public function test_get_base_url_returns_sandbox_url(): void
    {
        $config = new Configuration([
            'api_key' => 'test_api_key',
            'sandbox' => true,
        ]);

        $this->assertEquals('https://sandbox.opusdns.com', $config->getBaseUrl());
    }
}
