<?php

declare(strict_types=1);

namespace Atelier\Barcode\Internal;

use Atelier\Barcode\Exception\InvalidCodeException;

final class SvgRenderer
{
    public const int MAX_SIDE = 20000;
    public const int MAX_PIXELS = 40_000_000;
    public const int MAX_MARGIN = 10000;

    public static function positiveInt(string $name, int $value): int
    {
        if ($value <= 0) {
            throw new InvalidCodeException(sprintf('%s must be a positive integer, %d given.', $name, $value));
        }

        return $value;
    }

    public static function nonNegativeInt(string $name, int $value): int
    {
        if ($value < 0) {
            throw new InvalidCodeException(sprintf('%s must be zero or positive, %d given.', $name, $value));
        }

        return $value;
    }

    public static function boundedSide(string $name, int $value): int
    {
        self::positiveInt($name, $value);
        if ($value > self::MAX_SIDE) {
            throw new InvalidCodeException(sprintf('%s must not exceed %d pixels, %d given.', $name, self::MAX_SIDE, $value));
        }

        return $value;
    }

    public static function quietZone(int $modules): int
    {
        self::nonNegativeInt('Margin', $modules);
        if ($modules > self::MAX_MARGIN) {
            throw new InvalidCodeException(sprintf('Margin must not exceed %d modules, %d given.', self::MAX_MARGIN, $modules));
        }

        return $modules;
    }

    public static function maxPayloadLength(string $name, string $payload, int $maxBytes): string
    {
        $length = strlen($payload);
        if ($length > $maxBytes) {
            throw new InvalidCodeException(sprintf('%s must not exceed %d bytes, %d given.', $name, $maxBytes, $length));
        }

        return $payload;
    }

    /**
     * @param list<list<bool>> $matrix
     */
    public static function matrix(array $matrix, int $size, int $margin, string $foreground, string $background): string
    {
        $n = count($matrix);
        $total = $n + 2 * self::quietZone($margin);
        self::ensureOutputBounds(self::boundedSide('Size', $size), $size);
        $fg = self::attribute($foreground);
        $bg = self::attribute($background);

        $rects = '';
        for ($r = 0; $r < $n; ++$r) {
            $c = 0;
            while ($c < $n) {
                if (!$matrix[$r][$c]) {
                    ++$c;
                    continue;
                }
                $start = $c;
                while ($c < $n && $matrix[$r][$c]) {
                    ++$c;
                }
                $rects .= '<rect x="'.($margin + $start).'" y="'.($margin + $r)
                    .'" width="'.($c - $start).'" height="1"/>';
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="'.$size.'" height="'.$size
            .'" viewBox="0 0 '.$total.' '.$total.'" shape-rendering="crispEdges">'
            .'<rect width="'.$total.'" height="'.$total.'" fill="'.$bg.'"/>'
            .'<g fill="'.$fg.'">'.$rects.'</g></svg>';
    }

    /**
     * @param list<bool> $modules
     */
    public static function linear(array $modules, int $scale, int $height, int $margin, string $foreground, string $background, ?string $caption): string
    {
        $count = count($modules);
        $scale = self::positiveInt('Scale', $scale);
        $height = self::boundedSide('Height', $height);
        $margin = self::quietZone($margin);
        $totalModules = $count + 2 * $margin;
        $width = $totalModules * $scale;
        $textHeight = null === $caption ? 0 : max(14, $scale * 8);
        $svgHeight = $height + $textHeight;
        self::ensureOutputBounds($width, $svgHeight);
        $fg = self::attribute($foreground);
        $bg = self::attribute($background);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$width.'" height="'.$svgHeight
            .'" viewBox="0 0 '.$width.' '.$svgHeight.'" shape-rendering="crispEdges">'
            .'<rect width="'.$width.'" height="'.$svgHeight.'" fill="'.$bg.'"/>'
            .'<g fill="'.$fg.'">'.self::bars($modules, $scale, $height, $margin).'</g>';

        if (null !== $caption) {
            $svg .= '<text x="'.($width / 2).'" y="'.($height + $textHeight * 0.72)
                .'" fill="'.$fg.'" font-family="ui-monospace, monospace" font-size="'
                .round($textHeight * 0.82, 1).'" letter-spacing="'.$scale.'" text-anchor="middle">'
                .self::attribute($caption).'</text>';
        }

        return $svg.'</svg>';
    }

    /**
     * @param list<bool> $modules
     */
    public static function bars(array $modules, int $scale, float $height, int $margin): string
    {
        $bars = '';
        $count = count($modules);
        $c = 0;
        while ($c < $count) {
            if (!$modules[$c]) {
                ++$c;
                continue;
            }
            $start = $c;
            while ($c < $count && $modules[$c]) {
                ++$c;
            }
            $bars .= '<rect x="'.(($margin + $start) * $scale)
                .'" y="0" width="'.(($c - $start) * $scale)
                .'" height="'.round($height, 1).'"/>';
        }

        return $bars;
    }

    public static function ensureOutputBounds(int $width, int $height): void
    {
        if ($width <= 0 || $height <= 0) {
            throw new InvalidCodeException(sprintf('Output dimensions must be positive, %dx%d computed.', $width, $height));
        }
        if ($width > self::MAX_SIDE || $height > self::MAX_SIDE) {
            throw new InvalidCodeException(sprintf('Output %dx%d exceeds the maximum side of %d pixels.', $width, $height, self::MAX_SIDE));
        }
        if ($width * $height > self::MAX_PIXELS) {
            throw new InvalidCodeException(sprintf('Output %dx%d exceeds the maximum area of %d pixels.', $width, $height, self::MAX_PIXELS));
        }
    }

    public static function attribute(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1);
    }
}
