<?php

declare(strict_types=1);

namespace Atelier\Barcode;

use Atelier\Barcode\Exception\InvalidCodeException;
use Atelier\Barcode\Internal\SvgRenderer;

final class Code93
{
    private const int MAX_DATA_LENGTH = 512;

    private const string ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ-. $/+%';
    private const string ENCODING_ALPHABET = self::ALPHABET.'abcd*';

    private const array PATTERNS = [
        '0' => '100010100',
        '1' => '101001000',
        '2' => '101000100',
        '3' => '101000010',
        '4' => '100101000',
        '5' => '100100100',
        '6' => '100100010',
        '7' => '101010000',
        '8' => '100010010',
        '9' => '100001010',
        'A' => '110101000',
        'B' => '110100100',
        'C' => '110100010',
        'D' => '110010100',
        'E' => '110010010',
        'F' => '110001010',
        'G' => '101101000',
        'H' => '101100100',
        'I' => '101100010',
        'J' => '100110100',
        'K' => '100011010',
        'L' => '101011000',
        'M' => '101001100',
        'N' => '101000110',
        'O' => '100101100',
        'P' => '100010110',
        'Q' => '110110100',
        'R' => '110110010',
        'S' => '110101100',
        'T' => '110100110',
        'U' => '110010110',
        'V' => '110011010',
        'W' => '101101100',
        'X' => '101100110',
        'Y' => '100110110',
        'Z' => '100111010',
        '-' => '100101110',
        '.' => '111010100',
        ' ' => '111010010',
        '$' => '111001010',
        '/' => '101101110',
        '+' => '101110110',
        '%' => '110101110',
        'a' => '100100110',
        'b' => '111011010',
        'c' => '111010110',
        'd' => '100110010',
        '*' => '101011110',
    ];

    private int $scale = 2;
    private int $height = 80;
    private int $margin = 10;
    private string $foreground = '#000000';
    private string $background = '#ffffff';
    private bool $text = true;
    private bool $checksumText = false;
    /** @var list<bool>|null */
    private ?array $modules = null;

    private function __construct(private readonly string $data)
    {
    }

    /**
     * Creates a Code 93 barcode from visible Code 93 data.
     *
     * Lowercase input is normalized to uppercase. The mandatory Code 93 C/K
     * check characters are computed automatically and encoded in the symbol.
     *
     * @throws InvalidCodeException when the data is empty, too long, or contains unsupported characters
     */
    public static function create(string $data): self
    {
        $data = strtoupper($data);
        if ('' === $data) {
            throw new InvalidCodeException('Code 93 data must not be empty.');
        }
        SvgRenderer::maxPayloadLength('Code 93 data', $data, self::MAX_DATA_LENGTH);
        for ($i = 0, $len = strlen($data); $i < $len; ++$i) {
            if (false === strpos(self::ALPHABET, $data[$i])) {
                throw new InvalidCodeException('Code 93 supports digits, uppercase letters, space, and - . $ / + %.');
            }
        }

        return new self($data);
    }

    /**
     * Returns the normalized payload, without start/stop or C/K check characters.
     */
    public function value(): string
    {
        return $this->data;
    }

    /**
     * Sets the output scale in pixels per barcode module.
     *
     * @throws InvalidCodeException when the scale is not positive
     */
    public function scale(int $modulePixels): self
    {
        $this->scale = SvgRenderer::positiveInt('Scale', $modulePixels);

        return $this;
    }

    /**
     * Sets the barcode bar height in pixels.
     *
     * @throws InvalidCodeException when the height is not positive or exceeds the renderer limit
     */
    public function height(int $pixels): self
    {
        $this->height = SvgRenderer::boundedSide('Height', $pixels);

        return $this;
    }

    /**
     * Sets the quiet zone around the barcode, in modules.
     *
     * @throws InvalidCodeException when the margin is negative or exceeds the renderer limit
     */
    public function margin(int $modules): self
    {
        $this->margin = SvgRenderer::quietZone($modules);

        return $this;
    }

    /**
     * Sets the SVG fill used for barcode bars and optional text.
     */
    public function foreground(string $color): self
    {
        $this->foreground = $color;

        return $this;
    }

    /**
     * Sets the SVG fill used for the background.
     */
    public function background(string $color): self
    {
        $this->background = $color;

        return $this;
    }

    /**
     * Toggles the human-readable caption.
     */
    public function withText(bool $show): self
    {
        $this->text = $show;

        return $this;
    }

    /**
     * Toggles whether the C/K check characters are included in the caption.
     */
    public function withChecksumText(bool $show): self
    {
        $this->checksumText = $show;

        return $this;
    }

    /**
     * Returns the raw bar/space module sequence.
     *
     * @return list<bool>
     */
    public function modules(): array
    {
        return $this->modules ??= $this->build();
    }

    /**
     * Renders the barcode as an SVG string.
     *
     * @throws InvalidCodeException when the computed SVG dimensions exceed renderer limits
     */
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

    /**
     * Returns the mandatory Code 93 C/K check characters.
     */
    public function checksum(): string
    {
        $c = self::checksumCharacter($this->data, 20);

        return $c.self::checksumCharacter($this->data.$c, 15);
    }

    private function caption(): string
    {
        return $this->checksumText ? $this->data.$this->checksum() : $this->data;
    }

    private static function checksumCharacter(string $data, int $maxWeight): string
    {
        $sum = 0;
        $weight = 1;
        for ($i = strlen($data) - 1; $i >= 0; --$i) {
            $sum += $weight * self::valueOf($data[$i]);
            ++$weight;
            if ($weight > $maxWeight) {
                $weight = 1;
            }
        }

        return self::ENCODING_ALPHABET[$sum % 47];
    }

    private static function valueOf(string $character): int
    {
        $value = strpos(self::ENCODING_ALPHABET, $character);
        if (false === $value || $value >= 47) {
            throw new InvalidCodeException(sprintf('Unsupported Code 93 character "%s".', $character));
        }

        return $value;
    }

    /**
     * @return list<bool>
     */
    private function build(): array
    {
        $payload = '*'.$this->data.$this->checksum().'*';
        $bits = '';
        for ($i = 0, $len = strlen($payload); $i < $len; ++$i) {
            $bits .= self::PATTERNS[$payload[$i]];
        }
        $bits .= '1';

        return array_map(static fn (string $c): bool => '1' === $c, str_split($bits));
    }
}
