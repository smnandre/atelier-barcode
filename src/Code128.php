<?php

declare(strict_types=1);

namespace Atelier\Barcode;

use Atelier\Barcode\Exception\InvalidCodeException;
use Atelier\Barcode\Internal\SvgRenderer;

final class Code128
{
    private const int MAX_DATA_LENGTH = 512;

    private int $scale = 2;
    private int $height = 80;
    private int $margin = 10;
    private string $foreground = '#000000';
    private string $background = '#ffffff';
    private bool $text = true;
    /** @var list<bool>|null */
    private ?array $modules = null;

    private function __construct(private readonly string $data)
    {
    }

    public static function create(string $data): self
    {
        if ('' === $data) {
            throw new InvalidCodeException('Code 128 data must not be empty.');
        }
        SvgRenderer::maxPayloadLength('Code 128 data', $data, self::MAX_DATA_LENGTH);
        for ($i = 0, $len = strlen($data); $i < $len; ++$i) {
            if (ord($data[$i]) > 127) {
                throw new InvalidCodeException('Code 128 supports 7-bit ASCII only.');
            }
        }

        return new self($data);
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
            $this->text ? $this->data : null,
        );
    }

    /**
     * @return list<bool>
     */
    private function build(): array
    {
        $values = $this->encode();

        $checksum = $values[0];
        for ($i = 1, $count = count($values); $i < $count; ++$i) {
            $checksum += $i * $values[$i];
        }
        $values[] = $checksum % 103;

        $bits = '';
        foreach ($values as $value) {
            $bits .= self::PATTERNS[$value];
        }
        $bits .= self::STOP.'11';

        return array_map(static fn (string $c): bool => '1' === $c, str_split($bits));
    }

    /**
     * @return list<int>
     */
    private function encode(): array
    {
        $data = $this->data;
        $len = strlen($data);
        $leadingDigits = static function (int $p) use ($data, $len): int {
            $n = 0;
            while ($p + $n < $len && ctype_digit($data[$p + $n])) {
                ++$n;
            }

            return $n;
        };

        $values = [];
        if ((ctype_digit($data) && 0 === $len % 2) || $leadingDigits(0) >= 4) {
            $charset = 'C';
            $values[] = 105;
        } elseif (ord($data[0]) < 32) {
            $charset = 'A';
            $values[] = 103;
        } else {
            $charset = 'B';
            $values[] = 104;
        }

        $i = 0;
        while ($i < $len) {
            if ('C' === $charset) {
                if ($i + 1 < $len && ctype_digit($data[$i]) && ctype_digit($data[$i + 1])) {
                    $values[] = (int) substr($data, $i, 2);
                    $i += 2;
                    continue;
                }
                if (ord($data[$i]) >= 32) {
                    $values[] = 100;
                    $charset = 'B';
                } else {
                    $values[] = 101;
                    $charset = 'A';
                }
                continue;
            }

            $lead = $leadingDigits($i);
            if ($lead >= 4 || ($lead >= 2 && 0 === $lead % 2 && $i + $lead === $len)) {
                $values[] = 99;
                $charset = 'C';
                continue;
            }

            $ordinal = ord($data[$i]);
            if ('B' === $charset) {
                if ($ordinal >= 32) {
                    $values[] = $ordinal - 32;
                    ++$i;
                } else {
                    $values[] = 101;
                    $charset = 'A';
                }
            } else {
                if ($ordinal >= 32 && $ordinal <= 95) {
                    $values[] = $ordinal - 32;
                    ++$i;
                } elseif ($ordinal < 32) {
                    $values[] = $ordinal + 64;
                    ++$i;
                } else {
                    $values[] = 100;
                    $charset = 'B';
                }
            }
        }

        return $values;
    }

    // 11-module bar/space patterns per code value 0..106 (1 = bar module)
    private const array PATTERNS = [
        '11011001100', '11001101100', '11001100110', '10010011000', '10010001100', '10001001100',
        '10011001000', '10011000100', '10001100100', '11001001000', '11001000100', '11000100100',
        '10110011100', '10011011100', '10011001110', '10111001100', '10011101100', '10011100110',
        '11001110010', '11001011100', '11001001110', '11011100100', '11001110100', '11101101110',
        '11101001100', '11100101100', '11100100110', '11101100100', '11100110100', '11100110010',
        '11011011000', '11011000110', '11000110110', '10100011000', '10001011000', '10001000110',
        '10110001000', '10001101000', '10001100010', '11010001000', '11000101000', '11000100010',
        '10110111000', '10110001110', '10001101110', '10111011000', '10111000110', '10001110110',
        '11101110110', '11010001110', '11000101110', '11011101000', '11011100010', '11011101110',
        '11101011000', '11101000110', '11100010110', '11101101000', '11101100010', '11100011010',
        '11101111010', '11001000010', '11110001010', '10100110000', '10100001100', '10010110000',
        '10010000110', '10000101100', '10000100110', '10110010000', '10110000100', '10011010000',
        '10011000010', '10000110100', '10000110010', '11000010010', '11001010000', '11110111010',
        '11000010100', '10001111010', '10100111100', '10010111100', '10010011110', '10111100100',
        '10011110100', '10011110010', '11110100100', '11110010100', '11110010010', '11011011110',
        '11011110110', '11110110110', '10101111000', '10100011110', '10001011110', '10111101000',
        '10111100010', '11110101000', '11110100010', '10111011110', '10111101110', '11101011110',
        '11110101110', '11010000100', '11010010000', '11010011100',
    ];

    private const string STOP = '11000111010';
}
