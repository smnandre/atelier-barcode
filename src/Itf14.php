<?php

declare(strict_types=1);

namespace Atelier\Barcode;

use Atelier\Barcode\Exception\InvalidCodeException;
use Atelier\Barcode\Internal\SvgRenderer;

final class Itf14
{
    private int $scale = 2;
    private int $height = 80;
    private int $margin = 10;
    private int $wideRatio = 3;
    private string $foreground = '#000000';
    private string $background = '#ffffff';
    private bool $text = true;
    private bool $bearerBars = true;
    /** @var list<bool>|null */
    private ?array $modules = null;

    private function __construct(private readonly string $digits)
    {
    }

    public static function create(string $digits): self
    {
        if (!ctype_digit($digits) || (13 !== strlen($digits) && 14 !== strlen($digits))) {
            throw new InvalidCodeException('ITF-14 expects 13 or 14 digits.');
        }

        if (13 === strlen($digits)) {
            $digits .= (string) self::checkDigit($digits);
        } elseif ((int) $digits[13] !== self::checkDigit(substr($digits, 0, 13))) {
            throw new InvalidCodeException('Invalid ITF-14 check digit.');
        }

        return new self($digits);
    }

    public function value(): string
    {
        return $this->digits;
    }

    public function scale(int $modulePixels): self
    {
        $this->scale = SvgRenderer::positiveInt('Scale', $modulePixels);

        return $this;
    }

    public function height(int $pixels): self
    {
        $this->height = SvgRenderer::boundedSide('Height', $pixels);

        return $this;
    }

    public function margin(int $modules): self
    {
        $this->margin = SvgRenderer::quietZone($modules);

        return $this;
    }

    public function wideRatio(int $ratio): self
    {
        if ($ratio < 2 || $ratio > 10) {
            throw new InvalidCodeException('ITF-14 wide ratio must be between 2 and 10.');
        }
        $this->wideRatio = $ratio;
        $this->modules = null;

        return $this;
    }

    public function foreground(string $color): self
    {
        $this->foreground = $color;

        return $this;
    }

    public function background(string $color): self
    {
        $this->background = $color;

        return $this;
    }

    public function withText(bool $show): self
    {
        $this->text = $show;

        return $this;
    }

    public function withBearerBars(bool $show): self
    {
        $this->bearerBars = $show;

        return $this;
    }

    /**
     * @return list<bool>
     */
    public function modules(): array
    {
        return $this->modules ??= Interleaved2Of5::create($this->digits)
            ->wideRatio($this->wideRatio)
            ->modules();
    }

    public function render(): string
    {
        $caption = $this->text ? $this->digits : null;
        if (!$this->bearerBars) {
            return SvgRenderer::linear(
                $this->modules(),
                $this->scale,
                $this->height,
                $this->margin,
                $this->foreground,
                $this->background,
                $caption,
            );
        }

        $modules = $this->modules();
        $scale = SvgRenderer::positiveInt('Scale', $this->scale);
        $height = SvgRenderer::boundedSide('Height', $this->height);
        $margin = SvgRenderer::quietZone($this->margin);
        $width = (count($modules) + 2 * $margin) * $scale;
        $textHeight = null === $caption ? 0 : max(14, $scale * 8);
        $bearerHeight = max(2, $scale * 2);
        $svgHeight = $height + 2 * $bearerHeight + $textHeight;
        SvgRenderer::ensureOutputBounds($width, $svgHeight);
        $fg = SvgRenderer::attribute($this->foreground);
        $bg = SvgRenderer::attribute($this->background);
        $barGroup = SvgRenderer::bars($modules, $scale, $height, $margin);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$width.'" height="'.$svgHeight
            .'" viewBox="0 0 '.$width.' '.$svgHeight.'" shape-rendering="crispEdges">'
            .'<rect width="'.$width.'" height="'.$svgHeight.'" fill="'.$bg.'"/>'
            .'<g fill="'.$fg.'">'
            .'<rect x="0" y="0" width="'.$width.'" height="'.$bearerHeight.'"/>'
            .'<rect x="0" y="'.($bearerHeight + $height).'" width="'.$width.'" height="'.$bearerHeight.'"/>'
            .'<rect x="0" y="0" width="'.$bearerHeight.'" height="'.($height + 2 * $bearerHeight).'"/>'
            .'<rect x="'.($width - $bearerHeight).'" y="0" width="'.$bearerHeight.'" height="'.($height + 2 * $bearerHeight).'"/>'
            .'</g><g fill="'.$fg.'" transform="translate(0 '.$bearerHeight.')">'.$barGroup.'</g>';

        if (null !== $caption) {
            $svg .= '<text x="'.($width / 2).'" y="'.($height + 2 * $bearerHeight + $textHeight * 0.72)
                .'" fill="'.$fg.'" font-family="ui-monospace, monospace" font-size="'
                .round($textHeight * 0.82, 1).'" letter-spacing="'.$scale.'" text-anchor="middle">'
                .SvgRenderer::attribute($caption).'</text>';
        }

        return $svg.'</svg>';
    }

    private static function checkDigit(string $thirteen): int
    {
        $sum = 0;
        $weight = 3;
        for ($i = 12; $i >= 0; --$i) {
            $sum += $weight * (int) $thirteen[$i];
            $weight = 4 - $weight;
        }

        return (10 - $sum % 10) % 10;
    }
}
