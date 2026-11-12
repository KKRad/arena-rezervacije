<?php
// Lokalni razvojni server:  php -S localhost:8090 tools/dev-router.php
// Statika: public/ (aplikacija) pa postojeći sajt (logo, favicon) iz ../arena-bakarni-site.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
foreach ([__DIR__ . '/../public', __DIR__ . '/../../arena-bakarni-site'] as $root) {
    $f = realpath($root . $path);
    if ($f && is_file($f) && str_starts_with($f, realpath($root)) && !str_ends_with($f, '.php')) {
        $types = ['css' => 'text/css', 'js' => 'text/javascript', 'mjs' => 'text/javascript', 'webp' => 'image/webp', 'png' => 'image/png', 'svg' => 'image/svg+xml', 'woff2' => 'font/woff2', 'html' => 'text/html'];
        header('Content-Type: ' . ($types[pathinfo($f, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
        readfile($f); return true;
    }
}
if ($path === '/' ) { header('Location: /rezervacije/'); return true; }
require __DIR__ . '/../public/_rs/index.php';
