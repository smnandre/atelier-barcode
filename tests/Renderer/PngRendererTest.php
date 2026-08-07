<?php

declare(strict_types=1);

namespace Atelier\Barcode\Tests\Renderer;

use Atelier\Barcode\DataMatrix;
use Atelier\Barcode\Exception\CodeExceptionInterface;
use Atelier\Barcode\Exception\InvalidCodeException;
use Atelier\Barcode\Exception\InvalidColorException;
use Atelier\Barcode\Exception\MissingExtensionException;
use Atelier\Barcode\QrCode;
use Atelier\Barcode\Renderer\PngRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PngRenderer::class)]
#[CoversClass(MissingExtensionException::class)]
#[CoversClass(InvalidCodeException::class)]
#[CoversClass(InvalidColorException::class)]
final class PngRendererTest extends TestCase
{
    public function testEnsureGdLoadedThrowsWhenAbsent(): void
    {
        $this->expectException(MissingExtensionException::class);
        $this->expectExceptionMessage('PNG rendering requires ext-gd, which is not loaded.');

        PngRenderer::ensureGdLoaded(false);
    }

    public function testEnsureGdLoadedPassesWhenPresent(): void
    {
        $this->expectNotToPerformAssertions();

        PngRenderer::ensureGdLoaded(true);
    }

    public function testAssertAllocatedThrowsWhenColorAllocationFails(): void
    {
        $this->expectException(InvalidCodeException::class);
        $this->expectExceptionMessage('Failed to allocate PNG color.');

        PngRenderer::assertAllocated(false);
    }

    public function testAssertAllocatedReturnsTheColorWhenAllocationSucceeds(): void
    {
        self::assertSame(42, PngRenderer::assertAllocated(42));
    }

    public function testAssertImageThrowsWhenCreationFails(): void
    {
        $this->expectException(InvalidCodeException::class);
        $this->expectExceptionMessage('Failed to create PNG image.');

        PngRenderer::assertImage(false);
    }

    public function testAssertImageReturnsTheImageWhenCreationSucceeds(): void
    {
        $image = imagecreatetruecolor(1, 1);
        self::assertNotFalse($image);

        self::assertSame($image, PngRenderer::assertImage($image));
    }

    public function testAssertEncodedThrowsWhenPngEncodingFails(): void
    {
        $this->expectException(InvalidCodeException::class);
        $this->expectExceptionMessage('Failed to encode PNG image.');

        PngRenderer::assertEncoded(false);
    }

    public function testAssertEncodedReturnsTheBytesWhenEncodingSucceeds(): void
    {
        self::assertSame('png-bytes', PngRenderer::assertEncoded('png-bytes'));
    }

    public function testAssertPngEncodedThrowsWhenEncodingFails(): void
    {
        $this->expectException(InvalidCodeException::class);
        $this->expectExceptionMessage('Failed to encode PNG image.');

        PngRenderer::assertPngEncoded(false);
    }

    public function testAssertPngEncodedPassesWhenEncodingSucceeds(): void
    {
        $this->expectNotToPerformAssertions();

        PngRenderer::assertPngEncoded(true);
    }

    public function testRenderProducesValidPngWithExpectedDimensions(): void
    {
        $matrix = QrCode::create('ATELIER-2026')->matrix();
        $moduleSize = 4;
        $margin = 2;

        $png = PngRenderer::render($matrix, $moduleSize, $margin);

        self::assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);

        $info = getimagesizefromstring($png);
        self::assertIsArray($info);

        $side = (\count($matrix) + 2 * $margin) * $moduleSize;
        self::assertSame($side, $info[0]);
        self::assertSame($side, $info[1]);
        self::assertSame('image/png', $info['mime']);
    }

    public function testRoundTripMatchesQrMatrixModuleForModule(): void
    {
        $matrix = QrCode::create('https://ateliersvg.com')->matrix();

        self::assertMatrixRoundTrips($matrix, 3, 4);
    }

    public function testRoundTripMatchesDataMatrixModuleForModule(): void
    {
        $matrix = DataMatrix::create('ATELIER-2026')->matrix();

        self::assertMatrixRoundTrips($matrix, 5, 2);
    }

    public function testShortHexColorsAreExpanded(): void
    {
        $png = PngRenderer::render([[true, false]], 2, 0, '#f00', '#00f');
        $image = imagecreatefromstring($png);
        self::assertNotFalse($image);

        self::assertSame([255, 0, 0], self::pixelRgb($image, 1, 1));
        self::assertSame([0, 0, 255], self::pixelRgb($image, 3, 1));
    }

    /**
     * @return list<array{string}>
     */
    public static function invalidColorProvider(): array
    {
        return [
            ['red'],
            ['000000'],
            ['#12345'],
            ['#1234567'],
            ['#gggggg'],
            ['"><script>alert(1)</script>'],
            [''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidColorProvider')]
    public function testInvalidForegroundColorIsRejected(string $color): void
    {
        $this->expectException(InvalidColorException::class);

        PngRenderer::render([[true]], 2, 0, $color);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidColorProvider')]
    public function testInvalidBackgroundColorIsRejected(string $color): void
    {
        $this->expectException(InvalidColorException::class);

        PngRenderer::render([[true]], 2, 0, '#000000', $color);
    }

    public function testEmptyMatrixIsRejected(): void
    {
        $this->expectException(CodeExceptionInterface::class);
        $this->expectExceptionMessage('Matrix must not be empty.');

        PngRenderer::render([], 2, 0);
    }

    public function testEmptyRowIsRejected(): void
    {
        $this->expectException(CodeExceptionInterface::class);
        $this->expectExceptionMessage('rows must not be empty');

        PngRenderer::render([[]], 2, 0);
    }

    public function testRaggedRowsAreRejected(): void
    {
        $this->expectException(CodeExceptionInterface::class);
        $this->expectExceptionMessage('same length');

        PngRenderer::render([[true, false], [true]], 2, 0);
    }

    public function testNonPositiveModuleSizeIsRejected(): void
    {
        $this->expectException(CodeExceptionInterface::class);
        $this->expectExceptionMessage('positive integer');

        PngRenderer::render([[true]], 0, 0);
    }

    public function testNegativeMarginIsRejected(): void
    {
        $this->expectException(CodeExceptionInterface::class);
        $this->expectExceptionMessage('zero or positive');

        PngRenderer::render([[true]], 2, -1);
    }

    public function testOversizedSideIsRejected(): void
    {
        $this->expectException(CodeExceptionInterface::class);
        $this->expectExceptionMessage('maximum side');

        PngRenderer::render([[true]], 20001, 0);
    }

    public function testOversizedAreaIsRejected(): void
    {
        $matrix = array_fill(0, 100, array_fill(0, 100, true));

        $this->expectException(CodeExceptionInterface::class);
        $this->expectExceptionMessage('maximum area');

        PngRenderer::render($matrix, 70, 0);
    }

    public function testLargestSymbolsEncodeQuickly(): void
    {
        $qr = QrCode::create(str_repeat('A', 900))->ecc(\Atelier\Barcode\Ecc::Low)->matrix();
        $dm = DataMatrix::create(str_repeat('A', 44))->matrix();

        self::assertGreaterThanOrEqual(26, count($dm));

        $start = microtime(true);
        $qrPng = PngRenderer::render($qr, 4, 4);
        $dmPng = PngRenderer::render($dm, 8, 2);
        $elapsed = microtime(true) - $start;

        self::assertNotSame('', $qrPng);
        self::assertNotSame('', $dmPng);
        self::assertLessThan(2.0, $elapsed, sprintf('Encoding took %.3fs, slower than expected.', $elapsed));
    }

    /**
     * @param list<list<bool>> $matrix
     */
    private static function assertMatrixRoundTrips(array $matrix, int $moduleSize, int $margin): void
    {
        $png = PngRenderer::render($matrix, $moduleSize, $margin);
        $image = imagecreatefromstring($png);
        self::assertNotFalse($image);

        $rows = count($matrix);
        $cols = count($matrix[0]);
        $half = intdiv($moduleSize, 2);

        for ($row = 0; $row < $rows; ++$row) {
            for ($col = 0; $col < $cols; ++$col) {
                $x = ($margin + $col) * $moduleSize + $half;
                $y = ($margin + $row) * $moduleSize + $half;
                $isForeground = [0, 0, 0] === self::pixelRgb($image, $x, $y);

                self::assertSame(
                    $matrix[$row][$col],
                    $isForeground,
                    sprintf('Module (%d,%d) does not round-trip.', $row, $col),
                );
            }
        }
    }

    /**
     * @return array{int, int, int}
     */
    private static function pixelRgb(\GdImage $image, int $x, int $y): array
    {
        $rgb = imagecolorat($image, $x, $y);

        return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
    }
}
