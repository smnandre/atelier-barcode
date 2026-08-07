<?php

declare(strict_types=1);

namespace Atelier\Barcode\Tests;

use Atelier\Barcode\Code128;
use Atelier\Barcode\Exception\CodeExceptionInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Code128::class)]
final class Code128Test extends TestCase
{
    public function testCreateRejectsEmptyData(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Code128::create('');
    }

    public function testCreateRejectsNonAsciiData(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Code128::create("caf\xC3\xA9");
    }

    public function testCreateRejectsOverlongData(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Code128::create(str_repeat('A', 513));
    }

    public function testEncodingIsDeterministic(): void
    {
        $first = Code128::create('ATELIER-SVG')->modules();
        $second = Code128::create('ATELIER-SVG')->modules();

        self::assertSame($first, $second);
    }

    public function testDigitRunsUseCompactEncoding(): void
    {
        $digits = Code128::create('12345678')->modules();
        $letters = Code128::create('ABCDEFGH')->modules();

        self::assertLessThan(count($letters), count($digits));
    }

    public function testLeadingControlCharactersUseCodeSetA(): void
    {
        $modules = Code128::create("\x01ABC")->modules();

        self::assertNotEmpty($modules);
    }

    public function testCompactDigitsCanSwitchBackToCodeSetB(): void
    {
        $modules = Code128::create('1234A')->modules();

        self::assertNotEmpty($modules);
    }

    public function testCompactDigitsCanSwitchBackToCodeSetA(): void
    {
        $modules = Code128::create("1234\x01")->modules();

        self::assertNotEmpty($modules);
    }

    public function testCodeSetBCanShiftToCodeSetA(): void
    {
        $modules = Code128::create("A\x01")->modules();

        self::assertNotEmpty($modules);
    }

    public function testCodeSetACanEmitControlPrintableAndSwitchToCodeSetB(): void
    {
        $modules = Code128::create("\x01A`")->modules();

        self::assertNotEmpty($modules);
    }

    public function testSymbolStartsAndEndsWithBar(): void
    {
        $modules = Code128::create('SKU-0042')->modules();

        self::assertTrue($modules[0]);
        self::assertTrue($modules[count($modules) - 1]);
    }

    public function testRenderProducesSvgWithBars(): void
    {
        $svg = Code128::create('ATELIER')->render();

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringContainsString('<rect', $svg);
    }

    public function testRenderIncludesHumanReadableTextByDefault(): void
    {
        $svg = Code128::create('ATELIER')->render();

        self::assertStringContainsString('>ATELIER<', $svg);
    }

    public function testTextCanBeDisabled(): void
    {
        $svg = Code128::create('ATELIER')->withText(false)->render();

        self::assertStringNotContainsString('<text', $svg);
    }

    public function testInvalidDimensionsAreRejected(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Code128::create('ATELIER')->scale(0);
    }

    public function testOversizedRenderIsRejected(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Code128::create(str_repeat('A', 512))->scale(4)->render();
    }

    public function testBuilderReturnsSameInstance(): void
    {
        $code = Code128::create('ATELIER');

        self::assertSame($code, $code->scale(3));
        self::assertSame($code, $code->height(60));
        self::assertSame($code, $code->margin(8));
        self::assertSame($code, $code->foreground('#000000'));
        self::assertSame($code, $code->background('#ffffff'));
        self::assertSame($code, $code->withText(true));
    }

    public function testColorsAreEscapedAgainstAttributeInjection(): void
    {
        $svg = Code128::create('ATELIER')->foreground('"><script>alert(1)</script>')->render();

        self::assertStringNotContainsString('<script>', $svg);
        self::assertStringContainsString('&lt;script&gt;', $svg);
    }

    public function testValidColorSyntaxSurvivesEscaping(): void
    {
        $svg = Code128::create('ATELIER')->foreground('oklch(0.7 0.2 200)')->background('none')->render();

        self::assertStringContainsString('fill="oklch(0.7 0.2 200)"', $svg);
        self::assertStringContainsString('fill="none"', $svg);
    }
}
