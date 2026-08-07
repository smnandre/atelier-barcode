<?php

declare(strict_types=1);

namespace Atelier\Barcode\Renderer;

use Atelier\Barcode\Exception\InvalidCodeException;
use Atelier\Barcode\Exception\InvalidColorException;
use Atelier\Barcode\Exception\MissingExtensionException;

/**
 * Renders a 2D boolean module grid to raw PNG bytes using ext-gd.
 *
 * ext-gd is an optional (soft) dependency of atelier/barcode: the rest of the
 * library is pure PHP and emits SVG. Only the actual {@see self::render()}
 * call requires the extension, so this class stays autoloadable and its pure
 * helpers stay callable even when GD is absent.
 */
final class PngRenderer
{
    /** Hard cap on either output side, in pixels. */
    private const int MAX_SIDE = 20000;

    /** Hard cap on total output area, in pixels (~160 MB truecolor). */
    private const int MAX_PIXELS = 40_000_000;

    /**
     * @param list<list<bool>> $matrix     row-major grid; true = foreground module
     * @param int              $moduleSize edge length of one module, in pixels
     * @param int              $margin     quiet zone around the grid, in modules
     * @param string           $foreground hex color (#rgb or #rrggbb)
     * @param string           $background hex color (#rgb or #rrggbb)
     *
     * @return string raw PNG bytes
     */
    public static function render(
        array $matrix,
        int $moduleSize,
        int $margin,
        string $foreground = '#000000',
        string $background = '#ffffff',
    ): string {
        self::ensureGdLoaded(extension_loaded('gd'));

        [$rows, $cols] = self::measure($matrix);

        if ($moduleSize <= 0) {
            throw new InvalidCodeException(sprintf('Module size must be a positive integer, %d given.', $moduleSize));
        }
        if ($margin < 0) {
            throw new InvalidCodeException(sprintf('Margin must be zero or positive, %d given.', $margin));
        }

        [$fgR, $fgG, $fgB] = self::parseHexColor($foreground);
        [$bgR, $bgG, $bgB] = self::parseHexColor($background);

        $width = ($cols + 2 * $margin) * $moduleSize;
        $height = ($rows + 2 * $margin) * $moduleSize;

        self::ensureWithinBounds($width, $height);

        $image = self::assertImage(imagecreatetruecolor($width, $height));

        // imagecolorallocate() cannot fail on a truecolor image (no fixed palette to
        // exhaust); assertAllocated() narrows its int|false signature to int for PHPStan.
        $backgroundColor = self::assertAllocated(imagecolorallocate($image, $bgR, $bgG, $bgB));
        $foregroundColor = self::assertAllocated(imagecolorallocate($image, $fgR, $fgG, $fgB));

        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $backgroundColor);

        foreach ($matrix as $row => $columns) {
            foreach ($columns as $col => $filled) {
                if (!$filled) {
                    continue;
                }

                $x0 = ($margin + $col) * $moduleSize;
                $y0 = ($margin + $row) * $moduleSize;
                imagefilledrectangle($image, $x0, $y0, $x0 + $moduleSize - 1, $y0 + $moduleSize - 1, $foregroundColor);
            }
        }

        ob_start();
        $encoded = imagepng($image);
        $png = ob_get_clean();
        self::assertPngEncoded($encoded);

        return self::assertEncoded($png);
    }

    /**
     * Guards the ext-gd requirement.
     *
     * Extracted as a pure function of an injected boolean so the missing-
     * extension path is testable without unloading the extension: production
     * code passes the real {@see extension_loaded()} result, tests pass false.
     *
     * @internal
     */
    public static function ensureGdLoaded(bool $loaded): void
    {
        if (!$loaded) {
            throw new MissingExtensionException('PNG rendering requires ext-gd, which is not loaded.');
        }
    }

    /**
     * Narrows imagecolorallocate()'s int|false signature to int for PHPStan.
     *
     * A truecolor image (as created by render() via imagecreatetruecolor()) has no fixed
     * palette to exhaust, so this never actually returns false in practice; the check exists
     * to satisfy static analysis of the downstream int-typed calls, not a reachable failure
     * mode, hence it is exercised directly here rather than by trying to force GD to fail.
     *
     * @internal
     */
    public static function assertAllocated(int|false $color): int
    {
        if (false === $color) {
            throw new InvalidCodeException('Failed to allocate PNG color.');
        }

        return $color;
    }

    /**
     * @internal
     */
    public static function assertImage(\GdImage|false $image): \GdImage
    {
        if (false === $image) {
            throw new InvalidCodeException('Failed to create PNG image.');
        }

        return $image;
    }

    /**
     * Narrows ob_get_clean()'s string|false signature to string for PHPStan.
     *
     * imagepng() writing to an active output buffer for a just-created, valid image does not
     * fail in practice; see {@see self::assertAllocated()} for why this is tested directly.
     *
     * @internal
     */
    public static function assertEncoded(string|false $png): string
    {
        if (false === $png) {
            throw new InvalidCodeException('Failed to encode PNG image.');
        }

        return $png;
    }

    /**
     * @internal
     */
    public static function assertPngEncoded(bool $encoded): void
    {
        if (!$encoded) {
            throw new InvalidCodeException('Failed to encode PNG image.');
        }
    }

    /**
     * @param list<list<bool>> $matrix
     *
     * @return array{int<1, max>, int<1, max>} rows and columns
     */
    private static function measure(array $matrix): array
    {
        $rows = count($matrix);
        if (0 === $rows) {
            throw new InvalidCodeException('Matrix must not be empty.');
        }

        $cols = count($matrix[0]);
        if (0 === $cols) {
            throw new InvalidCodeException('Matrix rows must not be empty.');
        }

        foreach ($matrix as $row) {
            if (count($row) !== $cols) {
                throw new InvalidCodeException('Matrix rows must all have the same length.');
            }
        }

        return [$rows, $cols];
    }

    private static function ensureWithinBounds(int $width, int $height): void
    {
        if ($width > self::MAX_SIDE || $height > self::MAX_SIDE) {
            throw new InvalidCodeException(sprintf('Output %dx%d exceeds the maximum side of %d pixels.', $width, $height, self::MAX_SIDE));
        }

        if ($width * $height > self::MAX_PIXELS) {
            throw new InvalidCodeException(sprintf('Output %dx%d exceeds the maximum area of %d pixels.', $width, $height, self::MAX_PIXELS));
        }
    }

    /**
     * @return array{int<0, 255>, int<0, 255>, int<0, 255>} red, green, blue
     */
    private static function parseHexColor(string $color): array
    {
        if (1 !== preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color, $match)) {
            throw new InvalidColorException(sprintf('Invalid hex color: %s', $color));
        }

        $hex = $match[1];
        if (3 === strlen($hex)) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return [
            self::hexByte(substr($hex, 0, 2)),
            self::hexByte(substr($hex, 2, 2)),
            self::hexByte(substr($hex, 4, 2)),
        ];
    }

    /**
     * @return int<0, 255>
     */
    private static function hexByte(string $pair): int
    {
        return max(0, min(255, (int) hexdec($pair)));
    }
}
