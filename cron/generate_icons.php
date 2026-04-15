<?php
/**
 * Generate PWA icons for SparkSpend
 *
 * Renders icon-192.png and icon-512.png in images/ by replicating the
 * favicon.svg design using PHP GD:
 *   - Cobalt blue filled circle background (#0047AB)
 *   - Four green bar-chart bars (#4caf50)
 *   - Gold lightning bolt polygon (#FFD700)
 *
 * Usage: php cron/generate_icons.php
 * Run once after deployment; committed PNGs can be reused indefinitely.
 *
 * Requires: PHP GD extension (php-gd), enabled on most hosts.
 */

if (!extension_loaded('gd')) {
    fwrite(STDERR, "Error: PHP GD extension is not available.\n");
    exit(1);
}

$outDir = __DIR__ . '/../images';
if (!is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}

$sizes = [192, 512];

foreach ($sizes as $size) {
    $img = generateIcon($size);
    $path = $outDir . '/icon-' . $size . '.png';

    if (imagepng($img, $path)) {
        echo "OK  images/icon-{$size}.png\n";
    } else {
        fwrite(STDERR, "FAIL images/icon-{$size}.png (check write permissions)\n");
    }
    imagedestroy($img);
}

/**
 * Draw the SparkSpend icon at the given pixel size.
 * All coordinates are defined in a 64×64 SVG coordinate space and scaled up.
 */
function generateIcon(int $size): GdImage
{
    $s = $size / 64.0; // scale factor

    $img = imagecreatetruecolor($size, $size);

    // Transparent background (for maskable safe-zone padding)
    imagealphablending($img, false);
    imagesavealpha($img, true);
    $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
    imagefill($img, 0, 0, $transparent);
    imagealphablending($img, true);

    // ── Colours ──────────────────────────────────────────────────────────────
    $cobalt = imagecolorallocate($img, 0x00, 0x47, 0xAB);  // #0047AB
    $green  = imagecolorallocate($img, 0x4C, 0xAF, 0x50);  // #4caf50
    $gold   = imagecolorallocate($img, 0xFF, 0xD7, 0x00);  // #FFD700

    // ── Background circle (cx=32, cy=32, r=30) ────────────────────────────
    $cx = (int) round(32 * $s);
    $cy = (int) round(32 * $s);
    $d  = (int) round(60 * $s); // diameter = r*2
    imagefilledellipse($img, $cx, $cy, $d, $d, $cobalt);

    // ── Bar chart bars ────────────────────────────────────────────────────
    // [x, y, width, height] in SVG 64×64 space
    $bars = [
        [12, 40, 4, 12],
        [20, 35, 4, 17],
        [28, 30, 4, 22],
        [36, 38, 4, 14],
    ];
    foreach ($bars as [$bx, $by, $bw, $bh]) {
        imagefilledrectangle(
            $img,
            (int) round($bx * $s),
            (int) round($by * $s),
            (int) round(($bx + $bw) * $s),
            (int) round(($by + $bh) * $s),
            $green
        );
    }

    // ── Lightning bolt polygon ────────────────────────────────────────────
    // SVG path: M28 12 L18 36 L32 36 L24 52 L44 28 L30 28 Z
    $svgPoints = [28, 12, 18, 36, 32, 36, 24, 52, 44, 28, 30, 28];
    $gdPoints  = array_map(fn(int $v) => (int) round($v * $s), $svgPoints);

    // 4-parameter form is compatible with PHP 7 and PHP 8
    imagefilledpolygon($img, $gdPoints, count($gdPoints) / 2, $gold);

    return $img;
}
?>
