<?php
declare(strict_types=1);

// LOGO1: builds the navbar lockup from public/assets/images/brand/logo-full.png (left untouched).
// Run: php -d extension=gd tools/logo_build.php [crop-folder]
// - trims the fully transparent margins exactly (every pixel with any opacity is kept)
// - writes logo-full-<h>.webp/.png for the header heights at 1x/2x/3x: phones 44px (the name is 25.2% of the height: 44px = an 11px name, the floor), desktop 56px
// - rewrites email-logo.png (the emails' 2x picture, same 264px height as before)
// - with a crop folder: a 400% crop of a letter edge on the cream header colour (#fffdd0), for the halo check
// Halo check (LOGO1): the letter edge is a dark-brown bevel line that belongs to the artwork, outside it only a faint gold glow (2-12% opacity);
// there is no red/orange background-removal fringe, so no pixel is recoloured.
if (!extension_loaded('gd') || !function_exists('imagewebp')) {
    fwrite(STDERR, "needs GD with WebP: php -d extension=gd tools/logo_build.php\n");
    exit(1);
}
$dir = dirname(__DIR__) . '/public/assets/images/brand/';
$src = imagecreatefrompng($dir . 'logo-full.png');
$w = imagesx($src);
$h = imagesy($src);

// exact trim: the box of every pixel that is not fully transparent (GD alpha 127 = transparent)
[$minX, $minY, $maxX, $maxY] = [$w, $h, -1, -1];
for ($y = 0; $y < $h; $y++) {
    for ($x = 0; $x < $w; $x++) {
        if (((imagecolorat($src, $x, $y) >> 24) & 127) < 127) {
            $minX = min($minX, $x);
            $maxX = max($maxX, $x);
            $minY = min($minY, $y);
            $maxY = max($maxY, $y);
        }
    }
}
$tw = $maxX - $minX + 1;
$th = $maxY - $minY + 1;
$trim = imagecreatetruecolor($tw, $th);
imagealphablending($trim, false);
imagesavealpha($trim, true);
imagecopy($trim, $src, 0, 0, $minX, $minY, $tw, $th);
echo "trimmed {$w}x{$h} -> {$tw}x{$th} (box {$minX},{$minY}..{$maxX},{$maxY})\n";

$resize = static function (int $height) use ($trim, $tw, $th): \GdImage {
    $width = (int) round($tw * $height / $th);
    $out = imagecreatetruecolor($width, $height);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagecopyresampled($out, $trim, 0, 0, 0, 0, $width, $height, $tw, $th);
    return $out;
};
foreach ([44, 88, 132, 56, 112, 168] as $height) {
    $img = $resize($height);
    imagepng($img, $dir . "logo-full-{$height}.png", 9);
    imagewebp($img, $dir . "logo-full-{$height}.webp", 90);
    printf("logo-full-%d: %dx%d  png %d B  webp %d B\n", $height, imagesx($img), $height, filesize($dir . "logo-full-{$height}.png"), filesize($dir . "logo-full-{$height}.webp"));
}
$mail = $resize(264);
imagepng($mail, $dir . 'email-logo.png', 9);
printf("email-logo.png: %dx264 (shown at half size)\n", imagesx($mail));

if (isset($argv[1])) {
    // 400% crop of the "P" of PRATYANGI at the 2x desktop size, on the header cream
    $img = $resize(112);
    $bg = imagecreatetruecolor(imagesx($img), 112);
    imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 253, 208));
    imagealphablending($bg, true);
    imagecopy($bg, $img, 0, 0, 0, 0, imagesx($img), 112);
    $crop = imagecreatetruecolor(280, 160);
    imagecopyresized($crop, $bg, 0, 0, (int) (imagesx($img) * 0.325), 36, 280, 160, 70, 40);
    imagepng($crop, rtrim($argv[1], '/\\') . '/edge-400pct.png');
    echo "crop written\n";
}
