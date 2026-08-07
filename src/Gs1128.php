<?php

declare(strict_types=1);

namespace Atelier\Barcode;

use Atelier\Barcode\Exception\InvalidCodeException;
use Atelier\Barcode\Internal\SvgRenderer;

final class Gs1128
{
    private const int MAX_DATA_LENGTH = 512;
    private const string GS = "\x1D";

    private int $scale = 2;
    private int $height = 80;
    private int $margin = 10;
    private string $foreground = '#000000';
    private string $background = '#ffffff';
    private bool $text = true;
    /** @var list<bool>|null */
    private ?array $modules = null;

    private function __construct(
        private readonly string $payload,
        private readonly string $elementString,
    ) {
    }

    /**
     * @param array<array{0: string, 1: string}|string, string>|string $data
     */
    public static function create(array|string $data): self
    {
        $pairs = is_string($data) ? self::parseCompact($data) : self::parsePairs($data);
        $payload = self::payloadFrom($pairs);
        SvgRenderer::maxPayloadLength('GS1-128 payload', $payload, self::MAX_DATA_LENGTH);

        return new self($payload, self::elementStringFrom($pairs));
    }

    public function payload(): string
    {
        return $this->payload;
    }

    public function elementString(): string
    {
        return $this->elementString;
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
            $this->text ? $this->elementString : null,
        );
    }

    /**
     * @param list<array{ai: string, value: string}> $pairs
     */
    private static function payloadFrom(array $pairs): string
    {
        $payload = '';
        $last = count($pairs) - 1;
        foreach ($pairs as $index => $pair) {
            $payload .= $pair['ai'].$pair['value'];
            if ($index < $last && self::isVariable($pair['ai'])) {
                $payload .= self::GS;
            }
        }

        return $payload;
    }

    /**
     * @param list<array{ai: string, value: string}> $pairs
     */
    private static function elementStringFrom(array $pairs): string
    {
        $value = '';
        foreach ($pairs as $pair) {
            $value .= '('.$pair['ai'].')'.$pair['value'];
        }

        return $value;
    }

    /**
     * @param array<array{0: string, 1: string}|string, string> $data
     *
     * @return list<array{ai: string, value: string}>
     */
    private static function parsePairs(array $data): array
    {
        if ([] === $data) {
            throw new InvalidCodeException('GS1 data must contain at least one application identifier.');
        }

        $pairs = [];
        foreach ($data as $key => $item) {
            if (is_string($key)) {
                if (!is_string($item)) {
                    throw new InvalidCodeException('GS1 associative data values must be strings.');
                }
                $pairs[] = self::validatedPair($key, $item);
                continue;
            }
            if (is_string($item)) {
                $ai = str_pad((string) $key, 2, '0', STR_PAD_LEFT);
                if (in_array($ai, ['01', '10', '17', '21'], true)) {
                    $pairs[] = self::validatedPair($ai, $item);
                    continue;
                }
            }
            if (!is_array($item) || !isset($item[0], $item[1]) || 2 !== count($item)) {
                throw new InvalidCodeException('GS1 data pairs must be [application identifier, value] tuples.');
            }
            if (!is_string($item[0]) || !is_string($item[1])) {
                throw new InvalidCodeException('GS1 data pairs must contain string application identifiers and values.');
            }
            $pairs[] = self::validatedPair($item[0], $item[1]);
        }

        return $pairs;
    }

    /**
     * @return list<array{ai: string, value: string}>
     */
    private static function parseCompact(string $data): array
    {
        if ('' === $data) {
            throw new InvalidCodeException('GS1 data must not be empty.');
        }
        if (!str_starts_with($data, '(')) {
            throw new InvalidCodeException('Compact GS1 data must use the "(AI)value" form.');
        }

        $pairs = [];
        $offset = 0;
        $length = strlen($data);
        while ($offset < $length) {
            $close = strpos($data, ')', $offset + 1);
            if (false === $close) {
                throw new InvalidCodeException('Compact GS1 data contains an unterminated application identifier.');
            }
            $ai = substr($data, $offset + 1, $close - $offset - 1);
            $next = self::nextAiMarker($data, $close + 1);
            $value = substr($data, $close + 1, $next - $close - 1);
            $pairs[] = self::validatedPair($ai, $value);
            $offset = $next;
        }

        return $pairs;
    }

    private static function nextAiMarker(string $data, int $offset): int
    {
        $next = strlen($data);
        foreach (['(01)', '(10)', '(17)', '(21)'] as $marker) {
            $position = strpos($data, $marker, $offset);
            if (false !== $position && $position < $next) {
                $next = $position;
            }
        }

        return $next;
    }

    /**
     * @return array{ai: string, value: string}
     */
    private static function validatedPair(string $ai, string $value): array
    {
        if (!in_array($ai, ['01', '10', '17', '21'], true)) {
            throw new InvalidCodeException(sprintf('Unsupported GS1 application identifier "%s".', $ai));
        }
        if ('' === $value) {
            throw new InvalidCodeException(sprintf('GS1 application identifier "%s" must not be empty.', $ai));
        }

        match ($ai) {
            '01' => self::validateGtin($value),
            '17' => self::validateExpiry($value),
            '10', '21' => self::validateVariable($ai, $value),
        };

        return ['ai' => $ai, 'value' => $value];
    }

    private static function validateGtin(string $value): void
    {
        if (!ctype_digit($value) || 14 !== strlen($value)) {
            throw new InvalidCodeException('GS1 application identifier "01" expects a 14-digit GTIN.');
        }
        if ((int) $value[13] !== self::mod10(substr($value, 0, 13))) {
            throw new InvalidCodeException('Invalid GTIN check digit for GS1 application identifier "01".');
        }
    }

    private static function validateExpiry(string $value): void
    {
        if (!ctype_digit($value) || 6 !== strlen($value)) {
            throw new InvalidCodeException('GS1 application identifier "17" expects a YYMMDD date.');
        }

        $month = (int) substr($value, 2, 2);
        $day = (int) substr($value, 4, 2);
        if (!checkdate($month, $day, 2000 + (int) substr($value, 0, 2))) {
            throw new InvalidCodeException('GS1 application identifier "17" expects a valid YYMMDD date.');
        }
    }

    private static function validateVariable(string $ai, string $value): void
    {
        if (strlen($value) > 20) {
            throw new InvalidCodeException(sprintf('GS1 application identifier "%s" must not exceed 20 characters.', $ai));
        }
        for ($i = 0, $length = strlen($value); $i < $length; ++$i) {
            $ordinal = ord($value[$i]);
            if ($ordinal < 32 || $ordinal > 126 || '(' === $value[$i] || ')' === $value[$i]) {
                throw new InvalidCodeException(sprintf('GS1 application identifier "%s" contains unsupported characters.', $ai));
            }
        }
    }

    private static function mod10(string $digits): int
    {
        $sum = 0;
        $weight = 3;
        for ($i = strlen($digits) - 1; $i >= 0; --$i) {
            $sum += $weight * (int) $digits[$i];
            $weight = 4 - $weight;
        }

        return (10 - $sum % 10) % 10;
    }

    private static function isVariable(string $ai): bool
    {
        return '10' === $ai || '21' === $ai;
    }

    /**
     * @return list<bool>
     */
    private function build(): array
    {
        $values = $this->encode();

        $checksum = $values[0];
        for ($i = 1, $count = count($values); $i < $count; ++$i) {
            $checksum += $i * $values[$i];
        }
        $values[] = $checksum % 103;

        $bits = '';
        foreach ($values as $value) {
            $bits .= self::PATTERNS[$value];
        }
        $bits .= self::STOP.'11';

        return array_map(static fn (string $c): bool => '1' === $c, str_split($bits));
    }

    /**
     * @return list<int>
     */
    private function encode(): array
    {
        $data = $this->payload;
        $len = strlen($data);
        $leadingDigits = static function (int $p) use ($data, $len): int {
            $n = 0;
            while ($p + $n < $len && ctype_digit($data[$p + $n])) {
                ++$n;
            }

            return $n;
        };

        if ((ctype_digit($data) && 0 === $len % 2) || $leadingDigits(0) >= 4) {
            $charset = 'C';
            $values = [105, 102];
        } else {
            $charset = 'B';
            $values = [104, 102];
        }

        $i = 0;
        while ($i < $len) {
            if (self::GS === $data[$i]) {
                $values[] = 102;
                ++$i;
                continue;
            }
            if ('C' === $charset) {
                if ($i + 1 < $len && ctype_digit($data[$i]) && ctype_digit($data[$i + 1])) {
                    $values[] = (int) substr($data, $i, 2);
                    $i += 2;
                    continue;
                }
                $values[] = 100;
                $charset = 'B';
                continue;
            }

            $lead = $leadingDigits($i);
            if ($lead >= 4 || ($lead >= 2 && 0 === $lead % 2 && $i + $lead === $len)) {
                $values[] = 99;
                $charset = 'C';
                continue;
            }

            $ordinal = ord($data[$i]);
            $values[] = $ordinal - 32;
            ++$i;
        }

        return $values;
    }

    // 11-module bar/space patterns per Code 128 value 0..106 (1 = bar module).
    private const array PATTERNS = [
        '11011001100', '11001101100', '11001100110', '10010011000', '10010001100', '10001001100',
        '10011001000', '10011000100', '10001100100', '11001001000', '11001000100', '11000100100',
        '10110011100', '10011011100', '10011001110', '10111001100', '10011101100', '10011100110',
        '11001110010', '11001011100', '11001001110', '11011100100', '11001110100', '11101101110',
        '11101001100', '11100101100', '11100100110', '11101100100', '11100110100', '11100110010',
        '11011011000', '11011000110', '11000110110', '10100011000', '10001011000', '10001000110',
        '10110001000', '10001101000', '10001100010', '11010001000', '11000101000', '11000100010',
        '10110111000', '10110001110', '10001101110', '10111011000', '10111000110', '10001110110',
        '11101110110', '11010001110', '11000101110', '11011101000', '11011100010', '11011101110',
        '11101011000', '11101000110', '11100010110', '11101101000', '11101100010', '11100011010',
        '11101111010', '11001000010', '11110001010', '10100110000', '10100001100', '10010110000',
        '10010000110', '10000101100', '10000100110', '10110010000', '10110000100', '10011010000',
        '10011000010', '10000110100', '10000110010', '11000010010', '11001010000', '11110111010',
        '11000010100', '10001111010', '10100111100', '10010111100', '10010011110', '10111100100',
        '10011110100', '10011110010', '11110100100', '11110010100', '11110010010', '11011011110',
        '11011110110', '11110110110', '10101111000', '10100011110', '10001011110', '10111101000',
        '10111100010', '11110101000', '11110100010', '10111011110', '10111101110', '11101011110',
        '11110101110', '11010000100', '11010010000', '11010011100',
    ];

    private const string STOP = '11000111010';
}
