<?php

declare(strict_types=1);

namespace Atelier\Barcode\Exception;

/**
 * Thrown when an optional renderer dependency is not available.
 */
final class MissingExtensionException extends \RuntimeException implements CodeExceptionInterface
{
}
