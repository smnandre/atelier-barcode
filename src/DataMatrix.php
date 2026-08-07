<?php

declare(strict_types=1);

namespace Atelier\Barcode;

use Atelier\Barcode\Exception\InvalidCodeException;
use Atelier\Barcode\Internal\SvgRenderer;

final class DataMatrix
{
    private const array SYMBOLS = [
        ['size' => 10, 'data' => 3, 'ecc' => 5],
        ['size' => 12, 'data' => 5, 'ecc' => 7],
        ['size' => 14, 'data' => 8, 'ecc' => 10],
        ['size' => 16, 'data' => 12, 'ecc' => 12],
        ['size' => 18, 'data' => 18, 'ecc' => 14],
        ['size' => 20, 'data' => 22, 'ecc' => 18],
        ['size' => 22, 'data' => 30, 'ecc' => 20],
        ['size' => 24, 'data' => 36, 'ecc' => 24],
        ['size' => 26, 'data' => 44, 'ecc' => 28],
    ];

    /** @var array<int, int> */
    private static array $exp = [];
    /** @var array<int, int> */
    private static array $log = [];

    private int $size = 220;
    private int $margin = 2;
    private string $foreground = '#000000';
    private string $background = '#ffffff';
    /** @var list<list<bool>>|null */
    private ?array $matrix = null;

    private function __construct(private readonly string $data)
    {
    }

    public static function create(string $data): self
    {
        if ('' === $data) {
            throw new InvalidCodeException('Data Matrix data must not be empty.');
        }

        return new self($data);
    }

    public function size(int $pixels): self
    {
        $this->size = SvgRenderer::boundedSide('Size', $pixels);

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
     * @return list<list<bool>>
     */
    public function matrix(): array
    {
        return $this->matrix ??= $this->build();
    }

    public function render(): string
    {
        return SvgRenderer::matrix($this->matrix(), $this->size, $this->margin, $this->foreground, $this->background);
    }

    /**
     * @return list<list<bool>>
     */
    private function build(): array
    {
        $data = $this->encodeAscii();
        $symbol = $this->selectSymbol(count($data));
        $dataCodewords = $this->pad($data, $symbol['data']);
        $codewords = [...$dataCodewords, ...$this->errorCodewords($dataCodewords, $symbol['ecc'])];
        $dataSize = $symbol['size'] - 2;
        $placed = $this->place($codewords, $dataSize, $dataSize);

        return $this->wrapFinder($placed);
    }

    /**
     * @return list<int>
     */
    private function encodeAscii(): array
    {
        $codewords = [];
        $length = strlen($this->data);
        $i = 0;
        while ($i < $length) {
            if ($i + 1 < $length && ctype_digit($this->data[$i]) && ctype_digit($this->data[$i + 1])) {
                $codewords[] = 130 + (int) substr($this->data, $i, 2);
                $i += 2;
                continue;
            }

            $ordinal = ord($this->data[$i]);
            if ($ordinal <= 127) {
                $codewords[] = $ordinal + 1;
            } else {
                $codewords[] = 235;
                $codewords[] = $ordinal - 127;
            }
            ++$i;
        }

        return $codewords;
    }

    /**
     * @return array{size: int, data: int, ecc: int}
     */
    private function selectSymbol(int $dataCount): array
    {
        foreach (self::SYMBOLS as $symbol) {
            if ($dataCount <= $symbol['data']) {
                return $symbol;
            }
        }

        throw new InvalidCodeException('Data is too long for a single-region Data Matrix symbol.');
    }

    /**
     * @param list<int> $codewords
     *
     * @return list<int>
     */
    private function pad(array $codewords, int $capacity): array
    {
        if (count($codewords) < $capacity) {
            $codewords[] = 129;
        }
        while (count($codewords) < $capacity) {
            $position = count($codewords) + 1;
            $pseudoRandom = ((149 * $position) % 253) + 1;
            $value = 129 + $pseudoRandom;
            $codewords[] = $value <= 254 ? $value : $value - 254;
        }

        return $codewords;
    }

    /**
     * @param list<int> $data
     *
     * @return list<int>
     */
    private function errorCodewords(array $data, int $count): array
    {
        self::initGaloisField();

        $generator = [1];
        for ($i = 1; $i <= $count; ++$i) {
            $generator = self::polyMultiply($generator, [1, self::$exp[$i]]);
        }

        $ecc = array_fill(0, $count, 0);
        foreach ($data as $word) {
            $factor = $word ^ $ecc[0];
            array_shift($ecc);
            $ecc[] = 0;
            for ($i = 0; $i < $count; ++$i) {
                $ecc[$i] ^= self::gfMultiply($generator[$i + 1], $factor);
            }
        }

        return array_values($ecc);
    }

    /**
     * @param list<int> $left
     * @param list<int> $right
     *
     * @return list<int>
     */
    private static function polyMultiply(array $left, array $right): array
    {
        $result = array_fill(0, count($left) + count($right) - 1, 0);
        foreach ($left as $i => $a) {
            foreach ($right as $j => $b) {
                $result[$i + $j] ^= self::gfMultiply($a, $b);
            }
        }

        return array_values($result);
    }

    private static function gfMultiply(int $a, int $b): int
    {
        if (0 === $a || 0 === $b) {
            return 0;
        }

        return self::$exp[(self::$log[$a] + self::$log[$b]) % 255];
    }

    private static function initGaloisField(): void
    {
        if ([] !== self::$exp) {
            return;
        }

        $x = 1;
        for ($i = 0; $i < 255; ++$i) {
            self::$exp[$i] = $x;
            self::$log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x12D;
            }
        }
        for ($i = 255; $i < 512; ++$i) {
            self::$exp[$i] = self::$exp[$i - 255];
        }
    }

    /**
     * @param list<int> $codewords
     *
     * @return list<list<bool>>
     */
    private function place(array $codewords, int $rows, int $cols): array
    {
        $matrix = array_fill(0, $rows, array_fill(0, $cols, null));
        $row = 4;
        $col = 0;
        $pos = 0;

        do {
            if ($row === $rows && 0 === $col) {
                $this->corner1($matrix, $rows, $cols, $codewords[$pos++]);
            }
            if ($row === $rows - 2 && 0 === $col && 0 !== $cols % 4) {
                $this->corner2($matrix, $rows, $cols, $codewords[$pos++]);
            }
            do {
                if ($row < $rows && null === $matrix[$row][$col]) {
                    $this->utah($matrix, $rows, $cols, $row, $col, $codewords[$pos++]);
                }
                $row -= 2;
                $col += 2;
            } while ($row >= 0 && $col < $cols);
            ++$row;
            $col += 3;

            do {
                if ($row >= 0 && $col < $cols && null === $matrix[$row][$col]) {
                    $this->utah($matrix, $rows, $cols, $row, $col, $codewords[$pos++]);
                }
                $row += 2;
                $col -= 2;
            } while ($row < $rows && $col >= 0);
            $row += 3;
            ++$col;
        } while ($row < $rows || $col < $cols);

        if (null === $matrix[$rows - 1][$cols - 1]) {
            $matrix[$rows - 1][$cols - 1] = true;
            $matrix[$rows - 2][$cols - 2] = true;
        }

        $result = [];
        foreach ($matrix as $line) {
            $result[] = array_values(array_map(static fn (?bool $value): bool => true === $value, $line));
        }

        return $result;
    }

    /**
     * @param array<int, array<int, bool|null>> $matrix
     */
    private function utah(array &$matrix, int $rows, int $cols, int $row, int $col, int $codeword): void
    {
        $this->module($matrix, $rows, $cols, $row - 2, $col - 2, $codeword, 1);
        $this->module($matrix, $rows, $cols, $row - 2, $col - 1, $codeword, 2);
        $this->module($matrix, $rows, $cols, $row - 1, $col - 2, $codeword, 3);
        $this->module($matrix, $rows, $cols, $row - 1, $col - 1, $codeword, 4);
        $this->module($matrix, $rows, $cols, $row - 1, $col, $codeword, 5);
        $this->module($matrix, $rows, $cols, $row, $col - 2, $codeword, 6);
        $this->module($matrix, $rows, $cols, $row, $col - 1, $codeword, 7);
        $this->module($matrix, $rows, $cols, $row, $col, $codeword, 8);
    }

    /**
     * @param array<int, array<int, bool|null>> $matrix
     */
    private function corner1(array &$matrix, int $rows, int $cols, int $codeword): void
    {
        $this->module($matrix, $rows, $cols, $rows - 1, 0, $codeword, 1);
        $this->module($matrix, $rows, $cols, $rows - 1, 1, $codeword, 2);
        $this->module($matrix, $rows, $cols, $rows - 1, 2, $codeword, 3);
        $this->module($matrix, $rows, $cols, 0, $cols - 2, $codeword, 4);
        $this->module($matrix, $rows, $cols, 0, $cols - 1, $codeword, 5);
        $this->module($matrix, $rows, $cols, 1, $cols - 1, $codeword, 6);
        $this->module($matrix, $rows, $cols, 2, $cols - 1, $codeword, 7);
        $this->module($matrix, $rows, $cols, 3, $cols - 1, $codeword, 8);
    }

    /**
     * @param array<int, array<int, bool|null>> $matrix
     */
    private function corner2(array &$matrix, int $rows, int $cols, int $codeword): void
    {
        $this->module($matrix, $rows, $cols, $rows - 3, 0, $codeword, 1);
        $this->module($matrix, $rows, $cols, $rows - 2, 0, $codeword, 2);
        $this->module($matrix, $rows, $cols, $rows - 1, 0, $codeword, 3);
        $this->module($matrix, $rows, $cols, 0, $cols - 4, $codeword, 4);
        $this->module($matrix, $rows, $cols, 0, $cols - 3, $codeword, 5);
        $this->module($matrix, $rows, $cols, 0, $cols - 2, $codeword, 6);
        $this->module($matrix, $rows, $cols, 0, $cols - 1, $codeword, 7);
        $this->module($matrix, $rows, $cols, 1, $cols - 1, $codeword, 8);
    }

    /**
     * @param array<int, array<int, bool|null>> $matrix
     */
    private function module(array &$matrix, int $rows, int $cols, int $row, int $col, int $codeword, int $bit): void
    {
        if ($row < 0) {
            $row += $rows;
            $col += 4 - (($rows + 4) % 8);
        }
        if ($col < 0) {
            $col += $cols;
            $row += 4 - (($cols + 4) % 8);
        }

        $matrix[$row][$col] = 1 === (($codeword >> (8 - $bit)) & 1);
    }

    /**
     * @param list<list<bool>> $data
     *
     * @return list<list<bool>>
     */
    private function wrapFinder(array $data): array
    {
        $dataSize = count($data);
        $size = $dataSize + 2;
        $matrix = array_fill(0, $size, array_fill(0, $size, false));

        for ($i = 0; $i < $size; ++$i) {
            $matrix[0][$i] = 0 === $i % 2;
        }
        for ($i = 0; $i < $size; ++$i) {
            $matrix[$i][$size - 1] = 1 === $i % 2;
        }
        for ($i = 0; $i < $size; ++$i) {
            $matrix[$i][0] = true;
            $matrix[$size - 1][$i] = true;
        }
        for ($r = 0; $r < $dataSize; ++$r) {
            for ($c = 0; $c < $dataSize; ++$c) {
                $matrix[$r + 1][$c + 1] = $data[$r][$c];
            }
        }

        $result = [];
        foreach ($matrix as $line) {
            $result[] = array_values($line);
        }

        return $result;
    }
}
