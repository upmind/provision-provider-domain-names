<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\TwentyI\Helper\Traits;

use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use stdClass;
use Throwable;
use TwentyI\API\HTTPException;

/**
 * TwentyI\API\REST decorator which can log request and response data.
 *
 * @mixin \TwentyI\API\REST
 */
trait LogsRequests
{
    /**
     * PSR-3 logger.
     *
     * @var LoggerInterface|null
     */
    protected $logger = null;

    /**
     * Set's a PSR-3 logger to use.
     *
     * @param LoggerInterface|null $logger
     *
     * @return void
     */
    public function setLogger(?LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * @inheritDoc
     *
     * @throws \Throwable
     */
    public function getRawWithFields($url, $fields = [], $options = [])
    {
        try {
            $raw = parent::getRawWithFields($url, $fields, $options + $this->getCurlOptions());
            $result = json_decode($raw) ?? $raw;
            return $raw;
        } catch (\Throwable $e) {
            $result = $this->getErrorResult($e);
            throw $e;
        } finally {
            $this->logRequest('GET', $url, $fields, $result ?? null);
        }
    }

    /**
     * @inheritDoc
     *
     * @throws \Throwable
     */
    public function postWithFields($url, array $fields, array $options = [])
    {
        try {
            return $result = parent::postWithFields($url, $fields, $options + $this->getCurlOptions());
        } catch (\Throwable $e) {
            $result = $this->getErrorResult($e);
            throw $e;
        } finally {
            $this->logRequest('POST', $url, $fields, $result ?? null);
        }
    }

    /**
     * @inheritDoc
     *
     * @throws \Throwable
     */
    public function putWithFields($url, $fields, $options = [])
    {
        try {
            return $result = parent::putWithFields($url, $fields, $options + $this->getCurlOptions());
        } catch (\Throwable $e) {
            $result = $this->getErrorResult($e);
            throw $e;
        } finally {
            $this->logRequest('PUT', $url, $fields, $result ?? null);
        }
    }

    /**
     * @inheritDoc
     *
     * @throws \Throwable
     */
    public function deleteWithFields($url, $fields = [], $options = [])
    {
        try {
            return $result = parent::deleteWithFields($url, $fields, $options + $this->getCurlOptions());
        } catch (\Throwable $e) {
            $result = $this->getErrorResult($e);
            throw $e;
        } finally {
            $this->logRequest('DELETE', $url, $fields, $result ?? null);
        }
    }

    /**
     * @param HTTPException|Throwable $e
     */
    protected function getErrorResult(Throwable $e): array
    {
        return [
            'exception' => get_class($e),
            'response' => $e->decodedBody ?? null,
        ];
    }

    protected function logRequest(string $method, string $url, $params, $result): void
    {
        if ($this->logger) {
            $result = $this->filterResult($result);

            $status = empty($result['exception']) ? 'OK' : 'ERROR';

            $this->logger->debug(sprintf('20i API Call [%s]: %s %s', $status, strtoupper($method), $url), [
                'params' => $params,
                'result' => $result,
            ]);
        }
    }

    /**
     * @param mixed[]|mixed $result
     * @param string|int|null $key
     *
     * @return mixed
     */
    protected function filterResult($result, $key = null)
    {
        $result = $result instanceof stdClass ? (array)$result : $result;

        if (is_iterable($result)) {
            foreach ($result as $k => $v) {
                $result[$k] = $this->filterResult($v, $k);
            }
        }

        if ($this->shouldRedact($result, $key)) {
            return '[Redacted]';
        }

        return $result;
    }

    protected function shouldRedact($value, $key): bool
    {
        return is_string($value)
            && $key !== 'error'
            && (
                in_array($key, [
                'customHeader',
                'passwordResetEmail',
                'bannerUrl',
                'billingDue',
                'passwordReset',
            ], true)
                || Str::endsWith((string)$key, ['Html', 'EmailContent', 'Css'])
                || strlen($value) > 500
            );
    }

    protected function getCurlOptions(): array
    {
        return [
            CURLOPT_TIMEOUT => 60, // yep, it seriously can take almost this long to do some things!
        ];
    }
}
