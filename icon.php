<?php
// icon.php — генерирует PNG-иконку кошелька на лету (нужен модуль GD)
$size = (int)($_GET['s'] ?? 512);
if ($size < 16 || $size > 1024) $size = 512;

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');

if (!function_exists('imagecreatetruecolor')) {
    // GD нет — отдаём прозрачный 1x1, чтобы не было 404
    http_response_code(200);
    echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
    exit;
}

$img = imagecreatetruecolor($size, $size);
imagealphablending($img, true);

// Фон #0b0e14
$bg = imagecolorallocate($img, 0x0b, 0x0e, 0x14);
imagefilledrectangle($img, 0, 0, $size, $size, $bg);

// Рисуем ромб (💎) градиентом сине-фиолетовым
$cx = $size / 2;
$cy = $size / 2;
$r  = $size * 0.32;

// тело ромба
$points = [
    $cx,       $cy - $r,   // верх
    $cx + $r,  $cy,        // право
    $cx,       $cy + $r,   // низ
    $cx - $r,  $cy         // лево
];
$diamond = imagecolorallocate($img, 0x60, 0xa5, 0xfa); // #60a5fa
imagefilledpolygon($img, $points, 4, $diamond);

// верхняя грань светлее
$top = [
    $cx,            $cy - $r,
    $cx + $r*0.5,   $cy - $r*0.25,
    $cx,            $cy + $r*0.1,
    $cx - $r*0.5,   $cy - $r*0.25
];
$topc = imagecolorallocate($img, 0xa7, 0x8b, 0xfa); // #a78bfa
imagefilledpolygon($img, $top, 4, $topc);

// обводка
$edge = imagecolorallocate($img, 0xc4, 0xb5, 0xfd);
imagesetthickness($img, max(2, (int)($size*0.01)));
imagepolygon($img, $points, 4, $edge);

imagepng($img);
imagedestroy($img);