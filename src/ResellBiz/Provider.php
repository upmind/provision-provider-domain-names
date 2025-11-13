<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\ResellBiz;

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
            ->setName('Resell.biz')
            ->setDescription(
                'Resell.biz provides low-cost domain registration, '
                    . 'domain management, and hosting services for thousands of resellers worldwide'
            )
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/resell-biz-logo.jpeg');
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
