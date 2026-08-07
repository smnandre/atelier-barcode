<?php

declare(strict_types=1);

namespace Atelier\Barcode;

use Atelier\Barcode\Exception\InvalidCodeException;
use Atelier\Barcode\Internal\SvgRenderer;

final class Ean8
{
    private const array L = [
        '0001101', '0011001', '0010011', '0111101', '0100011',
        '0110001', '0101111', '0111011', '0110111', '0001011',
    ];

    private const array R = [
        '1110010', '1100110', '1101100', '1000010', '1011100',
        '1001110', '1010000', '1000100', '1001000', '1110100',
    ];

    private int $scale = 2;
    private int $height = 72;
    private int $margin = 7;
    private string $foreground = '#000000';
    private string $background = '#ffffff';
    private bool $text = true;
    /** @var list<bool>|null */
    private ?array $modules = null;

    private function __construct(private readonly string $digits)
    {
    }

    public static function create(string $code): self
    {
        if (!ctype_digit($code) || (7 !== strlen($code) && 8 !== strlen($code))) {
            throw new InvalidCodeException('EAN-8 expects 7 or 8 digits.');
        }

        if (7 === strlen($code)) {
            $code .= (string) self::checkDigit($code);
        } elseif ((int) $code[7] !== self::checkDigit(substr($code, 0, 7))) {
            throw new InvalidCodeException('Invalid EAN-8 check digit.');
        }

        return new self($code);
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

    /**
     * @return list<bool>
     */
    public function modules(): array
    {
        return $this->modules ??= $this->build();
    }

    public function render(): string
    {
        return SvgRenderer::linear(
            $this->modules(),
            $this->scale,
            $this->height,
            $this->margin,
            $this->foreground,
            $this->background,
            $this->text ? $this->digits : null,
        );
    }

    public static function checkDigit(string $seven): int
    {
        if (!ctype_digit($seven) || 7 !== strlen($seven)) {
            throw new InvalidCodeException('EAN-8 check digit expects 7 digits.');
        }

        $sum = 0;
        for ($i = 0; $i < 7; ++$i) {
            $sum += (0 === $i % 2 ? 3 : 1) * (int) $seven[$i];
        }

        return (10 - $sum % 10) % 10;
    }

    /**
     * @return list<bool>
     */
    private function build(): array
    {
        $bits = '101';
        for ($i = 0; $i < 4; ++$i) {
            $bits .= self::L[(int) $this->digits[$i]];
        }
        $bits .= '01010';
        for ($i = 4; $i < 8; ++$i) {
            $bits .= self::R[(int) $this->digits[$i]];
        }
        $bits .= '101';

        return array_map(static fn (string $c): bool => '1' === $c, str_split($bits));
    }
}
