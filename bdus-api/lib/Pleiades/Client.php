<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

declare(strict_types=1);

namespace Pleiades;

/**
 * Thin, read-only wrapper around the public Pleiades gazetteer
 * (https://pleiades.stoa.org). Unauthenticated — no API key.
 *
 * Two endpoints:
 *   - search_rss (RSS 1.0 / RDF) for name search — results carry no coordinates.
 *   - places/{id}/json for a single place's full record, including reprPoint.
 *
 * All methods throw PleiadesException on HTTP or network errors.
 *
 * Usage:
 *   $client  = new Client();
 *   $results = $client->search('Roma');          // [{id, title, snippet}, ...]
 *   $place   = $client->getPlace($results[0]['id']); // {id, title, reprPoint}
 */
class Client
{
    private const API_BASE = 'https://pleiades.stoa.org';

    private int $timeout;

    public function __construct(int $timeout = 10)
    {
        $this->timeout = $timeout;
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Searches Pleiades places by name (search-as-you-type suggestions).
     * No client-side result cap — Pleiades' own search already caps a single
     * response (around 100 hits, per its search API), and re-truncating here
     * would just hide results a user might actually be looking for.
     *
     * @return array<int, array{id:int, title:string, snippet:?string}>
     */
    public function search(string $q): array
    {
        $url = self::API_BASE . '/search_rss?' . http_build_query([
            'SearchableText' => $q,
            'portal_type'    => 'Place',
        ]);

        return self::parseSearchXml($this->getRaw($url));
    }

    /**
     * Fetches a single place's normalized representation.
     *
     * @return array{id:int, title:string, reprPoint:?array}
     */
    public function getPlace(int $id): array
    {
        $url     = self::API_BASE . "/places/{$id}/json";
        $decoded = json_decode($this->getRaw($url), true);

        if (!is_array($decoded)) {
            throw new PleiadesException("Pleiades API returned non-JSON response for {$url}");
        }

        return self::normalizePlaceJson($decoded);
    }

    // ── Pure helpers (unit-tested without a real network call) ─────────────────

    /**
     * Parses a Pleiades search_rss (RSS 1.0 / RDF) response into a trimmed
     * result list. Split out from search() so it can be exercised against
     * fixture XML in PHPUnit without a real network call.
     *
     * RSS 1.0 puts repeated <item> elements as direct siblings of the root
     * <rdf:RDF>, in the default (unprefixed) RSS 1.0 namespace — not nested
     * inside <channel>, which only carries an ordered index of item URIs.
     * <link> is the place URI (…/places/{id}); the numeric id is its last
     * path segment. <description> is used as a search-result snippet.
     *
     * @return array<int, array{id:int, title:string, snippet:?string}>
     */
    public static function parseSearchXml(string $xml): array
    {
        $prevSetting = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_use_internal_errors($prevSetting);

        if ($doc === false) {
            throw new PleiadesException('Pleiades search response is not valid XML');
        }

        $rss = $doc->children('http://purl.org/rss/1.0/');

        $results = [];
        foreach ($rss->item as $item) {
            $link = trim((string) $item->link);
            if (!preg_match('#/places/(\d+)/?$#', $link, $m)) {
                continue;
            }

            $snippet = trim((string) $item->description);

            $results[] = [
                'id'      => (int) $m[1],
                'title'   => trim((string) $item->title),
                'snippet' => $snippet === '' ? null : $snippet,
            ];
        }

        return $results;
    }

    /**
     * Normalizes a raw Pleiades place JSON into the {id, title, reprPoint}
     * shape the record-save flow needs. Split out from getPlace() so it can
     * be exercised against fixture JSON in PHPUnit without a real network call.
     *
     * @return array{id:int, title:string, reprPoint:?array}
     */
    public static function normalizePlaceJson(array $raw): array
    {
        $reprPoint = $raw['reprPoint'] ?? null;
        if (!is_array($reprPoint) || count($reprPoint) !== 2) {
            $reprPoint = null;
        }

        return [
            'id'        => (int) ($raw['id'] ?? 0),
            'title'     => (string) ($raw['title'] ?? ''),
            'reprPoint' => $reprPoint,
        ];
    }

    // ── Transport ────────────────────────────────────────────────────────────

    /**
     * Makes an HTTP GET and returns the raw response body.
     *
     * @throws PleiadesException
     */
    private function getRaw(string $url): string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_FOLLOWLOCATION => true,
            // PHP's cURL sends no User-Agent by default (unlike the curl CLI,
            // which sends its own) — Pleiades' front-end WAF 403s any request
            // with an empty/missing UA, so one must be set explicitly.
            CURLOPT_USERAGENT      => 'BraDypUS (+https://github.com/lad-sapienza/BraDypUS)',
        ]);

        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new PleiadesException("cURL error for {$url}: {$error}");
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new PleiadesException("Pleiades API error {$httpCode} for {$url}");
        }

        return (string) $body;
    }
}
