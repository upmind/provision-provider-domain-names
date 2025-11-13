<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\ResellerClub;

use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionProviders\DomainNames\LogicBoxes\Provider as LogicBoxesProvider;
use Upmind\ProvisionProviders\DomainNames\Data\SetGlueRecordParams;
use Upmind\ProvisionProviders\DomainNames\Data\RemoveGlueRecordParams;
use Upmind\ProvisionProviders\DomainNames\Data\GlueRecordsResult;

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
    public function setGlueRecord(SetGlueRecordParams $params): GlueRecordsResult
    {
        $this->errorResult('Operation not supported', $params);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function removeGlueRecord(RemoveGlueRecordParams $params): GlueRecordsResult
    {
        $this->errorResult('Operation not supported', $params);
    }
}
