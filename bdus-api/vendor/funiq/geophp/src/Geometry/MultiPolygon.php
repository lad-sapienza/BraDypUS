<?php

namespace geoPHP\Geometry;

use geoPHP\geoPHP;

/**
 * MultiPolygon: A collection of Polygons
 *
 * @method Polygon[] getComponents()
 * @property Polygon[] $components
 */
class MultiPolygon extends MultiSurface
{

    public function __construct($components = [])
    {
        parent::__construct($components, true, Polygon::class);
    }

    public function geometryType()
    {
        return Geometry::MULTI_POLYGON;
    }

    public function centroid()
    {
        if ($this->isEmpty()) {
            return null;
        }

        if ($this->getGeos()) {
            // @codeCoverageIgnoreStart
            /** @noinspection PhpUndefinedMethodInspection */
            return geoPHP::geosToGeometry($this->getGeos()->centroid());
            // @codeCoverageIgnoreEnd
        }

        $x = 0;
        $y = 0;
        $totalArea = 0;
        foreach ($this->getComponents() as $component) {
            if ($component->isEmpty()) {
                continue;
            }
            $componentArea = $component->area();
            $totalArea += $componentArea;
            $componentCentroid = $component->centroid();
            $x += $componentCentroid->x() * $componentArea;
            $y += $componentCentroid->y() * $componentArea;
        }
        return new Point($x / $totalArea, $y / $totalArea);
    }

    public function area()
    {
        if ($this->getGeos()) {
            // @codeCoverageIgnoreStart
            /** @noinspection PhpUndefinedMethodInspection */
            return $this->getGeos()->area();
            // @codeCoverageIgnoreEnd
        }

        $area = 0;
        foreach ($this->components as $component) {
            $area += $component->area();
        }
        return $area;
    }

    public function boundary()
    {
        $rings = [];
        foreach ($this->getComponents() as $component) {
            $rings = array_merge($rings, $component->components);
        }
        return new MultiLineString($rings);
    }
}
