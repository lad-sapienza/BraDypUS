<?php

namespace Geo;

/**
 * Native WKT <-> GeoJSON geometry converter.
 *
 * Replaces funiq/geophp, which was only ever used in this codebase for
 * format conversion (never buffer/area/intersection/SRID operations).
 * Supports the 2D geometry types actually produced by the app: Point,
 * LineString, Polygon, MultiPoint, MultiLineString, MultiPolygon.
 * GeometryCollection is intentionally not supported (unused).
 */
class WktGeoJson
{
    private const TYPES = [
        'POINT'           => 'Point',
        'LINESTRING'      => 'LineString',
        'POLYGON'         => 'Polygon',
        'MULTIPOINT'      => 'MultiPoint',
        'MULTILINESTRING' => 'MultiLineString',
        'MULTIPOLYGON'    => 'MultiPolygon',
    ];

    public static function toGeoJson(string $wkt): array
    {
        $wkt = trim($wkt);
        if (!preg_match('/^([A-Za-z]+)\s*\((.*)\)$/s', $wkt, $m)) {
            throw new \InvalidArgumentException("Invalid WKT: {$wkt}");
        }

        $type = strtoupper($m[1]);
        if (!isset(self::TYPES[$type])) {
            throw new \InvalidArgumentException("Unsupported WKT geometry type: {$type}");
        }
        $body = $m[2];

        $coordinates = match ($type) {
            'POINT'           => self::parsePoint($body),
            'LINESTRING'      => self::parsePointList($body),
            'POLYGON'         => self::parseRingList($body),
            'MULTIPOINT'      => self::parseMultiPoint($body),
            'MULTILINESTRING' => self::parseRingList($body),
            'MULTIPOLYGON'    => self::parsePolygonList($body),
        };

        return ['type' => self::TYPES[$type], 'coordinates' => $coordinates];
    }

    public static function toWkt(array $geojson): string
    {
        $type        = $geojson['type'] ?? null;
        $coordinates = $geojson['coordinates'] ?? null;

        if (!is_string($type) || !is_array($coordinates)) {
            throw new \InvalidArgumentException('Invalid GeoJSON geometry');
        }

        $upperType = strtoupper($type);
        if (!isset(self::TYPES[$upperType])) {
            throw new \InvalidArgumentException("Unsupported GeoJSON geometry type: {$type}");
        }

        $body = match ($upperType) {
            'POINT'           => self::writePoint($coordinates),
            'LINESTRING'      => self::writePointList($coordinates),
            'POLYGON'         => self::writeRingList($coordinates),
            'MULTIPOINT'      => self::writeMultiPoint($coordinates),
            'MULTILINESTRING' => self::writeRingList($coordinates),
            'MULTIPOLYGON'    => self::writePolygonList($coordinates),
        };

        return $upperType . ' (' . $body . ')';
    }

    // ── WKT parsing ──────────────────────────────────────────────────────

    private static function parsePoint(string $s): array
    {
        $parts = preg_split('/\s+/', trim($s));
        return [(float) $parts[0], (float) $parts[1]];
    }

    private static function parsePointList(string $s): array
    {
        return array_map([self::class, 'parsePoint'], self::splitTopLevel($s));
    }

    /**
     * Parses a comma-separated list of "(...)" groups, each a ring
     * (POLYGON) or a line (MULTILINESTRING), into a list of point lists.
     */
    private static function parseRingList(string $s): array
    {
        $rings = [];
        foreach (self::splitTopLevel($s) as $group) {
            $rings[] = self::parsePointList(self::stripParens($group));
        }
        return $rings;
    }

    /**
     * MULTIPOINT accepts both "(1 2), (3 4)" and the bare "1 2, 3 4" form.
     */
    private static function parseMultiPoint(string $s): array
    {
        $points = [];
        foreach (self::splitTopLevel($s) as $group) {
            $points[] = self::parsePoint(self::stripParens($group));
        }
        return $points;
    }

    private static function parsePolygonList(string $s): array
    {
        $polygons = [];
        foreach (self::splitTopLevel($s) as $group) {
            $polygons[] = self::parseRingList(self::stripParens($group));
        }
        return $polygons;
    }

    /**
     * Splits a WKT coordinate body on top-level commas only, leaving
     * nested "(...)" groups (rings, polygons) intact.
     */
    private static function splitTopLevel(string $s): array
    {
        $parts   = [];
        $depth   = 0;
        $current = '';

        for ($i = 0, $len = strlen($s); $i < $len; $i++) {
            $ch = $s[$i];
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
            }
            if ($ch === ',' && $depth === 0) {
                $parts[]  = trim($current);
                $current = '';
            } else {
                $current .= $ch;
            }
        }
        if (trim($current) !== '') {
            $parts[] = trim($current);
        }

        return $parts;
    }

    private static function stripParens(string $s): string
    {
        $s = trim($s);
        if (str_starts_with($s, '(') && str_ends_with($s, ')')) {
            $s = substr($s, 1, -1);
        }
        return trim($s);
    }

    // ── WKT writing ──────────────────────────────────────────────────────

    private static function writePoint(array $p): string
    {
        return self::formatNumber((float) $p[0]) . ' ' . self::formatNumber((float) $p[1]);
    }

    private static function writePointList(array $points): string
    {
        return implode(', ', array_map([self::class, 'writePoint'], $points));
    }

    private static function writeRingList(array $rings): string
    {
        return implode(', ', array_map(
            fn (array $ring) => '(' . self::writePointList($ring) . ')',
            $rings
        ));
    }

    private static function writeMultiPoint(array $points): string
    {
        return implode(', ', array_map(
            fn (array $p) => '(' . self::writePoint($p) . ')',
            $points
        ));
    }

    private static function writePolygonList(array $polygons): string
    {
        return implode(', ', array_map(
            fn (array $poly) => '(' . self::writeRingList($poly) . ')',
            $polygons
        ));
    }

    private static function formatNumber(float $n): string
    {
        if ($n == (int) $n && abs($n) < 1e15) {
            return (string) (int) $n;
        }
        $s = sprintf('%.10F', $n);
        $s = rtrim($s, '0');
        return rtrim($s, '.');
    }
}
