<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Data;

use Upmind\ProvisionBase\Provider\DataSet\ResultData;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Resend verification email result.
 *
 * @property-read bool $success Whether resend was successful
 * @property-read string $message Result message
 */
class ResendVerificationResult extends ResultData
{
    public static function rules(): Rules
    {
        return new Rules([
            'success' => ['required', 'boolean'],
            'message' => ['required', 'string'],
        ]);
    }

    /**
     * @return static $this
     */
    public function setSuccess(bool $success): self
    {
        $this->setValue('success', $success);
        return $this;
    }

    /**
     * @return static $this
     */
    public function setMessage($message): self
    {
        $this->setValue('message', $message);
        return $this;
    }
}
