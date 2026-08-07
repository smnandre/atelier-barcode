# QR Code

| Kind | Charset | Capacity | URL | Phone |
|------|---------|----------|-----|-------|
| 2D matrix | Numeric, alphanumeric, or byte | up to version 40* | Yes | Yes |

The most recognizable 2D symbol. Automatically picks numeric, alphanumeric, or byte mode,
then selects the smallest version (1-40) that fits the data at the chosen error-correction
level and applies masking for reliable scanning.

## Example

<img src="../examples/qr-code.svg" width="150">

```php
QrCode::create('https://ateliersvg.com')->ecc(Ecc::Quartile)->render();
```

## Usage

```php
use Atelier\Barcode\Ecc;
use Atelier\Barcode\QrCode;

$svg = QrCode::create('https://ateliersvg.com')
    ->ecc(Ecc::Medium)
    ->size(240)
    ->margin(4)
    ->render();
```

## Options

| Method | Default | Description |
|---|---|---|
| `ecc(Ecc)` | `Ecc::Medium` | error-correction level, see below |
| `size(int)` | `320` | target width/height in pixels |
| `margin(int)` | `4` | quiet zone, in modules |
| `foreground(string)` | `#000000` | module color |
| `background(string)` | `#ffffff` | background color |

`matrix()` returns the module grid as a 2D array of booleans instead of SVG. Numeric data uses
QR numeric compaction; uppercase QR-alphanumeric data uses alphanumeric compaction; everything
else falls back to byte mode.

### Error correction

```php
Ecc::Low       // ~7%   recoverable, 2953-byte max
Ecc::Medium    // ~15%,  2331-byte max
Ecc::Quartile  // ~25%,  1663-byte max
Ecc::High      // ~30%,  1273-byte max
```

Higher correction stores more redundancy, so the same string may select a larger version.

## Limits

- Empty data throws `InvalidCodeException`.
- Data whose encoded length exceeds the chosen ECC level's version-40 capacity throws.

*The byte-mode ceiling is 2953 bytes at version 40, `Ecc::Low`; numeric and alphanumeric data
can fit more characters because they use QR compaction modes. Higher error-correction levels
lower the ceiling.*

---
[← ISBN](isbn.md)
