<?php

declare(strict_types=1);

namespace Atelier\Barcode\Tests;

use Atelier\Barcode\DataMatrix;
use Atelier\Barcode\Exception\CodeExceptionInterface;
use Atelier\Barcode\Exception\InvalidCodeException;
use Atelier\Barcode\Gs1128;
use Atelier\Barcode\Gs1DataMatrix;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Gs1DataMatrix::class)]
#[CoversClass(Gs1128::class)]
#[CoversClass(InvalidCodeException::class)]
final class Gs1DataMatrixTest extends TestCase
{
    public function testCreateAcceptsGs1Data(): void
    {
        $matrix = Gs1DataMatrix::create('(01)09501101530003(17)260728(10)BATCH42(21)SERIAL9');

        self::assertSame('(01)09501101530003(17)260728(10)BATCH42(21)SERIAL9', $matrix->elementString());
        self::assertSame("01095011015300031726072810BATCH42\x1D21SERIAL9", $matrix->payload());
    }

    public function testCreateRejectsInvalidGs1Data(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Gs1DataMatrix::create('(01)09501101530004');
    }

    public function testMatrixIsSquare(): void
    {
        $matrix = Gs1DataMatrix::create('(01)09501101530003')->matrix();
        $size = count($matrix);

        self::assertGreaterThanOrEqual(14, $size);
        foreach ($matrix as $row) {
            self::assertCount($size, $row);
        }
    }

    public function testFinderPatternUsesSolidLeftAndBottomEdges(): void
    {
        $matrix = Gs1DataMatrix::create('(01)09501101530003')->matrix();
        $last = count($matrix) - 1;

        for ($i = 0; $i <= $last; ++$i) {
            self::assertTrue($matrix[$i][0]);
            self::assertTrue($matrix[$last][$i]);
        }
    }

    public function testFinderPatternUsesAlternatingTopAndRightEdges(): void
    {
        $matrix = Gs1DataMatrix::create('(01)09501101530003')->matrix();
        $last = count($matrix) - 1;

        self::assertSame([true, false, true, false], array_slice($matrix[0], 0, 4));
        self::assertSame([false, true, false, true], [$matrix[0][$last], $matrix[1][$last], $matrix[2][$last], $matrix[3][$last]]);
    }

    public function testInitialFnc1ChangesMatrixFromPlainDataMatrixPayload(): void
    {
        $gs1 = Gs1DataMatrix::create('(01)09501101530003')->matrix();
        $plain = DataMatrix::create('0109501101530003')->matrix();

        self::assertNotSame($plain, $gs1);
    }

    public function testGroupSeparatorChangesMatrix(): void
    {
        $withSeparator = Gs1DataMatrix::create('(10)BATCH42(21)SERIAL9')->matrix();
        $withoutSeparator = Gs1DataMatrix::create('(21)BATCH42SERIAL9')->matrix();

        self::assertNotSame($withoutSeparator, $withSeparator);
    }

    public function testEncodingIsDeterministic(): void
    {
        self::assertSame(
            Gs1DataMatrix::create('(01)09501101530003(10)BATCH42')->matrix(),
            Gs1DataMatrix::create('(01)09501101530003(10)BATCH42')->matrix(),
        );
    }

    public function testAllSingleRegionSymbolSizesRender(): void
    {
        foreach ([
            '(10)A',
            '(10)ABC',
            '(10)ABCDE',
            '(10)ABCDEFGH',
            '(10)ABCDEFGHIJKL',
            '(10)ABCDEFGHIJKLMNOPQR',
            '(01)09501101530003(10)A',
            '(01)09501101530003(10)ABCDEF',
            '(01)09501101530003(10)ABCDEFGHIJKL',
        ] as $payload) {
            $matrix = Gs1DataMatrix::create($payload)->matrix();

            self::assertGreaterThanOrEqual(10, count($matrix));
            self::assertLessThanOrEqual(26, count($matrix));
        }
    }

    public function testLongPayloadBeyondSingleRegionThrows(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Gs1DataMatrix::create('(01)09501101530003(17)260728(10)ABCDEFGHIJKLMNOPQRST(21)ABCDEFGHIJKLMNOPQRST')->matrix();
    }

    public function testRenderProducesSvgWithQuietZone(): void
    {
        $svg = Gs1DataMatrix::create('(01)09501101530003')->size(210)->margin(3)->render();

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringContainsString('width="210"', $svg);
        self::assertStringContainsString('<rect', $svg);
    }

    public function testInvalidSizeIsRejected(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Gs1DataMatrix::create('(01)09501101530003')->size(0);
    }

    public function testInvalidMarginIsRejected(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Gs1DataMatrix::create('(01)09501101530003')->margin(-1);
    }

    public function testBuilderReturnsSameInstance(): void
    {
        $matrix = Gs1DataMatrix::create('(01)09501101530003');

        self::assertSame($matrix, $matrix->size(256));
        self::assertSame($matrix, $matrix->margin(2));
        self::assertSame($matrix, $matrix->foreground('#000000'));
        self::assertSame($matrix, $matrix->background('#ffffff'));
    }

    public function testColorsAreEscapedAgainstAttributeInjection(): void
    {
        $svg = Gs1DataMatrix::create('(01)09501101530003')->foreground('"><script>alert(1)</script>')->render();

        self::assertStringNotContainsString('<script>', $svg);
        self::assertStringContainsString('&lt;script&gt;', $svg);
    }

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

        $ref = new \ReflectionClass(Gs1DataMatrix::class);
        $encodeAscii = $ref->getMethod('encodeAscii');
        $pad = $ref->getMethod('pad');
        $errorCodewords = $ref->getMethod('errorCodewords');
        $selectSymbol = $ref->getMethod('selectSymbol');

        foreach (['(10)A', '(10)ABCD', '(01)09501101530003', '(10)ABCDEFGHIJKLMNOPQRST'] as $payload) {
            $instance = Gs1DataMatrix::create($payload);
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

    public function testGaloisFieldMultiplicationByZeroIsZero(): void
    {
        $ref = new \ReflectionClass(Gs1DataMatrix::class);
        $gfMultiply = $ref->getMethod('gfMultiply');

        self::assertSame(0, $gfMultiply->invoke(null, 0, 173));
        self::assertSame(0, $gfMultiply->invoke(null, 173, 0));
        self::assertSame(0, $gfMultiply->invoke(null, 0, 0));
    }
}
