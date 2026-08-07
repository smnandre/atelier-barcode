<?php

declare(strict_types=1);

namespace Atelier\Barcode\Tests;

use Atelier\Barcode\Exception\CodeExceptionInterface;
use Atelier\Barcode\UpcA;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UpcA::class)]
final class UpcATest extends TestCase
{
    public function testCreateComputesCheckDigitFromEleven(): void
    {
        self::assertSame('036000291452', UpcA::create('03600029145')->value());
    }

    public function testCreateAcceptsValidTwelveDigits(): void
    {
        self::assertSame('036000291452', UpcA::create('036000291452')->value());
    }

    public function testCreateRejectsInvalidCheckDigit(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        UpcA::create('036000291453');
    }

    public function testCreateRejectsNonNumericInput(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        UpcA::create('0360002914A');
    }

    public function testCreateRejectsWrongLength(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        UpcA::create('036000');
    }

    public function testCheckDigitRejectsWrongLength(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        UpcA::checkDigit('036000');
    }

    public function testSymbolHasNinetyFiveModules(): void
    {
        self::assertCount(95, UpcA::create('03600029145')->modules());
    }

    public function testGuardPatternsArePlaced(): void
    {
        $m = UpcA::create('03600029145')->modules();

        self::assertSame([true, false, true], [$m[0], $m[1], $m[2]]);
        self::assertSame([false, true, false, true, false], [$m[45], $m[46], $m[47], $m[48], $m[49]]);
        self::assertSame([true, false, true], [$m[92], $m[93], $m[94]]);
    }

    public function testEncodingIsDeterministicAndCached(): void
    {
        $code = UpcA::create('03600029145');

        self::assertSame($code->modules(), $code->modules());
    }

    public function testRenderProducesSvgWithCaption(): void
    {
        $svg = UpcA::create('03600029145')->render();

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringContainsString('<rect', $svg);
        self::assertStringContainsString('>036000291452<', $svg);
    }

    public function testTextCanBeDisabled(): void
    {
        $svg = UpcA::create('03600029145')->withText(false)->render();

        self::assertStringNotContainsString('<text', $svg);
    }

    public function testInvalidDimensionsAreRejected(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        UpcA::create('03600029145')->scale(0);
    }

    public function testOversizedRenderIsRejected(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        UpcA::create('03600029145')->scale(300)->render();
    }

    public function testBuilderReturnsSameInstance(): void
    {
        $code = UpcA::create('03600029145');

        self::assertSame($code, $code->scale(3));
        self::assertSame($code, $code->height(64));
        self::assertSame($code, $code->margin(8));
        self::assertSame($code, $code->foreground('#111111'));
        self::assertSame($code, $code->background('#ffffff'));
        self::assertSame($code, $code->withText(true));
    }

    public function testColorsAreEscapedAgainstAttributeInjection(): void
    {
        $svg = UpcA::create('03600029145')->foreground('"><script>alert(1)</script>')->render();

        self::assertStringNotContainsString('<script>', $svg);
        self::assertStringContainsString('&lt;script&gt;', $svg);
    }
}
