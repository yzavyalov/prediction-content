<?php

namespace App\Services\Discovery;

class ArticleUrlCanonicalizer
{
    private const TRACKING_PARAMETERS = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'utm_id',
        'gclid',
        'fbclid',
        'yclid',
        'mc_cid',
        'mc_eid',
    ];

    public function canonicalize(string $url): ?string
    {
        $url = trim($url);

        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);

        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        if ($scheme !== 'https' || $host === '') {
            return null;
        }

        $path = $parts['path'] ?? '/';

        if ($path === '') {
            $path = '/';
        }

        $query = $this->canonicalizeQuery($parts['query'] ?? null);

        $canonicalUrl = 'https://' . $host;

        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            $canonicalUrl .= ':' . (int) $parts['port'];
        }

        $canonicalUrl .= $path;

        if ($query !== '') {
            $canonicalUrl .= '?' . $query;
        }

        return $canonicalUrl;
    }

    public function hash(string $canonicalUrl): string
    {
        return hash('sha256', $canonicalUrl);
    }

    private function canonicalizeQuery(?string $query): string
    {
        if ($query === null || $query === '') {
            return '';
        }

        parse_str($query, $parameters);

        foreach (array_keys($parameters) as $key) {
            if ($this->isTrackingParameter((string) $key)) {
                unset($parameters[$key]);
            }
        }

        if ($parameters === []) {
            return '';
        }

        ksort($parameters);

        return http_build_query(
            $parameters,
            '',
            '&',
            PHP_QUERY_RFC3986
        );
    }

    private function isTrackingParameter(string $key): bool
    {
        $key = strtolower($key);

        if (str_starts_with($key, 'utm_')) {
            return true;
        }

        return in_array($key, self::TRACKING_PARAMETERS, true);
    }
}
