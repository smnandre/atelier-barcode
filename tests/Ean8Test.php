<?php

declare(strict_types=1);

namespace Atelier\Barcode\Tests;

use Atelier\Barcode\Ean8;
use Atelier\Barcode\Exception\CodeExceptionInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Ean8::class)]
final class Ean8Test extends TestCase
{
    public function testCreateComputesCheckDigitFromSeven(): void
    {
        self::assertSame('55123457', Ean8::create('5512345')->value());
    }

    public function testCreateAcceptsValidEightDigits(): void
    {
        self::assertSame('55123457', Ean8::create('55123457')->value());
    }

    public function testCreateRejectsInvalidCheckDigit(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Ean8::create('55123458');
    }

    public function testCreateRejectsNonNumericInput(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Ean8::create('551234A');
    }

    public function testCreateRejectsWrongLength(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Ean8::create('551234');
    }

    public function testCheckDigitRejectsWrongLength(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Ean8::checkDigit('551234');
    }

    public function testSymbolHasSixtySevenModules(): void
    {
        self::assertCount(67, Ean8::create('5512345')->modules());
    }

    public function testGuardPatternsArePlaced(): void
    {
        $m = Ean8::create('5512345')->modules();

        self::assertSame([true, false, true], [$m[0], $m[1], $m[2]]);
        self::assertSame([false, true, false, true, false], [$m[31], $m[32], $m[33], $m[34], $m[35]]);
        self::assertSame([true, false, true], [$m[64], $m[65], $m[66]]);
    }

    public function testEncodingIsDeterministicAndCached(): void
    {
        $code = Ean8::create('5512345');

        self::assertSame($code->modules(), $code->modules());
    }

    public function testRenderProducesSvgWithCaption(): void
    {
        $svg = Ean8::create('5512345')->render();

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringContainsString('<rect', $svg);
        self::assertStringContainsString('>55123457<', $svg);
    }

    public function testTextCanBeDisabled(): void
    {
        $svg = Ean8::create('5512345')->withText(false)->render();

        self::assertStringNotContainsString('<text', $svg);
    }

    public function testInvalidDimensionsAreRejected(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Ean8::create('5512345')->height(0);
    }

    public function testOversizedRenderIsRejected(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Ean8::create('5512345')->scale(400)->render();
    }

    public function testBuilderReturnsSameInstance(): void
    {
        $code = Ean8::create('5512345');

        self::assertSame($code, $code->scale(3));
        self::assertSame($code, $code->height(64));
        self::assertSame($code, $code->margin(8));
        self::assertSame($code, $code->foreground('#111111'));
        self::assertSame($code, $code->background('#ffffff'));
        self::assertSame($code, $code->withText(true));
    }

    public function testColorsAreEscapedAgainstAttributeInjection(): void
    {
        $svg = Ean8::create('5512345')->foreground('"><script>alert(1)</script>')->render();

        self::assertStringNotContainsString('<script>', $svg);
        self::assertStringContainsString('&lt;script&gt;', $svg);
    }
}
