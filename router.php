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
//
// Containment is enforced here rather than assumed. Both servers that front
// this app already normalise the path before it arrives - the PHP built-in
// server strips `..` segments, and production runs Apache with DocumentRoot
// pinned to /app/public - so no traversal is reachable today, and probing
// with ../, %2e%2e and encoded separators returns the SPA shell rather than
// source. But the wiki branch above resolves and containment-checks its
// candidate, and this branch concatenated and read. That difference is only
// safe for as long as something upstream keeps normalising, which is not a
// property this file should depend on.
$staticFile = $publicRoot . $path;
$resolvedStatic = realpath($staticFile);
$resolvedRoot = realpath($publicRoot);
$withinRoot = $resolvedStatic !== false
    && $resolvedRoot !== false
    && strpos($resolvedStatic, $resolvedRoot . DIRECTORY_SEPARATOR) === 0;

if ($path !== '/' && $withinRoot && file_exists($staticFile) && is_file($staticFile)) {
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

    // Keep app shell assets fresh while still caching media/fonts.
    // The ?ui=next client's modules count as shell assets too: they are loaded
    // by URL from an import graph, so a stale cached module can be paired with
    // a fresh one and produce a mismatch no reload seems to fix.
    $isAppShellAsset = (
        $path === '/js/main.js'
        || $path === '/js/patch-notes.js'
        || $path === '/css/tokens.css'
        || $path === '/css/next.css'
        || $path === '/css/assets.css'
        || $path === '/css/screens.css'
        || strpos($path, '/js/screens/') === 0
        || strpos($path, '/js/core/') === 0
    );
    if ($isAppShellAsset) {
        header('Cache-Control: no-cache, no-store, must-revalidate');
    } else if (in_array($ext, ['css', 'js', 'png', 'jpg', 'gif', 'svg', 'woff2', 'woff', 'ico'])) {
        header('Cache-Control: public, max-age=3600');
    } else {
        header('Cache-Control: no-cache, no-store, must-revalidate');
    }

    readfile($staticFile);
    return true;
}

// Default: serve index.html with CSP
header('Content-Type: text/html; charset=UTF-8');
// The Google Fonts allowances are gone with the webfont they existed for:
// the app now uses the platform UI stack and loads nothing off-origin, so
// leaving them would keep a hole open for a dependency that no longer exists.
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; font-src 'self'; img-src 'self' data:; connect-src 'self'");
header('Cache-Control: no-cache, no-store, must-revalidate');
readfile(__DIR__ . '/public/index.html');
return true;
