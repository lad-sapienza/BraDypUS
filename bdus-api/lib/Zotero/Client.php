<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

declare(strict_types=1);

namespace Zotero;

/**
 * Thin wrapper around the Zotero REST API v3.
 *
 * Supports both user libraries (users/{id}) and group libraries (groups/{id}).
 * All methods throw ZoteroException on HTTP or network errors.
 *
 * Usage:
 *   $client = new Client('group', '123456', 'myApiKey');
 *   $items  = $client->search('Hodder 2012');
 *   $html   = $client->getFormattedCitation('X7K2M3P', 'chicago-author-date');
 */
class Client
{
    private const API_BASE    = 'https://api.zotero.org';
    private const API_VERSION = 3;

    /** Default citation style — Chicago Manual of Style 17th ed. author-date.
     *  Zotero does not yet publish a separate CMS 18 slug; update when available.
     *  Any valid CSL style identifier accepted by the Zotero API can be used. */
    public const DEFAULT_STYLE = 'chicago-author-date';

    /** Maximum items Zotero allows in a single itemKey= batch request. */
    private const MAX_BATCH = 50;

    private string $type;      // 'user' | 'group'
    private string $zoteroId;
    private ?string $apiKey;
    private int $timeout;

    /**
     * @param string      $type     'user' or 'group'
     * @param string      $zoteroId Zotero user or group numeric ID
     * @param string|null $apiKey   Optional API key (required for private libraries)
     * @param int         $timeout  HTTP timeout in seconds (default 10)
     */
    public function __construct(
        string $type,
        string $zoteroId,
        ?string $apiKey = null,
        int $timeout = 10
    ) {
        if (!in_array($type, ['user', 'group'], true)) {
            throw new ZoteroException("Invalid library type '{$type}': must be 'user' or 'group'");
        }
        $this->type     = $type;
        $this->zoteroId = $zoteroId;
        $this->apiKey   = $apiKey;
        $this->timeout  = $timeout;
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Full-text search across the library.
     *
     * @param  string $q     Search query
     * @param  int    $limit Max results (1–100, Zotero default 25)
     * @return array         Array of Zotero item objects (decoded JSON)
     */
    public function search(string $q, int $limit = 25): array
    {
        $url = $this->baseUrl() . '/items?' . http_build_query([
            'q'      => $q,
            'limit'  => min(100, max(1, $limit)),
            'format' => 'json',
        ]);
        return $this->get($url);
    }

    /**
     * Fetch one or more items by their Zotero keys in a single HTTP request.
     *
     * @param  string[] $keys  Zotero item keys (e.g. ['X7K2M3P', 'AB123CD'])
     * @return array           Associative array keyed by Zotero item key
     * @throws ZoteroException
     */
    public function getItems(array $keys): array
    {
        if (empty($keys)) {
            return [];
        }

        $result = [];

        // Zotero allows at most MAX_BATCH keys per request.
        foreach (array_chunk($keys, self::MAX_BATCH) as $chunk) {
            $url = $this->baseUrl() . '/items?' . http_build_query([
                'itemKey' => implode(',', $chunk),
                'format'  => 'json',
            ]);
            $items = $this->get($url);
            foreach ($items as $item) {
                $key = $item['key'] ?? null;
                if ($key) {
                    $result[$key] = $item;
                }
            }
        }

        return $result;
    }

    /**
     * Returns a formatted citation for a single item using the given CSL style.
     *
     * The Zotero API returns an HTML <div> with the citation; strip tags if
     * plain text is needed.
     *
     * @param  string $key   Zotero item key
     * @param  string $style CSL style identifier (e.g. 'chicago-author-date')
     * @return string        HTML-formatted citation
     * @throws ZoteroException
     */
    public function getFormattedCitation(string $key, string $style = self::DEFAULT_STYLE): string
    {
        $url = $this->baseUrl() . '/items/' . urlencode($key) . '?' . http_build_query([
            'format' => 'bib',
            'style'  => $style,
        ]);
        return $this->getRaw($url);
    }

    /**
     * Extracts a short "Author Year" label from a Zotero JSON item.
     *
     * Used to populate the author_year cache column. Falls back to the item
     * title if no usable creator is found.
     *
     * @param  array  $item  Zotero item as returned by getItems()
     * @return string        e.g. "Hodder 2012", "Anonymous", "Untitled"
     */
    public function extractAuthorYear(array $item): string
    {
        $data = $item['data'] ?? [];

        // Year
        $date = $data['date'] ?? '';
        preg_match('/\b(\d{4})\b/', $date, $m);
        $year = $m[1] ?? '';

        // First creator
        $creators = $data['creators'] ?? [];
        $lastName  = '';
        foreach ($creators as $c) {
            $lastName = $c['lastName'] ?? ($c['name'] ?? '');
            if ($lastName !== '') {
                break;
            }
        }

        if ($lastName && $year) {
            return "{$lastName} {$year}";
        }
        if ($lastName) {
            return $lastName;
        }
        if ($year) {
            return $year;
        }

        return $data['title'] ?? 'Unknown';
    }

    /**
     * Builds the public Zotero web URL for an item.
     *
     * Returns null for user libraries because personal libraries may be private
     * and do not always have a publicly accessible URL.
     *
     * @param  string $key  Zotero item key
     * @return string|null
     */
    public function buildPublicUrl(string $key): ?string
    {
        if ($this->type === 'user') {
            return null; // Personal libraries may not be public.
        }
        return "https://www.zotero.org/groups/{$this->zoteroId}/items/{$key}";
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function baseUrl(): string
    {
        return self::API_BASE . '/' . $this->type . 's/' . $this->zoteroId;
    }

    /**
     * Makes an HTTP GET and returns decoded JSON.
     *
     * @throws ZoteroException on non-2xx response or network error
     */
    private function get(string $url): array
    {
        $raw = $this->getRaw($url);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new ZoteroException("Zotero API returned non-JSON response for {$url}");
        }
        return $decoded;
    }

    /**
     * Makes an HTTP GET and returns the raw response body.
     *
     * @throws ZoteroException
     */
    private function getRaw(string $url): string
    {
        $headers = [
            'Zotero-API-Version: ' . self::API_VERSION,
        ];
        if ($this->apiKey) {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new ZoteroException("cURL error for {$url}: {$error}");
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new ZoteroException("Zotero API error {$httpCode} for {$url}");
        }

        return (string) $body;
    }
}
