<?php

declare(strict_types=1);

namespace Atelier\Barcode\Tests;

use Atelier\Barcode\Exception\CodeExceptionInterface;
use Atelier\Barcode\Exception\InvalidCodeException;
use Atelier\Barcode\Isbn;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Isbn::class)]
#[CoversClass(InvalidCodeException::class)]
final class IsbnTest extends TestCase
{
    public function testCreateAcceptsValidIsbn10AndConvertsToEan13(): void
    {
        $isbn = Isbn::create('0-201-37962-7');

        self::assertSame('0201379627', $isbn->isbn());
        self::assertSame('9780201379624', $isbn->value());
    }

    public function testCreateAcceptsValidIsbn10WithXCheckDigit(): void
    {
        $isbn = Isbn::create('080442957X');

        self::assertSame('9780804429573', $isbn->value());
    }

    public function testCreateAcceptsValidIsbn13(): void
    {
        $isbn = Isbn::create('978-0-201-37962-4');

        self::assertSame('9780201379624', $isbn->isbn());
        self::assertSame('9780201379624', $isbn->value());
    }

    public function testCreateRejectsWrongLength(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Isbn::create('1234');
    }

    public function testCreateRejectsInvalidIsbn10Characters(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Isbn::create('02013796XA');
    }

    public function testCreateRejectsInvalidIsbn10CheckDigit(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Isbn::create('0201379629');
    }

    public function testCreateRejectsInvalidIsbn13Prefix(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Isbn::create('9770201379625');
    }

    public function testCreateRejectsInvalidIsbn13CheckDigit(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Isbn::create('9780201379625');
    }

    public function testModulesDelegateToEan13(): void
    {
        self::assertCount(95, Isbn::create('9780201379624')->modules());
    }

    public function testRenderProducesSvgWithDigits(): void
    {
        $svg = Isbn::create('9780201379624')->render();

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringContainsString('<rect', $svg);
        self::assertStringContainsString('<text', $svg);
    }

    public function testInvalidDimensionsAreRejected(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        Isbn::create('9780201379624')->margin(-1);
    }

    public function testEan13BuilderIsExposed(): void
    {
        self::assertSame('9780201379624', Isbn::create('0201379627')->ean13()->value());
    }

    public function testBuilderReturnsSameInstance(): void
    {
        $isbn = Isbn::create('9780201379624');

        self::assertSame($isbn, $isbn->scale(3));
        self::assertSame($isbn, $isbn->height(70));
        self::assertSame($isbn, $isbn->margin(9));
        self::assertSame($isbn, $isbn->foreground('#000000'));
        self::assertSame($isbn, $isbn->background('#ffffff'));
    }

    public function testColorsAreEscapedAgainstAttributeInjection(): void
    {
        $svg = Isbn::create('9780201379624')->foreground('"><script>alert(1)</script>')->render();

        self::assertStringNotContainsString('<script>', $svg);
        self::assertStringContainsString('&lt;script&gt;', $svg);
    }
}
