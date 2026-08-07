# EAN-8

| Kind | Charset | Capacity | URL | Phone |
|------|---------|----------|-----|-------|
| 1D linear | Digits only | 7-8 digits | No | No* |

Compact retail barcode for small products. Give it 7 digits and the check digit is computed;
give it 8 and the check digit is validated.

## Example

<img src="../examples/ean-8.svg" width="180">

```php
Ean8::create('5512345')->render();
```

## Usage

```php
use Atelier\Barcode\Ean8;

echo Ean8::create('5512345')
    ->scale(2)
    ->height(72)
    ->render();
```

## Options

| Method | Default | Description |
|---|---|---|
| `scale(int)` | `2` | pixels per module |
| `height(int)` | `72` | bar height in pixels |
| `margin(int)` | `7` | quiet zone, in modules |
| `foreground(string)` | `#000000` | bar color |
| `background(string)` | `#ffffff` | background color |
| `withText(bool)` | `true` | show the human-readable value |

`modules()` returns the raw bar pattern. `value()` returns the full 8-digit code.

## Limits

- Exactly 7 or 8 digits, nothing else: throws `InvalidCodeException` otherwise.
- If 8 digits are given, the 8th must be the correct check digit.

*EAN-8 has no phone-number semantics; it is digits-only product identification.*

---
[← Data Matrix](data-matrix.md) · [EAN-13 →](ean-13.md)
