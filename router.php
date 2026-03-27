<?php
/**
 * Too Many Coins - PHP Built-in Server Router
 * Routes API requests to the API handler and serves static files
 * Includes security headers and cache control
 */
$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);

$publicRoot = __DIR__ . '/public';

// Security headers for all responses
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

// API routes
if (strpos($path, '/api/') === 0 || strpos($path, '/api') === 0) {
    require __DIR__ . '/api/index.php';
    return true;
}

// Wiki routes get priority over SPA fallback.
if ($path === '/wiki' || strpos($path, '/wiki/') === 0) {
    $wikiPath = ($path === '/wiki') ? '/wiki/' : $path;
    $candidate = realpath($publicRoot . $wikiPath);

    if ($candidate && strpos($candidate, realpath($publicRoot)) === 0) {
        if (is_dir($candidate)) {
            $indexFile = rtrim($candidate, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'index.html';
            if (file_exists($indexFile) && is_file($indexFile)) {
                header('Content-Type: text/html; charset=UTF-8');
                header('Cache-Control: no-cache, no-store, must-revalidate');
                readfile($indexFile);
                return true;
            }
        }

        if (is_file($candidate)) {
            $ext = pathinfo($candidate, PATHINFO_EXTENSION);
            $mimeTypes = [
                'html' => 'text/html; charset=UTF-8',
                'css'  => 'text/css; charset=UTF-8',
                'js'   => 'application/javascript; charset=UTF-8',
                'json' => 'application/json',
                'png'  => 'image/png',
                'jpg'  => 'image/jpeg',
                'gif'  => 'image/gif',
                'svg'  => 'image/svg+xml',
                'ico'  => 'image/x-icon',
                'woff2'=> 'font/woff2',
                'woff' => 'font/woff',
                'webmanifest' => 'application/manifest+json',
            ];

            if (isset($mimeTypes[$ext])) {
                header('Content-Type: ' . $mimeTypes[$ext]);
            }

            if (in_array($ext, ['css', 'js', 'png', 'jpg', 'gif', 'svg', 'woff2', 'woff', 'ico'])) {
                header('Cache-Control: public, max-age=3600');
            } else {
                header('Cache-Control: no-cache, no-store, must-revalidate');
            }

            readfile($candidate);
            return true;
        }
    }

    // Unknown wiki deep link falls back to wiki home instead of SPA shell.
    $wikiHome = $publicRoot . '/wiki/index.html';
    if (file_exists($wikiHome) && is_file($wikiHome)) {
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        readfile($wikiHome);
        return true;
    }
}

// Static files
$staticFile = $publicRoot . $path;
if ($path !== '/' && file_exists($staticFile) && is_file($staticFile)) {
    $ext = pathinfo($staticFile, PATHINFO_EXTENSION);
    $mimeTypes = [
        'html' => 'text/html; charset=UTF-8',
        'css'  => 'text/css; charset=UTF-8',
        'js'   => 'application/javascript; charset=UTF-8',
        'json' => 'application/json',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'gif'  => 'image/gif',
        'svg'  => 'image/svg+xml',
        'ico'  => 'image/x-icon',
        'woff2'=> 'font/woff2',
        'woff' => 'font/woff',
        'webmanifest' => 'application/manifest+json',
    ];
    if (isset($mimeTypes[$ext])) {
        header('Content-Type: ' . $mimeTypes[$ext]);
    }

    // Cache static assets (CSS, JS, images) for 1 hour; HTML for 0
    if (in_array($ext, ['css', 'js', 'png', 'jpg', 'gif', 'svg', 'woff2', 'woff', 'ico'])) {
        header('Cache-Control: public, max-age=3600');
    } else {
        header('Cache-Control: no-cache, no-store, must-revalidate');
    }

    readfile($staticFile);
    return true;
}

// Default: serve index.html with CSP
header('Content-Type: text/html; charset=UTF-8');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'");
header('Cache-Control: no-cache, no-store, must-revalidate');
readfile(__DIR__ . '/public/index.html');
return true;
