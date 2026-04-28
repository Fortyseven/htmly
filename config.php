<?php
/**
 * Htmly — Configuration
 *
 * All tuning parameters live here. No code logic.
 */

// ── Site ──────────────────────────────────────────────────────
define('SITE_TITLE', 'Htmly');

// ── Database ──────────────────────────────────────────────────
define('DB_PATH', __DIR__ . '/snippets.db');

// ── Snippet defaults ──────────────────────────────────────────
define('DEFAULT_TTL_SECONDS',   604800);       // 7 days
define('MAX_SNIPPET_SIZE',      2048 * 1024);     // 2 MB
define('GUID_LENGTH',           10);            // alphanumeric GUID
define('GUID_CHARS',            '0123456789abcdefghijklmnopqrstuvwxyz');
define('TOKEN_LENGTH',          40);            // bytes → 80-char hex
define('GUID_RETRIES',          3);

// ── TTL presets (label => seconds) ────────────────────────────
// Use 0 for "permanent" — only available to IPs in ADMIN_IP_WHITELIST.
define('TTL_PERMANENT', 0);
define('TTL_PRESETS', [
    3600    => '1 hour',
    86400   => '1 day',
    604800  => '7 days',
]);

// ── Rate limiting ─────────────────────────────────────────────
define('IP_SALT', 'htmly-scratchpad-' . __FILE__);

// Save: 10 per 10 minutes
define('RATE_SAVE_MAX',      10);
define('RATE_SAVE_WINDOW',   600);       // seconds

// Read: 50 per 1 minute
define('RATE_READ_MAX',      50);
define('RATE_READ_WINDOW',   60);        // seconds

// ── Security headers ──────────────────────────────────────────
define('HEADER_CSP',          "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-src 'self'; object-src 'none'");
define('HEADER_X_FRAME',      'SAMEORIGIN');
define('HEADER_REFERRER',     'strict-origin-when-cross-origin');
define('HEADER_CONTENT_TYPE', 'nosniff');

// ── Live preview debounce (ms) ────────────────────────────────
define('LIVE_PREVIEW_DEBOUNCE', 300);

// ── Preview defaults ──────────────────────────────────────────
define('DEFAULT_JS_ENABLED', true);             // JS/Canvas in iframes by default

// ── Admin access ──────────────────────────────────────────────
// Whitelist of IPs allowed to access the admin page.
// Set to an empty array to disable admin access entirely.
define('ADMIN_IP_WHITELIST', ['127.0.0.1','24.62.226.62']);

// ── Session / flash ───────────────────────────────────────────
define('SESSION_NAME', 'htmly');
