<?php

declare(strict_types=1);

namespace Atelier\Barcode\Tests;

use Atelier\Barcode\Exception\CodeExceptionInterface;
use Atelier\Barcode\Exception\InvalidCodeException;
use Atelier\Barcode\Gs1128;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Gs1128::class)]
#[CoversClass(InvalidCodeException::class)]
final class Gs1128Test extends TestCase
{
    public function testCreateAcceptsOrderedPairs(): void
    {
        $code = Gs1128::create([
            ['01', '09501101530003'],
            ['17', '260728'],
            ['10', 'BATCH42'],
            ['21', 'SERIAL9'],
        ]);

        self::assertSame('(01)09501101530003(17)260728(10)BATCH42(21)SERIAL9', $code->elementString());
        self::assertSame("01095011015300031726072810BATCH42\x1D21SERIAL9", $code->payload());
    }

    public function testCreateAcceptsAssociativePairs(): void
    {
        $code = Gs1128::create([
            '01' => '09501101530003',
            '17' => '260728',
            '10' => 'BATCH42',
        ]);

        self::assertSame('(01)09501101530003(17)260728(10)BATCH42', $code->elementString());
        self::assertSame('01095011015300031726072810BATCH42', $code->payload());
    }

    public function testCreateAcceptsCompactString(): void
    {
        $code = Gs1128::create('(01)09501101530003(17)260728(10)BATCH42(21)SERIAL9');

        self::assertSame('(01)09501101530003(17)260728(10)BATCH42(21)SERIAL9', $code->elementString());
        self::assertSame("01095011015300031726072810BATCH42\x1D21SERIAL9", $code->payload());
    }

    public function testCreateRejectsEmptyArray(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Gs1128::create([]);
    }

    public function testCreateRejectsEmptyString(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Gs1128::create('');
    }

    public function testCreateRejectsRawCompactString(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Gs1128::create('0109501101530003');
    }

    public function testCreateRejectsUnterminatedCompactAi(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Gs1128::create('(01');
    }

    public function testCreateRejectsMalformedCompactString(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Gs1128::create('(01)09501101530003x');
    }

    public function testCreateRejectsMalformedPair(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Gs1128::create([['01', '09501101530003', 'extra']]);
    }

    public function testCreateRejectsAssociativeNonStringValue(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        $parsePairs = (new \ReflectionClass(Gs1128::class))->getMethod('parsePairs');
        $parsePairs->invoke(null, ['lot' => ['BATCH42']]);
    }

    public function testCreateRejectsTupleNonStringValue(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        $parsePairs = (new \ReflectionClass(Gs1128::class))->getMethod('parsePairs');
        $parsePairs->invoke(null, [['10', 42]]);
    }

    public function testCreateRejectsUnsupportedAi(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Gs1128::create([['00', '123']]);
    }

    public function testCreateRejectsEmptyAiValue(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Gs1128::create([['10', '']]);
    }

    public function testCreateRejectsInvalidGtinLength(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Gs1128::create([['01', '9501101530003']]);
    }

    public function testCreateRejectsInvalidGtinCheckDigit(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Gs1128::create([['01', '09501101530004']]);
    }

    public function testCreateRejectsInvalidExpiryLength(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Gs1128::create([['17', '26072']]);
    }

    public function testCreateRejectsInvalidExpiryDate(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Gs1128::create([['17', '260231']]);
    }

    public function testCreateRejectsOverlongVariableValue(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Gs1128::create([['10', str_repeat('A', 21)]]);
    }

    public function testCreateRejectsUnsupportedVariableCharacters(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Gs1128::create([['21', "SERIAL\x1D"]]);
    }

    public function testEncodingIsDeterministic(): void
    {
        self::assertSame(
            Gs1128::create('(01)09501101530003(10)BATCH42')->modules(),
            Gs1128::create('(01)09501101530003(10)BATCH42')->modules(),
        );
    }

    public function testNumericDataUsesCompactEncoding(): void
    {
        $numeric = Gs1128::create('(01)09501101530003(17)260728')->modules();
        $mixed = Gs1128::create('(01)09501101530003(10)BATCH42')->modules();

        self::assertLessThan(count($mixed), count($numeric));
    }

    public function testFnc1ChangesTheEncodedSymbol(): void
    {
        $withSeparator = Gs1128::create('(10)BATCH42(21)SERIAL9')->modules();
        $withoutSeparator = Gs1128::create('(21)BATCH42SERIAL9')->modules();

        self::assertNotSame($withoutSeparator, $withSeparator);
    }

    public function testRenderProducesSvgWithHumanReadableText(): void
    {
        $svg = Gs1128::create('(01)09501101530003(10)BATCH42')->render();

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringContainsString('<rect', $svg);
        self::assertStringContainsString('>(01)09501101530003(10)BATCH42<', $svg);
    }

    public function testTextCanBeDisabled(): void
    {
        $svg = Gs1128::create('(01)09501101530003(10)BATCH42')->withText(false)->render();

        self::assertStringNotContainsString('<text', $svg);
    }

    public function testInvalidDimensionsAreRejected(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Gs1128::create('(01)09501101530003')->scale(0);
    }

    public function testOversizedRenderIsRejected(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Gs1128::create('(10)'.str_repeat('A', 20))->scale(1000)->render();
    }

    public function testBuilderReturnsSameInstance(): void
    {
        $code = Gs1128::create('(01)09501101530003');

        self::assertSame($code, $code->scale(3));
        self::assertSame($code, $code->height(60));
        self::assertSame($code, $code->margin(8));
        self::assertSame($code, $code->foreground('#000000'));
        self::assertSame($code, $code->background('#ffffff'));
        self::assertSame($code, $code->withText(true));
    }

    public function testColorsAreEscapedAgainstAttributeInjection(): void
    {
        $svg = Gs1128::create('(01)09501101530003')->foreground('"><script>alert(1)</script>')->render();

        self::assertStringNotContainsString('<script>', $svg);
        self::assertStringContainsString('&lt;script&gt;', $svg);
    }
}
