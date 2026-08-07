<?php

declare(strict_types=1);

namespace Atelier\Barcode;

use Atelier\Barcode\Exception\InvalidCodeException;
use Atelier\Barcode\Internal\SvgRenderer;

final class Codabar
{
    private const int MAX_DATA_LENGTH = 512;

    private const string BODY_ALPHABET = '0123456789-$:/.+';
    private const string GUARDS = 'ABCD';

    private const array PATTERNS = [
        '0' => '101010011',
        '1' => '101011001',
        '2' => '101001011',
        '3' => '110010101',
        '4' => '101101001',
        '5' => '110101001',
        '6' => '100101011',
        '7' => '100101101',
        '8' => '100110101',
        '9' => '110100101',
        '-' => '101001101',
        '$' => '101100101',
        ':' => '1101011011',
        '/' => '1101101011',
        '.' => '1101101101',
        '+' => '101100110011',
        'A' => '1011001001',
        'B' => '1001001011',
        'C' => '1010010011',
        'D' => '1010011001',
    ];

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
        private readonly string $start,
        private readonly string $stop,
    ) {
    }

    /**
     * Creates a Codabar barcode from either guarded data or a bare payload.
     *
     * If the input starts and ends with A-D guards, they are preserved. Otherwise
     * the payload is wrapped with A...A guards.
     *
     * @throws InvalidCodeException when the data is empty, too long, or contains unsupported characters
     */
    public static function create(string $data): self
    {
        $data = strtoupper($data);
        if ('' === $data) {
            throw new InvalidCodeException('Codabar data must not be empty.');
        }
        SvgRenderer::maxPayloadLength('Codabar data', $data, self::MAX_DATA_LENGTH + 2);

        if (strlen($data) >= 2 && self::isGuard($data[0]) && self::isGuard($data[strlen($data) - 1])) {
            return self::withGuards(substr($data, 1, -1), $data[0], $data[strlen($data) - 1]);
        }

        return self::withGuards($data, 'A', 'A');
    }

    /**
     * Creates a Codabar barcode with explicit start and stop guards.
     *
     * @throws InvalidCodeException when the payload is empty, too long, has invalid characters, or guards are not A-D
     */
    public static function withGuards(string $payload, string $start = 'A', string $stop = 'A'): self
    {
        $payload = strtoupper($payload);
        $start = strtoupper($start);
        $stop = strtoupper($stop);

        if ('' === $payload) {
            throw new InvalidCodeException('Codabar payload must not be empty.');
        }
        SvgRenderer::maxPayloadLength('Codabar payload', $payload, self::MAX_DATA_LENGTH);
        if (!self::isGuard($start) || !self::isGuard($stop) || 1 !== strlen($start) || 1 !== strlen($stop)) {
            throw new InvalidCodeException('Codabar guards must be one of A, B, C, or D.');
        }

        for ($i = 0, $len = strlen($payload); $i < $len; ++$i) {
            if (false === strpos(self::BODY_ALPHABET, $payload[$i])) {
                throw new InvalidCodeException('Codabar payload supports digits and - $ : / . +.');
            }
        }

        return new self($payload, $start, $stop);
    }

    /**
     * Returns the complete guarded Codabar value.
     */
    public function value(): string
    {
        return $this->start.$this->payload.$this->stop;
    }

    /**
     * Returns the payload without start and stop guards.
     */
    public function payload(): string
    {
        return $this->payload;
    }

    /**
     * Returns the start guard character.
     */
    public function start(): string
    {
        return $this->start;
    }

    /**
     * Returns the stop guard character.
     */
    public function stop(): string
    {
        return $this->stop;
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
            $this->text ? $this->value() : null,
        );
    }

    private static function isGuard(string $character): bool
    {
        return 1 === strlen($character) && str_contains(self::GUARDS, $character);
    }

    /**
     * @return list<bool>
     */
    private function build(): array
    {
        $characters = $this->value();
        $modules = [];
        for ($i = 0, $len = strlen($characters); $i < $len; ++$i) {
            if ($i > 0) {
                $modules[] = false;
            }
            foreach (str_split(self::PATTERNS[$characters[$i]]) as $bit) {
                $modules[] = '1' === $bit;
            }
        }

        return $modules;
    }
}
