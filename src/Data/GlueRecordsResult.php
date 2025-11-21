<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Data;

use Upmind\ProvisionBase\Provider\DataSet\ResultData;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Set of all glue records result.
 *
 * @property-read GlueRecord[]|null $glue_records Set of glue records
 */
class GlueRecordsResult extends ResultData
{
    public static function rules(): Rules
    {
        return new Rules([
            'glue_records' => ['nullable', 'array'],
            'glue_records.*' => [GlueRecord::class],
        ]);
    }

    /**
     * @return $this
     */
    public function setGlueRecords(?array $glueRecords): self
    {
        $this->setValue('glue_records', $glueRecords);
        return $this;
    }
}
