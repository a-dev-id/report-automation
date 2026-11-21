<?php

declare(strict_types=1);

namespace App\Services\Google;

use App\Contracts\SearchConsoleClient;
use App\Exceptions\GoogleApiException;
use App\Exceptions\GooglePermissionDeniedException;
use App\Exceptions\PropertyNotConfiguredException;
use App\Models\Property;
use App\Support\SearchConsoleQuery;
use Google\Client;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\SearchConsole;
use Google\Service\SearchConsole\SearchAnalyticsQueryRequest;
use Google\Service\SearchConsole\SearchAnalyticsQueryResponse;
use Throwable;

/**
 * Search Console searchanalytics.query wrapper with the same error handling as the GA4 client.
 *
 * Retried with backoff: HTTP 429, 5xx, and 403 with a rate-limit reason.
 * Not retried: any other 403 (the service account is not a user of that site) and 4xx.
 */
class SearchConsoleApiClient implements SearchConsoleClient
{
    private const RATE_LIMIT_REASONS = ['rateLimitExceeded', 'userRateLimitExceeded', 'quotaExceeded'];

    private ?SearchConsole $service = null;

    public function __construct(
        private readonly string $credentialsPath,
        private readonly Retrier $retrier,
    ) {
    }

    public function query(Property $property, SearchConsoleQuery $query): array
    {
        $siteUrl = trim((string) $property->gsc_site_url);
        if ($siteUrl === '') {
            throw new PropertyNotConfiguredException(
                "Property '{$property->slug}' has no Search Console site (gsc_site_url is empty).",
            );
        }

        // The Google model setters return void, so they cannot be chained.
        $request = new SearchAnalyticsQueryRequest();
        $request->setStartDate($query->startDate);
        $request->setEndDate($query->endDate);
        $request->setRowLimit($query->rowLimit);
        $request->setSearchType('web');   // the API default, stated so it cannot change silently
        if ($query->dimensions !== []) {
            $request->setDimensions($query->dimensions);
        }

        try {
            $response = $this->retrier->run(
                fn () => $this->send($siteUrl, $request),
                fn (Throwable $e) => ($e instanceof GoogleServiceException && $this->isRetryable($e))
                    || TransientNetworkError::matches($e),
            );
        } catch (GoogleServiceException $e) {
            if ($e->getCode() === 403) {
                throw new GooglePermissionDeniedException(
                    "The service account has not been added to Search Console site {$siteUrl} ({$property->slug}). "
                    .'Add it as a user under Search Console > Settings > Users and permissions.',
                    0,
                    $e,
                );
            }

            throw new GoogleApiException(
                "Search Console request '{$query->label}' failed for {$property->slug}: HTTP {$e->getCode()} {$e->getMessage()}",
                0,
                $e,
            );
        } catch (GoogleApiException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new GoogleApiException(
                "Search Console request '{$query->label}' failed for {$property->slug}: ".class_basename($e).': '.$e->getMessage(),
                0,
                $e,
            );
        }

        $rows = [];
        foreach ($response->getRows() ?? [] as $row) {
            $rows[] = [
                'keys' => array_map('strval', $row->getKeys() ?? []),
                'clicks' => (float) $row->getClicks(),
                'impressions' => (float) $row->getImpressions(),
                'ctr' => (float) $row->getCtr(),
                'position' => (float) $row->getPosition(),
            ];
        }

        return $rows;
    }

    /** @throws GoogleServiceException */
    protected function send(string $siteUrl, SearchAnalyticsQueryRequest $request): SearchAnalyticsQueryResponse
    {
        return $this->service()->searchanalytics->query($siteUrl, $request);
    }

    private function isRetryable(GoogleServiceException $e): bool
    {
        $code = (int) $e->getCode();
        if ($code === 429 || $code >= 500) {
            return true;
        }

        if ($code === 403) {
            foreach ($e->getErrors() ?? [] as $error) {
                if (in_array($error['reason'] ?? null, self::RATE_LIMIT_REASONS, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function service(): SearchConsole
    {
        if ($this->credentialsPath === '' || ! is_file($this->credentialsPath)) {
            throw new GoogleApiException('Google credentials file not found. Check GOOGLE_APPLICATION_CREDENTIALS.');
        }

        if ($this->service === null) {
            $client = new Client();
            $client->setAuthConfig($this->credentialsPath);
            $client->addScope(SearchConsole::WEBMASTERS_READONLY);
            $this->service = new SearchConsole($client);
        }

        return $this->service;
    }
}
