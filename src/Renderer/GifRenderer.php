<?php

declare(strict_types=1);

namespace Atelier\Barcode\Renderer;

use Atelier\Barcode\Exception\InvalidCodeException;
use Atelier\Barcode\Exception\InvalidColorException;

/**
 * Renders a boolean module matrix as a standalone GIF89a image, in pure PHP.
 *
 * No image extension is required: the 1-bit image and its LZW-compressed data
 * are assembled byte by byte. The matrix uses the same convention as
 * {@see \Atelier\Barcode\DataMatrix::matrix()} / {@see \Atelier\Barcode\QrCode::matrix()}:
 * `true` is a foreground module, `false` is background.
 */
final class GifRenderer
{
    /**
     * Hard upper bound on the rendered pixel area, guarding against inputs that
     * would exhaust memory. 40 megapixels covers the largest realistic symbol
     * (QR version 40 at a generous module size) with room to spare.
     */
    private const int MAX_PIXELS = 40_000_000;

    /** GIF stores each dimension in an unsigned 16-bit field. */
    private const int MAX_DIMENSION = 65535;

    /**
     * @param list<list<bool>> $matrix     square-or-rectangular module grid, row-major
     * @param int              $moduleSize edge length of one module, in pixels (> 0)
     * @param int              $margin     quiet zone around the symbol, in modules (>= 0)
     * @param string           $foreground module colour as `#rgb` or `#rrggbb`
     * @param string           $background quiet-zone/off-module colour as `#rgb` or `#rrggbb`
     *
     * @return string raw GIF89a bytes
     */
    public static function render(
        array $matrix,
        int $moduleSize,
        int $margin,
        string $foreground = '#000000',
        string $background = '#ffffff',
    ): string {
        if ($moduleSize <= 0) {
            throw new InvalidCodeException('Module size must be a positive integer.');
        }

        if ($margin < 0) {
            throw new InvalidCodeException('Margin must not be negative.');
        }

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

        $fg = self::parseColor($foreground);
        $bg = self::parseColor($background);

        $width = ($cols + 2 * $margin) * $moduleSize;
        $height = ($rows + 2 * $margin) * $moduleSize;

        if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            throw new InvalidCodeException(sprintf('Rendered dimensions %dx%d exceed the GIF limit of %d pixels per side.', $width, $height, self::MAX_DIMENSION));
        }

        if ($width * $height > self::MAX_PIXELS) {
            throw new InvalidCodeException(sprintf('Rendered area %dx%d exceeds the %d pixel limit.', $width, $height, self::MAX_PIXELS));
        }

        $pixels = self::buildPixels($matrix, $moduleSize, $margin, $width, $height);

        return self::assemble($width, $height, $fg, $bg, $pixels);
    }

    /**
     * @return array{int<0, 255>, int<0, 255>, int<0, 255>} red, green, blue
     */
    private static function parseColor(string $color): array
    {
        if (1 !== preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color)) {
            throw new InvalidColorException(sprintf('Invalid colour "%s": expected #rgb or #rrggbb.', $color));
        }

        $hex = substr($color, 1);
        if (3 === strlen($hex)) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return [
            max(0, min(255, (int) hexdec(substr($hex, 0, 2)))),
            max(0, min(255, (int) hexdec(substr($hex, 2, 2)))),
            max(0, min(255, (int) hexdec(substr($hex, 4, 2)))),
        ];
    }

    /**
     * Expands the module grid to a row-major stream of palette indices
     * (0 = background, 1 = foreground), one byte per pixel.
     *
     * @param list<list<bool>> $matrix
     */
    private static function buildPixels(array $matrix, int $moduleSize, int $margin, int $width, int $height): string
    {
        $bg = "\x00";
        $fg = "\x01";
        $moduleBg = str_repeat($bg, $moduleSize);
        $moduleFg = str_repeat($fg, $moduleSize);
        $quietRow = str_repeat($bg, $width);
        $sideQuiet = str_repeat($bg, $margin * $moduleSize);

        $marginRows = $margin * $moduleSize;
        $pixels = str_repeat($quietRow, $marginRows);

        foreach ($matrix as $row) {
            $line = $sideQuiet;
            foreach ($row as $cell) {
                $line .= $cell ? $moduleFg : $moduleBg;
            }
            $line .= $sideQuiet;
            $pixels .= str_repeat($line, $moduleSize);
        }

        $pixels .= str_repeat($quietRow, $marginRows);

        assert(strlen($pixels) === $width * $height);

        return $pixels;
    }

    /**
     * @param array{int<0, 255>, int<0, 255>, int<0, 255>} $fg
     * @param array{int<0, 255>, int<0, 255>, int<0, 255>} $bg
     */
    private static function assemble(int $width, int $height, array $fg, array $bg, string $pixels): string
    {
        $out = 'GIF89a';

        // Logical Screen Descriptor: canvas size, then a packed field marking a
        // 2-entry global colour table (flag set, size code 0 => 2 colours).
        $out .= self::uint16($width);
        $out .= self::uint16($height);
        $out .= "\x80";
        $out .= "\x00";
        $out .= "\x00";

        // Global Colour Table: index 0 background, index 1 foreground.
        $out .= chr($bg[0]).chr($bg[1]).chr($bg[2]);
        $out .= chr($fg[0]).chr($fg[1]).chr($fg[2]);

        // Image Descriptor: full-canvas image, no local colour table.
        $out .= "\x2C";
        $out .= self::uint16(0);
        $out .= self::uint16(0);
        $out .= self::uint16($width);
        $out .= self::uint16($height);
        $out .= "\x00";

        $minCodeSize = 2;
        $out .= chr($minCodeSize);
        $out .= self::subBlocks(self::lzwEncode($pixels, $minCodeSize));

        // Trailer.
        $out .= "\x3B";

        return $out;
    }

    private static function uint16(int $value): string
    {
        return chr($value & 0xFF).chr(($value >> 8) & 0xFF);
    }

    /**
     * GIF-variant LZW. Emits a leading clear code, grows the code width when the
     * code just added to the dictionary reaches 2^codeSize (the timing decoders
     * expect), resets on a full 12-bit table, and ends with the end-of-information
     * code. Codes are packed LSB-first.
     */
    private static function lzwEncode(string $indices, int $minCodeSize): string
    {
        $clearCode = 1 << $minCodeSize;
        $eoiCode = $clearCode + 1;

        $codeSize = $minCodeSize + 1;
        $nextCode = $eoiCode + 1;
        /** @var array<int, int> $dict */
        $dict = [];

        $bitBuffer = 0;
        $bitCount = 0;
        $output = '';

        $emit = static function (int $code) use (&$bitBuffer, &$bitCount, &$output, &$codeSize): void {
            $bitBuffer |= $code << $bitCount;
            $bitCount += $codeSize;
            while ($bitCount >= 8) {
                $output .= chr($bitBuffer & 0xFF);
                $bitBuffer >>= 8;
                $bitCount -= 8;
            }
        };

        $emit($clearCode);

        $length = strlen($indices);
        $prefix = ord($indices[0]);

        for ($i = 1; $i < $length; ++$i) {
            $k = ord($indices[$i]);
            $key = ($prefix << 8) | $k;

            if (isset($dict[$key])) {
                $prefix = $dict[$key];
                continue;
            }

            $emit($prefix);

            if ($nextCode < 4096) {
                $dict[$key] = $nextCode;
                if ($nextCode === (1 << $codeSize) && $codeSize < 12) {
                    ++$codeSize;
                }
                ++$nextCode;
            } else {
                $emit($clearCode);
                $dict = [];
                $codeSize = $minCodeSize + 1;
                $nextCode = $eoiCode + 1;
            }

            $prefix = $k;
        }

        $emit($prefix);
        $emit($eoiCode);

        if ($bitCount > 0) {
            $output .= chr($bitBuffer & 0xFF);
        }

        return $output;
    }

    /**
     * Frames raw LZW bytes as GIF data sub-blocks (each up to 255 bytes, length
     * prefixed) and appends the zero-length block terminator.
     */
    private static function subBlocks(string $data): string
    {
        $out = '';
        $length = strlen($data);
        for ($offset = 0; $offset < $length; $offset += 255) {
            $chunk = substr($data, $offset, 255);
            $out .= chr(min(255, strlen($chunk))).$chunk;
        }

        return $out."\x00";
    }
}
