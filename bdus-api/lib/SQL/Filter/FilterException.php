<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace SQL\Filter;

/**
 * Thrown when a JSON filter array contains an invalid field name,
 * an unknown operator, or a structurally malformed node.
 */
class FilterException extends \InvalidArgumentException {}
