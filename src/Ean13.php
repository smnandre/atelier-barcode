<?php

declare(strict_types=1);

namespace Atelier\Barcode;

use Atelier\Barcode\Exception\InvalidCodeException;
use Atelier\Barcode\Internal\SvgRenderer;

final class Ean13
{
    private const array L = [
        '0001101', '0011001', '0010011', '0111101', '0100011',
        '0110001', '0101111', '0111011', '0110111', '0001011',
    ];

    private const array G = [
        '0100111', '0110011', '0011011', '0100001', '0011101',
        '0111001', '0000101', '0010001', '0001001', '0010111',
    ];

    private const array R = [
        '1110010', '1100110', '1101100', '1000010', '1011100',
        '1001110', '1010000', '1000100', '1001000', '1110100',
    ];

    private const array PARITY = [
        'AAAAAA', 'AABABB', 'AABBAB', 'AABBBA', 'ABAABB',
        'ABBAAB', 'ABBBAA', 'ABABAB', 'ABABBA', 'ABBABA',
    ];

    private int $scale = 2;
    private int $height = 80;
    private int $margin = 11;
    private string $foreground = '#000000';
    private string $background = '#ffffff';
    /** @var list<bool>|null */
    private ?array $modules = null;

    private function __construct(private readonly string $digits)
    {
    }

    public static function create(string $code): self
    {
        if (!ctype_digit($code) || (12 !== strlen($code) && 13 !== strlen($code))) {
            throw new InvalidCodeException('EAN-13 expects 12 or 13 digits.');
        }

        if (12 === strlen($code)) {
            $code .= (string) self::checkDigit($code);
        } elseif ((int) $code[12] !== self::checkDigit(substr($code, 0, 12))) {
            throw new InvalidCodeException('Invalid EAN-13 check digit.');
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

    /**
     * @return list<bool>
     */
    public function modules(): array
    {
        return $this->modules ??= $this->build();
    }

    public function render(): string
    {
        $modules = $this->build();
        $count = count($modules);
        $width = ($count + 2 * $this->margin) * $this->scale;
        $textHeight = max(14, $this->scale * 9);
        $overrun = $textHeight * 0.55;
        $height = $this->height + $textHeight;
        SvgRenderer::ensureOutputBounds($width, $height);
        $fg = SvgRenderer::attribute($this->foreground);
        $bg = SvgRenderer::attribute($this->background);

        $guards = [[0, 2], [46, 49], [92, 94]];
        $isGuard = static function (int $index) use ($guards): bool {
            foreach ($guards as [$from, $to]) {
                if ($index >= $from && $index <= $to) {
                    return true;
                }
            }

            return false;
        };

        $bars = '';
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
            $barHeight = $isGuard($start) ? $this->height + $overrun : $this->height;
            $bars .= '<rect x="'.(($this->margin + $start) * $this->scale)
                .'" y="0" width="'.(($c - $start) * $this->scale)
                .'" height="'.round($barHeight, 1).'"/>';
        }

        $fontSize = round($textHeight * 0.85, 1);
        $baseline = $this->height + $textHeight * 0.86;
        $digit = fn (string $glyph, float $centerModule): string => '<text x="'
            .round(($this->margin + $centerModule) * $this->scale, 1).'" y="'.round($baseline, 1)
            .'" font-size="'.$fontSize.'" text-anchor="middle">'.SvgRenderer::attribute($glyph).'</text>';

        $text = '<g fill="'.$fg.'" font-family="ui-monospace, monospace">';
        $text .= $digit($this->digits[0], -5);
        for ($i = 0; $i < 6; ++$i) {
            $text .= $digit($this->digits[1 + $i], 3 + $i * 7 + 3.5);
        }
        for ($i = 0; $i < 6; ++$i) {
            $text .= $digit($this->digits[7 + $i], 50 + $i * 7 + 3.5);
        }
        $text .= '</g>';

        return '<svg xmlns="http://www.w3.org/2000/svg" width="'.$width.'" height="'.$height
            .'" viewBox="0 0 '.$width.' '.$height.'" shape-rendering="crispEdges">'
            .'<rect width="'.$width.'" height="'.$height.'" fill="'.$bg.'"/>'
            .'<g fill="'.$fg.'">'.$bars.'</g>'.$text.'</svg>';
    }

    private static function checkDigit(string $twelve): int
    {
        $sum = 0;
        for ($i = 0; $i < 12; ++$i) {
            $sum += (0 === $i % 2 ? 1 : 3) * (int) $twelve[$i];
        }

        return (10 - $sum % 10) % 10;
    }

    /**
     * @return list<bool>
     */
    private function build(): array
    {
        $parity = self::PARITY[(int) $this->digits[0]];

        $bits = '101';
        for ($i = 0; $i < 6; ++$i) {
            $d = (int) $this->digits[1 + $i];
            $bits .= 'A' === $parity[$i] ? self::L[$d] : self::G[$d];
        }
        $bits .= '01010';
        for ($i = 0; $i < 6; ++$i) {
            $bits .= self::R[(int) $this->digits[7 + $i]];
        }
        $bits .= '101';

        return array_map(static fn (string $c): bool => '1' === $c, str_split($bits));
    }
}
