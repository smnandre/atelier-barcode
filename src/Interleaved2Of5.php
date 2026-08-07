<?php

declare(strict_types=1);

namespace Atelier\Barcode;

use Atelier\Barcode\Exception\InvalidCodeException;
use Atelier\Barcode\Internal\SvgRenderer;

final class Interleaved2Of5
{
    private const int MAX_DIGITS = 512;

    private const array PATTERNS = [
        '0' => 'nnwwn',
        '1' => 'wnnnw',
        '2' => 'nwnnw',
        '3' => 'wwnnn',
        '4' => 'nnwnw',
        '5' => 'wnwnn',
        '6' => 'nwwnn',
        '7' => 'nnnww',
        '8' => 'wnnwn',
        '9' => 'nwnwn',
    ];

    private int $scale = 2;
    private int $height = 80;
    private int $margin = 10;
    private int $wideRatio = 3;
    private string $foreground = '#000000';
    private string $background = '#ffffff';
    private bool $text = true;
    /** @var list<bool>|null */
    private ?array $modules = null;

    private function __construct(private readonly string $digits)
    {
    }

    public static function create(string $digits): self
    {
        if ('' === $digits) {
            throw new InvalidCodeException('Interleaved 2 of 5 data must not be empty.');
        }
        if (!ctype_digit($digits)) {
            throw new InvalidCodeException('Interleaved 2 of 5 supports digits only.');
        }
        SvgRenderer::maxPayloadLength('Interleaved 2 of 5 data', $digits, self::MAX_DIGITS);
        if (1 === strlen($digits) % 2) {
            throw new InvalidCodeException('Interleaved 2 of 5 expects an even number of digits.');
        }

        return new self($digits);
    }

    public function value(): string
    {
        return $this->digits;
    }

    public function scale(int $modulePixels): self
    {
        $this->scale = SvgRenderer::positiveInt('Scale', $modulePixels);

        return $this;
    }

    public function height(int $pixels): self
    {
        $this->height = SvgRenderer::boundedSide('Height', $pixels);

        return $this;
    }

    public function margin(int $modules): self
    {
        $this->margin = SvgRenderer::quietZone($modules);

        return $this;
    }

    public function wideRatio(int $ratio): self
    {
        if ($ratio < 2 || $ratio > 10) {
            throw new InvalidCodeException('Interleaved 2 of 5 wide ratio must be between 2 and 10.');
        }
        $this->wideRatio = $ratio;
        $this->modules = null;

        return $this;
    }

    public function foreground(string $color): self
    {
        $this->foreground = $color;

        return $this;
    }

    public function background(string $color): self
    {
        $this->background = $color;

        return $this;
    }

    public function withText(bool $show): self
    {
        $this->text = $show;

        return $this;
    }

    /**
     * @return list<bool>
     */
    public function modules(): array
    {
        return $this->modules ??= $this->build();
    }

    public function render(): string
    {
        return SvgRenderer::linear(
            $this->modules(),
            $this->scale,
            $this->height,
            $this->margin,
            $this->foreground,
            $this->background,
            $this->text ? $this->digits : null,
        );
    }

    /**
     * @return list<bool>
     */
    private function build(): array
    {
        $modules = [];
        self::appendModules($modules, true, 1);
        self::appendModules($modules, false, 1);
        self::appendModules($modules, true, 1);
        self::appendModules($modules, false, 1);

        for ($i = 0, $len = strlen($this->digits); $i < $len; $i += 2) {
            $bars = self::PATTERNS[$this->digits[$i]];
            $spaces = self::PATTERNS[$this->digits[$i + 1]];

            for ($p = 0; $p < 5; ++$p) {
                self::appendModules($modules, true, self::moduleWidth($bars[$p], $this->wideRatio));
                self::appendModules($modules, false, self::moduleWidth($spaces[$p], $this->wideRatio));
            }
        }

        self::appendModules($modules, true, $this->wideRatio);
        self::appendModules($modules, false, 1);
        self::appendModules($modules, true, 1);

        return $modules;
    }

    private static function moduleWidth(string $width, int $wideRatio): int
    {
        return 'w' === $width ? $wideRatio : 1;
    }

    /**
     * @param list<bool> $modules
     */
    private static function appendModules(array &$modules, bool $bar, int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $modules[] = $bar;
        }
    }
}
