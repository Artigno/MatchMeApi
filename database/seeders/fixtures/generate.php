<?php

declare(strict_types=1);

/**
 * One-shot generator for the seed-wardrobe fixture images.
 *
 * Usage: php database/seeders/fixtures/generate.php
 *
 * Renders one 640x640 JPEG per wardrobe row: solid background in the garment's
 * color with a category/brand label. Text is drawn on a 160x160 canvas with the
 * GD built-in font and upscaled 4x — built-in fonts are tiny, upscaling keeps
 * the label readable without shipping a TTF. Labels are ASCII on purpose: the
 * built-in font has no Polish diacritics.
 *
 * Deterministic: same inputs → same files. Requires ext-gd only.
 */
if (! extension_loaded('gd')) {
    fwrite(STDERR, "ext-gd is required.\n");
    exit(1);
}

// [filename, [r, g, b], label line 1 (category), label line 2 (brand)]
$rows = [
    ['01-niebieski-tshirt.jpg', [42, 111, 191], 'GORA', 'ZARA'],
    ['02-biala-koszula.jpg', [242, 242, 242], 'GORA', 'H&M'],
    ['03-granatowe-jeansy.jpg', [31, 42, 68], 'DOL', "LEVI'S"],
    ['04-czarne-spodnie.jpg', [26, 26, 26], 'DOL', 'RESERVED'],
    ['05-biale-sneakersy.jpg', [235, 235, 230], 'BUTY', 'NIKE'],
    ['06-brazowe-buty.jpg', [107, 74, 43], 'BUTY', 'CCC'],
    ['07-czerwony-szalik.jpg', [192, 57, 43], 'AKCESORIUM', '-'],
    ['08-czarna-czapka.jpg', [30, 30, 34], 'AKCESORIUM', '4F'],
    ['09-bezowy-trencz.jpg', [217, 199, 167], 'OKRYCIE', 'ZARA'],
    ['10-zielona-kurtka.jpg', [46, 107, 58], 'OKRYCIE', 'NORTH FACE'],
    ['11-szary-sweter.jpg', [154, 160, 166], 'GORA', 'MEDICINE'],
    ['12-bordowa-spodnica.jpg', [123, 30, 59], 'DOL', 'MANGO'],
];

$small = 160;
$full = 640;
$font = 5; // GD built-in, 9x15 px glyphs

foreach ($rows as [$filename, [$r, $g, $b], $line1, $line2]) {
    $img = imagecreatetruecolor($small, $small);
    imagefill($img, 0, 0, imagecolorallocate($img, $r, $g, $b));

    // Contrast-aware text color (ITU-R BT.601 luma).
    $luma = 0.299 * $r + 0.587 * $g + 0.114 * $b;
    [$tr, $tg, $tb] = $luma > 150 ? [20, 20, 20] : [245, 245, 245];
    $text = imagecolorallocate($img, $tr, $tg, $tb);

    $charW = imagefontwidth($font);
    $charH = imagefontheight($font);

    $drawCentered = function (string $line, int $y) use ($img, $font, $charW, $text, $small): void {
        $x = (int) (($small - strlen($line) * $charW) / 2);
        imagestring($img, $font, max(0, $x), $y, $line, $text);
    };

    $drawCentered($line1, (int) ($small / 2 - $charH - 4));
    $drawCentered($line2, (int) ($small / 2 + 4));

    $scaled = imagescale($img, $full, $full, IMG_NEAREST_NEIGHBOUR);
    imagejpeg($scaled, __DIR__.'/'.$filename, 80);
    imagedestroy($img);
    imagedestroy($scaled);

    echo $filename."\n";
}

echo 'Done: '.count($rows)." fixtures.\n";
