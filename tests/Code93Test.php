<?php

declare(strict_types=1);

namespace Atelier\Barcode\Tests;

use Atelier\Barcode\Code93;
use Atelier\Barcode\Exception\CodeExceptionInterface;
use Atelier\Barcode\Exception\InvalidCodeException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Code93::class)]
#[CoversClass(InvalidCodeException::class)]
final class Code93Test extends TestCase
{
    public function testCreateRejectsEmptyData(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Code93::create('');
    }

    public function testCreateRejectsUnsupportedCharacters(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Code93::create('@');
    }

    public function testCreateRejectsOverlongData(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Code93::create(str_repeat('A', 513));
    }

    public function testCreateNormalizesLowercaseInput(): void
    {
        self::assertSame('ATELIER-93', Code93::create('atelier-93')->value());
    }

    public function testChecksumUsesCode93CAndKCharacters(): void
    {
        self::assertSame('PV', Code93::create('CODE93')->checksum());
    }

    public function testSymbolLengthIncludesStartTwoChecksStopAndTerminationBar(): void
    {
        self::assertCount(91, Code93::create('CODE93')->modules());
    }

    public function testSymbolStartsAndEndsWithBar(): void
    {
        $modules = Code93::create('CODE93')->modules();

        self::assertTrue($modules[0]);
        self::assertTrue($modules[count($modules) - 1]);
    }

    public function testEncodingIsDeterministicAndCached(): void
    {
        $code = Code93::create('ATELIER-93');

        self::assertSame($code->modules(), $code->modules());
        self::assertSame($code->modules(), Code93::create('ATELIER-93')->modules());
    }

    public function testRenderProducesSvgWithCaption(): void
    {
        $svg = Code93::create('CODE93')->render();

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringContainsString('<rect', $svg);
        self::assertStringContainsString('>CODE93<', $svg);
    }

    public function testChecksumTextCanBeDisplayed(): void
    {
        $svg = Code93::create('CODE93')->withChecksumText(true)->render();

        self::assertStringContainsString('>CODE93PV<', $svg);
    }

    public function testTextCanBeDisabled(): void
    {
        $svg = Code93::create('CODE93')->withText(false)->render();

        self::assertStringNotContainsString('<text', $svg);
    }

    public function testInvalidDimensionsAreRejected(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Code93::create('CODE93')->scale(0);
    }

    public function testOversizedRenderIsRejected(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Code93::create(str_repeat('A', 512))->scale(8)->render();
    }

    public function testBuilderReturnsSameInstance(): void
    {
        $code = Code93::create('CODE93');

        self::assertSame($code, $code->scale(3));
        self::assertSame($code, $code->height(60));
        self::assertSame($code, $code->margin(8));
        self::assertSame($code, $code->foreground('#000000'));
        self::assertSame($code, $code->background('#ffffff'));
        self::assertSame($code, $code->withText(true));
        self::assertSame($code, $code->withChecksumText(false));
    }

    public function testColorsAreEscapedAgainstAttributeInjection(): void
    {
        $svg = Code93::create('CODE93')->foreground('"><script>alert(1)</script>')->render();

        self::assertStringNotContainsString('<script>', $svg);
        self::assertStringContainsString('&lt;script&gt;', $svg);
    }

    public function testInternalValueLookupRejectsNonDataCharacters(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        $valueOf = (new \ReflectionClass(Code93::class))->getMethod('valueOf');
        $valueOf->invoke(null, '*');
    }
}
