<?php

namespace geoPHP\Geometry;

/**
 * Class MultiSurface
 * TODO write this
 *
 * @package geoPHP\Geometry
 */
abstract class MultiSurface extends MultiGeometry
{

    public function __construct($components = [], $allowEmptyComponents = true, $allowedComponentType = Surface::class)
    {
        parent::__construct($components, $allowEmptyComponents, $allowedComponentType);
    }

    public function geometryType()
    {
        return Geometry::MULTI_SURFACE;
    }

    public function dimension()
    {
        return 2;
    }
}
