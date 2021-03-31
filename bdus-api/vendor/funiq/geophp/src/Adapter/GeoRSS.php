<?php

namespace geoPHP\Adapter;

use geoPHP\Geometry\Collection;
use geoPHP\geoPHP;
use geoPHP\Geometry\Geometry;
use geoPHP\Geometry\GeometryCollection;
use geoPHP\Geometry\Point;
use geoPHP\Geometry\LineString;
use geoPHP\Geometry\Polygon;

/*
 * Copyright (c) Patrick Hayes
 *
 * This code is open-source and licenced under the Modified BSD License.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * PHP Geometry/GeoRSS encoder/decoder
 */
class GeoRSS implements GeoAdapter
{

    /**
     * @var \DOMDocument $xmlObject
     */
    protected $xmlObject;

    private $namespace = false;

    private $nss = ''; // Name-space string. eg 'georss:'

    /**
     * Read GeoRSS string into geometry objects
     *
     * @param string $georss - an XML feed containing geoRSS
     *
     * @return Geometry|GeometryCollection
     */
    public function read($georss)
    {
        return $this->geomFromText($georss);
    }

    /**
     * Serialize geometries into a GeoRSS string.
     *
     * @param Geometry $geometry
     * @param boolean|string $namespace
     * @return string The georss string representation of the input geometries
     */
    public function write(Geometry $geometry, $namespace = false)
    {
        if ($namespace) {
            $this->namespace = $namespace;
            $this->nss = $namespace . ':';
        }
        return $this->geometryToGeoRSS($geometry) ?: '';
    }

    public function geomFromText($text)
    {
        // Change to lower-case, strip all CDATA, and de-namespace
        $text = strtolower($text);
        $text = preg_replace('/<!\[cdata\[(.*?)\]\]>/s', '', $text);

        // Load into DOMDocument
        $xmlObject = new \DOMDocument();
        @$xmlObject->loadXML($text);
        if ($xmlObject === false) {
            throw new \Exception("Invalid GeoRSS: " . $text);
        }

        $this->xmlObject = $xmlObject;
        try {
            $geom = $this->geomFromXML();
        } catch (\Exception $e) {
            throw new \Exception("Cannot Read Geometry From GeoRSS: " . $e->getMessage());
        }

        return $geom;
    }

    protected function geomFromXML()
    {
        $geometries = [];
        $geometries = array_merge($geometries, $this->parsePoints());
        $geometries = array_merge($geometries, $this->parseLines());
        $geometries = array_merge($geometries, $this->parsePolygons());
        $geometries = array_merge($geometries, $this->parseBoxes());
        $geometries = array_merge($geometries, $this->parseCircles());

        if (empty($geometries)) {
            throw new \Exception("Invalid / Empty GeoRSS");
        }

        return geoPHP::geometryReduce($geometries);
    }

    protected function getPointsFromCoordinates($string)
    {
        $coordinates = [];
        $latitudeAndLongitude = explode(' ', $string);
        $lat = 0;
        foreach ($latitudeAndLongitude as $key => $item) {
            if (!($key % 2)) {
                // It's a latitude
                $lat = is_numeric($item) ? $item : NAN;
            } else {
                // It's a longitude
                $lon = is_numeric($item) ? $item : NAN;
                $coordinates[] = new Point($lon, $lat);
            }
        }
        return $coordinates;
    }

    protected function parsePoints()
    {
        $points = [];
        $pointElements = $this->xmlObject->getElementsByTagName('point');
        foreach ($pointElements as $pt) {
            $pointArray = $this->getPointsFromCoordinates(trim($pt->firstChild->nodeValue));
            $points[] = !empty($pointArray) ? $pointArray[0] : new Point();
        }
        return $points;
    }

    protected function parseLines()
    {
        $lines = [];
        $lineElements = $this->xmlObject->getElementsByTagName('line');
        foreach ($lineElements as $line) {
            $components = $this->getPointsFromCoordinates(trim($line->firstChild->nodeValue));
            $lines[] = new LineString($components);
        }
        return $lines;
    }

    protected function parsePolygons()
    {
        $polygons = [];
        $polygonElements = $this->xmlObject->getElementsByTagName('polygon');
        foreach ($polygonElements as $polygon) {
            /** @noinspection PhpUndefinedMethodInspection */
            if ($polygon->hasChildNodes()) {
                $points = $this->getPointsFromCoordinates(trim($polygon->firstChild->nodeValue));
                $exteriorRing = new LineString($points);
                $polygons[] = new Polygon([$exteriorRing]);
            } else {
                // It's an EMPTY polygon
                $polygons[] = new Polygon();
            }
        }
        return $polygons;
    }

    // Boxes are rendered into polygons
    protected function parseBoxes()
    {
        $polygons = [];
        $boxElements = $this->xmlObject->getElementsByTagName('box');
        foreach ($boxElements as $box) {
            $parts = explode(' ', trim($box->firstChild->nodeValue));
            $components = [
                    new Point($parts[3], $parts[2]),
                    new Point($parts[3], $parts[0]),
                    new Point($parts[1], $parts[0]),
                    new Point($parts[1], $parts[2]),
                    new Point($parts[3], $parts[2]),
            ];
            $exteriorRing = new LineString($components);
            $polygons[] = new Polygon([$exteriorRing]);
        }
        return $polygons;
    }

    // Circles are rendered into points
    // @@TODO: Add good support once we have circular-string geometry support
    protected function parseCircles()
    {
        $points = [];
        $circleElements = $this->xmlObject->getElementsByTagName('circle');
        foreach ($circleElements as $circle) {
            $parts = explode(' ', trim($circle->firstChild->nodeValue));
            $points[] = new Point($parts[1], $parts[0]);
        }
        return $points;
    }

    /**
     * @param Geometry $geometry
     * @return string|null
     */
    protected function geometryToGeoRSS($geometry)
    {
        $type = $geometry->geometryType();
        switch ($type) {
            case Geometry::POINT:
                return $this->pointToGeoRSS($geometry);
            case Geometry::LINE_STRING:
                /** @noinspection PhpParamsInspection */
                return $this->linestringToGeoRSS($geometry);
            case Geometry::POLYGON:
                /** @noinspection PhpParamsInspection */
                return $this->PolygonToGeoRSS($geometry);
            case Geometry::MULTI_POINT:
            case Geometry::MULTI_LINE_STRING:
            case Geometry::MULTI_POLYGON:
            case Geometry::GEOMETRY_COLLECTION:
            /** @noinspection PhpParamsInspection */
                return $this->collectionToGeoRSS($geometry);
        }
        return null;
    }

    /**
     * @param Geometry $geometry
     * @return string
     */
    private function pointToGeoRSS($geometry)
    {
        return '<' . $this->nss . 'point>' . $geometry->y() . ' ' . $geometry->x() . '</' . $this->nss . 'point>';
    }

    /**
     * @param LineString $geometry
     * @return string
     */
    private function linestringToGeoRSS($geometry)
    {
        $output = '<' . $this->nss . 'line>';
        foreach ($geometry->getComponents() as $k => $point) {
            $output .= $point->y() . ' ' . $point->x();
            if ($k < ($geometry->numGeometries() - 1)) {
                $output .= ' ';
            }
        }
        $output .= '</' . $this->nss . 'line>';
        return $output;
    }

    /**
     * @param Polygon $geometry
     * @return string
     */
    private function polygonToGeoRSS($geometry)
    {
        $output = '<' . $this->nss . 'polygon>';
        $exteriorRing = $geometry->exteriorRing();
        foreach ($exteriorRing->getComponents() as $k => $point) {
            $output .= $point->y() . ' ' . $point->x();
            if ($k < ($exteriorRing->numGeometries() - 1)) {
                $output .= ' ';
            }
        }
        $output .= '</' . $this->nss . 'polygon>';
        return $output;
    }

    /**
     * @param Collection $geometry
     * @return string
     */
    public function collectionToGeoRSS($geometry)
    {
        $georss = '<' . $this->nss . 'where>';
        $components = $geometry->getComponents();
        foreach ($components as $component) {
            $georss .= $this->geometryToGeoRSS($component);
        }

        $georss .= '</' . $this->nss . 'where>';

        return $georss;
    }
}
