<?php
declare(strict_types=1);
// Ulaz aplikacije. Na serveru: public_html/_rs/index.php, kod u ../../arena-rezervacije (izvan public_html).

$candidates = [dirname(__DIR__, 2) . '/app/bootstrap.php', dirname(__DIR__, 2) . '/arena-rezervacije/app/bootstrap.php'];
foreach ($candidates as $c) if (is_file($c)) { require $c; break; }
if (!class_exists('Api')) { http_response_code(500); exit('Aplikacija nije instalirana.'); }

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$prod = Config::get('env') === 'prod';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
if ($prod) header('Strict-Transport-Security: max-age=31536000');

if (preg_match('#^/rezervacije/api/(.*)$#', $path, $m)) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $in = [];
    if ($method === 'POST') {
        if (($_SERVER['HTTP_X_RS'] ?? '') !== '1') { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Neispravan zahtjev.']); exit; }
        $raw = file_get_contents('php://input') ?: '';
        if (strlen($raw) > 200000) { http_response_code(413); exit; }
        $in = $raw === '' ? [] : (json_decode($raw, true) ?? []);
        if (!is_array($in)) $in = [];
    } elseif ($method !== 'GET') { http_response_code(405); exit; }
    $api = new Api($_COOKIE[Auth::COOKIE] ?? null, $_SERVER['REMOTE_ADDR'] ?? '');
    [$status, $body] = $api->handle($method, $m[1], $_GET, $in);
    $opts = ['path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => (bool) Config::get('cookie_secure', $prod)];
    if ($api->setToken) setcookie(Auth::COOKIE, $api->setToken, $opts + ['expires' => time() + 30 * 86400]);
    if ($api->clearToken) setcookie(Auth::COOKIE, '', $opts + ['expires' => time() - 3600]);
    if ($status === 200 && is_array($body['data'] ?? null) && isset($body['data']['__file'])) {
        $f = $body['data']['__file'];
        if (!is_file($f)) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'Datoteka ne postoji.']); exit; }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '', $body['data']['name']) . '"');
        readfile($f); exit;
    }
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

$pages = ['/rezervacije' => ['public', 'Rezervacije — kalendar staza'], '/nalog' => ['account', 'Moj nalog'], '/administracija' => ['admin', 'Administracija']];
$key = rtrim($path, '/');
foreach ($pages as $prefix => [$page, $title]) {
    if ($key === $prefix || str_starts_with($key, $prefix . '/')) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache');
        $v = substr(md5((string) @filemtime(__DIR__ . '/assets/app.js') . @filemtime(__DIR__ . '/assets/app.css')), 0, 8);
        $robots = $page === 'public' ? 'index,follow' : 'noindex,nofollow';
        echo <<<HTML
<!doctype html>
<html lang="sr-Latn">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="$robots">
<title>$title — Arena Bakarni</title>
<link rel="icon" href="/assets/img/favicon.png">
<link rel="stylesheet" href="/_rs/assets/app.css?v=$v">
</head>
<body data-page="$page">
<header class="rs-top">
  <a class="rs-brand" href="/"><img src="/assets/img/logo-light.webp" alt="Arena Bakarni" width="120" height="59"></a>
  <nav class="rs-nav">
    <a href="/rezervacije/" data-nav="public">Kalendar</a>
    <a href="/nalog/" data-nav="account" id="rs-nav-account">Prijava</a>
    <a href="/administracija/" data-nav="admin" id="rs-nav-admin" hidden>Administracija</a>
  </nav>
</header>
<main id="app" class="rs-main"><p class="rs-muted">Učitavanje…</p></main>
<div id="rs-panel" class="rs-panel" hidden></div>
<div id="rs-modal" class="rs-modal" hidden></div>
<div id="rs-toast" class="rs-toast" hidden></div>
<script type="module" src="/_rs/assets/app.js?v=$v"></script>
</body>
</html>
HTML;
        exit;
    }
}
http_response_code(404);
echo 'Stranica ne postoji.';
