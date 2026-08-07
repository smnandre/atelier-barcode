<?php

declare(strict_types=1);

namespace Atelier\Barcode\Tests;

use Atelier\Barcode\Exception\CodeExceptionInterface;
use Atelier\Barcode\Exception\InvalidCodeException;
use Atelier\Barcode\Interleaved2Of5;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Interleaved2Of5::class)]
#[CoversClass(InvalidCodeException::class)]
final class Interleaved2Of5Test extends TestCase
{
    public function testCreateRejectsEmptyData(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Interleaved2Of5::create('');
    }

    public function testCreateRejectsNonNumericData(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Interleaved2Of5::create('12A4');
    }

    public function testCreateRejectsOverlongData(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Interleaved2Of5::create(str_repeat('1', 514));
    }

    public function testCreateRejectsOddDigitCount(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Interleaved2Of5::create('123');
    }

    public function testValueReturnsDigits(): void
    {
        self::assertSame('1234', Interleaved2Of5::create('1234')->value());
    }

    public function testModulesMatchKnownPairPattern(): void
    {
        self::assertSame(
            [
                true, false, true, false,
                true, true, true, false, true, false, false, false,
                true, false, true, false, true, true, true, false, false, false,
                true, true, true, false, true,
            ],
            Interleaved2Of5::create('12')->modules(),
        );
    }

    public function testModulesAreCachedAndDeterministic(): void
    {
        $code = Interleaved2Of5::create('123456');

        self::assertSame($code->modules(), $code->modules());
        self::assertSame($code->modules(), Interleaved2Of5::create('123456')->modules());
    }

    public function testWideRatioChangesModules(): void
    {
        self::assertGreaterThan(
            count(Interleaved2Of5::create('12')->wideRatio(2)->modules()),
            count(Interleaved2Of5::create('12')->wideRatio(3)->modules()),
        );
    }

    public function testWideRatioMustBeAtLeastTwo(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Interleaved2Of5::create('12')->wideRatio(1);
    }

    public function testWideRatioMustNotExceedTen(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Interleaved2Of5::create('12')->wideRatio(11);
    }

    public function testRenderProducesSvgWithCaption(): void
    {
        $svg = Interleaved2Of5::create('1234')->render();

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringContainsString('<rect', $svg);
        self::assertStringContainsString('>1234<', $svg);
    }

    public function testTextCanBeDisabled(): void
    {
        $svg = Interleaved2Of5::create('1234')->withText(false)->render();

        self::assertStringNotContainsString('<text', $svg);
    }

    public function testInvalidDimensionsAreRejected(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Interleaved2Of5::create('1234')->scale(0);
    }

    public function testOversizedRenderIsRejected(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Interleaved2Of5::create(str_repeat('1', 512))->scale(8)->render();
    }

    public function testBuilderReturnsSameInstance(): void
    {
        $code = Interleaved2Of5::create('1234');

        self::assertSame($code, $code->scale(3));
        self::assertSame($code, $code->height(60));
        self::assertSame($code, $code->margin(8));
        self::assertSame($code, $code->wideRatio(2));
        self::assertSame($code, $code->foreground('#000000'));
        self::assertSame($code, $code->background('#ffffff'));
        self::assertSame($code, $code->withText(true));
    }

    public function testColorsAreEscapedAgainstAttributeInjection(): void
    {
        $svg = Interleaved2Of5::create('1234')->foreground('"><script>alert(1)</script>')->render();

        self::assertStringNotContainsString('<script>', $svg);
        self::assertStringContainsString('&lt;script&gt;', $svg);
    }

    public function testValidColorSyntaxSurvivesEscaping(): void
    {
        $svg = Interleaved2Of5::create('1234')->foreground('oklch(0.7 0.2 200)')->background('none')->render();

        self::assertStringContainsString('fill="oklch(0.7 0.2 200)"', $svg);
        self::assertStringContainsString('fill="none"', $svg);
    }
}
