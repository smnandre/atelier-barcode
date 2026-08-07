<?php

declare(strict_types=1);

namespace Atelier\Barcode\Tests;

use Atelier\Barcode\DataMatrix;
use Atelier\Barcode\Exception\CodeExceptionInterface;
use Atelier\Barcode\Exception\InvalidCodeException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DataMatrix::class)]
#[CoversClass(InvalidCodeException::class)]
final class DataMatrixTest extends TestCase
{
    public function testCreateRejectsEmptyData(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        DataMatrix::create('');
    }

    public function testMatrixIsSquare(): void
    {
        $matrix = DataMatrix::create('ABC')->matrix();
        $size = count($matrix);

        self::assertSame(10, $size);
        foreach ($matrix as $row) {
            self::assertCount($size, $row);
        }
    }

    public function testFinderPatternUsesSolidLeftAndBottomEdges(): void
    {
        $matrix = DataMatrix::create('ABC')->matrix();
        $last = count($matrix) - 1;

        for ($i = 0; $i <= $last; ++$i) {
            self::assertTrue($matrix[$i][0]);
            self::assertTrue($matrix[$last][$i]);
        }
    }

    public function testFinderPatternUsesAlternatingTopAndRightEdges(): void
    {
        $matrix = DataMatrix::create('ABC')->matrix();
        $last = count($matrix) - 1;

        self::assertSame([true, false, true, false], array_slice($matrix[0], 0, 4));
        self::assertSame([false, true, false, true], [$matrix[0][$last], $matrix[1][$last], $matrix[2][$last], $matrix[3][$last]]);
    }

    public function testNumericPairsAreCompacted(): void
    {
        $digits = DataMatrix::create('1234567890')->matrix();
        $letters = DataMatrix::create('ABCDEFGHIJ')->matrix();

        self::assertLessThan(count($letters), count($digits));
    }

    public function testExtendedAsciiBytesAreEncoded(): void
    {
        $matrix = DataMatrix::create("A\xC8")->matrix();

        self::assertNotEmpty($matrix);
    }

    public function testAllSingleRegionSymbolSizesRender(): void
    {
        foreach ([1, 3, 5, 8, 12, 18, 22, 30, 36, 44] as $length) {
            $matrix = DataMatrix::create(str_repeat('A', $length))->matrix();

            self::assertGreaterThanOrEqual(10, count($matrix));
            self::assertLessThanOrEqual(26, count($matrix));
        }
    }

    public function testEncodingIsDeterministic(): void
    {
        self::assertSame(
            DataMatrix::create('ATELIER-2026')->matrix(),
            DataMatrix::create('ATELIER-2026')->matrix(),
        );
    }

    public function testLongPayloadBeyondSingleRegionThrows(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        DataMatrix::create(str_repeat('A', 45))->matrix();
    }

    public function testRenderProducesSvgWithQuietZone(): void
    {
        $svg = DataMatrix::create('HELLO')->size(210)->margin(3)->render();

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringContainsString('width="210"', $svg);
        self::assertStringContainsString('<rect', $svg);
    }

    public function testInvalidSizeIsRejected(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        DataMatrix::create('HELLO')->size(0);
    }

    public function testInvalidMarginIsRejected(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        DataMatrix::create('HELLO')->margin(-1);
    }

    public function testBuilderReturnsSameInstance(): void
    {
        $matrix = DataMatrix::create('HELLO');

        self::assertSame($matrix, $matrix->size(256));
        self::assertSame($matrix, $matrix->margin(2));
        self::assertSame($matrix, $matrix->foreground('#000000'));
        self::assertSame($matrix, $matrix->background('#ffffff'));
    }

    public function testColorsAreEscapedAgainstAttributeInjection(): void
    {
        $svg = DataMatrix::create('HELLO')->foreground('"><script>alert(1)</script>')->render();

        self::assertStringNotContainsString('<script>', $svg);
        self::assertStringContainsString('&lt;script&gt;', $svg);
    }

    /**
     * Golden-master matrix, captured from this implementation and independently verified
     * pixel-for-pixel against a reference Data Matrix encoder (ZXing-C++'s ZXingWriter) rendered
     * to PNG. Guards against regressions in module placement / finder pattern orientation, which
     * can silently corrupt the symbol without any PHP error or exception.
     */
    public function testSingleCharacterMatrixMatchesVerifiedReferenceLayout(): void
    {
        self::assertSame([
            [true, false, true, false, true, false, true, false, true, false],
            [true, true, false, true, true, false, false, false, true, true],
            [true, false, false, false, true, true, false, true, false, false],
            [true, false, false, true, true, false, true, false, true, true],
            [true, false, false, true, false, true, false, false, false, false],
            [true, false, false, true, false, false, true, false, true, true],
            [true, true, false, true, false, false, true, true, false, false],
            [true, true, false, false, true, true, true, true, false, true],
            [true, true, false, false, false, false, true, false, false, false],
            [true, true, true, true, true, true, true, true, true, true],
        ], DataMatrix::create('A')->matrix());
    }

    /**
     * Same golden-master approach as above, for a size that exercises the "corner2" special
     * placement case (10-module data region, not a multiple of 4).
     */
    public function testFourCharacterMatrixMatchesVerifiedReferenceLayout(): void
    {
        self::assertSame([
            [true, false, true, false, true, false, true, false, true, false, true, false],
            [true, false, true, true, false, false, false, false, false, false, true, true],
            [true, false, false, false, true, false, true, false, true, false, false, false],
            [true, false, true, true, false, false, false, false, false, false, true, true],
            [true, false, false, true, false, false, true, true, true, true, false, false],
            [true, false, false, true, true, false, true, false, true, false, false, true],
            [true, false, true, true, false, true, false, false, false, true, false, false],
            [true, false, false, true, true, false, true, true, true, false, true, true],
            [true, false, false, false, false, false, true, true, false, false, false, false],
            [true, false, true, true, false, true, true, true, true, true, false, true],
            [true, false, false, false, false, true, true, true, false, false, true, false],
            [true, true, true, true, true, true, true, true, true, true, true, true],
        ], DataMatrix::create('ABCD')->matrix());
    }

    /**
     * Independent, from-scratch verification of the Reed-Solomon error-correction codewords:
     * rebuilds GF(256) tables and evaluates the received polynomial at each of its expected
     * roots (a codeword is valid Reed-Solomon iff every syndrome is zero). This does not reuse
     * any of DataMatrix's own Galois-field arithmetic, so it cannot pass merely because the
     * production code and the test agree with each other.
     */
    public function testDataAndErrorCodewordsFormAValidReedSolomonCodeword(): void
    {
        $exp = [];
        $log = [];
        $x = 1;
        for ($i = 0; $i < 255; ++$i) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x12D;
            }
        }
        $gfMul = static function (int $a, int $b) use ($exp, $log): int {
            if (0 === $a || 0 === $b) {
                return 0;
            }

            return $exp[($log[$a] + $log[$b]) % 255];
        };

        $ref = new \ReflectionClass(DataMatrix::class);
        $encodeAscii = $ref->getMethod('encodeAscii');
        $pad = $ref->getMethod('pad');
        $errorCodewords = $ref->getMethod('errorCodewords');
        $selectSymbol = $ref->getMethod('selectSymbol');
        $ref->getProperty('exp')->setValue(null, []);
        $ref->getProperty('log')->setValue(null, []);

        foreach (['A', 'ABCD', 'ABCDEFGHI', str_repeat('X', 40)] as $payload) {
            $instance = DataMatrix::create($payload);
            $data = $encodeAscii->invoke($instance);
            $symbol = $selectSymbol->invoke($instance, count($data));
            $dataCodewords = $pad->invoke($instance, $data, $symbol['data']);
            $eccCodewords = $errorCodewords->invoke($instance, $dataCodewords, $symbol['ecc']);
            $codeword = [...$dataCodewords, ...$eccCodewords];

            for ($root = 1; $root <= $symbol['ecc']; ++$root) {
                $alpha = $exp[$root % 255];
                $syndrome = 0;
                foreach ($codeword as $c) {
                    $syndrome = $gfMul($syndrome, $alpha) ^ $c;
                }
                self::assertSame(0, $syndrome, "Syndrome at root {$root} must be zero for payload '{$payload}'.");
            }
        }
    }

    /**
     * gfMultiply()'s zero case (GF(256) multiplication by zero is always zero) is part of
     * the Galois-field contract, but is never actually reached through the public API for
     * any symbol size this library supports: an exhaustive check of all 9 supported ECC
     * codeword counts confirms the generator polynomial never has a zero coefficient, and a
     * 200,000-payload randomized search never produced a zero LFSR factor either. Tested
     * directly here rather than by contriving a payload to hit an effectively unreachable path.
     */
    public function testGaloisFieldMultiplicationByZeroIsZero(): void
    {
        $ref = new \ReflectionClass(DataMatrix::class);
        $gfMultiply = $ref->getMethod('gfMultiply');

        self::assertSame(0, $gfMultiply->invoke(null, 0, 173));
        self::assertSame(0, $gfMultiply->invoke(null, 173, 0));
        self::assertSame(0, $gfMultiply->invoke(null, 0, 0));
    }
}
