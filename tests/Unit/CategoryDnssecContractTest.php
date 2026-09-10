<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Tests\Unit;

use ReflectionMethod;
use Upmind\ProvisionProviders\DomainNames\Category;
use Upmind\ProvisionProviders\DomainNames\Data\DisableDnssecParams;
use Upmind\ProvisionProviders\DomainNames\Data\DomainResult;
use Upmind\ProvisionProviders\DomainNames\Data\EnableDnssecParams;
use Upmind\ProvisionProviders\DomainNames\Tests\TestCase;

class CategoryDnssecContractTest extends TestCase
{
    public function test_enable_dnssec_contract(): void
    {
        $this->assertDnssecMethod('enableDnssec', EnableDnssecParams::class);
    }

    public function test_disable_dnssec_contract(): void
    {
        $this->assertDnssecMethod('disableDnssec', DisableDnssecParams::class);
    }

    private function assertDnssecMethod(string $methodName, string $parameterClass): void
    {
        $method = new ReflectionMethod(Category::class, $methodName);
        $parameters = $method->getParameters();

        $this->assertTrue($method->isAbstract());
        $this->assertCount(1, $parameters);
        $this->assertSame($parameterClass, $parameters[0]->getType()->getName());
        $this->assertSame(DomainResult::class, $method->getReturnType()->getName());
    }
}
