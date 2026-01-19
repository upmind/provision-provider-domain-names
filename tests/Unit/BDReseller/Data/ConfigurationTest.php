<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Tests\Unit\BDReseller\Data;

use Upmind\ProvisionProviders\DomainNames\BDReseller\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\Tests\TestCase;

class ConfigurationTest extends TestCase
{
    public function test_is_sandbox_returns_true_when_sandbox_is_true(): void
    {
        $config = new Configuration([
            'username' => 'test_username',
            'password' => 'test_password',
            'sandbox' => true,
        ]);

        $this->assertTrue($config->isSandbox());
    }

    public function test_is_sandbox_returns_false_when_sandbox_is_false(): void
    {
        $config = new Configuration([
            'username' => 'test_username',
            'password' => 'test_password',
            'sandbox' => false,
        ]);

        $this->assertFalse($config->isSandbox());
    }

    public function test_is_sandbox_returns_false_when_sandbox_is_null(): void
    {
        $config = new Configuration([
            'username' => 'test_username',
            'password' => 'test_password',
            'sandbox' => null,
        ]);

        $this->assertFalse($config->isSandbox());
    }

    public function test_is_sandbox_returns_false_when_sandbox_is_not_set(): void
    {
        $config = new Configuration([
            'username' => 'test_username',
            'password' => 'test_password',
        ]);

        $this->assertFalse($config->isSandbox());
    }
}
