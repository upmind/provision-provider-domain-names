<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\ResellerClub;

use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionProviders\DomainNames\LogicBoxes\Provider as LogicBoxesProvider;

use Upmind\ProvisionProviders\DomainNames\Data\VerificationStatusParams;
use Upmind\ProvisionProviders\DomainNames\Data\VerificationStatusResult;
use Upmind\ProvisionProviders\DomainNames\Data\ResendVerificationParams;
use Upmind\ProvisionProviders\DomainNames\Data\ResendVerificationResult;

class Provider extends LogicBoxesProvider
{
    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('ResellerClub')
            ->setDescription(
                'ResellerClub offers a comprehensive solution to register and '
                . 'manage 500+ gTLDs, ccTLDs and new domains.'
            )
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/resellerclub-logo_2x.png');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getVerificationStatus(VerificationStatusParams $params): VerificationStatusResult
    {
        $this->errorResult('Operation not supported', $params);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function resendVerificationEmail(ResendVerificationParams $params): ResendVerificationResult
    {
        $this->errorResult('Operation not supported', $params);
    }

}
