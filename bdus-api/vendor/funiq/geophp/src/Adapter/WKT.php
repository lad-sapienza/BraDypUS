<?php

namespace geoPHP\Adapter;

use geoPHP\Geometry\Collection;
use geoPHP\geoPHP;
use geoPHP\Geometry\Geometry;
use geoPHP\Geometry\GeometryCollection;
use geoPHP\Geometry\Point;
use geoPHP\Geometry\MultiPoint;
use geoPHP\Geometry\LineString;
use geoPHP\Geometry\MultiLineString;
use geoPHP\Geometry\Polygon;
use geoPHP\Geometry\MultiPolygon;

/**
 * WKT (Well Known Text) Adapter
 */
class WKT implements GeoAdapter
{

    protected $hasZ      = false;

    protected $measured  = false;

    /**
     * Determines if the given typeString is a valid WKT geometry type
     *
     * @param string $typeString Type to find, eg. "Point", or "LineStringZ"
     * @return string|bool The geometry type if found or false
     */
    public static function isWktType($typeString)
    {
        foreach (geoPHP::getGeometryList() as $geom => $type) {
            if (strtolower((substr($typeString, 0, strlen($geom)))) == $geom) {
                return $type;
            }
        }
        return false;
    }

    /**
     * Read WKT string into geometry objects
     *
     * @param string $wkt A WKT string
     * @return Geometry
     * @throws \Exception
     */
    public function read($wkt)
    {
        $this->hasZ = false;
        $this->measured = false;

        $wkt = trim(strtoupper($wkt));
        $srid = null;
        // If it contains a ';', then it contains additional SRID data
        if (preg_match('/^SRID=(\d+);/', $wkt, $m)) {
            $srid = $m[1];
            $wkt = substr($wkt, strlen($m[0]));
        }

        // If geos is installed, then we take a shortcut and let it parse the WKT
        if (geoPHP::geosInstalled()) {
            /** @noinspection PhpUndefinedClassInspection */
            $reader = new \GEOSWKTReader();
            try {
                $geom = geoPHP::geosToGeometry($reader->read($wkt));
                if ($srid) {
                    $geom->setSRID($srid);
                }
                return $geom;
            } catch (\Exception $e) {
//                if ($e->getMessage() !== 'IllegalArgumentException: Empty Points cannot be represented in WKB') {
//                    throw $e;
//                } // else try with GeoPHP' parser
            }
        }

        if ($geometry = $this->parseTypeAndGetData($wkt)) {
            if ($geometry && $srid) {
                $geometry->setSRID($srid);
            }
            return $geometry;
        }
        throw new \Exception('Invalid Wkt');
    }

    /**
     * @param string $wkt
     *
     * @return Geometry|null
     * @throws \Exception
     */
    private function parseTypeAndGetData($wkt)
    {
        // geometry type is the first word
        if (preg_match('/^(?<type>[A-Z]+)\s*(?<z>Z*)(?<m>M*)\s*(?:\((?<data>.+)\)|(?<data_empty>EMPTY))$/', $wkt, $m)) {
            $geometryType = $this->isWktType($m['type']);
            // Not used yet
            //$this->hasZ   = $this->hasZ || $m['z'];
            //$this->measured = $this->measured || $m['m'];
            $dataString = $m['data'] ?: $m['data_empty'];

            if ($geometryType) {
                $method = 'parse' . $geometryType;
                return call_user_func([$this, $method], $dataString);
            }
            throw new \Exception('Invalid WKT type "' . $m[1] . '"');
        }
        throw new \Exception('Cannot parse WKT');
    }

    private function parsePoint($dataString)
    {
        $dataString = trim($dataString);
        // If it's marked as empty, then return an empty point
        if ($dataString == 'EMPTY') {
            return new Point();
        }
        $z = $m = null;
        $parts = explode(' ', $dataString);
        if (isset($parts[2])) {
            if ($this->measured) {
                $m = $parts[2];
            } else {
                $z = $parts[2];
            }
        }
        if (isset($parts[3])) {
            $m = $parts[3];
        }
        return new Point($parts[0], $parts[1], $z, $m);
    }

    private function parseLineString($dataString)
    {
        // If it's marked as empty, then return an empty line
        if ($dataString == 'EMPTY') {
            return new LineString();
        }

        $points = [];
        foreach (explode(',', $dataString) as $part) {
            $points[] = $this->parsePoint($part);
        }
        return new LineString($points);
    }

    private function parsePolygon($dataString)
    {
        // If it's marked as empty, then return an empty polygon
        if ($dataString == 'EMPTY') {
            return new Polygon();
        }

        $lines = [];
        if (preg_match_all('/\(([^)(]*)\)/', $dataString, $m)) {
            foreach ($m[1] as $part) {
                $lines[] = $this->parseLineString($part);
            }
        }
        return new Polygon($lines);
    }

    /** @noinspection PhpUnusedPrivateMethodInspection
     * @param string $dataString
     *
     * @return MultiPoint
     */
    private function parseMultiPoint($dataString)
    {
        // If it's marked as empty, then return an empty MultiPoint
        if ($dataString == 'EMPTY') {
            return new MultiPoint();
        }

        $points = [];
        /* Should understand both forms:
         * MULTIPOINT ((1 2), (3 4))
         * MULTIPOINT (1 2, 3 4)
         */
        foreach (explode(',', $dataString) as $part) {
            $points[] =  $this->parsePoint(trim($part, ' ()'));
        }
        return new MultiPoint($points);
    }

    /** @noinspection PhpUnusedPrivateMethodInspection
     * @param string $dataString
     *
     * @return MultiLineString
     */
    private function parseMultiLineString($dataString)
    {
        // If it's marked as empty, then return an empty multi-linestring
        if ($dataString == 'EMPTY') {
            return new MultiLineString();
        }
        $lines = [];
        if (preg_match_all('/(\([^(]+\)|EMPTY)/', $dataString, $m)) {
            foreach ($m[1] as $part) {
                $lines[] =  $this->parseLineString(trim($part, ' ()'));
            }
        }
        return new MultiLineString($lines);
    }

    /** @noinspection PhpUnusedPrivateMethodInspection
     * @param string $dataString
     *
     * @return MultiPolygon
     */
    private function parseMultiPolygon($dataString)
    {
        // If it's marked as empty, then return an empty multi-polygon
        if ($dataString == 'EMPTY') {
            return new MultiPolygon();
        }

        $polygons = [];
        if (preg_match_all('/(\(\([^(].+\)\)|EMPTY)/', $dataString, $m)) {
            foreach ($m[0] as $part) {
                $polygons[] =  $this->parsePolygon($part);
            }
        }
        return new MultiPolygon($polygons);
    }

    /** @noinspection PhpUnusedPrivateMethodInspection
     * @param string $dataString
     *
     * @return GeometryCollection
     */
    private function parseGeometryCollection($dataString)
    {
        // If it's marked as empty, then return an empty geom-collection
        if ($dataString == 'EMPTY') {
            return new GeometryCollection();
        }

        $geometries = [];
        while (strlen($dataString) > 0) {
            // Matches the first balanced parenthesis group (or term EMPTY)
            preg_match(
                '/\((?>[^()]+|(?R))*\)|EMPTY/',
                $dataString,
                $m,
                PREG_OFFSET_CAPTURE
            );
            if (!isset($m[0])) {
                // something weird happened, we stop here before running in an infinite loop
                break;
            }
            $cutPosition = strlen($m[0][0]) + $m[0][1];
            $geometry = $this->parseTypeAndGetData(trim(substr($dataString, 0, $cutPosition)));
            $geometries[] = $geometry;
            $dataString = trim(substr($dataString, $cutPosition + 1));
        }

        return new GeometryCollection($geometries);
    }


    /**
     * Serialize geometries into a WKT string.
     *
     * @param Geometry $geometry
     *
     * @return string The WKT string representation of the input geometries
     */
    public function write(Geometry $geometry)
    {
        // If geos is installed, then we take a shortcut and let it write the WKT
        if (geoPHP::geosInstalled()) {
            /** @noinspection PhpUndefinedClassInspection */
            $writer = new \GEOSWKTWriter();
            /** @noinspection PhpUndefinedMethodInspection */
            $writer->setRoundingPrecision(14);
            /** @noinspection PhpUndefinedMethodInspection */
            $writer->setTrim(true);
            /** @noinspection PhpUndefinedMethodInspection */
            return $writer->write($geometry->getGeos());
        }
        $this->measured = $geometry->isMeasured();
        $this->hasZ     = $geometry->hasZ();

        if ($geometry->isEmpty()) {
            return strtoupper($geometry->geometryType()) . ' EMPTY';
        }

        if ($data = $this->extractData($geometry)) {
            $extension = '';
            if ($this->hasZ) {
                $extension .= 'Z';
            }
            if ($this->measured) {
                $extension .= 'M';
            }
            return strtoupper($geometry->geometryType()) . ($extension ? ' ' . $extension : '') . ' (' . $data . ')';
        }
        return '';
    }

    /**
     * Extract geometry to a WKT string
     *
     * @param Geometry|Collection $geometry A Geometry object
     *
     * @return string
     */
    public function extractData($geometry)
    {
        $parts = [];
        switch ($geometry->geometryType()) {
            case Geometry::POINT:
                $p = $geometry->x() . ' ' . $geometry->y();
                if ($geometry->hasZ()) {
                    $p .= ' ' . $geometry->getZ();
                    $this->hasZ = $this->hasZ || $geometry->hasZ();
                }
                if ($geometry->isMeasured()) {
                    $p .= ' ' . $geometry->getM();
                    $this->measured = $this->measured || $geometry->isMeasured();
                }
                return $p;
            case Geometry::LINE_STRING:
                foreach ($geometry->getComponents() as $component) {
                    $parts[] = $this->extractData($component);
                }
                return implode(', ', $parts);
            case Geometry::POLYGON:
            case Geometry::MULTI_POINT:
            case Geometry::MULTI_LINE_STRING:
            case Geometry::MULTI_POLYGON:
                foreach ($geometry->getComponents() as $component) {
                    if ($component->isEmpty()) {
                        $parts[] = 'EMPTY';
                    } else {
                        $parts[] = '(' . $this->extractData($component) . ')';
                    }
                }
                return implode(', ', $parts);
            case Geometry::GEOMETRY_COLLECTION:
                foreach ($geometry->getComponents() as $component) {
                    $this->hasZ = $this->hasZ || $geometry->hasZ();
                    $this->measured = $this->measured || $geometry->isMeasured();

                    $extension = '';
                    if ($this->hasZ) {
                        $extension .= 'Z';
                    }
                    if ($this->measured) {
                        $extension .= 'M';
                    }
                    $data = $this->extractData($component);
                    $parts[] = strtoupper($component->geometryType())
                            . ($extension ? ' ' . $extension : '')
                            . ($data ? ' (' . $data . ')' : ' EMPTY');
                }
                return implode(', ', $parts);
        }
        return '';
    }
}
