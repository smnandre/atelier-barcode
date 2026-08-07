<?php

declare(strict_types=1);

namespace Atelier\Barcode\Exception;

/**
 * Thrown when barcode input, options, geometry, or output bounds are invalid.
 */
final class InvalidCodeException extends \InvalidArgumentException implements CodeExceptionInterface
{
}
