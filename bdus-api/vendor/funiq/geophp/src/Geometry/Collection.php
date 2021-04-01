<?php

namespace geoPHP\Geometry;

use geoPHP\Exception\InvalidGeometryException;
use geoPHP\geoPHP;

/**
 * Collection: Abstract class for compound geometries
 *
 * A geometry is a collection if it is made up of other
 * component geometries. Therefore everything but a Point
 * is a Collection. For example a LingString is a collection
 * of Points. A Polygon is a collection of LineStrings etc.
 */
abstract class Collection extends Geometry
{

    /** @var Geometry[]|Collection[] */
    protected $components = [];

    /**
     * Constructor: Checks and sets component geometries
     *
     * @param Geometry[] $components Array of geometries
     * @param bool|true  $allowEmptyComponents Allow creating geometries with empty components
     * @param string     $allowedComponentType A class the components must be instance of
     *
     * @throws \Exception
     */
    public function __construct(
        $components = [],
        $allowEmptyComponents = true,
        $allowedComponentType = Geometry::class
    ) {
        if (!is_array($components)) {
            throw new InvalidGeometryException("Component geometries must be passed as array");
        }
        $componentCount = count($components);
        for ($i = 0; $i < $componentCount; ++$i) { // foreach is too memory-intensive here in PHP 5.*
            if ($components[$i] instanceof $allowedComponentType) {
                if (!$allowEmptyComponents && $components[$i]->isEmpty()) {
                    throw new InvalidGeometryException(
                        'Cannot create a collection of empty ' .
                        $components[$i]->geometryType() . 's (' . ($i + 1) . '. component)'
                    );
                }
                if ($components[$i]->hasZ() && !$this->hasZ) {
                    $this->hasZ = true;
                }
                if ($components[$i]->isMeasured() && !$this->isMeasured) {
                    $this->isMeasured = true;
                }
            } else {
                $componentType = gettype($components[$i]) !== 'object'
                    ? gettype($components[$i])
                    : get_class($components[$i]);
                throw new InvalidGeometryException(
                    'Cannot create a collection of ' . $componentType .
                    ' components, expected type is ' . $allowedComponentType
                );
            }
        }
        $this->components = $components;
    }

    /**
     * Check if Geometry has Z (altitude) coordinate
     *
     * @return bool True if collection has Z value
     */
    public function is3D()
    {
        return $this->hasZ;
    }

    /**
     * Check if Geometry has a measure value
     *
     * @return bool True if collection has measure values
     */
    public function isMeasured()
    {
        return $this->isMeasured;
    }

    /**
     * Returns Collection component geometries
     *
     * @return Geometry[]
     */
    public function getComponents()
    {
        return $this->components;
    }

    /**
     * Inverts x and y coordinates
     * Useful for old data still using lng lat
     *
     * @return self
     *
     * */
    public function invertXY()
    {
        foreach ($this->components as $component) {
            $component->invertXY();
        }
        $this->setGeos(null);
        return $this;
    }

    public function getBBox()
    {
        if ($this->isEmpty()) {
            return null;
        }

        if ($this->getGeos()) {
            // @codeCoverageIgnoreStart
            /** @noinspection PhpUndefinedMethodInspection */
            $envelope = $this->getGeos()->envelope();
            /** @noinspection PhpUndefinedMethodInspection */
            if ($envelope->typeName() == 'Point') {
                return geoPHP::geosToGeometry($envelope)->getBBox();
            }

            /** @noinspection PhpUndefinedMethodInspection */
            $geosRing = $envelope->exteriorRing();
            /** @noinspection PhpUndefinedMethodInspection */
            return [
                    'maxy' => $geosRing->pointN(3)->getY(),
                    'miny' => $geosRing->pointN(1)->getY(),
                    'maxx' => $geosRing->pointN(1)->getX(),
                    'minx' => $geosRing->pointN(3)->getX(),
            ];
            // @codeCoverageIgnoreEnd
        }

        // Go through each component and get the max and min x and y
        $maxX = $maxY = $minX = $minY = 0;
        foreach ($this->components as $i => $component) {
            $componentBoundingBox = $component->getBBox();
            if ($componentBoundingBox === null) {
                continue;
            }

            // On the first run through, set the bounding box to the component's bounding box
            if ($i == 0) {
                $maxX = $componentBoundingBox['maxx'];
                $maxY = $componentBoundingBox['maxy'];
                $minX = $componentBoundingBox['minx'];
                $minY = $componentBoundingBox['miny'];
            }

            // Do a check and replace on each boundary, slowly growing the bounding box
            $maxX = $componentBoundingBox['maxx'] > $maxX ? $componentBoundingBox['maxx'] : $maxX;
            $maxY = $componentBoundingBox['maxy'] > $maxY ? $componentBoundingBox['maxy'] : $maxY;
            $minX = $componentBoundingBox['minx'] < $minX ? $componentBoundingBox['minx'] : $minX;
            $minY = $componentBoundingBox['miny'] < $minY ? $componentBoundingBox['miny'] : $minY;
        }

        return [
                'maxy' => $maxY,
                'miny' => $minY,
                'maxx' => $maxX,
                'minx' => $minX,
        ];
    }

    /**
     * Returns every sub-geometry as a multidimensional array
     *
     * @return array
     */
    public function asArray()
    {
        $array = [];
        foreach ($this->components as $component) {
            $array[] = $component->asArray();
        }
        return $array;
    }

    /**
     * @return int
     */
    public function numGeometries()
    {
        return count($this->components);
    }

    /**
     * Returns the 1-based Nth geometry.
     *
     * @param int $n 1-based geometry number
     * @return Geometry|null
     */
    public function geometryN($n)
    {
        return isset($this->components[$n - 1]) ? $this->components[$n - 1] : null;
    }

    /**
     * A collection is not empty if it has at least one non empty component.
     *
     * @return bool
     */
    public function isEmpty()
    {
        foreach ($this->components as $component) {
            if (!$component->isEmpty()) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return int
     */
    public function numPoints()
    {
        $num = 0;
        foreach ($this->components as $component) {
            $num += $component->numPoints();
        }
        return $num;
    }

    /**
     * @return Point[]
     */
    public function getPoints()
    {
        $points = [];
        // Same as array_merge($points, $component->getPoints()), but 500× faster
        static::getPointsRecursive($this, $points);
        return $points;
    }

    /**
     * @param Collection $geometry The geometry from which points will be extracted
     * @param Point[] $points Result array as reference
     */
    private static function getPointsRecursive($geometry, &$points)
    {
        foreach ($geometry->components as $component) {
            if ($component instanceof Point) {
                $points[] = $component;
            } else {
                static::getPointsRecursive($component, $points);
            }
        }
    }

    /**
     * @param Geometry $geometry
     * @return bool
     */
    public function equals($geometry)
    {
        if ($this->getGeos()) {
            // @codeCoverageIgnoreStart
            /** @noinspection PhpUndefinedMethodInspection */
            return $this->getGeos()->equals($geometry->getGeos());
            // @codeCoverageIgnoreEnd
        }

        // To test for equality we check to make sure that there is a matching point
        // in the other geometry for every point in this geometry.
        // This is slightly more strict than the standard, which
        // uses Within(A,B) = true and Within(B,A) = true
        // @@TODO: Eventually we could fix this by using some sort of simplification
        // method that strips redundant vertices (that are all in a row)

        $thisPoints = $this->getPoints();
        $otherPoints = $geometry->getPoints();

        // First do a check to make sure they have the same number of vertices
        if (count($thisPoints) != count($otherPoints)) {
            return false;
        }

        foreach ($thisPoints as $point) {
            $foundMatch = false;
            foreach ($otherPoints as $key => $testPoint) {
                if ($point->equals($testPoint)) {
                    $foundMatch = true;
                    unset($otherPoints[$key]);
                    break;
                }
            }
            if (!$foundMatch) {
                return false;
            }
        }

        // All points match, return TRUE
        return true;
    }

    /**
     * Get all line segments
     * @param bool $toArray return segments as LineString or array of start and end points. Explode(true) is faster
     *
     * @return LineString[] | Point[][]
     */
    public function explode($toArray = false)
    {
        $parts = [];
        foreach ($this->components as $component) {
            foreach ($component->explode($toArray) as $part) {
                $parts[] = $part;
            }
        }
        return $parts;
    }

    public function flatten()
    {
        if ($this->hasZ() || $this->isMeasured()) {
            foreach ($this->components as $component) {
                $component->flatten();
            }
            $this->hasZ = false;
            $this->isMeasured = false;
            $this->setGeos(null);
        }
    }

    public function distance($geometry)
    {
        if ($this->getGeos()) {
            // @codeCoverageIgnoreStart
            /** @noinspection PhpUndefinedMethodInspection */
            return $this->getGeos()->distance($geometry->getGeos());
            // @codeCoverageIgnoreEnd
        }
        $distance = null;
        foreach ($this->components as $component) {
            $checkDistance = $component->distance($geometry);
            if ($checkDistance === 0) {
                return 0;
            }
            if ($checkDistance === null) {
                return null;
            }
            if ($distance === null) {
                $distance = $checkDistance;
            }
            if ($checkDistance < $distance) {
                $distance = $checkDistance;
            }
        }
        return $distance;
    }

    // Not valid for this geometry type
    // --------------------------------
    public function x()
    {
        return null;
    }

    public function y()
    {
        return null;
    }

    public function z()
    {
        return null;
    }

    public function m()
    {
        return null;
    }
}
