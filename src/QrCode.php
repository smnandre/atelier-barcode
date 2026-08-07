<?php

declare(strict_types=1);

namespace Atelier\Barcode;

use Atelier\Barcode\Exception\InvalidCodeException;
use Atelier\Barcode\Internal\SvgRenderer;

final class QrCode
{
    private const string MODE_NUMERIC = 'numeric';
    private const string MODE_ALPHANUMERIC = 'alphanumeric';
    private const string MODE_BYTE = 'byte';

    private const string ALPHANUMERIC = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';

    private Ecc $ecc = Ecc::Medium;
    private int $size = 320;
    private int $margin = 4;
    private string $foreground = '#000000';
    private string $background = '#ffffff';
    /** @var list<list<bool>>|null */
    private ?array $matrix = null;

    /** @var array<int, int> */
    private static array $exp = [];
    /** @var array<int, int> */
    private static array $log = [];

    private function __construct(private readonly string $data)
    {
    }

    public static function create(string $data): self
    {
        if ('' === $data) {
            throw new InvalidCodeException('QR data must not be empty.');
        }

        return new self($data);
    }

    public function ecc(Ecc $ecc): self
    {
        $this->ecc = $ecc;
        $this->matrix = null;

        return $this;
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
        $version = $this->selectVersion();
        $codewords = $this->encode($version);
        $n = $version * 4 + 17;

        $best = $this->buildMatrix($version, $codewords, 0);
        $bestScore = $this->penalty($best, $n);
        for ($mask = 1; $mask < 8; ++$mask) {
            $m = $this->buildMatrix($version, $codewords, $mask);
            $score = $this->penalty($m, $n);
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $m;
            }
        }

        $result = [];
        foreach ($best as $row) {
            $line = [];
            foreach ($row as $value) {
                $line[] = true === $value;
            }
            $result[] = $line;
        }

        return $result;
    }

    /**
     * @param list<int> $codewords
     *
     * @return array<int, array<int, bool|null>>
     */
    private function buildMatrix(int $version, array $codewords, int $mask): array
    {
        $n = $version * 4 + 17;
        $m = array_fill(0, $n, array_fill(0, $n, null));
        $this->finders($m, $n);
        $this->alignment($m, $version);
        $this->timing($m, $n);
        if ($version >= 7) {
            $this->versionInfo($m, $n, $version);
        }
        $this->formatInfo($m, $n, $mask);
        $this->mapData($m, $codewords, $mask, $n);

        return $m;
    }

    private function selectVersion(): int
    {
        $mode = $this->mode();
        $payloadBits = $this->payloadBitCount($mode);
        for ($v = 1; $v <= 40; ++$v) {
            $needed = (int) ceil((4 + $this->characterCountBits($mode, $v) + $payloadBits) / 8);
            if ($needed <= $this->capacity($v)) {
                return $v;
            }
        }

        throw new InvalidCodeException('Data is too long for a QR code.');
    }

    private function capacity(int $version): int
    {
        $total = 0;
        foreach (self::BLOCKS[$version][$this->ecc->index()][1] as [$count, $dataWords]) {
            $total += $count * $dataWords;
        }

        return $total;
    }

    /**
     * @return list<int>
     */
    private function encode(int $version): array
    {
        self::initGaloisField();

        $mode = $this->mode();
        $len = strlen($this->data);

        $bits = [];
        $put = static function (int $value, int $width) use (&$bits): void {
            for ($i = $width - 1; $i >= 0; --$i) {
                $bits[] = ($value >> $i) & 1;
            }
        };

        $put($this->modeIndicator($mode), 4);
        $put($len, $this->characterCountBits($mode, $version));

        if (self::MODE_NUMERIC === $mode) {
            for ($i = 0; $i < $len; $i += 3) {
                $chunk = substr($this->data, $i, 3);
                $put((int) $chunk, match (strlen($chunk)) {
                    1 => 4,
                    2 => 7,
                    default => 10,
                });
            }
        } elseif (self::MODE_ALPHANUMERIC === $mode) {
            for ($i = 0; $i < $len; $i += 2) {
                if ($i + 1 < $len) {
                    $put(45 * $this->alphanumericValue($this->data[$i]) + $this->alphanumericValue($this->data[$i + 1]), 11);
                } else {
                    $put($this->alphanumericValue($this->data[$i]), 6);
                }
            }
        } else {
            for ($i = 0; $i < $len; ++$i) {
                $put(ord($this->data[$i]), 8);
            }
        }

        $capacityWords = $this->capacity($version);
        $capacityBits = $capacityWords * 8;

        $terminator = min(4, $capacityBits - count($bits));
        for ($i = 0; $i < $terminator; ++$i) {
            $bits[] = 0;
        }
        while (0 !== count($bits) % 8) {
            $bits[] = 0;
        }

        $words = [];
        for ($i = 0, $total = count($bits); $i < $total; $i += 8) {
            $byte = 0;
            for ($j = 0; $j < 8; ++$j) {
                $byte = ($byte << 1) | $bits[$i + $j];
            }
            $words[] = $byte;
        }

        $pad = [0xEC, 0x11];
        $p = 0;
        while (count($words) < $capacityWords) {
            $words[] = $pad[$p++ % 2];
        }

        [$ecPerBlock, $groups] = self::BLOCKS[$version][$this->ecc->index()];
        $dataBlocks = [];
        $ecBlocks = [];
        $offset = 0;
        foreach ($groups as [$count, $dataWords]) {
            for ($b = 0; $b < $count; ++$b) {
                $block = array_slice($words, $offset, $dataWords);
                $offset += $dataWords;
                $dataBlocks[] = $block;
                $ecBlocks[] = self::reedSolomon($block, $ecPerBlock);
            }
        }

        $result = [];
        $maxData = max(array_map('count', $dataBlocks));
        for ($i = 0; $i < $maxData; ++$i) {
            foreach ($dataBlocks as $block) {
                if ($i < count($block)) {
                    $result[] = $block[$i];
                }
            }
        }
        for ($i = 0; $i < $ecPerBlock; ++$i) {
            foreach ($ecBlocks as $block) {
                $result[] = $block[$i];
            }
        }

        return $result;
    }

    private function mode(): string
    {
        if (ctype_digit($this->data)) {
            return self::MODE_NUMERIC;
        }

        for ($i = 0, $len = strlen($this->data); $i < $len; ++$i) {
            if (false === strpos(self::ALPHANUMERIC, $this->data[$i])) {
                return self::MODE_BYTE;
            }
        }

        return self::MODE_ALPHANUMERIC;
    }

    private function payloadBitCount(string $mode): int
    {
        $len = strlen($this->data);

        if (self::MODE_NUMERIC === $mode) {
            return 10 * intdiv($len, 3) + match ($len % 3) {
                1 => 4,
                2 => 7,
                default => 0,
            };
        }

        if (self::MODE_ALPHANUMERIC === $mode) {
            return 11 * intdiv($len, 2) + 6 * ($len % 2);
        }

        return 8 * $len;
    }

    private function characterCountBits(string $mode, int $version): int
    {
        if (self::MODE_NUMERIC === $mode) {
            return match (true) {
                $version <= 9 => 10,
                $version <= 26 => 12,
                default => 14,
            };
        }

        if (self::MODE_ALPHANUMERIC === $mode) {
            return match (true) {
                $version <= 9 => 9,
                $version <= 26 => 11,
                default => 13,
            };
        }

        return $version <= 9 ? 8 : 16;
    }

    private function modeIndicator(string $mode): int
    {
        return match ($mode) {
            self::MODE_NUMERIC => 0b0001,
            self::MODE_ALPHANUMERIC => 0b0010,
            default => 0b0100,
        };
    }

    private function alphanumericValue(string $character): int
    {
        $value = strpos(self::ALPHANUMERIC, $character);
        if (false === $value) {
            throw new InvalidCodeException(sprintf('Unsupported QR alphanumeric character "%s".', $character));
        }

        return $value;
    }

    private static function initGaloisField(): void
    {
        if ([] !== self::$exp) {
            return;
        }
        $exp = array_fill(0, 512, 0);
        $log = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; ++$i) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; ++$i) {
            $exp[$i] = $exp[$i - 255];
        }
        self::$exp = $exp;
        self::$log = $log;
    }

    private static function mul(int $a, int $b): int
    {
        if (0 === $a || 0 === $b) {
            return 0;
        }

        return self::$exp[self::$log[$a] + self::$log[$b]];
    }

    /**
     * @param list<int> $data
     *
     * @return list<int>
     */
    private static function reedSolomon(array $data, int $degree): array
    {
        $generator = [1];
        for ($i = 0; $i < $degree; ++$i) {
            $next = array_fill(0, count($generator) + 1, 0);
            foreach ($generator as $a => $value) {
                $next[$a] ^= $value;
                $next[$a + 1] ^= self::mul($value, self::$exp[$i]);
            }
            $generator = $next;
        }

        $residual = array_merge($data, array_fill(0, $degree, 0));
        $length = count($data);
        for ($i = 0; $i < $length; ++$i) {
            $coefficient = $residual[$i];
            if (0 !== $coefficient) {
                foreach ($generator as $j => $value) {
                    $residual[$i + $j] ^= self::mul($value, $coefficient);
                }
            }
        }

        return array_values(array_slice($residual, $length));
    }

    /**
     * @param array<int, array<int, bool|null>> $m
     */
    private function finders(array &$m, int $n): void
    {
        foreach ([[0, 0], [0, $n - 7], [$n - 7, 0]] as [$row, $col]) {
            for ($r = -1; $r <= 7; ++$r) {
                if ($row + $r < 0 || $row + $r >= $n) {
                    continue;
                }
                for ($c = -1; $c <= 7; ++$c) {
                    if ($col + $c < 0 || $col + $c >= $n) {
                        continue;
                    }
                    $on = ($r >= 0 && $r <= 6 && (0 === $c || 6 === $c))
                        || ($c >= 0 && $c <= 6 && (0 === $r || 6 === $r))
                        || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4);
                    $m[$row + $r][$col + $c] = $on;
                }
            }
        }
    }

    /**
     * @param array<int, array<int, bool|null>> $m
     */
    private function timing(array &$m, int $n): void
    {
        for ($i = 8; $i < $n - 8; ++$i) {
            if (null === $m[$i][6]) {
                $m[$i][6] = 0 === $i % 2;
            }
            if (null === $m[6][$i]) {
                $m[6][$i] = 0 === $i % 2;
            }
        }
    }

    /**
     * @param array<int, array<int, bool|null>> $m
     */
    private function alignment(array &$m, int $version): void
    {
        $positions = self::ALIGN[$version];
        foreach ($positions as $row) {
            foreach ($positions as $col) {
                if (null !== $m[$row][$col]) {
                    continue;
                }
                for ($r = -2; $r <= 2; ++$r) {
                    for ($c = -2; $c <= 2; ++$c) {
                        $m[$row + $r][$col + $c] = -2 === $r || 2 === $r || -2 === $c || 2 === $c || (0 === $r && 0 === $c);
                    }
                }
            }
        }
    }

    /**
     * @param array<int, array<int, bool|null>> $m
     */
    private function formatInfo(array &$m, int $n, int $mask): void
    {
        $data = ($this->ecc->formatBits() << 3) | $mask;
        $d = $data << 10;
        while (self::bitLength($d) - self::bitLength(0x537) >= 0) {
            $d ^= 0x537 << (self::bitLength($d) - self::bitLength(0x537));
        }
        $bits = (($data << 10) | $d) ^ 0x5412;

        for ($i = 0; $i < 15; ++$i) {
            $bit = (($bits >> $i) & 1) === 1;
            if ($i < 6) {
                $m[$i][8] = $bit;
            } elseif ($i < 8) {
                $m[$i + 1][8] = $bit;
            } else {
                $m[$n - 15 + $i][8] = $bit;
            }
        }
        for ($i = 0; $i < 15; ++$i) {
            $bit = (($bits >> $i) & 1) === 1;
            if ($i < 8) {
                $m[8][$n - $i - 1] = $bit;
            } elseif ($i < 9) {
                $m[8][15 - $i] = $bit;
            } else {
                $m[8][15 - $i - 1] = $bit;
            }
        }
        $m[$n - 8][8] = true;
    }

    /**
     * @param array<int, array<int, bool|null>> $m
     */
    private function versionInfo(array &$m, int $n, int $version): void
    {
        $d = $version << 12;
        while (self::bitLength($d) - self::bitLength(0x1F25) >= 0) {
            $d ^= 0x1F25 << (self::bitLength($d) - self::bitLength(0x1F25));
        }
        $bits = ($version << 12) | $d;

        for ($i = 0; $i < 18; ++$i) {
            $bit = (($bits >> $i) & 1) === 1;
            $m[intdiv($i, 3)][$i % 3 + $n - 11] = $bit;
            $m[$i % 3 + $n - 11][intdiv($i, 3)] = $bit;
        }
    }

    /**
     * @param array<int, array<int, bool|null>> $m
     * @param list<int>                         $data
     */
    private function mapData(array &$m, array $data, int $mask, int $n): void
    {
        $inc = -1;
        $row = $n - 1;
        $bitIndex = 7;
        $byteIndex = 0;
        $length = count($data);

        for ($colBase = $n - 1; $colBase > 0; $colBase -= 2) {
            $col = $colBase <= 6 ? $colBase - 1 : $colBase;
            while (true) {
                foreach ([$col, $col - 1] as $c) {
                    if (null !== $m[$row][$c]) {
                        continue;
                    }
                    $dark = $byteIndex < $length && (($data[$byteIndex] >> $bitIndex) & 1) === 1;
                    if ($this->maskBit($mask, $row, $c)) {
                        $dark = !$dark;
                    }
                    $m[$row][$c] = $dark;
                    if (-1 === --$bitIndex) {
                        ++$byteIndex;
                        $bitIndex = 7;
                    }
                }
                $row += $inc;
                if ($row < 0 || $row >= $n) {
                    $row -= $inc;
                    $inc = -$inc;
                    break;
                }
            }
        }
    }

    private function maskBit(int $mask, int $i, int $j): bool
    {
        return match ($mask) {
            0 => ($i + $j) % 2 === 0,
            1 => 0 === $i % 2,
            2 => 0 === $j % 3,
            3 => ($i + $j) % 3 === 0,
            4 => (intdiv($i, 2) + intdiv($j, 3)) % 2 === 0,
            5 => ($i * $j) % 2 + ($i * $j) % 3 === 0,
            6 => (($i * $j) % 2 + ($i * $j) % 3) % 2 === 0,
            7 => (($i * $j) % 3 + ($i + $j) % 2) % 2 === 0,
            default => false,
        };
    }

    /**
     * @param array<int, array<int, bool|null>> $m
     */
    private function penalty(array &$m, int $n): int
    {
        $score = 0;

        for ($r = 0; $r < $n; ++$r) {
            $rowRun = 1;
            $colRun = 1;
            for ($c = 1; $c < $n; ++$c) {
                if ($m[$r][$c] === $m[$r][$c - 1]) {
                    ++$rowRun;
                } else {
                    if ($rowRun >= 5) {
                        $score += 3 + $rowRun - 5;
                    }
                    $rowRun = 1;
                }
                if ($m[$c][$r] === $m[$c - 1][$r]) {
                    ++$colRun;
                } else {
                    if ($colRun >= 5) {
                        $score += 3 + $colRun - 5;
                    }
                    $colRun = 1;
                }
            }
            if ($rowRun >= 5) {
                $score += 3 + $rowRun - 5;
            }
            if ($colRun >= 5) {
                $score += 3 + $colRun - 5;
            }
        }

        for ($r = 0; $r < $n - 1; ++$r) {
            for ($c = 0; $c < $n - 1; ++$c) {
                $v = $m[$r][$c];
                if ($v === $m[$r][$c + 1] && $v === $m[$r + 1][$c] && $v === $m[$r + 1][$c + 1]) {
                    $score += 3;
                }
            }
        }

        $pattern = [true, false, true, true, true, false, true, false, false, false, false];
        $reverse = array_reverse($pattern);
        for ($r = 0; $r < $n; ++$r) {
            for ($c = 0; $c <= $n - 11; ++$c) {
                if ($this->matches($m, $r, $c, $pattern, true) || $this->matches($m, $r, $c, $reverse, true)) {
                    $score += 40;
                }
                if ($this->matches($m, $r, $c, $pattern, false) || $this->matches($m, $r, $c, $reverse, false)) {
                    $score += 40;
                }
            }
        }

        $dark = 0;
        for ($r = 0; $r < $n; ++$r) {
            for ($c = 0; $c < $n; ++$c) {
                if ($m[$r][$c]) {
                    ++$dark;
                }
            }
        }
        $ratio = (int) (abs($dark * 100 / ($n * $n) - 50) / 5);
        $score += $ratio * 10;

        return $score;
    }

    /**
     * @param array<int, array<int, bool|null>> $m
     * @param list<bool>                        $pattern
     */
    private function matches(array &$m, int $r, int $c, array $pattern, bool $horizontal): bool
    {
        for ($k = 0; $k < 11; ++$k) {
            $cell = $horizontal ? $m[$r][$c + $k] : $m[$c + $k][$r];
            if (null === $cell || $cell !== $pattern[$k]) {
                return false;
            }
        }

        return true;
    }

    private static function bitLength(int $value): int
    {
        $length = 0;
        while (0 !== $value) {
            ++$length;
            $value >>= 1;
        }

        return $length;
    }

    // [ecPerBlock, [[blocks, dataCodewords], ...]] indexed [version][0=L,1=M,2=Q,3=H]
    private const array BLOCKS = [
        1 => [[7, [[1, 19]]], [10, [[1, 16]]], [13, [[1, 13]]], [17, [[1, 9]]]],
        2 => [[10, [[1, 34]]], [16, [[1, 28]]], [22, [[1, 22]]], [28, [[1, 16]]]],
        3 => [[15, [[1, 55]]], [26, [[1, 44]]], [18, [[2, 17]]], [22, [[2, 13]]]],
        4 => [[20, [[1, 80]]], [18, [[2, 32]]], [26, [[2, 24]]], [16, [[4, 9]]]],
        5 => [[26, [[1, 108]]], [24, [[2, 43]]], [18, [[2, 15], [2, 16]]], [22, [[2, 11], [2, 12]]]],
        6 => [[18, [[2, 68]]], [16, [[4, 27]]], [24, [[4, 19]]], [28, [[4, 15]]]],
        7 => [[20, [[2, 78]]], [18, [[4, 31]]], [18, [[2, 14], [4, 15]]], [26, [[4, 13], [1, 14]]]],
        8 => [[24, [[2, 97]]], [22, [[2, 38], [2, 39]]], [22, [[4, 18], [2, 19]]], [26, [[4, 14], [2, 15]]]],
        9 => [[30, [[2, 116]]], [22, [[3, 36], [2, 37]]], [20, [[4, 16], [4, 17]]], [24, [[4, 12], [4, 13]]]],
        10 => [[18, [[2, 68], [2, 69]]], [26, [[4, 43], [1, 44]]], [24, [[6, 19], [2, 20]]], [28, [[6, 15], [2, 16]]]],
        11 => [[20, [[4, 81]]], [30, [[1, 50], [4, 51]]], [28, [[4, 22], [4, 23]]], [24, [[3, 12], [8, 13]]]],
        12 => [[24, [[2, 92], [2, 93]]], [22, [[6, 36], [2, 37]]], [26, [[4, 20], [6, 21]]], [28, [[7, 14], [4, 15]]]],
        13 => [[26, [[4, 107]]], [22, [[8, 37], [1, 38]]], [24, [[8, 20], [4, 21]]], [22, [[12, 11], [4, 12]]]],
        14 => [[30, [[3, 115], [1, 116]]], [24, [[4, 40], [5, 41]]], [20, [[11, 16], [5, 17]]], [24, [[11, 12], [5, 13]]]],
        15 => [[22, [[5, 87], [1, 88]]], [24, [[5, 41], [5, 42]]], [30, [[5, 24], [7, 25]]], [24, [[11, 12], [7, 13]]]],
        16 => [[24, [[5, 98], [1, 99]]], [28, [[7, 45], [3, 46]]], [24, [[15, 19], [2, 20]]], [30, [[3, 15], [13, 16]]]],
        17 => [[28, [[1, 107], [5, 108]]], [28, [[10, 46], [1, 47]]], [28, [[1, 22], [15, 23]]], [28, [[2, 14], [17, 15]]]],
        18 => [[30, [[5, 120], [1, 121]]], [26, [[9, 43], [4, 44]]], [28, [[17, 22], [1, 23]]], [28, [[2, 14], [19, 15]]]],
        19 => [[28, [[3, 113], [4, 114]]], [26, [[3, 44], [11, 45]]], [26, [[17, 21], [4, 22]]], [26, [[9, 13], [16, 14]]]],
        20 => [[28, [[3, 107], [5, 108]]], [26, [[3, 41], [13, 42]]], [30, [[15, 24], [5, 25]]], [28, [[15, 15], [10, 16]]]],
        21 => [[28, [[4, 116], [4, 117]]], [26, [[17, 42]]], [28, [[17, 22], [6, 23]]], [30, [[19, 16], [6, 17]]]],
        22 => [[28, [[2, 111], [7, 112]]], [28, [[17, 46]]], [30, [[7, 24], [16, 25]]], [24, [[34, 13]]]],
        23 => [[30, [[4, 121], [5, 122]]], [28, [[4, 47], [14, 48]]], [30, [[11, 24], [14, 25]]], [30, [[16, 15], [14, 16]]]],
        24 => [[30, [[6, 117], [4, 118]]], [28, [[6, 45], [14, 46]]], [30, [[11, 24], [16, 25]]], [30, [[30, 16], [2, 17]]]],
        25 => [[26, [[8, 106], [4, 107]]], [28, [[8, 47], [13, 48]]], [30, [[7, 24], [22, 25]]], [30, [[22, 15], [13, 16]]]],
        26 => [[28, [[10, 114], [2, 115]]], [28, [[19, 46], [4, 47]]], [28, [[28, 22], [6, 23]]], [30, [[33, 16], [4, 17]]]],
        27 => [[30, [[8, 122], [4, 123]]], [28, [[22, 45], [3, 46]]], [30, [[8, 23], [26, 24]]], [30, [[12, 15], [28, 16]]]],
        28 => [[30, [[3, 117], [10, 118]]], [28, [[3, 45], [23, 46]]], [30, [[4, 24], [31, 25]]], [30, [[11, 15], [31, 16]]]],
        29 => [[30, [[7, 116], [7, 117]]], [28, [[21, 45], [7, 46]]], [30, [[1, 23], [37, 24]]], [30, [[19, 15], [26, 16]]]],
        30 => [[30, [[5, 115], [10, 116]]], [28, [[19, 47], [10, 48]]], [30, [[15, 24], [25, 25]]], [30, [[23, 15], [25, 16]]]],
        31 => [[30, [[13, 115], [3, 116]]], [28, [[2, 46], [29, 47]]], [30, [[42, 24], [1, 25]]], [30, [[23, 15], [28, 16]]]],
        32 => [[30, [[17, 115]]], [28, [[10, 46], [23, 47]]], [30, [[10, 24], [35, 25]]], [30, [[19, 15], [35, 16]]]],
        33 => [[30, [[17, 115], [1, 116]]], [28, [[14, 46], [21, 47]]], [30, [[29, 24], [19, 25]]], [30, [[11, 15], [46, 16]]]],
        34 => [[30, [[13, 115], [6, 116]]], [28, [[14, 46], [23, 47]]], [30, [[44, 24], [7, 25]]], [30, [[59, 16], [1, 17]]]],
        35 => [[30, [[12, 121], [7, 122]]], [28, [[12, 47], [26, 48]]], [30, [[39, 24], [14, 25]]], [30, [[22, 15], [41, 16]]]],
        36 => [[30, [[6, 121], [14, 122]]], [28, [[6, 47], [34, 48]]], [30, [[46, 24], [10, 25]]], [30, [[2, 15], [64, 16]]]],
        37 => [[30, [[17, 122], [4, 123]]], [28, [[29, 46], [14, 47]]], [30, [[49, 24], [10, 25]]], [30, [[24, 15], [46, 16]]]],
        38 => [[30, [[4, 122], [18, 123]]], [28, [[13, 46], [32, 47]]], [30, [[48, 24], [14, 25]]], [30, [[42, 15], [32, 16]]]],
        39 => [[30, [[20, 117], [4, 118]]], [28, [[40, 47], [7, 48]]], [30, [[43, 24], [22, 25]]], [30, [[10, 15], [67, 16]]]],
        40 => [[30, [[19, 118], [6, 119]]], [28, [[18, 47], [31, 48]]], [30, [[34, 24], [34, 25]]], [30, [[20, 15], [61, 16]]]],
    ];

    private const array ALIGN = [
        1 => [],
        2 => [6, 18],
        3 => [6, 22],
        4 => [6, 26],
        5 => [6, 30],
        6 => [6, 34],
        7 => [6, 22, 38],
        8 => [6, 24, 42],
        9 => [6, 26, 46],
        10 => [6, 28, 50],
        11 => [6, 30, 54],
        12 => [6, 32, 58],
        13 => [6, 34, 62],
        14 => [6, 26, 46, 66],
        15 => [6, 26, 48, 70],
        16 => [6, 26, 50, 74],
        17 => [6, 30, 54, 78],
        18 => [6, 30, 56, 82],
        19 => [6, 30, 58, 86],
        20 => [6, 34, 62, 90],
        21 => [6, 28, 50, 72, 94],
        22 => [6, 26, 50, 74, 98],
        23 => [6, 30, 54, 78, 102],
        24 => [6, 28, 54, 80, 106],
        25 => [6, 32, 58, 84, 110],
        26 => [6, 30, 58, 86, 114],
        27 => [6, 34, 62, 90, 118],
        28 => [6, 26, 50, 74, 98, 122],
        29 => [6, 30, 54, 78, 102, 126],
        30 => [6, 26, 52, 78, 104, 130],
        31 => [6, 30, 56, 82, 108, 134],
        32 => [6, 34, 60, 86, 112, 138],
        33 => [6, 30, 58, 86, 114, 142],
        34 => [6, 34, 62, 90, 118, 146],
        35 => [6, 30, 54, 78, 102, 126, 150],
        36 => [6, 24, 50, 76, 102, 128, 154],
        37 => [6, 28, 54, 80, 106, 132, 158],
        38 => [6, 32, 58, 84, 110, 136, 162],
        39 => [6, 26, 54, 82, 110, 138, 166],
        40 => [6, 30, 58, 86, 114, 142, 170],
    ];
}
