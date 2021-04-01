<?php

namespace geoPHP\Geometry;

use geoPHP\Exception\InvalidGeometryException;

/**
 * Class Curve
 * TODO write this
 *
 * @package geoPHP\Geometry
 * @method Point[] getComponents()
 * @property Point[] $components A curve consists of sequence of Points
 */
abstract class Curve extends Collection
{

    public function __construct($components = [], $allowEmptyComponents = false, $allowedComponentType = Point::class)
    {
        if (is_array($components) && count($components) == 1) {
            throw new InvalidGeometryException("Cannot construct a " . static::class . " with a single point");
        }
        parent::__construct($components, $allowEmptyComponents, $allowedComponentType);
    }

    protected $startPoint = null;

    protected $endPoint = null;

    public function geometryType()
    {
        return Geometry::CURVE;
    }

    public function dimension()
    {
        return 1;
    }

    public function startPoint()
    {
        if (!isset($this->startPoint)) {
            $this->startPoint = $this->pointN(1);
        }
        return $this->startPoint;
    }

    public function endPoint()
    {
        if (!isset($this->endPoint)) {
            $this->endPoint = $this->pointN($this->numPoints());
        }
        return $this->endPoint;
    }

    public function isClosed()
    {
        return ($this->startPoint() && $this->endPoint() ? $this->startPoint()->equals($this->endPoint()) : false);
    }

    public function isRing()
    {
        return ($this->isClosed() && $this->isSimple());
    }

    /**
     * @return Point[]
     */
    public function getPoints()
    {
        return $this->getComponents();
    }

    /**
     * The boundary of a non-closed Curve consists of its end Points
     *
     * @return LineString|MultiPoint
     */
    public function boundary()
    {
        return $this->isEmpty()
            ? new LineString()
            : ($this->isClosed()
                ? new MultiPoint()
                : new MultiPoint([$this->startPoint(), $this->endPoint()])
            );
    }

    // Not valid for this geometry type
    // --------------------------------
    public function area()
    {
        return 0;
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
}
