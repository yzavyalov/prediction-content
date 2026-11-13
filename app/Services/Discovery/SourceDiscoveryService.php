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
    private const ARTICLE_DAYS = 7;
    private const CACHE_TTL = 2592000;
    private const REQUEST_TIMEOUT = 10;
    private const MAX_CONSECUTIVE_FAILURES = 25;
    private const MAX_PENDING_URLS = 400;
    private const MAX_VISITED_URLS = 1200;

    public function __construct(private readonly GeminiService $gemini)
    {
    }

    public function discover(
        Source $source,
        Category $category,
        Country|CountryGroup $geo,
        CarbonInterface $from,
        CarbonInterface $to,
        ?callable $onArticle = null,
    ): array {
        $domain = strtolower(trim((string) $source->domain));
        $domain = preg_replace('~^https?://~i', '', $domain);
        $domain = explode('/', $domain)[0];
        $domain = preg_replace('/^www\./', '', $domain);

        if (!preg_match('/^(?=.{4,253}$)[a-z0-9-]+(?:\.[a-z0-9-]+)+$/', $domain)) {
            throw new RuntimeException('Invalid source domain.');
        }

        $key = 'discovery:crawl:v5:' . $source->id;
        $state = Cache::get($key);
        if (!is_array($state) || !isset($state['queue'], $state['visited'])) {
            $state = ['queue' => [], 'visited' => [], 'robots' => []];
        }
        $state['robots'] ??= [];
        // Bound persisted state even if an older run left a large backlog.
        $state['queue'] = array_slice(array_values(array_unique($state['queue'])), 0, self::MAX_PENDING_URLS);
        $state['visited'] = array_slice($state['visited'], -self::MAX_VISITED_URLS, null, true);

        $hosts = [$domain, 'www.' . $domain];
        $paths = [
            '/', '/robots.txt', '/rss', '/rss.xml', '/feed', '/feed.xml',
            '/sitemap.xml', '/sitemap_index.xml',
        ];

        // Retry entry points each run; retain the archive crawl cursor.
        $seeds = [];
        foreach ($paths as $path) {
            foreach ($hosts as $host) {
                $url = 'https://' . $host . $path;
                unset($state['visited'][$url]);
                $seeds[] = $url;
            }
        }
        $state['queue'] = array_slice(array_values(array_unique(array_merge($seeds, $state['queue']))), 0, self::MAX_PENDING_URLS);

        $articles = [];
        $found = 0;
        $requests = 0;
        $failed = 0;
        $http200 = ['html' => false, 'rss' => false, 'sitemap' => false];
        $started = microtime(true);
        $consecutiveFailures = 0;
        $deferred = [];
        $seenThisRun = [];

        while ($state['queue'] !== [] && microtime(true) - $started < self::WORK_SECONDS) {
            $url = array_shift($state['queue']);
            if ($this->isExpiredDatedSitemap($url)) {
                continue;
            }
            if (isset($seenThisRun[$url]) || isset($state['visited'][$url])) {
                continue;
            }
            $seenThisRun[$url] = true;

            if (!$this->allowedUrl($url, $domain)) {
                continue;
            }
            $path = parse_url($url, PHP_URL_PATH) ?: '/';
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            $isRobots = $path === '/robots.txt';
            if (!$isRobots && isset($state['robots'][$host]) &&
                !$this->robotsAllowed($url, $state['robots'][$host])) {
                $state['visited'][$url] = true;
                continue;
            }

            $response = $this->fetch($url, $domain);
            $requests++;
            if ($response === null) {
                $failed++;
                $consecutiveFailures++;
                $deferred[] = $url;
                if ($consecutiveFailures >= self::MAX_CONSECUTIVE_FAILURES) {
                    break;
                }
                continue;
            }
            $consecutiveFailures = 0;
            $body = $response['body'];
            $type = $response['type'];
            $discovered = [];

            if ($isRobots) {
                $state['robots'][$host] = $this->parseRobots($body);
                foreach (preg_split('/\R/', $body) as $line) {
                    if (preg_match('~^\s*Sitemap:\s*(\S+)~i', $line, $match)) {
                        $this->enqueue($state, $match[1], $domain);
                    }
                }
            } elseif ($this->isXml($type, $body)) {
                $kind = $this->processXml($body, $domain, $state, $discovered);
                if ($kind === 'rss') {
                    $http200['rss'] = true;
                } elseif ($kind === 'sitemap') {
                    $http200['sitemap'] = true;
                }
            } elseif (str_contains($type, 'html')) {
                $http200['html'] = true;
                $this->processHtml($body, $url, $domain, $state, $discovered);
            }

            // A failed DB write throws; do not advance the cursor past that page.
            foreach ($discovered as $article) {
                if ($onArticle !== null) {
                    $onArticle($article);
                } else {
                    $articles[] = $article;
                }
                $found++;
            }
            $state['visited'][$url] = true;
            $state['visited'] = array_slice($state['visited'], -self::MAX_VISITED_URLS, null, true);
            Cache::put($key, $state, self::CACHE_TTL);
            usleep(150000);
        }

        $state['queue'] = array_slice(array_values(array_unique(array_merge($state['queue'], $deferred))), 0, self::MAX_PENDING_URLS);
        Cache::put($key, $state, self::CACHE_TTL);

        $usage = ['google_search_count' => 0];
        $fallbackUsed = false;
        if (!$http200['html'] && !$http200['rss'] && !$http200['sitemap'] && $found === 0) {
            $fallbackUsed = true;
            $fallback = $this->discoverWithGemini($source, $domain);
            $usage = $fallback['usage'];
            foreach ($fallback['articles'] as $article) {
                if ($onArticle !== null) {
                    $onArticle($article);
                } else {
                    $articles[] = $article;
                }
                $found++;
            }
        }

        Log::info('Discovery crawl finished', [
            'source_id' => $source->id,
            'requests' => $requests,
            'articles' => $found,
            'failed_requests' => $failed,
            'remaining_urls' => count($state['queue']),
            'http_200' => $http200,
            'gemini_fallback' => $fallbackUsed,
        ]);

        return [
            'articles' => $articles,
            'usage' => $usage,
            'raw_response' => [
                'requests' => $requests,
                'articles' => $found,
                'failed_requests' => $failed,
                'remaining_urls' => count($state['queue']),
                'crawl_complete' => $state['queue'] === [],
                'http_200' => $http200,
                'gemini_fallback' => $fallbackUsed,
            ],
        ];
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
            'Only include articles published within the last 7 days, not older or future-dated. ' .
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

    private function fetch(string $url, string $domain): ?array
    {
        if (!$this->allowedUrl($url, $domain)) {
            return null;
        }
        try {
            $response = Http::withoutRedirecting()
                ->timeout(self::REQUEST_TIMEOUT)
                ->connectTimeout(5)
                ->withHeaders([
                    'User-Agent' => 'PredictionContentBot/1.0',
                    'Accept' => 'text/html,application/rss+xml,application/atom+xml,application/xml,text/xml',
                ])->get($url);

            if ($response->status() !== 200) {
                Log::debug('Discovery HTTP rejected', ['url' => $url, 'status' => $response->status()]);
                return null;
            }
            return [
                'body' => $response->body(),
                'type' => strtolower($response->header('Content-Type', '')),
            ];
        } catch (Throwable $e) {
            Log::warning('Discovery HTTP error', ['url' => $url, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function isXml(string $type, string $body): bool
    {
        return str_contains($type, 'xml') ||
            (bool) preg_match('~^\s*(?:<\?xml|<(?:rss|feed|urlset|sitemapindex)\b)~i', $body);
    }

    private function processXml(string $body, string $domain, array &$state, array &$articles): ?string
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET);
            if ($xml === false) {
                return null;
            }
            $root = strtolower($xml->getName());
            if (in_array($root, ['sitemapindex', 'urlset'], true)) {
                foreach ($xml->children() as $item) {
                    $loc = trim((string) $item->loc);
                    if ($this->isExpiredDatedSitemap($loc)) {
                        continue;
                    }
                    // lastmod is a change date, so it is only a preliminary filter.
                    $lastmod = trim((string) $item->lastmod);
                    if ($lastmod !== '' && !$this->isRecentDate($lastmod)) {
                        continue;
                    }
                    $this->enqueue($state, $loc, $domain);
                }
                return 'sitemap';
            }
            if ($root === 'rss') {
                foreach ($xml->channel->item as $item) {
                    $url = trim((string) $item->link);
                    $this->addArticle($articles, $url, (string) $item->title,
                        (string) $item->pubDate, (string) $item->description, $domain);
                }
                return 'rss';
            }
            if ($root === 'feed') {
                foreach ($xml->entry as $entry) {
                    $url = '';
                    foreach ($entry->link as $link) {
                        $attrs = $link->attributes();
                        if (!isset($attrs['rel']) || (string) $attrs['rel'] === 'alternate') {
                            $url = (string) $attrs['href'];
                            break;
                        }
                    }
                    $this->addArticle($articles, $url, (string) $entry->title,
                        (string) ($entry->published ?: $entry->updated),
                        (string) $entry->summary, $domain);
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
        array &$articles
    ): void {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);

        try {
            if (!$dom->loadHTML(
                $body,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
            )) {
                return;
            }

            $xpath = new DOMXPath($dom);

            $title = $this->meta($xpath, [
                'og:title',
                'twitter:title',
            ]);

            $title = $title ?: trim(
                (string) $xpath->evaluate('string(//h1[1])')
            );

            $date = $this->meta($xpath, [
                'article:published_time',
                'datePublished',
                'pubdate',
            ]);

            $date = $date ?: trim(
                (string) $xpath->evaluate(
                    'string((//time[@datetime])[1]/@datetime)'
                )
            );

            $canonical = trim(
                (string) $xpath->evaluate(
                    'string(//link[@rel="canonical"]/@href)'
                )
            );

            $articleUrl = $canonical !== ''
                ? $this->absoluteUrl($url, $canonical)
                : $url;

            if ($articleUrl === null) {
                return;
            }

            // Extract text only from likely article content.
            $content = '';

            $nodes = $xpath->query(
                '//article | //main//article'
            );

            if ($nodes !== false && $nodes->length > 0) {
                $articleNode = $nodes->item(0);

                if ($articleNode !== null) {
                    foreach ($xpath->query(
                        './/script | .//style | .//nav | .//footer | .//aside',
                        $articleNode
                    ) as $node) {
                        $node->parentNode?->removeChild($node);
                    }

                    $content = trim(
                        preg_replace(
                            '/\s+/u',
                            ' ',
                            $articleNode->textContent
                        ) ?? ''
                    );
                }
            }

            $this->addArticle(
                $articles,
                $articleUrl,
                $title,
                $date,
                $this->meta($xpath, [
                    'og:description',
                    'description',
                ]),
                $domain,
                $content
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
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

    private function enqueue(array &$state, string $url, string $domain): void
    {
        if (count($state['queue']) < self::MAX_PENDING_URLS &&
            !$this->isExpiredDatedSitemap($url) &&
            $this->allowedUrl($url, $domain) && !isset($state['visited'][$url]) &&
            !in_array($url, $state['queue'], true)) {
            $state['queue'][] = $url;
        }
    }

    private function isRecentDate(string $date): bool
    {
        if (trim($date) === '') {
            return false;
        }
        try {
            $published = CarbonImmutable::parse($date);
            $now = CarbonImmutable::now();
            return $published->betweenIncluded($now->subDays(self::ARTICLE_DAYS), $now);
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
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        if (!isset($query['yyyy'], $query['mm'], $query['dd'])) {
            return false;
        }
        $year = (string) $query['yyyy'];
        $month = (string) $query['mm'];
        $day = (string) $query['dd'];
        if (!ctype_digit($year) || !ctype_digit($month) || !ctype_digit($day) ||
            !checkdate((int) $month, (int) $day, (int) $year)) {
            return false;
        }
        $date = CarbonImmutable::create((int) $year, (int) $month, (int) $day, 0, 0, 0);
        return $date->endOfDay()->lt(CarbonImmutable::now()->subDays(self::ARTICLE_DAYS));
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
                if ($rules !== []) {
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
