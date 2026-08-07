<?php

declare(strict_types=1);

namespace Atelier\Barcode;

use Atelier\Barcode\Exception\InvalidCodeException;
use Atelier\Barcode\Internal\SvgRenderer;

final class UpcE
{
    private const array L = [
        '0001101', '0011001', '0010011', '0111101', '0100011',
        '0110001', '0101111', '0111011', '0110111', '0001011',
    ];

    private const array G = [
        '0100111', '0110011', '0011011', '0100001', '0011101',
        '0111001', '0000101', '0010001', '0001001', '0010111',
    ];

    private const array PARITY = [
        0 => [
            'OOOEEE', 'OOEOEE', 'OOEEOE', 'OOEEEO', 'OEOOEE',
            'OEEOOE', 'OEEEOO', 'OEOEOE', 'OEOEEO', 'OEEOEO',
        ],
        1 => [
            'EEEOOO', 'EEOEOO', 'EEOOEO', 'EEOOOE', 'EOEEOO',
            'EOOEEO', 'EOOOEE', 'EOEOEO', 'EOEOOE', 'EOOEOE',
        ],
    ];

    private int $scale = 2;
    private int $height = 72;
    private int $margin = 9;
    private string $foreground = '#000000';
    private string $background = '#ffffff';
    private bool $text = true;
    /** @var list<bool>|null */
    private ?array $modules = null;

    private function __construct(
        private readonly int $numberSystem,
        private readonly string $payload,
        private readonly int $checkDigit,
        private readonly string $expanded,
    ) {
    }

    public static function create(string $code, int $numberSystem = 0): self
    {
        if (!ctype_digit($code)) {
            throw new InvalidCodeException('UPC-E expects digits only.');
        }

        if (6 === strlen($code)) {
            self::assertNumberSystem($numberSystem);
            $expanded = self::expandPayload($numberSystem, $code);

            return new self($numberSystem, $code, UpcA::checkDigit($expanded), $expanded);
        }

        if (7 === strlen($code)) {
            $numberSystem = (int) $code[0];
            self::assertNumberSystem($numberSystem);
            $payload = substr($code, 1);
            $expanded = self::expandPayload($numberSystem, $payload);

            return new self($numberSystem, $payload, UpcA::checkDigit($expanded), $expanded);
        }

        if (8 === strlen($code)) {
            $numberSystem = (int) $code[0];
            self::assertNumberSystem($numberSystem);
            $payload = substr($code, 1, 6);
            $expanded = self::expandPayload($numberSystem, $payload);
            $checkDigit = UpcA::checkDigit($expanded);
            if ((int) $code[7] !== $checkDigit) {
                throw new InvalidCodeException('Invalid UPC-E check digit.');
            }

            return new self($numberSystem, $payload, $checkDigit, $expanded);
        }

        throw new InvalidCodeException('UPC-E expects 6 payload digits, 7 digits with number system, or 8 digits with check digit.');
    }

    public static function fromUpcA(string $code): self
    {
        $upcA = UpcA::create($code)->value();
        $numberSystem = (int) $upcA[0];
        self::assertNumberSystem($numberSystem);
        $manufacturer = substr($upcA, 1, 5);
        $product = substr($upcA, 6, 5);

        if ('00' === substr($manufacturer, 3) && in_array($manufacturer[2], ['0', '1', '2'], true) && '00' === substr($product, 0, 2)) {
            return self::create((string) $numberSystem.$manufacturer[0].$manufacturer[1].substr($product, 2).$manufacturer[2]);
        }

        if ('00' === substr($manufacturer, 3) && '000' === substr($product, 0, 3)) {
            return self::create((string) $numberSystem.substr($manufacturer, 0, 3).substr($product, 3).'3');
        }

        if ('0' === $manufacturer[4] && '0000' === substr($product, 0, 4)) {
            return self::create((string) $numberSystem.substr($manufacturer, 0, 4).$product[4].'4');
        }

        if ('0000' === substr($product, 0, 4) && (int) $product[4] >= 5) {
            return self::create((string) $numberSystem.$manufacturer.$product[4]);
        }

        throw new InvalidCodeException('UPC-A value cannot be compressed to UPC-E.');
    }

    public function value(): string
    {
        return (string) $this->numberSystem.$this->payload.$this->checkDigit;
    }

    public function payload(): string
    {
        return $this->payload;
    }

    public function numberSystem(): int
    {
        return $this->numberSystem;
    }

    public function expandedValue(): string
    {
        return $this->expanded.$this->checkDigit;
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
            $this->text ? $this->value() : null,
        );
    }

    private static function assertNumberSystem(int $numberSystem): void
    {
        if (0 !== $numberSystem && 1 !== $numberSystem) {
            throw new InvalidCodeException('UPC-E number system must be 0 or 1.');
        }
    }

    private static function expandPayload(int $numberSystem, string $payload): string
    {
        $last = $payload[5];
        if ('0' === $last || '1' === $last || '2' === $last) {
            return (string) $numberSystem.$payload[0].$payload[1].$last.'0000'.$payload[2].$payload[3].$payload[4];
        }
        if ('3' === $last) {
            return (string) $numberSystem.substr($payload, 0, 3).'00000'.$payload[3].$payload[4];
        }
        if ('4' === $last) {
            return (string) $numberSystem.substr($payload, 0, 4).'00000'.$payload[4];
        }

        return (string) $numberSystem.substr($payload, 0, 5).'0000'.$last;
    }

    /**
     * @return list<bool>
     */
    private function build(): array
    {
        $parity = self::PARITY[$this->numberSystem][$this->checkDigit];

        $bits = '101';
        for ($i = 0; $i < 6; ++$i) {
            $d = (int) $this->payload[$i];
            $bits .= 'O' === $parity[$i] ? self::L[$d] : self::G[$d];
        }
        $bits .= '010101';

        return array_map(static fn (string $c): bool => '1' === $c, str_split($bits));
    }
}
