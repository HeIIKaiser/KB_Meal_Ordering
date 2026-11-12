<?php

/**
 * Regenerates the PWA icons in public/icons (run locally: php tools/make-icons.php).
 * Simple, dependency-free: green tile with a neutral "people" pictogram (the app is
 * meant to grow beyond meal ordering, so no food symbol).
 */
$out = __DIR__.'/../public/icons';

function icon(int $size, bool $maskable, bool $rounded): GdImage
{
    $s = 4; // supersampling
    $n = $size * $s;
    $im = imagecreatetruecolor($n, $n);
    imagesavealpha($im, true);
    imagealphablending($im, true);
    $transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
    imagefill($im, 0, 0, $transparent);

    $green = imagecolorallocate($im, 4, 120, 87);   // #047857
    $white = imagecolorallocate($im, 255, 255, 255);

    if ($rounded) {
        $r = (int) ($n * 0.22);
        imagefilledrectangle($im, $r, 0, $n - $r, $n, $green);
        imagefilledrectangle($im, 0, $r, $n, $n - $r, $green);
        foreach ([[$r, $r], [$n - $r, $r], [$r, $n - $r], [$n - $r, $n - $r]] as [$x, $y]) {
            imagefilledellipse($im, $x, $y, 2 * $r, 2 * $r, $green);
        }
    } else {
        imagefilledrectangle($im, 0, 0, $n, $n, $green);
    }

    // Content scale: maskable icons keep everything inside the central 80 % safe zone.
    // Neutral "people" pictogram (not food-specific): a person in front, a second one behind.
    $k = $maskable ? 0.74 : 0.92;
    $c = $n / 2;
    $light = imagecolorallocate($im, 167, 243, 208); // #a7f3d0

    $person = function (float $x, float $y, float $scale, int $color, ?int $ring) use ($im, $n, $k) {
        $head = $n * 0.2 * $k * $scale;
        $bodyW = $n * 0.5 * $k * $scale;
        $bodyH = $n * 0.46 * $k * $scale;
        $headY = $y - $n * 0.13 * $k * $scale;
        $bodyY = $y + $n * 0.22 * $k * $scale;
        if ($ring !== null) { // green outline separating the front person from the one behind
            $g = $n * 0.035 * $k;
            imagefilledellipse($im, (int) $x, (int) $headY, (int) ($head + 2 * $g), (int) ($head + 2 * $g), $ring);
            imagefilledarc($im, (int) $x, (int) $bodyY, (int) ($bodyW + 2 * $g), (int) ($bodyH + 2 * $g), 180, 360, $ring, IMG_ARC_PIE);
        }
        imagefilledellipse($im, (int) $x, (int) $headY, (int) $head, (int) $head, $color);
        imagefilledarc($im, (int) $x, (int) $bodyY, (int) $bodyW, (int) $bodyH, 180, 360, $color, IMG_ARC_PIE);
    };

    $person($c + $n * 0.15 * $k, $c - $n * 0.03 * $k, 0.82, $light, null);
    $person($c - $n * 0.08 * $k, $c + $n * 0.02 * $k, 1.0, $white, $green);

    $final = imagecreatetruecolor($size, $size);
    imagesavealpha($final, true);
    imagealphablending($final, false);
    imagefill($final, 0, 0, imagecolorallocatealpha($final, 0, 0, 0, 127));
    imagecopyresampled($final, $im, 0, 0, 0, 0, $size, $size, $n, $n);

    return $final;
}

foreach ([
    'icon-192.png' => [192, false, true],
    'icon-512.png' => [512, false, true],
    'icon-maskable-512.png' => [512, true, false],
    'apple-touch-icon.png' => [180, false, false], // iOS rounds corners itself
] as $file => [$size, $maskable, $rounded]) {
    imagepng(icon($size, $maskable, $rounded), "$out/$file", 9);
    echo "public/icons/$file\n";
}
