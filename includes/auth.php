<?php
// ── includes/auth.php ─────────────────────────────────────────────────────────
// Resolves the current user.
//
// local mode:      returns the configured local_user from app.php (no auth needed)
// production mode: reads Windows Auth user set by IIS
//
// Returns ['domain' => '...', 'user' => '...', 'full' => '...']
// ─────────────────────────────────────────────────────────────────────────────

function getAuthUser(): array {
    $config = require __DIR__ . '/../config/app.php';

    // ── Local mode: bypass auth, use configured dev username ─────────────────
    if (($config['mode'] ?? 'production') === 'local') {
        $user = $config['local_user'] ?? 'developer';
        return ['domain' => '', 'user' => $user, 'full' => $user];
    }

    // ── Production: Windows Authentication via IIS ────────────────────────────
    $raw = $_SERVER['AUTH_USER'] ?? $_SERVER['REMOTE_USER'] ?? '';
    if (empty($raw)) {
        return ['domain' => '', 'user' => 'Unknown', 'full' => 'Unknown'];
    }
    if (str_contains($raw, '\\')) {
        [$domain, $user] = explode('\\', $raw, 2);
        return ['domain' => $domain, 'user' => $user, 'full' => $raw];
    }
    return ['domain' => '', 'user' => $raw, 'full' => $raw];
}

function requireAuth(): array {
    $auth = getAuthUser();
    if ($auth['user'] === 'Unknown') {
        http_response_code(401);
        echo json_encode(['error' => 'Authentication required']);
        exit;
    }
    return $auth;
}
