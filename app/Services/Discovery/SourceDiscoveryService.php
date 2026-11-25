<?php

namespace App\Services\Discovery;

use App\Models\Category;
use App\Models\Country;
use App\Models\CountryGroup;
use App\Models\Source;
use App\Services\AI\GeminiService;
use Carbon\CarbonInterface;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

class SourceDiscoveryService
{
    private const WORK_SECONDS = 1680;
    private const CACHE_TTL = 2592000;
    private const REQUEST_TIMEOUT = 10;
    private const MAX_CONSECUTIVE_FAILURES = 25;
    private const HTTP_429_INITIAL_COOLDOWN = 60;
    private const HTTP_429_COOLDOWN_STEP = 30;
    private const HTTP_429_MAX_COOLDOWN = 300;
    private const MIN_REQUEST_INTERVAL_US = 2_000_000;
    private const MAX_REQUEST_INTERVAL_US = 15_000_000;
    private const MAX_PENDING_URLS = 50000;
    private const MAX_HTML_SECTION_DEPTH = 1;
    private const MAX_VISITED_URLS = 8000;
    private const MAX_REQUESTS_PER_RUN = 250;
    private const MAX_LINKS_PER_PAGE = 500;
    private const MIN_SECTION_ARTICLE_LINKS = 11;
    private const MAX_REDIRECTS = 4;
    private const REVISIT_SECONDS = 7200;
    private const MAX_DEPTH = 5;
    private const MAX_BODY_BYTES = 5_000_000;

    private CarbonImmutable $windowFrom;
    private CarbonImmutable $windowTo;

    public function __construct(
        private readonly GeminiService $gemini,
        private readonly SourceCrawledUrlService $crawledUrls,
    ) {
    }

    public function discover(
        Source $source,
        Category $category,
        Country|CountryGroup $geo,
        CarbonInterface $from,
        CarbonInterface $to,
        ?callable $onArticle = null,
    ): array {
        if ($from->greaterThanOrEqualTo($to)) {
            throw new RuntimeException('Invalid discovery time window.');
        }
        $this->windowFrom = CarbonImmutable::instance($from);
        $this->windowTo = CarbonImmutable::instance($to);

        $domain = strtolower(trim((string) $source->domain));
        $domain = preg_replace('~^https?://~i', '', $domain);
        $domain = preg_replace('/^www\./', '', explode('/', $domain)[0]);
        if (!preg_match('/^(?=.{4,253}$)[a-z0-9-]+(?:\.[a-z0-9-]+)+$/', $domain)) {
            throw new RuntimeException('Invalid source domain.');
        }

        $key = 'discovery:crawl:v10:' . $source->id
            . ':' . $this->windowFrom->getTimestamp()
            . ':' . $this->windowTo->getTimestamp();
        $state = Cache::store('file')->get($key);
        $isNewCrawl = !is_array($state) || !isset($state['queue'], $state['visited']) || ($state['crawl_complete'] ?? false);

        if ($isNewCrawl) {
            $state = ['queue' => [], 'visited' => [], 'robots' => [], 'retry' => []];
        }

        $state['robots'] ??= [];
        $state['retry'] ??= [];
        $state['cooldown_until'] ??= 0;
        $state['request_interval_us'] ??= self::MIN_REQUEST_INTERVAL_US;
        $state['success_streak'] ??= 0;
        $state['http_429_streak'] ??= 0;
        $now = time();
        $state['visited'] = array_filter(
            $state['visited'],
            static fn ($v) => is_numeric($v) && (int) $v > $now - self::CACHE_TTL
        );
        $state['visited'] = array_slice($state['visited'], -self::MAX_VISITED_URLS, null, true);
        // Retry timestamps must be preserved for URLs still in the queue.
        $state['retry'] = array_filter(
            $state['retry'],
            static fn ($v) => is_numeric($v) && (int) $v > $now
        );

        $oldQueue = $state['queue'];
        $state['queue'] = [];
        $state['queued_index'] = [];
        foreach ($oldQueue as $item) {
            if (is_string($item)) {
                $this->enqueue($state, $item, $domain, 2);
            } elseif (is_array($item) && isset($item['url'])) {
                $this->enqueue(
                    $state,
                    (string) $item['url'],
                    $domain,
                    (int) ($item['depth'] ?? 2),
                    (int) ($item['priority'] ?? 2)
                );
            }
        }

        // Seed only once per crawl. Otherwise a completed batch can be
        // repopulated endlessly by the same RSS and sitemap entry points.
        if ($isNewCrawl) {
            $hosts = [$domain, 'www.' . $domain];
            foreach ($hosts as $host) {
                $this->loadRobots($host, $domain, $state);
                foreach (['/', '/rss', '/feed', '/rss.xml', '/feed.xml', '/sitemap.xml', '/sitemap_index.xml'] as $path) {
                    $this->enqueue(
                        $state,
                        'https://' . $host . $path,
                        $domain,
                        0,
                        $path === '/' ? 1 : 0,
                        true
                    );
                }
            }
        }

        $articles = [];
        $found = 0;
        $requests = 0;
        $failed = 0;
        $stats = ['rss' => 0, 'sitemap' => 0, 'html' => 0, 'redirects' => 0,
            'blocked_robots' => 0, 'http_403' => 0, 'http_404' => 0, 'http_429' => 0,
            'other_errors' => 0, 'enqueued' => 0, 'skipped_duplicate' => 0];
        $seenThisRun = [];
        $emitted = [];
        $started = microtime(true);
        $consecutiveFailures = 0;

        $deferredInRow = 0;
        while (
            $state['queue'] !== []
            && $requests < self::MAX_REQUESTS_PER_RUN
            && microtime(true) - $started < self::WORK_SECONDS
        ) {
            // A 429 applies to the source, not just the rejected URL.
            if ((int) $state['cooldown_until'] > time()) {
                break;
            }
            usort($state['queue'], static fn ($a, $b) => $a['priority'] <=> $b['priority']);
            $item = array_shift($state['queue']);
            unset($state['queued_index'][$item['url']]);
            $url = $item['url'];
            $depth = $item['depth'];

            if (
                isset($seenThisRun[$url])
                || !$this->allowedUrl($url, $domain)
                || $this->isExpiredDatedSitemap($url) || $this->isNonContentUrl($url)
            ) {
                $stats['skipped_duplicate']++;
                $deferredInRow = 0;
                continue;
            }

            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if (!$this->robotsAllowed($url, $state['robots'][$host]['rules'] ?? [])) {
                $stats['blocked_robots']++;
                $deferredInRow = 0;
                continue;
            }

            // Keep delayed URLs in the queue. If every remaining URL is
            // delayed, end this batch without claiming crawl completion.
            if (($state['retry'][$url] ?? 0) > time()) {
                $state['queue'][] = $item;
                $state['queued_index'][$url] = true;
                $deferredInRow++;
                if ($deferredInRow >= count($state['queue'])) {
                    break;
                }
                continue;
            }

            $deferredInRow = 0;
            if (
                isset($state['visited'][$url])
                && time() - (int) $state['visited'][$url] < $this->revisitInterval($url)
            ) {
                continue;
            }

            // Only previously processed article pages can be skipped permanently.
            // Indexes, pagination, RSS and sitemaps must remain refreshable.
            if (
                $this->isPermanentArticleUrl($url)
                && $this->crawledUrls->isProcessed((int) $source->id, $url)
            ) {
                $stats['skipped_duplicate']++;
                unset($state['retry'][$url]);
                continue;
            }

            $seenThisRun[$url] = true;
            $result = $this->fetch($url, $domain, $state);
            $requests++;

            if (!isset($result['body'])) {
                $failed++;
                $status = (int) ($result['status'] ?? 0);
                $bucket = match ($status) {
                    403 => 'http_403',
                    404, 410 => 'http_404',
                    429 => 'http_429',
                    default => 'other_errors',
                };
                $stats[$bucket]++;

                // Permanent responses are skipped; temporary failures
                // remain queued until their retry timestamp is reached.
                if (in_array($status, [403, 404, 410, 413], true)) {
                    unset($state['retry'][$url]);
                } else {
                    if ($status === 429) {
                        $state['http_429_streak'] = (int) $state['http_429_streak'] + 1;
                        $delay = min(
                            self::HTTP_429_MAX_COOLDOWN,
                            self::HTTP_429_INITIAL_COOLDOWN
                            + ($state['http_429_streak'] - 1) * self::HTTP_429_COOLDOWN_STEP
                        );
                    } else {
                        $delay = 1800;
                    }
                    $state['retry'][$url] = time() + $delay;
                    $state['queue'][] = $item;
                    $state['queued_index'][$url] = true;
                }

                if ($status === 429) {
                    $state['cooldown_until'] = max(
                        (int) $state['cooldown_until'],
                        (int) $state['retry'][$url]
                    );
                    $state['request_interval_us'] = min(
                        self::MAX_REQUEST_INTERVAL_US,
                        max(self::MIN_REQUEST_INTERVAL_US, (int) $state['request_interval_us'] * 2)
                    );
                    $state['success_streak'] = 0;
                    Cache::store('file')->put($key, $state, self::CACHE_TTL);
                    break;
                }

                $consecutiveFailures++;
                $state['success_streak'] = 0;
                Cache::store('file')->put($key, $state, self::CACHE_TTL);
                if ($consecutiveFailures >= self::MAX_CONSECUTIVE_FAILURES) {
                    break;
                }
                continue;
            }

            $consecutiveFailures = 0;
            // A successful response resets the consecutive 429 backoff.
            $state['http_429_streak'] = 0;
            $state['success_streak']++;
            if ($state['success_streak'] >= 20) {
                $state['request_interval_us'] = max(
                    self::MIN_REQUEST_INTERVAL_US,
                    (int) floor($state['request_interval_us'] * 0.9)
                );
                $state['success_streak'] = 0;
            }
            unset($state['retry'][$url]);
            $stats['redirects'] += (int) ($result['redirects'] ?? 0);
            $discovered = [];
            $body = $result['body'];
            $effectiveUrl = $result['url'];
            if ($this->isXml($result['type'], $body)) {
                $kind = $this->processXml($body, $domain, $state, $discovered, $effectiveUrl, $depth);
                if ($kind !== null) {
                    $stats[$kind]++;
                }
            } elseif (
                str_contains($result['type'], 'html')
                || preg_match('~^\s*(?:<!doctype\s+html|<html\b)~i', $body)
            ) {
                $stats['html']++;
                $this->processHtml($body, $effectiveUrl, $domain, $state, $discovered, $depth);
            }

            $isHtmlResponse = !$this->isXml($result['type'], $body)
                && (
                    str_contains($result['type'], 'html')
                    || preg_match('~^\s*(?:<!doctype\s+html|<html\b)~i', $body)
                );
            $processedArticlePage = false;

            foreach ($discovered as $article) {
                if (isset($emitted[$article['url']])) {
                    continue;
                }
                $emitted[$article['url']] = true;
                if ($onArticle !== null) {
                    $accepted = $onArticle($article);
                } else {
                    $articles[] = $article;
                    $accepted = true;
                }
                $found++;

                // Do not mark RSS/sitemap metadata as a fetched article page.
                // A callback returning false means the article was rejected.
                if ($isHtmlResponse && $accepted !== false) {
                    $processedArticlePage = true;
                }
            }

            // Save only successfully handled HTML article pages, never indexes,
            // pagination or failed HTTP requests. A DB failure prevents marking
            // the file-cache visit, so the URL can be retried.
            if ($processedArticlePage && $this->isPermanentArticleUrl($url)
                && $this->isPermanentArticleUrl($effectiveUrl)) {
                $this->crawledUrls->markProcessedBatch((int) $source->id, [$url, $effectiveUrl]);
            }

            $state['visited'][$url] = time();
            $state['visited'][$effectiveUrl] = time();
            $state['visited'] = array_slice($state['visited'], -self::MAX_VISITED_URLS, null, true);
            $stats['enqueued'] = count($state['queue']);
            Cache::store('file')->put($key, $state, self::CACHE_TTL);
            // Pace requests to the source; 429 will increase this interval.
            if ($state['queue'] !== []) {
                usleep((int) $state['request_interval_us']);
            }
        }

        unset($state['queued_index']);
        $state['crawl_complete'] = $state['queue'] === [];
        $nextAttemptAt = null;
        if (!$state['crawl_complete']) {
            $nextAttemptAt = max(time(), (int) $state['cooldown_until']);
            if ($nextAttemptAt <= time()) {
                $ready = false;
                $earliest = null;
                foreach ($state['queue'] as $pending) {
                    $retryAt = (int) ($state['retry'][$pending['url']] ?? 0);
                    if ($retryAt <= time()) {
                        $ready = true;
                        break;
                    }
                    $earliest = $earliest === null ? $retryAt : min($earliest, $retryAt);
                }
                $nextAttemptAt = $ready ? time() : ($earliest ?? time());
            }
        }
        Cache::store('file')->put($key, $state, self::CACHE_TTL);
        // Discovery never invokes Gemini. Keep the legacy method for compatibility,
        // but only the separate generation pipeline may call Gemini explicitly.
        $http200 = ['html' => $stats['html'] > 0, 'rss' => $stats['rss'] > 0, 'sitemap' => $stats['sitemap'] > 0];
        $raw = array_merge($stats, [
            'requests' => $requests, 'articles' => $found, 'failed_requests' => $failed,
            'remaining_urls' => count($state['queue']), 'crawl_complete' => $state['queue'] === [],
            'next_attempt_at' => $nextAttemptAt, 'cooldown_until' => (int) $state['cooldown_until'],
            'http_200' => $http200, 'http_429_streak' => (int) $state['http_429_streak'],
            'gemini_fallback' => false,
            'window_from' => $this->windowFrom->toIso8601String(),
            'window_to' => $this->windowTo->toIso8601String(),
        ]);
        Log::info('Discovery crawl finished', ['source_id' => $source->id] + $raw);
        return ['articles' => $articles, 'usage' => ['google_search_count' => 0], 'raw_response' => $raw];
    }

    private function revisitInterval(string $url): int
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        if ($path === '/' || preg_match('~/(rss|feed)(\.xml)?/?$~', $path)) {
            return 900;
        }
        if (str_contains($path, 'sitemap')) {
            return 3600;
        }
        return $this->likelyArticle($url) ? 604800 : self::REVISIT_SECONDS;
    }

    private function loadRobots(string $host, string $domain, array &$state): void
    {
        if (isset($state['robots'][$host]['checked']) && time() - $state['robots'][$host]['checked'] < 86400) {
            return;
        }
        $url = 'https://' . $host . '/robots.txt';
        $response = $this->fetch($url, $domain, $state);
        if (!isset($response['body'])) {
            // A missing robots.txt (404/410) imposes no rules; access errors fail closed.
            $missing = in_array((int) ($response['status'] ?? 0), [404, 410], true);
            $state['robots'][$host] = ['checked' => time(), 'rules' => $missing ? [] : [['allow' => false, 'path' => '/']]];
            return;
        }
        $state['robots'][$host] = ['checked' => time(), 'rules' => $this->parseRobots($response['body'])];
        foreach (preg_split('/\R/', $response['body']) as $line) {
            if (preg_match('~^\s*Sitemap:\s*(\S+)~i', $line, $m)) {
                $this->enqueue($state, $m[1], $domain, 0, 0);
            }
        }
    }

    private function discoverWithGemini(Source $source, string $domain): array
    {
        $model = (string) config('services.gemini.models.discovery');
        if ($model === '') {
            throw new RuntimeException('Gemini discovery model is not configured.');
        }

        $prompt = 'Find genuine publicly indexed news articles from the website ' . $domain .
            '. Return ONLY a JSON array of objects with keys url, title, published_at, summary. ' .
            'Use exact, verifiable article URLs on this domain; do not invent URLs, titles or dates. ' .
            'Only include articles published between ' . $this->windowFrom->toIso8601String() .
            ' and ' . $this->windowTo->toIso8601String() . ', inclusive. ' .
            'If no verifiable recent articles are available, return [].';

        $response = $this->gemini->generateText(model: $model, prompt: $prompt);
        $text = '';
        foreach (array_reverse($response['steps'] ?? []) as $step) {
            if (($step['type'] ?? null) !== 'model_output') {
                continue;
            }
            foreach ($step['content'] ?? [] as $content) {
                if (($content['type'] ?? null) === 'text' && is_string($content['text'] ?? null)) {
                    $text = $content['text'];
                    break 2;
                }
            }
        }
        $text = trim(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($text)));
        $items = json_decode($text, true);
        $articles = [];
        if (is_array($items) && array_is_list($items)) {
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $this->addArticle(
                    $articles,
                    (string) ($item['url'] ?? ''),
                    (string) ($item['title'] ?? ''),
                    (string) ($item['published_at'] ?? ''),
                    (string) ($item['summary'] ?? ''),
                    $domain
                );
            }
        }

        $usage = $response['usage'] ?? [];
        $usage['google_search_count'] ??= 0;
        return ['articles' => array_values($articles), 'usage' => $usage];
    }

    private function fetch(string $url, string $domain, array &$state): array
    {
        $redirects = 0;
        $seen = [];
        while ($redirects <= self::MAX_REDIRECTS) {
            if (!$this->allowedUrl($url, $domain) || isset($seen[$url])) {
                return ['status' => 0];
            }
            $seen[$url] = true;
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if ((parse_url($url, PHP_URL_PATH) ?: '/') !== '/robots.txt' &&
                !$this->robotsAllowed($url, $state['robots'][$host]['rules'] ?? [])) {
                return ['status' => 403];
            }
            try {
                $response = Http::withoutRedirecting()->timeout(self::REQUEST_TIMEOUT)->connectTimeout(5)
                    ->withHeaders(['User-Agent' => 'PredictionContentBot/1.0',
                        'Accept' => 'text/html,application/rss+xml,application/atom+xml,application/xml,text/xml'])
                    ->get($url);
                $status = $response->status();
                if (in_array($status, [301, 302, 303, 307, 308], true)) {
                    $location = $response->header('Location');
                    $next = $location ? $this->absoluteUrl($url, $location) : null;
                    if ($next === null || !$this->allowedUrl($next, $domain)) {
                        return ['status' => $status];
                    }
                    $url = $next;
                    $redirects++;
                    continue;
                }
                if ($status !== 200) {
                    Log::debug('Discovery HTTP rejected', ['url' => $url, 'status' => $status]);
                    return ['status' => $status, 'retry_after' => 60];
                }
                $body = $response->body();
                if (strlen($body) > self::MAX_BODY_BYTES) {
                    return ['status' => 413];
                }
                if (str_ends_with(strtolower((string) parse_url($url, PHP_URL_PATH)), '.gz')) {
                    $decoded = @gzdecode($body);
                    if ($decoded === false || strlen($decoded) > 10_000_000) {
                        return ['status' => 413];
                    }
                    $body = $decoded;
                }
                return ['body' => $body, 'type' => strtolower($response->header('Content-Type', '')),
                    'url' => $url, 'status' => 200, 'redirects' => $redirects];
            } catch (Throwable $e) {
                Log::warning('Discovery HTTP error', ['url' => $url, 'error' => $e->getMessage()]);
                return ['status' => 0];
            }
        }
        return ['status' => 310];
    }

    private function isXml(string $type, string $body): bool
    {
        return str_contains($type, 'xml') ||
            (bool) preg_match('~^(?:\xEF\xBB\xBF)?\s*(?:<\?xml|<(?:rss|feed|urlset|sitemapindex)\b)~i', $body);
    }

    private function processXml(string $body, string $domain, array &$state, array &$articles, string $baseUrl, int $depth): ?string
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
            if ($xml === false) {
                return null;
            }
            $root = strtolower($xml->getName());
            if ($root === 'sitemapindex' || $root === 'urlset') {
                foreach ($xml->children() as $item) {
                    $loc = $this->absoluteUrl($baseUrl, trim((string) $item->loc));
                    if ($loc === null) {
                        continue;
                    }
                    if ($root === 'sitemapindex') {
                        $lastmod = trim((string) $item->lastmod);
                        if ($lastmod !== '' && $this->isDefinitelyOldDate($lastmod)) {
                            continue;
                        }
                        $this->enqueue($state, $loc, $domain, $depth + 1, 0);
                    } else {
                        $news = $item->children('http://www.google.com/schemas/sitemap-news/0.9');
                        $newsItem = $news->news;
                        $newsTitle = trim((string) ($newsItem->title ?? ''));
                        $newsDate = trim((string) ($newsItem->publication_date ?? ''));
                        if ($newsDate !== '' && !$this->isRecentDate($newsDate)) {
                            continue;
                        }
                        $lastmod = trim((string) $item->lastmod);
                        if ($newsDate === '' && $lastmod !== '' && $this->isDefinitelyOldDate($lastmod)) {
                            continue;
                        }
                        if ($newsTitle !== '' && $newsDate !== '') {
                            $this->addArticle($articles, $loc, $newsTitle, $newsDate, '', $domain);
                        }
                        if ($this->likelyArticle($loc)) {
                            $this->enqueue($state, $loc, $domain, $depth + 1, 1);
                        }
                    }
                }
                return 'sitemap';
            }
            if ($root === 'rss') {
                foreach ($xml->channel->item as $item) {
                    $url = $this->absoluteUrl($baseUrl, trim((string) $item->link));
                    if ($url === null) {
                        continue;
                    }
                    $publishedAt = trim((string) $item->pubDate);
                    if (!$this->isRecentDate($publishedAt)) {
                        continue;
                    }
                    $this->addArticle($articles, $url, (string) $item->title, $publishedAt,
                        (string) $item->description, $domain);
                    $this->enqueue($state, $url, $domain, $depth + 1, 1);
                }
                return 'rss';
            }
            if ($root === 'feed') {
                foreach ($xml->entry as $entry) {
                    $url = null;
                    foreach ($entry->link as $link) {
                        $attrs = $link->attributes();
                        if (!isset($attrs['rel']) || (string) $attrs['rel'] === 'alternate') {
                            $url = $this->absoluteUrl($baseUrl, (string) $attrs['href']);
                            break;
                        }
                    }
                    if ($url === null) {
                        continue;
                    }
                    $publishedAt = trim((string) ($entry->published ?: $entry->updated));
                    if (!$this->isRecentDate($publishedAt)) {
                        continue;
                    }
                    $this->addArticle($articles, $url, (string) $entry->title,
                        $publishedAt, (string) $entry->summary, $domain);
                    $this->enqueue($state, $url, $domain, $depth + 1, 1);
                }
                return 'rss';
            }
            return null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function processHtml(
        string $body,
        string $url,
        string $domain,
        array &$state,
        array &$articles,
        int $depth
    ): void {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);

        try {
            if (!$dom->loadHTML($body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                return;
            }

            $xpath = new DOMXPath($dom);
            $canonical = trim((string) $xpath->evaluate('string(//link[@rel="canonical"]/@href)'));
            $articleUrl = $canonical !== '' ? $this->absoluteUrl($url, $canonical) : $url;
            $date = $this->meta($xpath, ['article:published_time', 'datePublished', 'pubdate']);
            $ogType = strtolower($this->meta($xpath, ['og:type']));
            $structuredArticle = false;

            // JSON-LD is common on news sites that do not expose article:published_time.
            foreach ($xpath->query('//script[@type="application/ld+json"]') as $script) {
                $data = json_decode($script->textContent, true);
                if (!is_array($data)) {
                    continue;
                }
                $stack = [$data];
                while ($stack !== []) {
                    $item = array_pop($stack);
                    if (!is_array($item)) {
                        continue;
                    }
                    $type = $item['@type'] ?? null;
                    $types = is_array($type) ? $type : [$type];
                    if (array_intersect($types, ['Article', 'NewsArticle', 'BlogPosting', 'ReportageNewsArticle', 'AnalysisNewsArticle']) !== []) {
                        $structuredArticle = true;
                        if ($date === '' && is_string($item['datePublished'] ?? null)) {
                            $date = $item['datePublished'];
                        }
                    }
                    foreach (['@graph', 'mainEntity'] as $key) {
                        if (isset($item[$key]) && is_array($item[$key])) {
                            $stack[] = $item[$key];
                        }
                    }
                    if (array_is_list($item)) {
                        foreach ($item as $child) {
                            if (is_array($child)) {
                                $stack[] = $child;
                            }
                        }
                    }
                }
            }

            $isArticlePage = $date !== '' || $ogType === 'article' || $structuredArticle
                || $this->likelyArticle($url);

            // Only real article pages are stored. A category's first <time> is
            // usually the date of a linked article, not the category publication date.
            if ($isArticlePage && $articleUrl !== null && !$this->isNonContentUrl($articleUrl)) {
                if ($date === '') {
                    $date = trim((string) $xpath->evaluate('string((//time[@datetime])[1]/@datetime)'));
                }
                $title = $this->meta($xpath, ['og:title', 'twitter:title']);
                $title = $title ?: trim((string) $xpath->evaluate('string(//h1[1])'));
                $content = null;
                $nodes = $xpath->query('//main//article | //article');
                if ($nodes !== false && $nodes->length > 0) {
                    $articleNode = $nodes->item(0);
                    if ($articleNode !== null) {
                        foreach ($xpath->query('.//script | .//style | .//nav | .//footer | .//aside', $articleNode) as $node) {
                            $node->parentNode?->removeChild($node);
                        }
                        $content = trim(preg_replace('/\s+/u', ' ', $articleNode->textContent) ?? '');
                    }
                }
                $this->addArticle(
                    $articles,
                    $articleUrl,
                    $title,
                    $date,
                    $this->meta($xpath, ['og:description', 'description']),
                    $domain,
                    $content
                );
            }

            // Article pages never generate further crawl tasks, regardless of age.
            if ($isArticlePage || $this->isNonContentUrl($url)) {
                return;
            }

            $isHome = trim((string) parse_url($url, PHP_URL_PATH), '/') === '';
            $articleCandidates = [];
            $sectionCandidates = [];
            $feedCandidates = [];
            foreach ($xpath->query('//link[@href] | //a[@href]') as $link) {
                $absolute = $this->absoluteUrl($url, $link->getAttribute('href'));
                if ($absolute === null || !$this->allowedUrl($absolute, $domain)
                    || $this->isNonContentUrl($absolute) || $this->isExpiredDatedSitemap($absolute)) {
                    continue;
                }
                $absolute = $this->normalizeUrl($absolute);
                if ($absolute === null || $absolute === $this->normalizeUrl($url)) {
                    continue;
                }
                $path = strtolower((string) parse_url($absolute, PHP_URL_PATH));
                $type = strtolower($link->getAttribute('type'));
                if (str_contains($type, 'rss') || str_contains($type, 'atom')
                    || preg_match('~/(?:rss|feed)(?:\.xml)?/?$~i', $path)
                    || str_contains($path, 'sitemap')) {
                    $feedCandidates[$absolute] = 0;
                    continue;
                }
                if ($this->likelyArticle($absolute)) {
                    $articleCandidates[$absolute] = 1;
                    continue;
                }
                // Only the home page can discover new sections. Section pages
                // discover articles, not other sections or tag archives.
                if ($isHome && $depth < self::MAX_HTML_SECTION_DEPTH
                    && substr_count(trim($path, '/'), '/') === 0 && $path !== '/') {
                    $sectionCandidates[$absolute] = 2;
                }
            }

            // A non-home page with <=10 article links is not treated as a
            // navigation hub. This is a heuristic, not a guarantee of completeness.
            if (!$isHome && count($articleCandidates) < self::MIN_SECTION_ARTICLE_LINKS) {
                return;
            }

            $candidates = $feedCandidates + $articleCandidates + $sectionCandidates;
            asort($candidates);
            $added = 0;
            foreach ($candidates as $candidate => $priority) {
                if ($added >= self::MAX_LINKS_PER_PAGE) {
                    break;
                }
                if ($this->enqueue($state, $candidate, $domain, $depth + 1, $priority)) {
                    $added++;
                }
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function isNonContentUrl(string $url): bool
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        return (bool) preg_match(
            '~(?:^|/)(?:stiri-despre|tag|tags|topic|topics|author|authors|search|login|register|account|privacy|terms|contact|user|wp-admin)(?:/|$)~i',
            $path
        );
    }

    private function addArticle(
        array &$articles,
        string $url,
        string $title,
        string $date,
        string $summary,
        string $domain,
        ?string $content = null
    ): void {
        if (
            !$this->allowedUrl($url, $domain)
            || trim($title) === ''
            || !$this->isRecentDate($date)
        ) {
            return;
        }

        $content = $content !== null
            ? trim($content)
            : null;

        $articles[$url] = [
            'url' => $url,
            'title' => trim(strip_tags($title)),
            'published_at' => date(DATE_ATOM, strtotime($date)),
            'summary' => trim(strip_tags($summary)) ?: null,
            'content' => $content !== '' ? $content : null,
        ];
    }


    private function meta(DOMXPath $xpath, array $names): string
    {
        foreach ($xpath->query('//meta[@content]') as $node) {
            $key = strtolower($node->getAttribute('property') ?: $node->getAttribute('name'));
            if (in_array($key, array_map('strtolower', $names), true)) {
                return trim($node->getAttribute('content'));
            }
        }
        return '';
    }

    private function enqueue(array &$state, string $url, string $domain, int $depth = 0, int $priority = 2, bool $force = false): bool
    {
        $url = $this->normalizeUrl($url);
        if ($url === null || $depth > self::MAX_DEPTH || !$this->allowedUrl($url, $domain) || $this->isExpiredDatedSitemap($url) || $this->isNonContentUrl($url)) {
            return false;
        }
        if (!$force && isset($state['visited'][$url]) && time() - (int) $state['visited'][$url] < $this->revisitInterval($url)) {
            return false;
        }
        if (isset($state['queued_index'][$url])) {
            foreach ($state['queue'] as &$item) {
                if ($item['url'] !== $url) {
                    continue;
                }
                $item['priority'] = min($item['priority'], $priority);
                $item['depth'] = min($item['depth'], $depth);
                return false;
            }
            unset($item);
        }
        // No silent truncation: discarding pending URLs would make
        // crawl_complete unreliable. A high cap is a safety limit only.
        if (count($state['queue']) >= self::MAX_PENDING_URLS) {
            throw new RuntimeException('Discovery queue capacity exceeded; pending URLs were not discarded.');
        }
        $state['queue'][] = ['url' => $url, 'depth' => $depth, 'priority' => $priority];
        $state['queued_index'][$url] = true;
        return true;
    }

    /**
     * Restrict permanent deduplication to article-like URLs.
     * A .html extension alone is not enough: news sites use it for pagination.
     */
    private function isPermanentArticleUrl(string $url): bool
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        if ($path === '/' || $this->isNonContentUrl($url)
            || str_contains($path, 'sitemap')
            || preg_match('~/(?:rss|feed)(?:\.xml)?/?$~i', $path)
            || preg_match('~(?:^|/)(?:pagina|page|pagination|paginare)[-_/]?\d+(?:\.html?)?/?$~i', $path)
            || preg_match('~/(?:ultimele-stiri|latest-news)/?$~i', $path)) {
            return false;
        }

        return $this->likelyArticle($url);
    }

    private function likelyArticle(string $url): bool
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        if ($this->isNonContentUrl($url)) {
            return false;
        }
        return (bool) preg_match('~\.(?:html?|php)$~i', $path)
            || (bool) preg_match('~/(?:20\d{2}/\d{1,2}/|\d{6,}|[^/]{40,})(?:/|$)~', $path);
    }

    private function normalizeUrl(string $url): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5));
        if ($url === '') {
            return null;
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $path = $parts['path'] ?? '/';
        $path = preg_replace('~/+~', '/', $path);
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '' && $segment !== '.') {
                $segments[] = $segment;
            }
        }
        $path = '/' . implode('/', $segments);
        $query = [];
        parse_str($parts['query'] ?? '', $query);
        foreach (array_keys($query) as $key) {
            if (preg_match('/^(utm_|fbclid$|gclid$|mc_)/i', $key)) {
                unset($query[$key]);
            }
        }
        ksort($query);
        return strtolower($parts['scheme']) . '://' . strtolower($parts['host']) . $path .
            ($query !== [] ? '?' . http_build_query($query) : '');
    }

    private function isRecentDate(string $date): bool
    {
        if (trim($date) === '') {
            return false;
        }
        try {
            $published = CarbonImmutable::parse($date);
            return $published->betweenIncluded($this->windowFrom, $this->windowTo);
        } catch (Throwable) {
            return false;
        }
    }

    private function isDefinitelyOldDate(string $date): bool
    {
        try {
            return CarbonImmutable::parse($date)->lt($this->windowFrom);
        } catch (Throwable) {
            return false;
        }
    }

    private function isExpiredDatedSitemap(string $url): bool
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        if (!str_contains($path, 'sitemap')) {
            return false;
        }
        $cutoff = $this->windowFrom;
        // Monthly sitemap: skip only if the whole month precedes the cutoff.
        if (preg_match('~(?<!\d)(20\d{2})[./_-](0?[1-9]|1[0-2])(?!\d)~', $path, $m)) {
            return CarbonImmutable::create((int) $m[1], (int) $m[2], 1)
                ->endOfMonth()->lt($cutoff);
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        if (isset($query['yyyy'], $query['mm'], $query['dd'])
            && ctype_digit((string) $query['yyyy'])
            && ctype_digit((string) $query['mm'])
            && ctype_digit((string) $query['dd'])
            && checkdate((int) $query['mm'], (int) $query['dd'], (int) $query['yyyy'])) {
            return CarbonImmutable::create(
                (int) $query['yyyy'], (int) $query['mm'], (int) $query['dd']
            )->endOfDay()->lt($cutoff);
        }
        return false;
    }

    private function absoluteUrl(string $base, string $href): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5));
        if ($href === '' || str_starts_with($href, '#') ||
            preg_match('~^(mailto:|tel:|javascript:|data:)~i', $href)) {
            return null;
        }
        if (str_starts_with($href, '//')) {
            return 'https:' . $href;
        }
        if (preg_match('~^https?://~i', $href)) {
            return $href;
        }
        $parts = parse_url($base);
        if (!isset($parts['host'])) {
            return null;
        }
        $origin = 'https://' . $parts['host'];
        if (str_starts_with($href, '/')) {
            return $origin . $href;
        }
        if (str_starts_with($href, '?')) {
            return $origin . ($parts['path'] ?? '/') . $href;
        }
        $path = $parts['path'] ?? '/';
        $directory = substr($path, 0, strrpos($path, '/') + 1);
        $segments = [];
        foreach (explode('/', $directory . $href) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '' && $segment !== '.') {
                $segments[] = $segment;
            }
        }
        return $origin . '/' . implode('/', $segments);
    }

    private function allowedUrl(string $url, string $domain): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);
        if (strtolower($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) ||
            isset($parts['pass']) || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            return false;
        }
        $host = strtolower($parts['host'] ?? '');
        if (!in_array($host, [$domain, 'www.' . $domain], true)) {
            return false;
        }
        return !preg_match('~\.(jpg|jpeg|png|gif|webp|svg|pdf|zip|mp4|mp3|css|js|woff2?)$~i',
            $parts['path'] ?? '');
    }

    private function parseRobots(string $body): array
    {
        $groups = [];
        $agents = [];
        $rules = [];
        foreach (preg_split('/\R/', $body) as $line) {
            $line = trim(explode('#', $line, 2)[0]);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^User-agent:\s*(.+)$/i', $line, $m)) {
                if ($agents !== [] && $rules !== []) {
                    $groups[] = ['agents' => $agents, 'rules' => $rules];
                    $agents = [];
                    $rules = [];
                }
                $agents[] = strtolower(trim($m[1]));
            } elseif (preg_match('/^(Allow|Disallow):\s*(.*)$/i', $line, $m)) {
                $rules[] = ['allow' => strtolower($m[1]) === 'allow', 'path' => trim($m[2])];
            }
        }
        if ($agents !== []) {
            $groups[] = ['agents' => $agents, 'rules' => $rules];
        }
        $specific = [];
        $general = [];
        foreach ($groups as $group) {
            if (in_array('predictioncontentbot', $group['agents'], true)) {
                $specific = array_merge($specific, $group['rules']);
            } elseif (in_array('*', $group['agents'], true)) {
                $general = array_merge($general, $group['rules']);
            }
        }
        return $specific !== [] ? $specific : $general;
    }

    private function robotsAllowed(string $url, array $rules): bool
    {
        $path = (parse_url($url, PHP_URL_PATH) ?: '/') .
            (($query = parse_url($url, PHP_URL_QUERY)) ? '?' . $query : '');
        $bestLength = -1;
        $allowed = true;
        foreach ($rules as $rule) {
            $pattern = (string) $rule['path'];
            if ($pattern === '') {
                continue;
            }
            $regex = '~^' . str_replace('\\*', '.*', preg_quote(rtrim($pattern, '$'), '~')) .
                (str_ends_with($pattern, '$') ? '$' : '') . '~';
            if (preg_match($regex, $path) && strlen($pattern) >= $bestLength) {
                $bestLength = strlen($pattern);
                $allowed = (bool) $rule['allow'];
            }
        }
        return $allowed;
    }
}

