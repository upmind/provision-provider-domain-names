<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Moniker\Data;

use Upmind\ProvisionProviders\DomainNames\CentralNicReseller\Data\Configuration as CentralNicResellerConfiguration;

/**
 * Moniker configuration.
 *
 * @property-read string $username
 * @property-read string $password
 * @property-read bool|null $sandbox
 * @property-read bool|null $debug Whether or not to enable debug logging
 */
class Configuration extends CentralNicResellerConfiguration
{
}
