<?php
// ── router.php ────────────────────────────────────────────────────────────────
// PHP built-in server router — used by start-local.bat / start-local.ps1.
// Routes requests to the correct PHP file or serves static assets directly.
// Only used in local mode; IIS handles routing in production.
// ─────────────────────────────────────────────────────────────────────────────

$uri  = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$base = __DIR__;

// Serve static files (css, js, images, fonts) directly if they exist
if ($uri !== '/' && file_exists($base . $uri) && !is_dir($base . $uri)) {
    return false; // Let PHP built-in server handle it
}

// Route map — add entries here if you add new top-level PHP pages
$routes = [
    '/'                    => '/index.php',
    '/index.php'           => '/index.php',
    '/dependency-chain.php'=> '/dependency-chain.php',
    '/trigger.php'         => '/trigger.php',
    '/status.php'          => '/status.php',
    '/save_docs.php'       => '/save_docs.php',
    '/generate_wrapper.php'=> '/generate_wrapper.php',
    '/log.php'             => '/log.php',
    '/seed_viewmap.php'    => '/seed_viewmap.php',
    '/add_process.php'     => '/add_process.php',
    '/save_process.php'    => '/save_process.php',
    '/ingest_pbix.php'     => '/ingest_pbix.php',
    '/generate_script.php'  => '/generate_script.php',
];

$target = $routes[$uri] ?? null;

if ($target && file_exists($base . $target)) {
    require $base . $target;
} else {
    http_response_code(404);
    echo "<h1>404 Not Found</h1><p>$uri</p>";
}
