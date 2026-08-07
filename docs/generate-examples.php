<?php

declare(strict_types=1);

/*
 * Regenerates docs/examples/*.svg -- one canonical example per symbology,
 * referenced from the README table. Run: php docs/generate-examples.php
 */

use Atelier\Barcode\Code128;
use Atelier\Barcode\Code39;
use Atelier\Barcode\Code93;
use Atelier\Barcode\Codabar;
use Atelier\Barcode\DataMatrix;
use Atelier\Barcode\Ean8;
use Atelier\Barcode\Ean13;
use Atelier\Barcode\Ecc;
use Atelier\Barcode\Gs1128;
use Atelier\Barcode\Gs1DataMatrix;
use Atelier\Barcode\Interleaved2Of5;
use Atelier\Barcode\Isbn;
use Atelier\Barcode\Itf14;
use Atelier\Barcode\QrCode;
use Atelier\Barcode\UpcA;
use Atelier\Barcode\UpcE;

require __DIR__.'/../vendor/autoload.php';

$outDir = __DIR__.'/examples';
if (!is_dir($outDir) && !mkdir($outDir, 0o755, true) && !is_dir($outDir)) {
    throw new RuntimeException(sprintf('Could not create output directory: %s', $outDir));
}

$examples = [
    'qr-code' => QrCode::create('https://ateliersvg.com')->ecc(Ecc::Quartile)->size(180)->margin(2)->render(),
    'data-matrix' => DataMatrix::create('ATELIER-2026')->size(180)->margin(2)->render(),
    'code-39' => Code39::create('ATELIER-39')->withChecksum(true)->scale(2)->height(60)->render(),
    'code-93' => Code93::create('ATELIER-93')->scale(2)->height(60)->render(),
    'codabar' => Codabar::create('A12345B')->scale(2)->height(60)->render(),
    'code-128' => Code128::create('ATELIER-2026')->scale(2)->height(60)->render(),
    'ean-8' => Ean8::create('5512345')->scale(2)->height(60)->render(),
    'ean-13' => Ean13::create('590123412345')->scale(2)->height(60)->render(),
    'upc-a' => UpcA::create('03600029145')->scale(2)->height(60)->render(),
    'upc-e' => UpcE::create('042100')->scale(2)->height(60)->render(),
    'interleaved-2-of-5' => Interleaved2Of5::create('1234567890')->scale(2)->height(60)->render(),
    'itf-14' => Itf14::create('1001234567890')->scale(2)->height(60)->render(),
    'gs1-128' => Gs1128::create('(01)09501101530003(17)260728(10)BATCH42')->scale(2)->height(60)->render(),
    'gs1-datamatrix' => Gs1DataMatrix::create('(01)09501101530003(17)260728(10)BATCH42')->size(180)->margin(2)->render(),
    'isbn' => Isbn::create('978-0-201-37962-4')->scale(2)->height(60)->render(),
];

foreach ($examples as $slug => $svg) {
    file_put_contents($outDir.'/'.$slug.'.svg', $svg);
}

echo 'Wrote '.count($examples)." examples to {$outDir}\n";
