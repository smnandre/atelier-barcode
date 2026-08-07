<?php

declare(strict_types=1);

namespace Atelier\Barcode\Tests;

use Atelier\Barcode\Exception\CodeExceptionInterface;
use Atelier\Barcode\Exception\InvalidCodeException;
use Atelier\Barcode\Interleaved2Of5;
use Atelier\Barcode\Itf14;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Itf14::class)]
#[CoversClass(InvalidCodeException::class)]
final class Itf14Test extends TestCase
{
    public function testCreateComputesCheckDigitFromThirteenDigits(): void
    {
        self::assertSame('10012345678902', Itf14::create('1001234567890')->value());
    }

    public function testCreateAcceptsValidFourteenDigits(): void
    {
        self::assertSame('10012345678902', Itf14::create('10012345678902')->value());
    }

    public function testCreateRejectsInvalidCheckDigit(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Itf14::create('10012345678903');
    }

    public function testCreateRejectsNonNumericData(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Itf14::create('1001234567890A');
    }

    public function testCreateRejectsWrongLength(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Itf14::create('123456');
    }

    public function testModulesMatchInterleaved2Of5Payload(): void
    {
        self::assertSame(
            Interleaved2Of5::create('10012345678902')->modules(),
            Itf14::create('1001234567890')->modules(),
        );
    }

    public function testModulesAreCachedAndDeterministic(): void
    {
        $code = Itf14::create('1001234567890');

        self::assertSame($code->modules(), $code->modules());
        self::assertSame($code->modules(), Itf14::create('1001234567890')->modules());
    }

    public function testWideRatioChangesModules(): void
    {
        self::assertGreaterThan(
            count(Itf14::create('1001234567890')->wideRatio(2)->modules()),
            count(Itf14::create('1001234567890')->wideRatio(3)->modules()),
        );
    }

    public function testWideRatioMustBeAtLeastTwo(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Itf14::create('1001234567890')->wideRatio(1);
    }

    public function testWideRatioMustNotExceedTen(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Itf14::create('1001234567890')->wideRatio(11);
    }

    public function testRenderProducesSvgWithBearerBarsAndCaption(): void
    {
        $svg = Itf14::create('1001234567890')->render();

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringContainsString('>10012345678902<', $svg);
        self::assertStringContainsString('transform="translate(0 4)"', $svg);
        self::assertStringContainsString('height="88"', $svg);
    }

    public function testBearerBarsCanBeDisabled(): void
    {
        $svg = Itf14::create('1001234567890')->withBearerBars(false)->render();

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringNotContainsString('transform="translate(0 4)"', $svg);
    }

    public function testTextCanBeDisabledWithBearerBars(): void
    {
        $svg = Itf14::create('1001234567890')->withText(false)->render();

        self::assertStringNotContainsString('<text', $svg);
    }

    public function testTextCanBeDisabledWithoutBearerBars(): void
    {
        $svg = Itf14::create('1001234567890')->withText(false)->withBearerBars(false)->render();

        self::assertStringNotContainsString('<text', $svg);
    }

    public function testInvalidDimensionsAreRejected(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Itf14::create('1001234567890')->height(0);
    }

    public function testOversizedRenderIsRejected(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Itf14::create('1001234567890')->scale(130)->render();
    }

    public function testBuilderReturnsSameInstance(): void
    {
        $code = Itf14::create('1001234567890');

        self::assertSame($code, $code->scale(3));
        self::assertSame($code, $code->height(60));
        self::assertSame($code, $code->margin(8));
        self::assertSame($code, $code->wideRatio(2));
        self::assertSame($code, $code->foreground('#000000'));
        self::assertSame($code, $code->background('#ffffff'));
        self::assertSame($code, $code->withText(true));
        self::assertSame($code, $code->withBearerBars(true));
    }

    public function testColorsAreEscapedAgainstAttributeInjection(): void
    {
        $svg = Itf14::create('1001234567890')->foreground('"><script>alert(1)</script>')->render();

        self::assertStringNotContainsString('<script>', $svg);
        self::assertStringContainsString('&lt;script&gt;', $svg);
    }

    public function testValidColorSyntaxSurvivesEscaping(): void
    {
        $svg = Itf14::create('1001234567890')->foreground('oklch(0.7 0.2 200)')->background('none')->render();

        self::assertStringContainsString('fill="oklch(0.7 0.2 200)"', $svg);
        self::assertStringContainsString('fill="none"', $svg);
    }
}
