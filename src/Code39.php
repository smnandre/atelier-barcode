<?php

declare(strict_types=1);

namespace Atelier\Barcode;

use Atelier\Barcode\Exception\InvalidCodeException;
use Atelier\Barcode\Internal\SvgRenderer;

final class Code39
{
    private const int MAX_DATA_LENGTH = 512;

    private const string ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ-. $/+%';

    private const array PATTERNS = [
        '0' => 'nnnwwnwnn',
        '1' => 'wnnwnnnnw',
        '2' => 'nnwwnnnnw',
        '3' => 'wnwwnnnnn',
        '4' => 'nnnwwnnnw',
        '5' => 'wnnwwnnnn',
        '6' => 'nnwwwnnnn',
        '7' => 'nnnwnnwnw',
        '8' => 'wnnwnnwnn',
        '9' => 'nnwwnnwnn',
        'A' => 'wnnnnwnnw',
        'B' => 'nnwnnwnnw',
        'C' => 'wnwnnwnnn',
        'D' => 'nnnnwwnnw',
        'E' => 'wnnnwwnnn',
        'F' => 'nnwnwwnnn',
        'G' => 'nnnnnwwnw',
        'H' => 'wnnnnwwnn',
        'I' => 'nnwnnwwnn',
        'J' => 'nnnnwwwnn',
        'K' => 'wnnnnnnww',
        'L' => 'nnwnnnnww',
        'M' => 'wnwnnnnwn',
        'N' => 'nnnnwnnww',
        'O' => 'wnnnwnnwn',
        'P' => 'nnwnwnnwn',
        'Q' => 'nnnnnnwww',
        'R' => 'wnnnnnwwn',
        'S' => 'nnwnnnwwn',
        'T' => 'nnnnwnwwn',
        'U' => 'wwnnnnnnw',
        'V' => 'nwwnnnnnw',
        'W' => 'wwwnnnnnn',
        'X' => 'nwnnwnnnw',
        'Y' => 'wwnnwnnnn',
        'Z' => 'nwwnwnnnn',
        '-' => 'nwnnnnwnw',
        '.' => 'wwnnnnwnn',
        ' ' => 'nwwnnnwnn',
        '$' => 'nwnwnwnnn',
        '/' => 'nwnwnnnwn',
        '+' => 'nwnnnwnwn',
        '%' => 'nnnwnwnwn',
        '*' => 'nwnnwnwnn',
    ];

    private int $scale = 2;
    private int $height = 80;
    private int $margin = 10;
    private int $wideRatio = 3;
    private string $foreground = '#000000';
    private string $background = '#ffffff';
    private bool $text = true;
    private bool $checksum = false;
    /** @var list<bool>|null */
    private ?array $modules = null;

    private function __construct(private readonly string $data)
    {
    }

    public static function create(string $data): self
    {
        $data = strtoupper($data);
        if ('' === $data) {
            throw new InvalidCodeException('Code 39 data must not be empty.');
        }
        SvgRenderer::maxPayloadLength('Code 39 data', $data, self::MAX_DATA_LENGTH);
        if (str_contains($data, '*')) {
            throw new InvalidCodeException('Code 39 data must not contain the start/stop character.');
        }
        for ($i = 0, $len = strlen($data); $i < $len; ++$i) {
            if (!isset(self::PATTERNS[$data[$i]])) {
                throw new InvalidCodeException('Code 39 supports digits, uppercase letters, space, and - . $ / + %.');
            }
        }

        return new self($data);
    }

    public function value(): string
    {
        return $this->data;
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
        if ($ratio < 2) {
            throw new InvalidCodeException('Code 39 wide ratio must be at least 2.');
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

    public function withChecksum(bool $checksum): self
    {
        $this->checksum = $checksum;
        $this->modules = null;

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
            $this->text ? $this->caption() : null,
        );
    }

    private function caption(): string
    {
        return $this->checksum ? $this->data.$this->checksumCharacter() : $this->data;
    }

    private function checksumCharacter(): string
    {
        $sum = 0;
        for ($i = 0, $len = strlen($this->data); $i < $len; ++$i) {
            $sum += strpos(self::ALPHABET, $this->data[$i]);
        }

        return self::ALPHABET[$sum % 43];
    }

    /**
     * @return list<bool>
     */
    private function build(): array
    {
        $payload = '*'.$this->caption().'*';
        $modules = [];
        for ($i = 0, $chars = strlen($payload); $i < $chars; ++$i) {
            if ($i > 0) {
                $modules[] = false;
            }
            $pattern = self::PATTERNS[$payload[$i]];
            for ($p = 0; $p < 9; ++$p) {
                $isBar = 0 === $p % 2;
                $width = 'w' === $pattern[$p] ? $this->wideRatio : 1;
                for ($j = 0; $j < $width; ++$j) {
                    $modules[] = $isBar;
                }
            }
        }

        return $modules;
    }
}
