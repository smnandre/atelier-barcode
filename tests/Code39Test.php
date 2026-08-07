<?php

declare(strict_types=1);

namespace Atelier\Barcode\Tests;

use Atelier\Barcode\Code39;
use Atelier\Barcode\Exception\CodeExceptionInterface;
use Atelier\Barcode\Exception\InvalidCodeException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Code39::class)]
#[CoversClass(InvalidCodeException::class)]
final class Code39Test extends TestCase
{
    public function testCreateRejectsEmptyData(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Code39::create('');
    }

    public function testCreateRejectsStartStopCharacter(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Code39::create('ATELIER*');
    }

    public function testCreateRejectsUnsupportedCharacters(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Code39::create('@');
    }

    public function testCreateRejectsOverlongData(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Code39::create(str_repeat('A', 513));
    }

    public function testCreateNormalizesLowercaseInput(): void
    {
        self::assertSame('ATELIER-42', Code39::create('atelier-42')->value());
    }

    public function testEncodingIsDeterministic(): void
    {
        self::assertSame(
            Code39::create('ATELIER-42')->modules(),
            Code39::create('ATELIER-42')->modules(),
        );
    }

    public function testSymbolStartsAndEndsWithBar(): void
    {
        $modules = Code39::create('ATELIER')->modules();

        self::assertTrue($modules[0]);
        self::assertTrue($modules[count($modules) - 1]);
    }

    public function testChecksumAddsModules(): void
    {
        $plain = Code39::create('ATELIER')->modules();
        $checked = Code39::create('ATELIER')->withChecksum(true)->modules();

        self::assertGreaterThan(count($plain), count($checked));
    }

    public function testRenderProducesSvgWithCaption(): void
    {
        $svg = Code39::create('ATELIER')->render();

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringContainsString('<rect', $svg);
        self::assertStringContainsString('>ATELIER<', $svg);
    }

    public function testTextCanBeDisabled(): void
    {
        $svg = Code39::create('ATELIER')->withText(false)->render();

        self::assertStringNotContainsString('<text', $svg);
    }

    public function testWideRatioMustBeAtLeastTwo(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Code39::create('ATELIER')->wideRatio(1);
    }

    public function testInvalidDimensionsAreRejected(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Code39::create('ATELIER')->height(0);
    }

    public function testOversizedRenderIsRejected(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Code39::create(str_repeat('A', 512))->scale(8)->render();
    }

    public function testBuilderReturnsSameInstance(): void
    {
        $code = Code39::create('ATELIER');

        self::assertSame($code, $code->scale(3));
        self::assertSame($code, $code->height(60));
        self::assertSame($code, $code->margin(8));
        self::assertSame($code, $code->wideRatio(2));
        self::assertSame($code, $code->foreground('#000000'));
        self::assertSame($code, $code->background('#ffffff'));
        self::assertSame($code, $code->withText(true));
        self::assertSame($code, $code->withChecksum(false));
    }

    public function testColorsAreEscapedAgainstAttributeInjection(): void
    {
        $svg = Code39::create('ATELIER')->foreground('"><script>alert(1)</script>')->render();

        self::assertStringNotContainsString('<script>', $svg);
        self::assertStringContainsString('&lt;script&gt;', $svg);
    }
}
