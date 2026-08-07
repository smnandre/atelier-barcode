<?php

declare(strict_types=1);

namespace Atelier\Barcode\Tests\Renderer;

use Atelier\Barcode\DataMatrix;
use Atelier\Barcode\Ecc;
use Atelier\Barcode\Exception\CodeExceptionInterface;
use Atelier\Barcode\Exception\InvalidCodeException;
use Atelier\Barcode\Exception\InvalidColorException;
use Atelier\Barcode\QrCode;
use Atelier\Barcode\Renderer\GifRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(GifRenderer::class)]
#[CoversClass(InvalidColorException::class)]
#[CoversClass(InvalidCodeException::class)]
final class GifRendererTest extends TestCase
{
    public function testOutputIsAWellFramedGif89a(): void
    {
        $gif = GifRenderer::render([[true, false], [false, true]], 4, 1);

        self::assertStringStartsWith('GIF89a', $gif);
        self::assertSame("\x3B", substr($gif, -1), 'GIF must end with the trailer byte.');
    }

    public function testDeclaredDimensionsMatchTheModuleGeometry(): void
    {
        $matrix = [[true, false, true], [false, true, false]];
        $gif = GifRenderer::render($matrix, 5, 2);
        $decoded = self::decodeGif($gif);

        // (cols + 2*margin) * moduleSize, (rows + 2*margin) * moduleSize
        self::assertSame((3 + 4) * 5, $decoded['width']);
        self::assertSame((2 + 4) * 5, $decoded['height']);
    }

    /**
     * @param callable(): list<list<bool>> $factory
     */
    #[DataProvider('realMatrices')]
    public function testRoundTripThroughOwnDecoder(callable $factory, int $moduleSize, int $margin): void
    {
        $matrix = $factory();
        $gif = GifRenderer::render($matrix, $moduleSize, $margin);

        $decoded = self::decodeGif($gif);
        $expected = self::expectedPixels($matrix, $moduleSize, $margin);

        self::assertSame($expected, $decoded['pixels'], 'Own decoder must reproduce the module grid pixel for pixel.');
    }

    /**
     * @param callable(): list<list<bool>> $factory
     */
    #[DataProvider('realMatrices')]
    public function testRoundTripThroughGd(callable $factory, int $moduleSize, int $margin): void
    {
        if (!\function_exists('imagecreatefromstring')) {
            self::markTestSkipped('ext-gd is not available as a test-time oracle.');
        }

        $matrix = $factory();
        $gif = GifRenderer::render($matrix, $moduleSize, $margin, '#112233', '#ffeecc');

        $pixels = self::decodeGifWithGd($gif, [0x11, 0x22, 0x33], [0xFF, 0xEE, 0xCC]);
        $expected = self::expectedPixels($matrix, $moduleSize, $margin);

        self::assertSame($expected, $pixels, 'GD must decode our GIF to the same module grid.');
    }

    /**
     * @return iterable<string, array{callable(): list<list<bool>>, int, int}>
     */
    public static function realMatrices(): iterable
    {
        yield 'qr low' => [static fn (): array => QrCode::create('https://ateliersvg.com')->ecc(Ecc::Low)->matrix(), 3, 4];
        yield 'qr high' => [static fn (): array => QrCode::create('ATELIER-2026-GIF-RENDERER')->ecc(Ecc::High)->matrix(), 2, 2];
        yield 'data matrix' => [static fn (): array => DataMatrix::create('ATELIER-2026')->matrix(), 6, 2];
        yield 'data matrix no margin' => [static fn (): array => DataMatrix::create('CODE128')->matrix(), 4, 0];
    }

    public function testColorsLandInTheGlobalColorTable(): void
    {
        $gif = GifRenderer::render([[true]], 1, 0, '#ff8800', '#0088ff');
        $decoded = self::decodeGif($gif);

        // Index 0 is background, index 1 is foreground.
        self::assertSame([0x00, 0x88, 0xFF], $decoded['palette'][0]);
        self::assertSame([0xFF, 0x88, 0x00], $decoded['palette'][1]);
    }

    public function testShortHexIsExpanded(): void
    {
        $gif = GifRenderer::render([[true]], 1, 0, '#f80', '#08f');
        $decoded = self::decodeGif($gif);

        self::assertSame([0x00, 0x88, 0xFF], $decoded['palette'][0]);
        self::assertSame([0xFF, 0x88, 0x00], $decoded['palette'][1]);
    }

    public function testMarginRendersAsBackgroundQuietZone(): void
    {
        $gif = GifRenderer::render([[true]], 2, 3, '#000000', '#ffffff');
        $decoded = self::decodeGif($gif);

        // Every corner pixel is inside the quiet zone => background index 0.
        $last = $decoded['height'] - 1;
        self::assertSame(0, $decoded['pixels'][0][0]);
        self::assertSame(0, $decoded['pixels'][$last][$decoded['width'] - 1]);
        // The single foreground module sits in the centre.
        self::assertSame(1, $decoded['pixels'][6][6]);
    }

    #[DataProvider('invalidColors')]
    public function testInvalidColorsAreRejected(string $color): void
    {
        $this->expectException(InvalidColorException::class);

        GifRenderer::render([[true]], 1, 0, $color);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidColors(): iterable
    {
        yield 'no hash' => ['000000'];
        yield 'named' => ['red'];
        yield 'too short' => ['#ff'];
        yield 'wrong length' => ['#fffff'];
        yield 'non hex' => ['#gggggg'];
        yield 'trailing junk' => ['#000000;'];
        yield 'injection' => ['#000"><script>'];
        yield 'empty' => [''];
        yield 'rgb function' => ['rgb(0,0,0)'];
    }

    public function testModuleSizeMustBePositive(): void
    {
        $this->expectException(InvalidCodeException::class);

        GifRenderer::render([[true]], 0, 1);
    }

    public function testMarginMustNotBeNegative(): void
    {
        $this->expectException(InvalidCodeException::class);

        GifRenderer::render([[true]], 1, -1);
    }

    public function testEmptyMatrixIsRejected(): void
    {
        $this->expectException(InvalidCodeException::class);

        GifRenderer::render([], 1, 0);
    }

    public function testEmptyRowIsRejected(): void
    {
        $this->expectException(InvalidCodeException::class);

        GifRenderer::render([[]], 1, 0);
    }

    public function testRaggedRowsAreRejected(): void
    {
        $this->expectException(CodeExceptionInterface::class);

        GifRenderer::render([[true, false], [true]], 1, 0);
    }

    public function testOversizedDimensionsAreRejected(): void
    {
        $this->expectException(InvalidCodeException::class);

        // moduleSize alone pushes a single module past the 65535-pixel side limit.
        GifRenderer::render([[true]], 70000, 0);
    }

    public function testOversizedAreaIsRejected(): void
    {
        $this->expectException(InvalidCodeException::class);

        // 10000 x 10000 modules at 1px each = 100M pixels, over the area cap.
        $row = array_fill(0, 10000, true);
        $matrix = array_fill(0, 10000, $row);
        GifRenderer::render($matrix, 1, 0);
    }

    public function testLargestRealisticMatrixEncodesQuickly(): void
    {
        // A long payload forces a high QR version (near the 177-module maximum),
        // and the pixel expansion is large enough to exercise the LZW dictionary
        // reset at the full 12-bit table.
        $matrix = QrCode::create(str_repeat('ATELIER-', 120))->ecc(Ecc::Low)->matrix();
        self::assertGreaterThan(80, count($matrix), 'Expected a large QR symbol for the benchmark.');

        $start = hrtime(true);
        $gif = GifRenderer::render($matrix, 3, 4);
        $elapsed = (hrtime(true) - $start) / 1e9;

        // Generous, non-flaky bound: a correct near-linear encoder finishes this
        // (~300k pixels) in well under a tenth of a second; 5s catches a
        // quadratic regression without failing on slow CI.
        self::assertLessThan(5.0, $elapsed, sprintf('Encoding took %.3fs, suspect non-linear LZW.', $elapsed));

        // Correctness must still hold on the large, dictionary-resetting input.
        $decoded = self::decodeGif($gif);
        $expected = self::expectedPixels($matrix, 3, 4);
        self::assertSame($expected, $decoded['pixels']);
    }

    /**
     * @param list<list<bool>> $matrix
     *
     * @return list<list<int>>
     */
    private static function expectedPixels(array $matrix, int $moduleSize, int $margin): array
    {
        $rows = count($matrix);
        $cols = count($matrix[0]);
        $width = ($cols + 2 * $margin) * $moduleSize;
        $height = ($rows + 2 * $margin) * $moduleSize;

        $grid = [];
        for ($y = 0; $y < $height; ++$y) {
            $my = intdiv($y, $moduleSize) - $margin;
            $row = [];
            for ($x = 0; $x < $width; ++$x) {
                $mx = intdiv($x, $moduleSize) - $margin;
                $on = $my >= 0 && $my < $rows && $mx >= 0 && $mx < $cols && $matrix[$my][$mx];
                $row[] = $on ? 1 : 0;
            }
            $grid[] = $row;
        }

        return $grid;
    }

    /**
     * Minimal from-scratch GIF decoder, independent of the encoder's internals,
     * used to prove round-trip correctness with no runtime dependency.
     *
     * @return array{width: int, height: int, palette: list<array{int, int, int}>, pixels: list<list<int>>}
     */
    private static function decodeGif(string $bytes): array
    {
        $pos = 6; // header "GIF89a"
        $width = \ord($bytes[$pos]) | (\ord($bytes[$pos + 1]) << 8);
        $pos += 2;
        $height = \ord($bytes[$pos]) | (\ord($bytes[$pos + 1]) << 8);
        $pos += 2;
        $packed = \ord($bytes[$pos]);
        $pos += 3; // packed + background index + aspect ratio

        $gctSize = 1 << (($packed & 0x07) + 1);
        $palette = [];
        for ($i = 0; $i < $gctSize; ++$i) {
            $palette[] = [\ord($bytes[$pos]), \ord($bytes[$pos + 1]), \ord($bytes[$pos + 2])];
            $pos += 3;
        }

        if ("\x2C" !== $bytes[$pos]) {
            self::fail('Expected an image descriptor.');
        }
        $pos += 10; // separator + left + top + width + height + packed

        $minCodeSize = \ord($bytes[$pos++]);

        $data = '';
        while (0 !== ($len = \ord($bytes[$pos++]))) {
            $data .= substr($bytes, $pos, $len);
            $pos += $len;
        }

        $indices = self::lzwDecode($data, $minCodeSize);

        $pixels = [];
        for ($y = 0; $y < $height; ++$y) {
            $pixels[] = array_map(ord(...), str_split(substr($indices, $y * $width, $width)));
        }

        return ['width' => $width, 'height' => $height, 'palette' => $palette, 'pixels' => $pixels];
    }

    private static function lzwDecode(string $data, int $minCodeSize): string
    {
        $clear = 1 << $minCodeSize;
        $eoi = $clear + 1;
        $length = \strlen($data);

        $reset = static function () use ($clear, $eoi): array {
            $table = [];
            for ($i = 0; $i < $clear; ++$i) {
                $table[$i] = \chr(min(255, $i));
            }
            $table[$clear] = '';
            $table[$eoi] = '';

            return $table;
        };

        $table = $reset();
        $codeSize = $minCodeSize + 1;
        $next = $eoi + 1;

        $bitBuffer = 0;
        $bitCount = 0;
        $p = 0;
        $out = '';
        $prev = null;

        while (true) {
            while ($bitCount < $codeSize && $p < $length) {
                $bitBuffer |= \ord($data[$p++]) << $bitCount;
                $bitCount += 8;
            }
            if ($bitCount < $codeSize) {
                break; // stream exhausted without an explicit EOI
            }

            $code = $bitBuffer & ((1 << $codeSize) - 1);
            $bitBuffer >>= $codeSize;
            $bitCount -= $codeSize;

            if ($code === $clear) {
                $table = $reset();
                $codeSize = $minCodeSize + 1;
                $next = $eoi + 1;
                $prev = null;
                continue;
            }

            if ($code === $eoi) {
                break;
            }

            if (null === $prev) {
                $out .= $table[$code];
                $prev = $code;
                continue;
            }

            if (isset($table[$code])) {
                $entry = $table[$code];
            } else {
                $entry = $table[$prev].$table[$prev][0];
            }

            $out .= $entry;

            if ($next < 4096) {
                $table[$next] = $table[$prev].$entry[0];
                if ($next === (1 << $codeSize) - 1 && $codeSize < 12) {
                    ++$codeSize;
                }
                ++$next;
            }

            $prev = $code;
        }

        return $out;
    }

    /**
     * @param array{int, int, int} $fg
     * @param array{int, int, int} $bg
     *
     * @return list<list<int>>
     */
    private static function decodeGifWithGd(string $bytes, array $fg, array $bg): array
    {
        $image = imagecreatefromstring($bytes);
        self::assertNotFalse($image, 'GD failed to parse our GIF, so it is malformed.');

        $width = imagesx($image);
        $height = imagesy($image);

        $pixels = [];
        for ($y = 0; $y < $height; ++$y) {
            $row = [];
            for ($x = 0; $x < $width; ++$x) {
                $index = imagecolorat($image, $x, $y);
                self::assertNotFalse($index);
                $rgb = imagecolorsforindex($image, $index);
                $color = [$rgb['red'], $rgb['green'], $rgb['blue']];
                if ($color === $fg) {
                    $row[] = 1;
                } elseif ($color === $bg) {
                    $row[] = 0;
                } else {
                    self::fail(sprintf('Unexpected colour at (%d,%d): #%02x%02x%02x', $x, $y, $color[0], $color[1], $color[2]));
                }
            }
            $pixels[] = $row;
        }

        return $pixels;
    }
}
