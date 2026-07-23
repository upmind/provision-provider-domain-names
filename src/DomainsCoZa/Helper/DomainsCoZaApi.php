<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\DomainsCoZa\Helper;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;

class DomainsCoZaApi
{
    protected ?Client $client = null;
    protected string $apiKey;
    protected string $apiBase;
    protected LoggerInterface $logger;

    public function __construct(string $apiKey, LoggerInterface $logger, bool $sandbox = false)
    {
        $this->apiKey = $apiKey;
        $this->logger = $logger;
        $this->apiBase = $sandbox
            ? 'https://lapi-dev.domains.co.za/api/'
            : 'https://api.domains.co.za/api/';
    }

    /**
     * Make an API request with proper error handling
     *
     * All Domains.co.za API rules enforced here:
     *   - GET / DELETE → query string params
     *   - POST / PUT   → URL-encoded form body (NOT JSON — API docs mandate this)
     *   - intReturnCode 1 and 2 = success; additional codes via $extraOkCodes
     *   - strReason surfaces first in errors (it carries the specific registry detail)
     *   - Non-JSON responses (WAF/proxy pages) are caught and reported clearly
     */
    public function request(
        string $method,
        string $endpoint,
        array $params = [],
        array $extraOkCodes = []
    ): array {
        if ($this->client === null) {
            $this->client = new Client([
                'base_uri' => $this->apiBase,
                'timeout' => 30,
                'http_errors' => false,
            ]);
        }

        $method = strtoupper($method);
        $paramsWithApiKey = array_merge(['key' => $this->apiKey], $params);

        $options = [
            'headers' => [
                'Accept' => 'application/json',
            ],
        ];

        if ($method === 'GET' || $method === 'DELETE') {
            $options['query'] = $paramsWithApiKey;
        } else {
            $options['form_params'] = $paramsWithApiKey;
        }

        $this->logger->debug('DomainsCoZa API request', [
            'method' => $method,
            'endpoint' => ltrim($endpoint, '/'),
            'params' => $params,
        ]);

        try {
            $response = $this->client->request($method, ltrim($endpoint, '/'), $options);
        } catch (GuzzleException $e) {
            $this->logger->debug('DomainsCoZa API transport error', [
                'method' => $method,
                'endpoint' => ltrim($endpoint, '/'),
                'exception' => $e->getMessage(),
            ]);

            throw ProvisionFunctionError::create('Unable to connect to service', $e)
                ->withDebug([
                    'method' => $method,
                    'endpoint' => ltrim($endpoint, '/'),
                    'params' => $params,
                    'exception' => $e->getMessage(),
                ]);
        }

        $rawBody = (string) $response->getBody();
        $data = json_decode($rawBody, true);

        $this->logger->debug('DomainsCoZa API response', [
            'method' => $method,
            'endpoint' => ltrim($endpoint, '/'),
            'http_status' => $response->getStatusCode(),
            'response' => self::redactSensitive($data),
        ]);

        if (!is_array($data)) {
            throw ProvisionFunctionError::create('Unexpected response from service')
                ->withDebug([
                    'method' => $method,
                    'endpoint' => ltrim($endpoint, '/'),
                    'params' => $params,
                    'http_status' => $response->getStatusCode(),
                    'raw_response' => $rawBody,
                ]);
        }

        $returnCode = isset($data['intReturnCode']) ? (int) $data['intReturnCode'] : null;
        $successCodes = array_merge([1, 2], $extraOkCodes);

        if ($returnCode === null || !in_array($returnCode, $successCodes, true)) {
            $rawReason = trim((string) ($data['strMessage'] ?? $data['strReason'] ?? ''));

            $message = match ($returnCode) {
                // Domain-state errors carry safe, customer-useful text; surface it verbatim
                4, 8, 11, 12, 13, 19 => $rawReason !== '' ? $rawReason : 'The request could not be completed.',
                6, 7 => 'Authentication failed. Please verify the configured API credentials.',
                18, 20, 22 => 'The service is temporarily unavailable. Please try again shortly.',
                default => 'The request could not be completed.',
            };

            throw ProvisionFunctionError::create($message)
                ->withData(self::redactSensitive($data))
                ->withDebug([
                    'method' => $method,
                    'endpoint' => ltrim($endpoint, '/'),
                    'params' => $params,
                    'raw_reason' => $rawReason,
                    'http_status' => $response->getStatusCode(),
                    'response' => self::redactSensitive($data),
                ]);
        }

        return $data;
    }

    /**
     * Redact sensitive fields (EPP/auth keys) from an API response before logging.
     */
    private static function redactSensitive(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }

        $sensitiveKeys = ['strEPPKey', 'eppKey', 'authCode', 'authInfo'];
        foreach ($sensitiveKeys as $k) {
            if (array_key_exists($k, $data) && $data[$k] !== null && $data[$k] !== '') {
                $data[$k] = '***REDACTED***';
            }
        }

        return $data;
    }
}
