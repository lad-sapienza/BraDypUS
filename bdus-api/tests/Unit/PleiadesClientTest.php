<?php

declare(strict_types=1);

namespace Tests\Unit;

use Pleiades\Client;
use Pleiades\PleiadesException;
use PHPUnit\Framework\TestCase;

/**
 * Pure, network-free tests for Pleiades\Client: parseSearchXml() and
 * normalizePlaceJson(). Client::search()/getPlace()'s actual cURL calls to
 * the public Pleiades API are intentionally not exercised here (same
 * reasoning as Zotero\Client's real HTTP calls, which are only exercised in
 * a hurl phase, not PHPUnit) — the fixtures below are trimmed copies of real
 * responses captured live from pleiades.stoa.org during development
 * (search_rss?SearchableText=Roma, and places/423025/json for Rome).
 */
class PleiadesClientTest extends TestCase
{
    // ── parseSearchXml() ─────────────────────────────────────────────────────

    private const SEARCH_XML = <<<'XML'
<?xml version="1.0" encoding="utf-8" ?>
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:syn="http://purl.org/rss/1.0/modules/syndication/" xmlns="http://purl.org/rss/1.0/">
<channel rdf:about="https://pleiades.stoa.org/search_rss">
  <title>Pleiades</title>
  <link>https://pleiades.stoa.org</link>
  <items>
    <rdf:Seq>
      <rdf:li rdf:resource="https://pleiades.stoa.org/places/653874577"/>
      <rdf:li rdf:resource="https://pleiades.stoa.org/places/423025"/>
    </rdf:Seq>
  </items>
</channel>
<item rdf:about="https://pleiades.stoa.org/places/653874577">
  <title>Via Berneri columbarium</title>
  <link>https://pleiades.stoa.org/places/653874577</link>
  <description>The Via Berneri columbarium was discovered in 2021 during utility work.</description>
  <dc:publisher>No publisher</dc:publisher>
  <dc:rights>Copyright &#169; The Contributors.</dc:rights>
  <dc:date>2026/09/04 15:26:23 GMT-4</dc:date>
  <dc:type>Place</dc:type>
</item>
<item rdf:about="https://pleiades.stoa.org/places/423025">
  <title>Roma</title>
  <link>https://pleiades.stoa.org/places/423025</link>
  <description></description>
  <dc:publisher>No publisher</dc:publisher>
  <dc:rights>Copyright &#169; The Contributors.</dc:rights>
  <dc:date>2016/07/06 12:00:00 GMT-4</dc:date>
  <dc:type>Place</dc:type>
</item>
</rdf:RDF>
XML;

    public function testParseSearchXmlExtractsIdTitleAndSnippet(): void
    {
        $results = Client::parseSearchXml(self::SEARCH_XML);

        $this->assertCount(2, $results);
        $this->assertSame(653874577, $results[0]['id']);
        $this->assertSame('Via Berneri columbarium', $results[0]['title']);
        $this->assertSame(
            'The Via Berneri columbarium was discovered in 2021 during utility work.',
            $results[0]['snippet']
        );
    }

    public function testParseSearchXmlNullsEmptyDescription(): void
    {
        $results = Client::parseSearchXml(self::SEARCH_XML);

        $this->assertSame(423025, $results[1]['id']);
        $this->assertSame('Roma', $results[1]['title']);
        $this->assertNull($results[1]['snippet']);
    }

    // No client-side cap: parseSearchXml() must return every item Pleiades'
    // own response carries, not truncate — Pleiades applies its own limit.
    public function testParseSearchXmlReturnsAllItemsNoTruncation(): void
    {
        $results = Client::parseSearchXml(self::SEARCH_XML);

        $this->assertCount(2, $results);
    }

    public function testParseSearchXmlThrowsOnInvalidXml(): void
    {
        $this->expectException(PleiadesException::class);
        Client::parseSearchXml('not xml at all <<<');
    }

    // ── normalizePlaceJson() ─────────────────────────────────────────────────

    private const PLACE_JSON = <<<'JSON'
{
    "id": "423025",
    "title": "Roma",
    "reprPoint": [12.491258174554883, 41.8899769864849],
    "uri": "https://pleiades.stoa.org/places/423025",
    "locations": []
}
JSON;

    public function testNormalizePlaceJsonExtractsIdTitleReprPoint(): void
    {
        $place = Client::normalizePlaceJson(json_decode(self::PLACE_JSON, true));

        $this->assertSame(423025, $place['id']);
        $this->assertSame('Roma', $place['title']);
        $this->assertSame([12.491258174554883, 41.8899769864849], $place['reprPoint']);
    }

    public function testNormalizePlaceJsonNullsMissingReprPoint(): void
    {
        $place = Client::normalizePlaceJson(['id' => '1', 'title' => 'No Coordinates']);

        $this->assertNull($place['reprPoint']);
    }

    public function testNormalizePlaceJsonNullsMalformedReprPoint(): void
    {
        $place = Client::normalizePlaceJson(['id' => '1', 'title' => 'X', 'reprPoint' => [1.0]]);

        $this->assertNull($place['reprPoint']);
    }
}
