<?php
// Router script for PHP built-in server
// Serves PHP API endpoints from public/ and static files from client/html/public/

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/secrets.php';

$publicDir = realpath(__DIR__ . '/public');
$envStaticDir = getenv('STATIC_DIR') ?: '../../client/html';
if ($envStaticDir[0] !== '/') {
    $staticDir = realpath(__DIR__ . '/' . $envStaticDir);
} else {
    $staticDir = realpath($envStaticDir);
}

// Only serve files whose canonical path is inside $baseDir.
function resolveWithin(string|false $baseDir, string $relativePath): ?string
{
    if ($baseDir === false || $baseDir === '') {
        return null;
    }
    $real = realpath($baseDir . '/' . $relativePath);
    if ($real === false || is_dir($real)) {
        return null;
    }
    $prefix = rtrim($baseDir, '/') . '/';
    if (strncmp($real, $prefix, strlen($prefix)) !== 0) {
        return null;
    }
    return $real;
}

// Normalize the request path: decode, and reject anything that is not a
// plain sequence of safe segments (no ".", "..", empty or hidden segments).
$rawUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = rawurldecode($rawUri ?? '/');
$segments = $uri === '/' ? [] : explode('/', ltrim($uri, '/'));
foreach ($segments as $segment) {
    if ($segment === '' || $segment[0] === '.' || !preg_match('/^[A-Za-z0-9._-]+$/', $segment)) {
        http_response_code(404);
        return true;
    }
}
$relative = implode('/', $segments);

// Check if it's a PHP endpoint in public/ (e.g., /config -> public/config.php)
if ($relative !== '' && !preg_match('/\.php$/', $relative)) {
    $phpFile = resolveWithin($publicDir, $relative . '.php');
    if ($phpFile !== null) {
        chdir($publicDir);
        require $phpFile;
        return true;
    }
}

// Check for exact PHP file (e.g., /config.php)
if (preg_match('/\.php$/', $relative)) {
    $phpFileDirect = resolveWithin($publicDir, $relative);
    if ($phpFileDirect !== null) {
        chdir($publicDir);
        require $phpFileDirect;
        return true;
    }
}

// Serve static files from client/html/ (allowlisted extensions only)
$mimeTypes = [
    'html' => 'text/html',
    'css'  => 'text/css',
    'js'   => 'application/javascript',
    'json' => 'application/json',
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'svg'  => 'image/svg+xml',
];
if ($relative !== '') {
    $ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
    if (isset($mimeTypes[$ext])) {
        $staticFile = resolveWithin($staticDir, $relative);
        if ($staticFile !== null) {
            header('Content-Type: ' . $mimeTypes[$ext]);
            readfile($staticFile);
            return true;
        }
    }
}

// Default: serve index.html from static dir or return 404
if ($relative === '') {
    $indexFile = resolveWithin($staticDir, 'index.html');
    if ($indexFile !== null) {
        header('Content-Type: text/html');
        readfile($indexFile);
        return true;
    }
}

// Let PHP built-in server handle the rest (404)
return false;
