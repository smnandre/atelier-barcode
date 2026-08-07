<?php

declare(strict_types=1);

namespace Atelier\Barcode\Tests;

use Atelier\Barcode\Ecc;
use Atelier\Barcode\QrCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(QrCode::class)]
#[CoversClass(Ecc::class)]
final class QrCodeTest extends TestCase
{
    public function testCreateRejectsEmptyData(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        QrCode::create('');
    }

    public function testMatrixIsSquareAndSizedByVersion(): void
    {
        $matrix = QrCode::create('HELLO')->matrix();
        $size = count($matrix);

        self::assertSame(21, $size);
        foreach ($matrix as $row) {
            self::assertCount($size, $row);
        }
    }

    public function testHigherErrorCorrectionSelectsLargerVersion(): void
    {
        $low = QrCode::create('atelier!')->ecc(Ecc::Low)->matrix();
        $high = QrCode::create('atelier!')->ecc(Ecc::High)->matrix();

        self::assertCount(21, $low);
        self::assertCount(25, $high);
    }

    public function testNumericModeFitsMoreDataThanByteMode(): void
    {
        $numeric = QrCode::create(str_repeat('1234567890', 7))->ecc(Ecc::Low)->matrix();
        $byte = QrCode::create(str_repeat('abcdefghij', 7))->ecc(Ecc::Low)->matrix();

        self::assertLessThan(count($byte), count($numeric));
    }

    public function testNumericModeEncodesTwoDigitRemainder(): void
    {
        self::assertCount(21, QrCode::create('12')->matrix());
    }

    public function testAlphanumericModeFitsMoreDataThanByteMode(): void
    {
        $alphanumeric = QrCode::create(str_repeat('ATELIER 26', 6))->ecc(Ecc::Low)->matrix();
        $byte = QrCode::create(str_repeat('atelier 26', 6))->ecc(Ecc::Low)->matrix();

        self::assertLessThan(count($byte), count($alphanumeric));
    }

    public function testEncodingIsDeterministic(): void
    {
        $first = QrCode::create('https://smnandre.dev')->ecc(Ecc::Quartile)->matrix();
        $second = QrCode::create('https://smnandre.dev')->ecc(Ecc::Quartile)->matrix();

        self::assertSame($first, $second);
    }

    public function testFinderPatternsArePresent(): void
    {
        $matrix = QrCode::create('HELLO')->matrix();
        $size = count($matrix);

        foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$row, $col]) {
            self::assertTrue($matrix[$row][$col]);
            self::assertTrue($matrix[$row + 2][$col + 2]);
            self::assertFalse($matrix[$row + 1][$col + 1]);
        }
    }

    public function testTimingPatternsAlternate(): void
    {
        $matrix = QrCode::create('HELLO')->matrix();

        self::assertNotSame($matrix[6][8], $matrix[6][9]);
        self::assertNotSame($matrix[8][6], $matrix[9][6]);
    }

    public function testRenderProducesSvgWithQuietZone(): void
    {
        $svg = QrCode::create('HELLO')->size(210)->margin(4)->render();

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringContainsString('width="210"', $svg);
        self::assertStringContainsString('viewBox="0 0 29 29"', $svg);
        self::assertStringContainsString('<rect', $svg);
    }

    public function testRenderAppliesColors(): void
    {
        $svg = QrCode::create('HELLO')->foreground('#08090c')->background('#f4f0e8')->render();

        self::assertStringContainsString('fill="#08090c"', $svg);
        self::assertStringContainsString('fill="#f4f0e8"', $svg);
    }

    public function testBuilderReturnsSameInstance(): void
    {
        $qr = QrCode::create('HELLO');

        self::assertSame($qr, $qr->ecc(Ecc::Medium));
        self::assertSame($qr, $qr->size(256));
        self::assertSame($qr, $qr->margin(2));
        self::assertSame($qr, $qr->foreground('#000000'));
        self::assertSame($qr, $qr->background('#ffffff'));
    }

    public function testModuleCountGrowsWithDataLength(): void
    {
        $short = QrCode::create('HI')->ecc(Ecc::Low)->matrix();
        $long = QrCode::create(str_repeat('DATA ', 60))->ecc(Ecc::Low)->matrix();

        self::assertGreaterThan(count($short), count($long));
    }

    public function testDataExceedingMaximumCapacityThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        QrCode::create(str_repeat('A', 3000))->ecc(Ecc::High)->matrix();
    }

    public function testColorsAreEscapedAgainstAttributeInjection(): void
    {
        $svg = QrCode::create('HELLO')->foreground('"><script>alert(1)</script>')->render();

        self::assertStringNotContainsString('<script>', $svg);
        self::assertStringContainsString('&lt;script&gt;', $svg);
    }

    public function testValidColorSyntaxSurvivesEscaping(): void
    {
        $svg = QrCode::create('HELLO')->foreground('oklch(0.7 0.2 200)')->background('none')->render();

        self::assertStringContainsString('fill="oklch(0.7 0.2 200)"', $svg);
        self::assertStringContainsString('fill="none"', $svg);
    }

    /**
     * mul()'s zero case (GF(256) multiplication by zero is always zero) is part of the
     * Galois-field contract, but is never reached through the public API for realistic
     * Reed-Solomon computation. Tested directly here, same approach as
     * DataMatrixTest::testGaloisFieldMultiplicationByZeroIsZero().
     */
    public function testGaloisFieldMultiplicationByZeroIsZero(): void
    {
        $ref = new \ReflectionClass(QrCode::class);
        $mul = $ref->getMethod('mul');

        self::assertSame(0, $mul->invoke(null, 0, 173));
        self::assertSame(0, $mul->invoke(null, 173, 0));
        self::assertSame(0, $mul->invoke(null, 0, 0));
    }

    public function testModeHelpersCoverVersionThresholds(): void
    {
        $qr = QrCode::create('A');
        $ref = new \ReflectionClass(QrCode::class);
        $characterCountBits = $ref->getMethod('characterCountBits');
        $modeIndicator = $ref->getMethod('modeIndicator');

        self::assertSame(12, $characterCountBits->invoke($qr, 'numeric', 10));
        self::assertSame(14, $characterCountBits->invoke($qr, 'numeric', 27));
        self::assertSame(11, $characterCountBits->invoke($qr, 'alphanumeric', 10));
        self::assertSame(13, $characterCountBits->invoke($qr, 'alphanumeric', 27));
        self::assertSame(16, $characterCountBits->invoke($qr, 'byte', 10));
        self::assertSame(0b0100, $modeIndicator->invoke($qr, 'byte'));
    }

    public function testAlphanumericValueRejectsUnsupportedCharacters(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $alphanumericValue = (new \ReflectionClass(QrCode::class))->getMethod('alphanumericValue');
        $alphanumericValue->invoke(QrCode::create('A'), '@');
    }
}
