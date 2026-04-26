<?php
/**
 * HTML Scratchpad — Main entry point
 *
 * Routing, security headers, API endpoints, and UI rendering.
 * Uses components in components/ for layout separation.
 * Requires: config.php, db.php
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// ── Boot ──────────────────────────────────────────────────────

session_name(SESSION_NAME);
session_start();
init_db();
[$snippetCleanup, $rateCleanup] = cleanup_expired();

// ── Security headers (all responses) ──────────────────────────

header('Content-Type: text/html; charset=utf-8');
header('Content-Security-Policy: ' . HEADER_CSP);
header('X-Frame-Options: ' . HEADER_X_FRAME);
header('Referrer-Policy: ' . HEADER_REFERRER);
header('X-Content-Type-Options: ' . HEADER_CONTENT_TYPE);

// ── Helpers ───────────────────────────────────────────────────

/** Set a flash message. */
function flash(string $msg): void
{
    $_SESSION['flash'] = $msg;
}

/** Get and clear the flash message. */
function get_flash(): ?string
{
    $msg = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $msg;
}

/** Redirect to a URL and exit. */
function redirect(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

/** JSON response. */
function json_response(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// ── Routing ───────────────────────────────────────────────────

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH);

// API: POST /api/save
if ($path === '/api/save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_save();
}

// API: POST /api/delete
if ($path === '/api/delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_delete();
}

// API: GET /api/download
if ($path === '/api/download' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    handle_download();
}

// Route: /s/{guid}  (snippet view)
if (preg_match('#^/s/([a-zA-Z0-9]+)$#', $path, $m)) {
    handle_snippet_view($m[1]);
} else {
    // Everything else: home page (Edit Mode for new snippets)
    render_home();
}

// ── Handlers ──────────────────────────────────────────────────

function handle_save(): void
{
    // Rate limit check
    if (!check_save_rate()) {
        $retryAfter = RATE_SAVE_WINDOW - (time() - (time() % RATE_SAVE_WINDOW));
        http_response_code(429);
        header('Retry-After: ' . $retryAfter);
        json_response(['error' => 'Too many saves. Try again later.'], 429);
    }

    $body = file_get_contents('php://input');
    $data = json_decode($body, true);

    if (!$data || empty($data['html']) || !isset($data['ttl'])) {
        json_response(['error' => 'Missing html or ttl'], 400);
    }

    $html = $data['html'];
    $ttl  = (int) $data['ttl'];

    // Validate TTL
    if (!in_array($ttl, array_keys(TTL_PRESETS), true)) {
        json_response(['error' => 'Invalid TTL'], 400);
    }

    // Validate size
    if (mb_strlen($html, '8bit') > MAX_SNIPPET_SIZE) {
        json_response(['error' => 'Snippet too large (max 50 KB)'], 413);
    }

    // Update existing snippet if guid and token provided
    if (!empty($data['guid']) && !empty($data['token'])) {
        $existingGuid = $data['guid'];
        $existingToken = $data['token'];
        if (!verify_token($existingGuid, $existingToken)) {
            json_response(['error' => 'Invalid token'], 403);
        }
        if (!update_snippet($existingGuid, $html, $ttl)) {
            json_response(['error' => 'Update failed'], 500);
        }
        json_response([
            'guid' => $existingGuid,
            'token' => $existingToken,
            'url' => '/s/' . $existingGuid,
            'updated' => true,
        ]);
    }

    try {
        $result = create_snippet($html, $ttl);
        json_response([
            'guid' => $result['guid'],
            'token' => $result['token'],
            'url' => '/s/' . $result['guid'],
            'updated' => false,
        ]);
    } catch (Exception $e) {
        json_response(['error' => $e->getMessage()], 500);
    }
}

function handle_delete(): void
{
    $body = file_get_contents('php://input');
    $data = json_decode($body, true);

    if (!$data || empty($data['guid']) || empty($data['token'])) {
        json_response(['error' => 'Missing guid or token'], 400);
    }

    if (!verify_token($data['guid'], $data['token'])) {
        json_response(['error' => 'Invalid token'], 403);
    }

    delete_snippet($data['guid']);
    json_response(['ok' => true]);
}

function handle_download(): void
{
    $guid = $_GET['guid'] ?? '';
    if (!preg_match('/^[a-zA-Z0-9]+$/', $guid)) {
        json_response(['error' => 'Invalid GUID'], 400);
    }

    $snippet = get_snippet($guid);
    if ($snippet === null) {
        json_response(['error' => 'Snippet not found'], 404);
    }

    // Rate limit check for downloads (counts as reads)
    if (!check_read_rate()) {
        $retryAfter = RATE_READ_WINDOW - (time() - (time() % RATE_READ_WINDOW));
        header('Retry-After: ' . $retryAfter);
        json_response(['error' => 'Too many requests. Try again later.'], 429);
    }

    // Download as attachment
    $filename = 'snippet-' . $guid . '.html';
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    echo $snippet['html_content'];
    exit;
}

function handle_snippet_view(string $guid): void
{
    // Rate limit check
    if (!check_read_rate()) {
        $retryAfter = RATE_READ_WINDOW - (time() - (time() % RATE_READ_WINDOW));
        header('Retry-After: ' . $retryAfter);
        http_response_code(429);
        echo '<!DOCTYPE html><html><head><title>429 Too Many Requests</title></head><body>';
        echo '<h1>Too many requests</h1><p>Please try again later.</p></body></html>';
        exit;
    }

    $snippet = get_snippet($guid);

    // Check for token in query string
    $token = $_GET['t'] ?? '';
    $isEdit = $snippet !== null && verify_token($guid, $token);

    if ($snippet === null) {
        render_not_found();
        return;
    }

    render_snippet_page($snippet, $isEdit, $token);
}

// ── Renderers ─────────────────────────────────────────────────

function render_home(): void
{
    $flash = get_flash();
    ?>
<!DOCTYPE html>
<html lang="en">
<?php
    $pageTitle = 'HTML Scratchpad';
    include __DIR__ . '/components/header.php';
?>
<body>
    <div class="header">
        <h1>HTML <span>Scratchpad</span></h1>
        <span class="badge">beta</span>
    </div>

    <?php if ($flash): ?>
    <div class="flash visible" id="flash">
        <span class="msg"><?= htmlspecialchars($flash) ?></span>
        <button class="dismiss" onclick="this.parentElement.classList.remove('visible')">&times;</button>
    </div>
    <?php endif; ?>

    <?php
    $htmlContent = '';
    $ttlPreset   = TTL_PRESETS;
    $defaultTtl  = DEFAULT_TTL_SECONDS;
    $guid        = '';
    $token       = '';
    include __DIR__ . '/components/edit-mode.php';
    ?>

    <div class="info-note">⚠️ Inline JS is blocked for security — CSS will work.</div>

</body>
</html>
    <?php
}

function render_snippet_page(array $snippet, bool $isEdit, string $token): void
{
    $guid = $snippet['guid'];
    $html = $snippet['html_content'];
    ?>
<!DOCTYPE html>
<html lang="en">
<?php
    $pageTitle = 'HTML Scratchpad — ' . htmlspecialchars($guid);
    $badgeText = $isEdit ? 'editing' : 'viewing';
    include __DIR__ . '/components/header.php';
?>
<body>
    <div class="header">
        <h1>HTML <span>Scratchpad</span></h1>
        <span class="badge <?= $isEdit ? 'edit' : '' ?>"><?= $isEdit ? 'editing' : 'viewing' ?></span>
    </div>

    <?php if ($isEdit): ?>
        <?php
        $htmlContent = $html;
        $ttlPreset   = TTL_PRESETS;
        $defaultTtl  = DEFAULT_TTL_SECONDS;
        include __DIR__ . '/components/edit-mode.php';
        ?>
    <?php else: ?>
        <?php
        $htmlContent = $html;
        include __DIR__ . '/components/view-mode.php';
        ?>
    <?php endif; ?>

    <div class="info-note">⚠️ Inline JS is blocked for security — CSS will work.</div>

</body>
</html>
    <?php
}

function render_not_found(): void
{
    ?>
<!DOCTYPE html>
<html lang="en">
<?php
    $pageTitle = 'Snippet Not Found — HTML Scratchpad';
    include __DIR__ . '/components/header.php';
?>
<body>
    <?php include __DIR__ . '/components/not-found.php'; ?>
</body>
</html>
    <?php
}
