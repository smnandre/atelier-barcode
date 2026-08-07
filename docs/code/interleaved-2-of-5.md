# Interleaved 2 of 5

| Kind | Charset | Capacity | URL | Phone |
|------|---------|----------|-----|-------|
| 1D linear | Digits only | even-length digits, up to 512 | No | No* |

Dense numeric-only barcode for warehouse, industrial, and distribution workflows. It encodes
digits in pairs, so the input length must be even.

## Example

<img src="../examples/interleaved-2-of-5.svg" width="220">

```php
Interleaved2Of5::create('1234567890')->render();
```

## Usage

```php
use Atelier\Barcode\Interleaved2Of5;

echo Interleaved2Of5::create('1234567890')
    ->wideRatio(3)
    ->scale(2)
    ->height(80)
    ->render();
```

## Options

| Method | Default | Description |
|---|---|---|
| `scale(int)` | `2` | pixels per module |
| `height(int)` | `80` | bar height in pixels |
| `margin(int)` | `10` | quiet zone, in modules |
| `wideRatio(int)` | `3` | wide bar/space ratio, from 2 to 10 |
| `foreground(string)` | `#000000` | bar color |
| `background(string)` | `#ffffff` | background color |
| `withText(bool)` | `true` | show the human-readable value |

`modules()` returns the raw bar pattern. `value()` returns the original digits.

## Limits

- Digits only, non-empty.
- Even number of digits.
- Up to 512 digits.

*Interleaved 2 of 5 has no phone-number semantics; it is numeric transport data.*

---
[← GS1 DataMatrix](gs1-datamatrix.md) · [ITF-14 →](itf-14.md)
