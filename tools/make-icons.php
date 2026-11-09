<?php

/**
 * Regenerates the PWA icons in public/icons (run locally: php tools/make-icons.php).
 * Simple, dependency-free: green tile with a white plate + fork & knife.
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
    $amber = imagecolorallocate($im, 251, 191, 36);

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
    $k = $maskable ? 0.72 : 0.9;
    $c = $n / 2;
    $plate = (int) ($n * 0.52 * $k);
    imagefilledellipse($im, (int) $c, (int) $c, $plate, $plate, $white);
    imagefilledellipse($im, (int) $c, (int) $c, (int) ($plate * 0.72), (int) ($plate * 0.72), $amber);
    imagefilledellipse($im, (int) $c, (int) $c, (int) ($plate * 0.62), (int) ($plate * 0.62), $white);

    $h = (int) ($n * 0.56 * $k);
    $w = (int) ($n * 0.045 * $k);
    $top = (int) ($c - $h / 2);
    // fork (left)
    $fx = (int) ($c - $n * 0.36 * $k);
    imagefilledrectangle($im, $fx - $w / 2, $top + (int) ($h * 0.35), $fx + $w / 2, $top + $h, $white);
    for ($i = -1; $i <= 1; $i++) {
        $tx = $fx + $i * (int) ($w * 1.4);
        imagefilledrectangle($im, $tx - (int) ($w * 0.3), $top, $tx + (int) ($w * 0.3), $top + (int) ($h * 0.3), $white);
    }
    imagefilledrectangle($im, $fx - (int) ($w * 1.7), $top + (int) ($h * 0.28), $fx + (int) ($w * 1.7), $top + (int) ($h * 0.38), $white);
    // knife (right)
    $kx = (int) ($c + $n * 0.36 * $k);
    imagefilledrectangle($im, $kx - $w / 2, $top + (int) ($h * 0.45), $kx + $w / 2, $top + $h, $white);
    imagefilledellipse($im, $kx, $top + (int) ($h * 0.22), (int) ($w * 2.2), (int) ($h * 0.5), $white);

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
