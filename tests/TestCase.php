<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Upmind\ProvisionBase\Laravel\ValidationServiceProvider;

abstract class TestCase extends BaseTestCase
{
    /**
     * Get package providers.
     *
     * @param \Illuminate\Foundation\Application $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            ValidationServiceProvider::class,
        ];
    }
}
