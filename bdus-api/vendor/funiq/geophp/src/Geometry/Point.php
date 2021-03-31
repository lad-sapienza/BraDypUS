<?php

namespace geoPHP\Geometry;

use geoPHP\Exception\InvalidGeometryException;

/**
 * A Point is a 0-dimensional geometric object and represents a single location in coordinate space.
 * A Point has an x-coordinate value, a y-coordinate value.
 * If called for by the associated Spatial Reference System, it may also have coordinate values for z and m.
 */
class Point extends Geometry
{

    protected $x = null;

    protected $y = null;

    protected $z = null;

    protected $m = null;

    /**
     * Constructor
     *
     * @param int|float|null $x The x coordinate (or longitude)
     * @param int|float|null $y The y coordinate (or latitude)
     * @param int|float|null $z The z coordinate (or altitude) - optional
     * @param int|float|null $m Measure - optional
     * @throws \Exception
     */
    public function __construct($x = null, $y = null, $z = null, $m = null)
    {
        // If X or Y is null than it is an empty point
        if ($x !== null && $y !== null) {
            // Basic validation on x and y
            if (!is_numeric($x) || !is_numeric($y)) {
                throw new InvalidGeometryException("Cannot construct Point. x and y should be numeric");
            }

            // Convert to float in case they are passed in as a string or integer etc.
            $this->x = floatval($x);
            $this->y = floatval($y);
        }

        // Check to see if this point has Z (height) value
        if ($z !== null) {
            if (!is_numeric($z)) {
                throw new InvalidGeometryException("Cannot construct Point. z should be numeric");
            }
            $this->hasZ = true;
            $this->z = $this->x !== null ? floatval($z) : null;
        }

        // Check to see if this is a measure
        if ($m !== null) {
            if (!is_numeric($m)) {
                throw new InvalidGeometryException("Cannot construct Point. m should be numeric");
            }
            $this->isMeasured = true;
            $this->m = $this->x !== null ? floatval($m) : null;
        }
    }

    /**
     * @param array $coordinates
     * @return Point
     * @throws \Exception
     */
    public static function fromArray($coordinates)
    {
        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return (new \ReflectionClass(get_called_class()))->newInstanceArgs($coordinates);
    }

    public function geometryType()
    {
        return Geometry::POINT;
    }

    public function dimension()
    {
        return 0;
    }

    /**
     * Get X (longitude) coordinate
     *
     * @return float The X coordinate
     */
    public function x()
    {
        return $this->x;
    }

    /**
     * Returns Y (latitude) coordinate
     *
     * @return float The Y coordinate
     */
    public function y()
    {
        return $this->y;
    }

    /**
     * Returns Z (altitude) coordinate
     *
     * @return float The Z coordinate or NULL is not a 3D point
     */
    public function z()
    {
        return $this->z;
    }

    /**
     * Returns M (measured) value
     *
     * @return float The measured value
     */
    public function m()
    {
        return $this->m;
    }

    /**
     * Inverts x and y coordinates
     * Useful with old applications still using lng lat
     *
     * @return self
     * */
    public function invertXY()
    {
        $x = $this->x;
        $this->x = $this->y;
        $this->y = $x;
        $this->setGeos(null);
        return $this;
    }

    // A point's centroid is itself
    public function centroid()
    {
        return $this;
    }

    public function getBBox()
    {
        return [
                'maxy' => $this->y(),
                'miny' => $this->y(),
                'maxx' => $this->x(),
                'minx' => $this->x(),
        ];
    }

    /**
     * @return array
     */
    public function asArray()
    {
        if ($this->isEmpty()) {
            return [NAN, NAN];
        }
        if (!$this->hasZ && !$this->isMeasured) {
            return [$this->x, $this->y];
        }
        if ($this->hasZ && $this->isMeasured) {
            return [$this->x, $this->y, $this->z, $this->m];
        }
        if ($this->hasZ) {
            return [$this->x, $this->y, $this->z];
        }
        // if ($this->isMeasured)
        return [$this->x, $this->y, null, $this->m];
    }

    /**
     * The boundary of a Point is the empty set.
     * @return GeometryCollection
     */
    public function boundary()
    {
        return new GeometryCollection();
    }

    /**
     * @return bool
     */
    public function isEmpty()
    {
        return $this->x === null;
    }

    /**
     * @return int Returns always 1
     */
    public function numPoints()
    {
        return 1;
    }

    /**
     * @return Point[]
     */
    public function getPoints()
    {
        return [$this];
    }

    /**
     * @return Point[]
     */
    public function getComponents()
    {
        return [$this];
    }

    /**
     * Determines weather the specified geometry is spatially equal to this Point
     *
     * Because of limited floating point precision in PHP, equality can be only approximated
     * @see: http://php.net/manual/en/function.bccomp.php
     * @see: http://php.net/manual/en/language.types.float.php
     *
     * @param Point|Geometry $geometry
     *
     * @return boolean
     */
    public function equals($geometry)
    {
        return $geometry->geometryType() === Geometry::POINT
            ? (abs($this->x() - $geometry->x()) <= 1.0E-9 && abs($this->y() - $geometry->y()) <= 1.0E-9)
            : false;
    }

    public function isSimple()
    {
        return true;
    }

    public function flatten()
    {
        $this->z = null;
        $this->m = null;
        $this->hasZ = false;
        $this->isMeasured = false;
        $this->setGeos(null);
    }

    /**
     * @param Geometry|Collection $geometry
     * @return float|null
     */
    public function distance($geometry)
    {
        if ($this->isEmpty() || $geometry->isEmpty()) {
            return null;
        }
        if ($this->getGeos()) {
            // @codeCoverageIgnoreStart
            /** @noinspection PhpUndefinedMethodInspection */
            return $this->getGeos()->distance($geometry->getGeos());
            // @codeCoverageIgnoreEnd
        }
        if ($geometry->geometryType() == Geometry::POINT) {
            return sqrt(
                pow(($this->x() - $geometry->x()), 2)
                + pow(($this->y() - $geometry->y()), 2)
            );
        }
        if ($geometry instanceof MultiGeometry) {
            $distance = null;
            foreach ($geometry->getComponents() as $component) {
                $checkDistance = $this->distance($component);
                if ($checkDistance === 0.0) {
                    return 0.0;
                }
                if ($checkDistance === null) {
                    continue;
                }
                if ($distance === null || $checkDistance < $distance) {
                    $distance = $checkDistance;
                }
            }
            return $distance;
        } else {
            // For LineString, Polygons, MultiLineString and MultiPolygon. the nearest point might be a vertex,
            // but it could also be somewhere along a line-segment that makes up the geometry (between vertices).
            // Here we brute force check all line segments that make up these geometries
            $distance = null;
            foreach ($geometry->explode(true) as $seg) {
                // As per http://stackoverflow.com/questions/849211/shortest-distance-between-a-point-and-a-line-segment
                // and http://paulbourke.net/geometry/pointline/
                $x1 = $seg[0]->x();
                $y1 = $seg[0]->y();
                $x2 = $seg[1]->x();
                $y2 = $seg[1]->y();
                $px = $x2 - $x1;
                $py = $y2 - $y1;
                $d = ($px * $px) + ($py * $py);
                if ($d == 0) {
                    // Line-segment's endpoints are identical. This is merely a point masquerading as a line-segment.
                    $checkDistance = $this->distance($seg[1]);
                } else {
                    $x3 = $this->x();
                    $y3 = $this->y();
                    $u =  ((($x3 - $x1) * $px) + (($y3 - $y1) * $py)) / $d;
                    if ($u > 1) {
                        $u = 1;
                    }
                    if ($u < 0) {
                        $u = 0;
                    }
                    $x = $x1 + ($u * $px);
                    $y = $y1 + ($u * $py);
                    $dx = $x - $x3;
                    $dy = $y - $y3;
                    $checkDistance = sqrt(($dx * $dx) + ($dy * $dy));
                }
                if ($checkDistance === 0.0) {
                    return 0.0;
                }
                if ($distance === null || $checkDistance < $distance) {
                    $distance = $checkDistance;
                }
            }
            return $distance;
        }
    }

    public function minimumZ()
    {
        return $this->hasZ ? $this->z() : null;
    }

    public function maximumZ()
    {
        return $this->hasZ ? $this->z() : null;
    }

    public function minimumM()
    {
        return $this->isMeasured ? $this->m() : null;
    }

    public function maximumM()
    {
        return $this->isMeasured ? $this->m() : null;
    }

    /* The following methods are not valid for this geometry type */

    public function area()
    {
        return 0.0;
    }

    public function length()
    {
        return 0.0;
    }

    public function length3D()
    {
        return 0.0;
    }

    public function greatCircleLength($radius = null)
    {
        return 0.0;
    }

    public function haversineLength()
    {
        return 0.0;
    }

    public function zDifference()
    {
        return null;
    }

    public function elevationGain($verticalTolerance = 0)
    {
        return null;
    }

    public function elevationLoss($verticalTolerance = 0)
    {
        return null;
    }

    public function numGeometries()
    {
        return null;
    }

    public function geometryN($n)
    {
        return null;
    }

    public function startPoint()
    {
        return null;
    }

    public function endPoint()
    {
        return null;
    }

    public function isRing()
    {
        return null;
    }

    public function isClosed()
    {
        return null;
    }

    public function pointN($n)
    {
        return null;
    }

    public function exteriorRing()
    {
        return null;
    }

    public function numInteriorRings()
    {
        return null;
    }

    public function interiorRingN($n)
    {
        return null;
    }

    /**
     * @param bool|false $toArray
     * @return null
     */
    public function explode($toArray = false)
    {
        return null;
    }
}
