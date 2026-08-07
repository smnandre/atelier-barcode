<?php

declare(strict_types=1);

namespace Atelier\Barcode\Tests;

use Atelier\Barcode\Exception\CodeExceptionInterface;
use Atelier\Barcode\UpcE;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UpcE::class)]
final class UpcETest extends TestCase
{
    public function testCreateComputesCheckDigitFromSixDigitPayload(): void
    {
        $code = UpcE::create('042100');

        self::assertSame('00421001', $code->value());
        self::assertSame('042100', $code->payload());
        self::assertSame(0, $code->numberSystem());
        self::assertSame('004000002101', $code->expandedValue());
    }

    public function testCreateAcceptsExplicitNumberSystemArgument(): void
    {
        $code = UpcE::create('042100', 1);

        self::assertSame('10421008', $code->value());
        self::assertSame(1, $code->numberSystem());
        self::assertSame('104000002108', $code->expandedValue());
    }

    public function testCreateAcceptsNumberSystemPrefix(): void
    {
        self::assertSame('10421008', UpcE::create('1042100')->value());
    }

    public function testCreateAcceptsValidEightDigits(): void
    {
        self::assertSame('00421001', UpcE::create('00421001')->value());
    }

    public function testCreateRejectsInvalidCheckDigit(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        UpcE::create('00421002');
    }

    public function testCreateRejectsNonNumericInput(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        UpcE::create('04210A');
    }

    public function testCreateRejectsWrongLength(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        UpcE::create('04210');
    }

    public function testCreateRejectsUnsupportedNumberSystemArgument(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        UpcE::create('042100', 2);
    }

    public function testCreateRejectsUnsupportedNumberSystemPrefix(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        UpcE::create('2042100');
    }

    public function testFromUpcACompressesZeroOneTwoRule(): void
    {
        self::assertSame('00421001', UpcE::fromUpcA('004000002101')->value());
    }

    public function testFromUpcACompressesThreeRule(): void
    {
        self::assertSame('01234531', UpcE::fromUpcA('012300000451')->value());
    }

    public function testFromUpcACompressesFourRule(): void
    {
        self::assertSame('01234543', UpcE::fromUpcA('012340000053')->value());
    }

    public function testFromUpcACompressesFiveThroughNineRule(): void
    {
        self::assertSame('01234565', UpcE::fromUpcA('012345000065')->value());
    }

    public function testFromUpcARejectsNonCompressibleInput(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        UpcE::fromUpcA('036000291452');
    }

    public function testFromUpcARejectsUnsupportedNumberSystem(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        UpcE::fromUpcA('212345000069');
    }

    public function testSymbolHasFiftyOneModules(): void
    {
        self::assertCount(51, UpcE::create('042100')->modules());
    }

    public function testGuardPatternsArePlaced(): void
    {
        $m = UpcE::create('042100')->modules();

        self::assertSame([true, false, true], [$m[0], $m[1], $m[2]]);
        self::assertSame([false, true, false, true, false, true], [$m[45], $m[46], $m[47], $m[48], $m[49], $m[50]]);
    }

    public function testEncodingUsesDifferentParityForNumberSystemOne(): void
    {
        self::assertNotSame(UpcE::create('042100')->modules(), UpcE::create('042100', 1)->modules());
    }

    public function testEncodingIsDeterministicAndCached(): void
    {
        $code = UpcE::create('042100');

        self::assertSame($code->modules(), $code->modules());
    }

    public function testRenderProducesSvgWithCaption(): void
    {
        $svg = UpcE::create('042100')->render();

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringContainsString('<rect', $svg);
        self::assertStringContainsString('>00421001<', $svg);
    }

    public function testTextCanBeDisabled(): void
    {
        $svg = UpcE::create('042100')->withText(false)->render();

        self::assertStringNotContainsString('<text', $svg);
    }

    public function testInvalidDimensionsAreRejected(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        UpcE::create('042100')->margin(-1);
    }

    public function testOversizedRenderIsRejected(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        UpcE::create('042100')->scale(500)->render();
    }

    public function testBuilderReturnsSameInstance(): void
    {
        $code = UpcE::create('042100');

        self::assertSame($code, $code->scale(3));
        self::assertSame($code, $code->height(64));
        self::assertSame($code, $code->margin(8));
        self::assertSame($code, $code->foreground('#111111'));
        self::assertSame($code, $code->background('#ffffff'));
        self::assertSame($code, $code->withText(true));
    }

    public function testColorsAreEscapedAgainstAttributeInjection(): void
    {
        $svg = UpcE::create('042100')->foreground('"><script>alert(1)</script>')->render();

        self::assertStringNotContainsString('<script>', $svg);
        self::assertStringContainsString('&lt;script&gt;', $svg);
    }
}
