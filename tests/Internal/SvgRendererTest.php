<?php

declare(strict_types=1);

namespace Atelier\Barcode\Tests\Internal;

use Atelier\Barcode\Exception\InvalidCodeException;
use Atelier\Barcode\Internal\SvgRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SvgRenderer::class)]
#[CoversClass(InvalidCodeException::class)]
final class SvgRendererTest extends TestCase
{
    public function testAttributeEscapesXmlSpecialCharacters(): void
    {
        self::assertSame('&quot;&gt;&lt;&amp;', SvgRenderer::attribute('"><&'));
    }

    public function testPositiveIntRejectsZero(): void
    {
        $this->expectException(InvalidCodeException::class);

        SvgRenderer::positiveInt('Scale', 0);
    }

    public function testNonNegativeIntRejectsNegativeValue(): void
    {
        $this->expectException(InvalidCodeException::class);

        SvgRenderer::nonNegativeInt('Margin', -1);
    }

    public function testQuietZoneRejectsOversizedMargin(): void
    {
        $this->expectException(InvalidCodeException::class);

        SvgRenderer::quietZone(SvgRenderer::MAX_MARGIN + 1);
    }

    public function testBoundedSideRejectsOversizedValue(): void
    {
        $this->expectException(InvalidCodeException::class);

        SvgRenderer::boundedSide('Size', SvgRenderer::MAX_SIDE + 1);
    }

    public function testMaxPayloadLengthRejectsOversizedPayload(): void
    {
        $this->expectException(InvalidCodeException::class);

        SvgRenderer::maxPayloadLength('Payload', 'abc', 2);
    }

    public function testMaxPayloadLengthReturnsValidPayload(): void
    {
        self::assertSame('abc', SvgRenderer::maxPayloadLength('Payload', 'abc', 3));
    }

    public function testMatrixRendererProducesSvg(): void
    {
        $svg = SvgRenderer::matrix([[true, false], [false, true]], 20, 1, '#000', '#fff');

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringContainsString('viewBox="0 0 4 4"', $svg);
        self::assertStringContainsString('<rect x="1" y="1" width="1" height="1"/>', $svg);
    }

    public function testLinearRendererProducesSvgWithoutCaption(): void
    {
        $svg = SvgRenderer::linear([true, false, true], 2, 10, 1, '#000', '#fff', null);

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringNotContainsString('<text', $svg);
    }

    public function testLinearRendererProducesSvgWithCaption(): void
    {
        $svg = SvgRenderer::linear([true, false, true], 2, 10, 1, '#000', '#fff', 'A&B');

        self::assertStringContainsString('<text', $svg);
        self::assertStringContainsString('A&amp;B', $svg);
    }

    public function testOutputBoundsRejectInvalidArea(): void
    {
        $this->expectException(InvalidCodeException::class);

        SvgRenderer::ensureOutputBounds(10000, 10000);
    }

    public function testOutputBoundsRejectInvalidDimensions(): void
    {
        $this->expectException(InvalidCodeException::class);

        SvgRenderer::ensureOutputBounds(0, 10);
    }

    public function testOutputBoundsRejectOversizedSide(): void
    {
        $this->expectException(InvalidCodeException::class);

        SvgRenderer::ensureOutputBounds(SvgRenderer::MAX_SIDE + 1, 10);
    }
}
