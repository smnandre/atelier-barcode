<?php

declare(strict_types=1);

namespace Atelier\Barcode\Tests;

use Atelier\Barcode\Codabar;
use Atelier\Barcode\Exception\CodeExceptionInterface;
use Atelier\Barcode\Exception\InvalidCodeException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Codabar::class)]
#[CoversClass(InvalidCodeException::class)]
final class CodabarTest extends TestCase
{
    public function testCreateRejectsEmptyData(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Codabar::create('');
    }

    public function testCreateDefaultsToAGuards(): void
    {
        $code = Codabar::create('12345');

        self::assertSame('A12345A', $code->value());
        self::assertSame('12345', $code->payload());
        self::assertSame('A', $code->start());
        self::assertSame('A', $code->stop());
    }

    public function testCreateAcceptsInlineGuards(): void
    {
        $code = Codabar::create('B12345C');

        self::assertSame('B12345C', $code->value());
        self::assertSame('12345', $code->payload());
        self::assertSame('B', $code->start());
        self::assertSame('C', $code->stop());
    }

    public function testWithGuardsAcceptsExplicitGuards(): void
    {
        self::assertSame('C12-34D', Codabar::withGuards('12-34', 'C', 'D')->value());
    }

    public function testCreateNormalizesLowercaseInput(): void
    {
        self::assertSame('A123A', Codabar::create('a123a')->value());
    }

    public function testCreateRejectsUnsupportedPayloadCharacters(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Codabar::create('12A34');
    }

    public function testWithGuardsRejectsInvalidGuard(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Codabar::withGuards('123', 'E', 'A');
    }

    public function testWithGuardsRejectsEmptyPayload(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Codabar::withGuards('', 'A', 'A');
    }

    public function testCreateRejectsOverlongData(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Codabar::create(str_repeat('1', 515));
    }

    public function testWithGuardsRejectsOverlongPayload(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Codabar::withGuards(str_repeat('1', 513));
    }

    public function testSymbolLengthIncludesInterCharacterGaps(): void
    {
        self::assertCount(51, Codabar::create('A123B')->modules());
    }

    public function testSymbolStartsAndEndsWithBar(): void
    {
        $modules = Codabar::create('A123B')->modules();

        self::assertTrue($modules[0]);
        self::assertTrue($modules[count($modules) - 1]);
    }

    public function testEncodingIsDeterministicAndCached(): void
    {
        $code = Codabar::create('A123B');

        self::assertSame($code->modules(), $code->modules());
        self::assertSame($code->modules(), Codabar::create('A123B')->modules());
    }

    public function testRenderProducesSvgWithCaption(): void
    {
        $svg = Codabar::create('A123B')->render();

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringContainsString('<rect', $svg);
        self::assertStringContainsString('>A123B<', $svg);
    }

    public function testTextCanBeDisabled(): void
    {
        $svg = Codabar::create('A123B')->withText(false)->render();

        self::assertStringNotContainsString('<text', $svg);
    }

    public function testInvalidDimensionsAreRejected(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Codabar::create('A123B')->height(0);
    }

    public function testOversizedRenderIsRejected(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Codabar::create(str_repeat('1', 512))->scale(20)->render();
    }

    public function testBuilderReturnsSameInstance(): void
    {
        $code = Codabar::create('A123B');

        self::assertSame($code, $code->scale(3));
        self::assertSame($code, $code->height(60));
        self::assertSame($code, $code->margin(8));
        self::assertSame($code, $code->foreground('#000000'));
        self::assertSame($code, $code->background('#ffffff'));
        self::assertSame($code, $code->withText(true));
    }

    public function testColorsAreEscapedAgainstAttributeInjection(): void
    {
        $svg = Codabar::create('A123B')->foreground('"><script>alert(1)</script>')->render();

        self::assertStringNotContainsString('<script>', $svg);
        self::assertStringContainsString('&lt;script&gt;', $svg);
    }
}
