<?php

declare(strict_types=1);

namespace Atelier\Barcode;

use Atelier\Barcode\Exception\InvalidCodeException;
use Atelier\Barcode\Internal\SvgRenderer;

final class Isbn
{
    private int $scale = 2;
    private int $height = 80;
    private int $margin = 11;
    private string $foreground = '#000000';
    private string $background = '#ffffff';

    private function __construct(private readonly string $isbn, private readonly string $ean13)
    {
    }

    public static function create(string $isbn): self
    {
        $normalized = strtoupper((string) preg_replace('/[\s-]+/', '', $isbn));
        if (10 === strlen($normalized)) {
            return self::fromIsbn10($normalized);
        }
        if (13 === strlen($normalized)) {
            return self::fromIsbn13($normalized);
        }

        throw new InvalidCodeException('ISBN expects a valid ISBN-10 or ISBN-13.');
    }

    public function isbn(): string
    {
        return $this->isbn;
    }

    public function value(): string
    {
        return $this->ean13;
    }

    public function ean13(): Ean13
    {
        return $this->eanBuilder();
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

    /**
     * @return list<bool>
     */
    public function modules(): array
    {
        return $this->eanBuilder()->modules();
    }

    public function render(): string
    {
        return $this->eanBuilder()->render();
    }

    private static function fromIsbn10(string $isbn): self
    {
        if (!preg_match('/^\d{9}[\dX]$/', $isbn)) {
            throw new InvalidCodeException('Invalid ISBN-10 characters.');
        }
        $sum = 0;
        for ($i = 0; $i < 10; ++$i) {
            $digit = 'X' === $isbn[$i] ? 10 : (int) $isbn[$i];
            $sum += (10 - $i) * $digit;
        }
        if (0 !== $sum % 11) {
            throw new InvalidCodeException('Invalid ISBN-10 check digit.');
        }

        $body = '978'.substr($isbn, 0, 9);

        return new self($isbn, $body.self::ean13CheckDigit($body));
    }

    private static function fromIsbn13(string $isbn): self
    {
        if (!ctype_digit($isbn) || (!str_starts_with($isbn, '978') && !str_starts_with($isbn, '979'))) {
            throw new InvalidCodeException('Invalid ISBN-13 prefix or characters.');
        }
        if ((int) $isbn[12] !== self::ean13CheckDigit(substr($isbn, 0, 12))) {
            throw new InvalidCodeException('Invalid ISBN-13 check digit.');
        }

        return new self($isbn, $isbn);
    }

    private static function ean13CheckDigit(string $twelve): int
    {
        $sum = 0;
        for ($i = 0; $i < 12; ++$i) {
            $sum += (0 === $i % 2 ? 1 : 3) * (int) $twelve[$i];
        }

        return (10 - $sum % 10) % 10;
    }

    private function eanBuilder(): Ean13
    {
        return Ean13::create($this->ean13)
            ->scale($this->scale)
            ->height($this->height)
            ->margin($this->margin)
            ->foreground($this->foreground)
            ->background($this->background);
    }
}
