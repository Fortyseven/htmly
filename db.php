<?php
/**
 * Htmly — Database layer
 *
 * SQLite schema, snippet CRUD, rate limiting, and TTL cleanup.
 * Requires: config.php
 */

declare(strict_types=1);

// ── Helpers ───────────────────────────────────────────────────

/** Get a shared SQLite3 instance (lazy-init, singleton). */
function db(): SQLite3
{
    static $db = null;
    if ($db === null) {
        $db = new SQLite3(DB_PATH);
        $db->busyTimeout(5000);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA foreign_keys=ON');
    }
    return $db;
}

/** Generate a unique GUID. Throws on exhaustion. */
function generate_guid(): string
{
    $chars = GUID_CHARS;
    $len   = GUID_LENGTH;
    $db    = db();
    $pool  = strlen($chars);

    for ($attempt = 0; $attempt < GUID_RETRIES; $attempt++) {
        $guid = '';
        for ($i = 0; $i < $len; $i++) {
            $guid .= $chars[random_int(0, $pool - 1)];
        }

        $stmt = $db->prepare('SELECT 1 FROM snippets WHERE guid = :g LIMIT 1');
        $stmt->bindValue(':g', $guid, SQLITE3_TEXT);
        $result = $stmt->execute();
        if (!$result->fetchArray()) {
            return $guid;
        }
    }

    throw new RuntimeException('GUID generation exhausted after ' . GUID_RETRIES . ' retries');
}

/** Generate a random access token (hex). */
function generate_token(): string
{
    return bin2hex(random_bytes(TOKEN_LENGTH));
}

/** Hash the client IP with the server-side salt. */
function ip_hash(): string
{
    return hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . IP_SALT);
}

// ── Schema ────────────────────────────────────────────────────

/** Ensure the database schema exists. */
function init_db(): void
{
    $db = db();
    $db->exec('
        CREATE TABLE IF NOT EXISTS snippets (
            guid          TEXT PRIMARY KEY,
            html_content  TEXT NOT NULL,
            access_token  TEXT NOT NULL,
            created_at    INTEGER NOT NULL,
            ttl_seconds   INTEGER NOT NULL DEFAULT ' . DEFAULT_TTL_SECONDS . '
        )
    ');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_created_at ON snippets(created_at)');

    $db->exec('
        CREATE TABLE IF NOT EXISTS rate_limits (
            ip_hash     TEXT NOT NULL,
            action      TEXT NOT NULL,
            window_start INTEGER NOT NULL,
            count       INTEGER NOT NULL DEFAULT 1,
            PRIMARY KEY (ip_hash, action, window_start)
        )
    ');
}

// ── Snippet CRUD ──────────────────────────────────────────────

/**
 * Create a new snippet.
 *
 * @param string $html    The HTML content (already validated for size).
 * @param int    $ttl     TTL in seconds (must be in TTL_PRESETS).
 * @return array{guid: string, token: string} New GUID and token.
 */
function create_snippet(string $html, int $ttl): array
{
    $guid = generate_guid();
    $token = generate_token();
    $now = time();

    $db = db();
    $stmt = $db->prepare('
        INSERT INTO snippets (guid, html_content, access_token, created_at, ttl_seconds)
        VALUES (:g, :h, :t, :c, :ttl)
    ');
    $stmt->bindValue(':g', $guid, SQLITE3_TEXT);
    $stmt->bindValue(':h', $html, SQLITE3_TEXT);
    $stmt->bindValue(':t', $token, SQLITE3_TEXT);
    $stmt->bindValue(':c', $now, SQLITE3_INTEGER);
    $stmt->bindValue(':ttl', $ttl, SQLITE3_INTEGER);

    if (!$stmt->execute()) {
        throw new RuntimeException('Failed to create snippet: ' . $db->lastErrorMsg());
    }

    return ['guid' => $guid, 'token' => $token];
}

/**
 * Read a snippet by GUID. Returns null if not found or expired.
 *
 * @return array{guid: string, html_content: string, access_token: string, created_at: int, ttl_seconds: int}|null
 */
function get_snippet(string $guid): ?array
{
    $db = db();
    $stmt = $db->prepare('SELECT * FROM snippets WHERE guid = :g LIMIT 1');
    $stmt->bindValue(':g', $guid, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row ?: null;
}

/**
 * Update an existing snippet's HTML content.
 *
 * @param string $html New HTML content.
 * @return bool True on success.
 */
function update_snippet(string $guid, string $html, int $ttl): bool
{
    $db = db();
    $stmt = $db->prepare('UPDATE snippets SET html_content = :h, ttl_seconds = :t WHERE guid = :g');
    $stmt->bindValue(':h', $html, SQLITE3_TEXT);
    $stmt->bindValue(':t', $ttl, SQLITE3_INTEGER);
    $stmt->bindValue(':g', $guid, SQLITE3_TEXT);

    if (!$stmt->execute()) {
        return false;
    }

    return $db->changes() > 0;
}

/**
 * Delete a snippet by GUID.
 *
 * @return bool True if a row was deleted.
 */
function delete_snippet(string $guid): bool
{
    $db = db();
    $stmt = $db->prepare('DELETE FROM snippets WHERE guid = :g');
    $stmt->bindValue(':g', $guid, SQLITE3_TEXT);

    if (!$stmt->execute()) {
        return false;
    }

    return $db->changes() > 0;
}

// ── Admin queries ─────────────────────────────────────────────

/**
 * Retrieve all snippets ordered by creation time (newest first).
 *
 * @return array<int, array{guid: string, html_content: string, access_token: string, created_at: int, ttl_seconds: int}>
 */
function get_all_snippets(): array
{
    $db = db();
    $result = $db->query('SELECT * FROM snippets ORDER BY created_at DESC');
    $snippets = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $snippets[] = $row;
    }
    return $snippets;
}

// ── Token verification ────────────────────────────────────────

/**
 * Check if a given token matches the stored token for a GUID.
 */
function verify_token(string $guid, string $token): bool
{
    $snippet = get_snippet($guid);
    if ($snippet === null) {
        return false;
    }
    return hash_equals($snippet['access_token'], $token);
}

// ── Rate limiting ─────────────────────────────────────────────

/**
 * Check (and increment) the rate limit counter for an action.
 *
 * @return true  if allowed, false if limit exceeded.
 */
function check_rate_limit(string $action, int $max, int $window): bool
{
    $now = time();
    $windowStart = $now - ($now % $window);
    $hash = ip_hash();

    $db = db();

    // Check current window
    $stmt = $db->prepare(
        'SELECT count FROM rate_limits WHERE ip_hash = :h AND action = :a AND window_start = :w LIMIT 1'
    );
    $stmt->bindValue(':h', $hash, SQLITE3_TEXT);
    $stmt->bindValue(':a', $action, SQLITE3_TEXT);
    $stmt->bindValue(':w', $windowStart, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);

    if ($row && $row['count'] >= $max) {
        return false; // rate limit exceeded
    }

    // Increment or insert
    if ($row) {
        $upd = $db->prepare('UPDATE rate_limits SET count = count + 1 WHERE ip_hash = :h AND action = :a AND window_start = :w');
    } else {
        $ins = $db->prepare(
            'INSERT INTO rate_limits (ip_hash, action, window_start, count) VALUES (:h, :a, :w, 1)'
        );
        $ins->bindValue(':h', $hash, SQLITE3_TEXT);
        $ins->bindValue(':a', $action, SQLITE3_TEXT);
        $ins->bindValue(':w', $windowStart, SQLITE3_INTEGER);
        $ins->execute();
        return true;
    }

    $upd->bindValue(':h', $hash, SQLITE3_TEXT);
    $upd->bindValue(':a', $action, SQLITE3_TEXT);
    $upd->bindValue(':w', $windowStart, SQLITE3_INTEGER);
    $upd->execute();

    return true;
}

/**
 * Check save rate limit. Returns true if allowed.
 */
function check_save_rate(): bool
{
    return check_rate_limit('save', RATE_SAVE_MAX, RATE_SAVE_WINDOW);
}

/**
 * Check read rate limit. Returns true if allowed.
 */
function check_read_rate(): bool
{
    return check_rate_limit('read', RATE_READ_MAX, RATE_READ_WINDOW);
}

// ── Cleanup ───────────────────────────────────────────────────

/**
 * Delete expired snippets and old rate-limit entries.
 * Returns [snippetCount, rateCount].
 */
function cleanup_expired(): array
{
    $now = time();
    $oldRate = $now - RATE_SAVE_WINDOW;
    $db = db();

    // Expired snippets (skip ttl_seconds=0 which means permanent)
    $del1 = $db->exec("DELETE FROM snippets WHERE ttl_seconds > 0 AND created_at + ttl_seconds < $now");

    // Old rate limit entries
    $del2 = $db->exec("DELETE FROM rate_limits WHERE window_start < $oldRate");

    return [$del1 ? $db->changes() : 0, $del2 ? $del2 : 0];
}
