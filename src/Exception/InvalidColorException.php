<?php

declare(strict_types=1);

namespace Atelier\Barcode\Exception;

/**
 * Thrown when a raster renderer receives an invalid color value.
 */
final class InvalidColorException extends \InvalidArgumentException implements CodeExceptionInterface
{
}
