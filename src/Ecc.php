<?php

declare(strict_types=1);

namespace Atelier\Barcode;

enum Ecc
{
    case Low;
    case Medium;
    case Quartile;
    case High;

    public function index(): int
    {
        return match ($this) {
            Ecc::Low => 0,
            Ecc::Medium => 1,
            Ecc::Quartile => 2,
            Ecc::High => 3,
        };
    }

    public function formatBits(): int
    {
        return match ($this) {
            Ecc::Low => 0b01,
            Ecc::Medium => 0b00,
            Ecc::Quartile => 0b11,
            Ecc::High => 0b10,
        };
    }
}
