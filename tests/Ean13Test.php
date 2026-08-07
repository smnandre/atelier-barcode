<?php

declare(strict_types=1);

namespace Atelier\Barcode\Tests;

use Atelier\Barcode\Ean13;
use Atelier\Barcode\Exception\CodeExceptionInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Ean13::class)]
final class Ean13Test extends TestCase
{
    public function testCreateComputesCheckDigitFromTwelve(): void
    {
        self::assertSame('5901234123457', Ean13::create('590123412345')->value());
    }

    public function testCreateAcceptsValidThirteenDigits(): void
    {
        self::assertSame('5901234123457', Ean13::create('5901234123457')->value());
    }

    public function testCreateRejectsInvalidCheckDigit(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Ean13::create('5901234123458');
    }

    public function testCreateRejectsNonNumeric(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Ean13::create('59012341234A');
    }

    public function testCreateRejectsWrongLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Ean13::create('0012345678');
    }

    public function testSymbolHasNinetyFiveModules(): void
    {
        self::assertCount(95, Ean13::create('590123412345')->modules());
    }

    public function testGuardPatternsArePlaced(): void
    {
        $m = Ean13::create('590123412345')->modules();

        self::assertSame([true, false, true], [$m[0], $m[1], $m[2]]);
        self::assertSame([false, true, false, true, false], [$m[45], $m[46], $m[47], $m[48], $m[49]]);
        self::assertSame([true, false, true], [$m[92], $m[93], $m[94]]);
    }

    public function testEncodingIsDeterministic(): void
    {
        self::assertSame(
            Ean13::create('4006381333931')->modules(),
            Ean13::create('4006381333931')->modules(),
        );
    }

    public function testRenderProducesSvgWithDigits(): void
    {
        $svg = Ean13::create('590123412345')->render();

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringContainsString('<rect', $svg);
        self::assertStringContainsString('<text', $svg);
    }

    public function testInvalidDimensionsAreRejected(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Ean13::create('590123412345')->height(0);
    }

    public function testBuilderReturnsSameInstance(): void
    {
        $code = Ean13::create('590123412345');

        self::assertSame($code, $code->scale(3));
        self::assertSame($code, $code->height(70));
        self::assertSame($code, $code->margin(9));
        self::assertSame($code, $code->foreground('#000000'));
        self::assertSame($code, $code->background('#ffffff'));
    }

    public function testColorsAreEscapedAgainstAttributeInjection(): void
    {
        $svg = Ean13::create('590123412345')->foreground('"><script>alert(1)</script>')->render();

        self::assertStringNotContainsString('<script>', $svg);
        self::assertStringContainsString('&lt;script&gt;', $svg);
    }

    public function testValidColorSyntaxSurvivesEscaping(): void
    {
        $svg = Ean13::create('590123412345')->foreground('oklch(0.7 0.2 200)')->background('none')->render();

        self::assertStringContainsString('fill="oklch(0.7 0.2 200)"', $svg);
        self::assertStringContainsString('fill="none"', $svg);
    }
}
