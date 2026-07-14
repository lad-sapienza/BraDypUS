<?php

declare(strict_types=1);

namespace Tests\Unit;

use Geo\WktGeoJson;
use PHPUnit\Framework\TestCase;

class WktGeoJsonTest extends TestCase
{
    // ── WKT → GeoJSON → WKT ──────────────────────────────────────────────────

    public function testPointWktRoundTrip(): void
    {
        $wkt = 'POINT (30 10)';
        $geojson = WktGeoJson::toGeoJson($wkt);

        $this->assertSame('Point', $geojson['type']);
        $this->assertSame([30.0, 10.0], $geojson['coordinates']);
        $this->assertSame($wkt, WktGeoJson::toWkt($geojson));
    }

    public function testLineStringWktRoundTrip(): void
    {
        $wkt = 'LINESTRING (30 10, 10 30, 40 40)';
        $geojson = WktGeoJson::toGeoJson($wkt);

        $this->assertSame('LineString', $geojson['type']);
        $this->assertSame([[30.0, 10.0], [10.0, 30.0], [40.0, 40.0]], $geojson['coordinates']);
        $this->assertSame($wkt, WktGeoJson::toWkt($geojson));
    }

    public function testPolygonWktRoundTrip(): void
    {
        $wkt = 'POLYGON ((30 10, 40 40, 20 40, 10 20, 30 10))';
        $geojson = WktGeoJson::toGeoJson($wkt);

        $this->assertSame('Polygon', $geojson['type']);
        $this->assertSame(
            [[[30.0, 10.0], [40.0, 40.0], [20.0, 40.0], [10.0, 20.0], [30.0, 10.0]]],
            $geojson['coordinates']
        );
        $this->assertSame($wkt, WktGeoJson::toWkt($geojson));
    }

    public function testPolygonWithHoleWktRoundTrip(): void
    {
        $wkt = 'POLYGON ((35 10, 45 45, 15 40, 10 20, 35 10), (20 30, 35 35, 30 20, 20 30))';
        $geojson = WktGeoJson::toGeoJson($wkt);

        $this->assertSame('Polygon', $geojson['type']);
        $this->assertCount(2, $geojson['coordinates']);
        $this->assertSame($wkt, WktGeoJson::toWkt($geojson));
    }

    public function testMultiPointWktRoundTrip(): void
    {
        $wkt = 'MULTIPOINT ((10 40), (40 30), (20 20), (30 10))';
        $geojson = WktGeoJson::toGeoJson($wkt);

        $this->assertSame('MultiPoint', $geojson['type']);
        $this->assertSame(
            [[10.0, 40.0], [40.0, 30.0], [20.0, 20.0], [30.0, 10.0]],
            $geojson['coordinates']
        );
        $this->assertSame($wkt, WktGeoJson::toWkt($geojson));
    }

    public function testMultiPointBareFormIsAccepted(): void
    {
        $geojson = WktGeoJson::toGeoJson('MULTIPOINT (10 40, 40 30)');

        $this->assertSame([[10.0, 40.0], [40.0, 30.0]], $geojson['coordinates']);
    }

    public function testMultiLineStringWktRoundTrip(): void
    {
        $wkt = 'MULTILINESTRING ((10 10, 20 20, 10 40), (40 40, 30 30, 40 20, 30 10))';
        $geojson = WktGeoJson::toGeoJson($wkt);

        $this->assertSame('MultiLineString', $geojson['type']);
        $this->assertSame(
            [
                [[10.0, 10.0], [20.0, 20.0], [10.0, 40.0]],
                [[40.0, 40.0], [30.0, 30.0], [40.0, 20.0], [30.0, 10.0]],
            ],
            $geojson['coordinates']
        );
        $this->assertSame($wkt, WktGeoJson::toWkt($geojson));
    }

    public function testMultiPolygonWktRoundTrip(): void
    {
        $wkt = 'MULTIPOLYGON (((30 20, 45 40, 10 40, 30 20)), ((15 5, 40 10, 10 20, 5 10, 15 5)))';
        $geojson = WktGeoJson::toGeoJson($wkt);

        $this->assertSame('MultiPolygon', $geojson['type']);
        $this->assertCount(2, $geojson['coordinates']);
        $this->assertSame($wkt, WktGeoJson::toWkt($geojson));
    }

    public function testMultiPolygonWithHoleWktRoundTrip(): void
    {
        $wkt = 'MULTIPOLYGON (((40 40, 20 45, 45 30, 40 40)), '
             . '((20 35, 10 30, 10 10, 30 5, 45 20, 20 35), (30 20, 20 15, 20 25, 30 20)))';
        $geojson = WktGeoJson::toGeoJson($wkt);

        $this->assertSame('MultiPolygon', $geojson['type']);
        $this->assertCount(2, $geojson['coordinates']);
        $this->assertCount(2, $geojson['coordinates'][1]); // outer ring + hole
        $this->assertSame($wkt, WktGeoJson::toWkt($geojson));
    }

    // ── GeoJSON → WKT → GeoJSON ──────────────────────────────────────────────

    public function testPointGeoJsonRoundTrip(): void
    {
        $geojson = ['type' => 'Point', 'coordinates' => [12.5, 41.9]];
        $wkt = WktGeoJson::toWkt($geojson);

        $this->assertSame('POINT (12.5 41.9)', $wkt);
        $this->assertSame($geojson, WktGeoJson::toGeoJson($wkt));
    }

    public function testLineStringGeoJsonRoundTrip(): void
    {
        $geojson = ['type' => 'LineString', 'coordinates' => [[30.0, 10.0], [10.0, 30.0], [40.0, 40.0]]];
        $wkt = WktGeoJson::toWkt($geojson);
        $roundTripped = WktGeoJson::toGeoJson($wkt);

        $this->assertSame('LineString', $roundTripped['type']);
        $this->assertSame($geojson['coordinates'], $roundTripped['coordinates']);
    }

    public function testPolygonGeoJsonRoundTrip(): void
    {
        $geojson = [
            'type' => 'Polygon',
            'coordinates' => [[[0.0, 0.0], [1.0, 0.0], [1.0, 1.0], [0.0, 1.0], [0.0, 0.0]]],
        ];
        $wkt = WktGeoJson::toWkt($geojson);
        $roundTripped = WktGeoJson::toGeoJson($wkt);

        $this->assertSame('Polygon', $roundTripped['type']);
        $this->assertSame($geojson['coordinates'], $roundTripped['coordinates']);
    }

    public function testMultiPointGeoJsonRoundTrip(): void
    {
        $geojson = ['type' => 'MultiPoint', 'coordinates' => [[10.0, 40.0], [40.0, 30.0]]];
        $wkt = WktGeoJson::toWkt($geojson);
        $roundTripped = WktGeoJson::toGeoJson($wkt);

        $this->assertSame('MultiPoint', $roundTripped['type']);
        $this->assertSame($geojson['coordinates'], $roundTripped['coordinates']);
    }

    public function testMultiLineStringGeoJsonRoundTrip(): void
    {
        $geojson = [
            'type' => 'MultiLineString',
            'coordinates' => [[[10.0, 10.0], [20.0, 20.0]], [[15.0, 15.0], [30.0, 15.0]]],
        ];
        $wkt = WktGeoJson::toWkt($geojson);
        $roundTripped = WktGeoJson::toGeoJson($wkt);

        $this->assertSame('MultiLineString', $roundTripped['type']);
        $this->assertSame($geojson['coordinates'], $roundTripped['coordinates']);
    }

    public function testMultiPolygonGeoJsonRoundTrip(): void
    {
        $geojson = [
            'type' => 'MultiPolygon',
            'coordinates' => [
                [[[30.0, 20.0], [45.0, 40.0], [10.0, 40.0], [30.0, 20.0]]],
                [[[15.0, 5.0], [40.0, 10.0], [10.0, 20.0], [5.0, 10.0], [15.0, 5.0]]],
            ],
        ];
        $wkt = WktGeoJson::toWkt($geojson);
        $roundTripped = WktGeoJson::toGeoJson($wkt);

        $this->assertSame('MultiPolygon', $roundTripped['type']);
        $this->assertSame($geojson['coordinates'], $roundTripped['coordinates']);
    }

    // ── Errors ────────────────────────────────────────────────────────────────

    public function testInvalidWktThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WktGeoJson::toGeoJson('NOT A GEOMETRY');
    }

    public function testUnsupportedGeometryCollectionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WktGeoJson::toGeoJson('GEOMETRYCOLLECTION (POINT (1 1))');
    }

    public function testInvalidGeoJsonThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WktGeoJson::toWkt(['type' => 'Point']);
    }
}
