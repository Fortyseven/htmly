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

/** Check if the client IP is in the admin whitelist. */
function is_admin_ip(): bool
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return in_array($ip, ADMIN_IP_WHITELIST, true);
}

/** Format a duration in seconds as a human-readable age string. */
function format_age(int $seconds): string
{
    if ($seconds < 60) {
        return $seconds . 's';
    }
    $minutes = (int) floor($seconds / 60);
    if ($minutes < 60) {
        return $minutes . 'm';
    }
    $hours = (int) floor($minutes / 60);
    if ($hours < 24) {
        return $hours . 'h';
    }
    $days = (int) floor($hours / 24);
    if ($days < 30) {
        return $days . 'd';
    }
    $months = (int) floor($days / 30);
    if ($months < 12) {
        return $months . 'mo';
    }
    return (int) floor($months / 12) . 'y';
}

/** Format a byte count as a human-readable size string. */
function format_bytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $size = $bytes;
    while ($size >= 1024 && $i < count($units) - 1) {
        $size /= 1024;
        $i++;
    }
    return round($size, ($i === 0 ? 0 : 1)) . ' ' . $units[$i];
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

// Route: /api/admin/delete
if ($path === '/api/admin/delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_admin_ip()) {
        json_response(['error' => 'Forbidden'], 403);
    }
    handle_admin_delete();
}

// Route: /admin  (admin page)
if ($path === '/admin') {
    if (!is_admin_ip()) {
        redirect('/');
    }
    handle_admin();
    exit;
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

function handle_admin_delete(): void
{
    $body = file_get_contents('php://input');
    $data = json_decode($body, true);

    if (!$data || empty($data['guid'])) {
        json_response(['error' => 'Missing guid'], 400);
    }

    if (!preg_match('/^[a-zA-Z0-9]+$/', $data['guid'])) {
        json_response(['error' => 'Invalid GUID'], 400);
    }

    if (!delete_snippet($data['guid'])) {
        json_response(['error' => 'Snippet not found'], 404);
    }

    json_response(['ok' => true]);
}

function handle_admin(): void
{
    $snippets = get_all_snippets();
    render_admin_page($snippets);
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
    require __DIR__ . '/components/header.php';
?>
<body>
    <div class="header">
        <a href="/" style="text-decoration:none; color:inherit;"><h1>HTML <span>Scratchpad</span></h1></a>
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
    require __DIR__ . '/components/edit-mode.php';
    ?>

    <?php if (!DEFAULT_JS_ENABLED): ?>
    <div class="info-note">⚠️ Inline JS is blocked for security — CSS will work.</div>
    <?php endif; ?>

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
    require __DIR__ . '/components/header.php';
?>
<body>
    <div class="header">
        <a href="/" style="text-decoration:none; color:inherit;"><h1>HTML <span>Scratchpad</span></h1></a>
        <span class="badge <?= $isEdit ? 'edit' : '' ?>"><?= $isEdit ? 'editing' : 'viewing' ?></span>
    </div>

    <?php if ($isEdit): ?>
        <?php
        $htmlContent = $html;
        $ttlPreset   = TTL_PRESETS;
        $defaultTtl  = DEFAULT_TTL_SECONDS;
        require __DIR__ . '/components/edit-mode.php';
        ?>
    <?php else: ?>
        <?php
        $htmlContent = $html;
        require __DIR__ . '/components/view-mode.php';
        ?>
    <?php endif; ?>

    <?php if (!DEFAULT_JS_ENABLED): ?>
    <div class="info-note">⚠️ Inline JS is blocked for security — CSS will work.</div>
    <?php endif; ?>

</body>
</html>
    <?php
}

function render_admin_page(array $snippets): void
{
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin — HTML Scratchpad</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif;
            background: #1a1b26;
            color: #c0caf5;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .admin-header {
            padding: 16px 24px;
            background: #24283b;
            border-bottom: 1px solid #3b4261;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }
        .admin-header h1 { font-size: 18px; font-weight: 600; }
        .admin-header h1 span { color: #7aa2f7; }
        .admin-header a {
            color: #7aa2f7;
            text-decoration: none;
            font-size: 14px;
        }
        .admin-header a:hover { text-decoration: underline; }
        .admin-body { padding: 24px; flex: 1; max-width: 960px; width: 100%; margin: 0 auto; }
        .admin-body h2 { font-size: 14px; color: #565f89; margin-bottom: 16px; font-weight: 500; }
        .snippet-table {
            width: 100%;
            border-collapse: collapse;
            background: #24283b;
            border-radius: 6px;
            overflow: hidden;
        }
        .snippet-table thead th {
            text-align: left;
            padding: 10px 14px;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #565f89;
            border-bottom: 1px solid #3b4261;
            background: #1f2335;
        }
        .snippet-table tbody td {
            padding: 10px 14px;
            font-size: 13px;
            border-bottom: 1px solid #3b4261;
            vertical-align: middle;
        }
        .snippet-table tbody tr:last-child td { border-bottom: none; }
        .snippet-table tbody tr:hover { background: rgba(122,162,247,0.05); }
        .guid {
            font-family: 'JetBrains Mono', monospace;
            font-size: 12px;
            color: #7aa2f7;
            font-weight: 600;
            text-decoration: none;
        }
        .guid:hover { text-decoration: underline; }
        .created-date { color: #565f89; font-size: 12px; }
        .ttl-label { color: #565f89; font-size: 12px; }
        .action-link {
            color: #7aa2f7;
            text-decoration: none;
            font-size: 12px;
            padding: 3px 8px;
            border: 1px solid #3b4261;
            border-radius: 4px;
            display: inline-block;
        }
        .action-link:hover { background: #7aa2f7; color: #1a1b26; }
        .delete-btn {
            background: none;
            border: 1px solid #f7768e;
            color: #f7768e;
            cursor: pointer;
            font-size: 12px;
            padding: 3px 8px;
            border-radius: 4px;
            transition: all 0.15s;
        }
        .delete-btn:hover { background: #f7768e; color: #1a1b26; }
        .empty-state {
            text-align: center;
            padding: 48px 24px;
            color: #565f89;
            font-size: 14px;
        }
        .toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: #24283b;
            border: 1px solid #3b4261;
            border-radius: 6px;
            padding: 10px 16px;
            font-size: 13px;
            color: #c0caf5;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            opacity: 0;
            transform: translateY(10px);
            transition: all 0.2s;
            pointer-events: none;
        }
        .toast.visible {
            opacity: 1;
            transform: translateY(0);
        }
    </style>
</head>
<body>
    <div class="admin-header">
        <h1>HTML <span>Scratchpad</span> — Admin</h1>
        <a href="/">← Back to homepage</a>
    </div>
    <div class="admin-body">
        <h2><?= count($snippets) ?> snippet<?= count($snippets) !== 1 ? 's' : '' ?> stored</h2>
        <?php if (empty($snippets)): ?>
            <div class="empty-state">No snippets in the database.</div>
        <?php else: ?>
            <table class="snippet-table">
                <thead>
                    <tr>
                        <th>GUID</th>
                        <th>Created</th>
                        <th>Age</th>
                        <th>TTL</th>
                        <th>Size</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($snippets as $snippet): ?>
                    <tr data-guid="<?= htmlspecialchars($snippet['guid']) ?>">
                        <td><a class="guid" href="/s/<?= htmlspecialchars($snippet['guid']) ?>" target="_blank"><?= htmlspecialchars($snippet['guid']) ?></a></td>
                        <td><span class="created-date"><?= date('Y-m-d H:i', $snippet['created_at']) ?></span></td>
                        <td><span class="created-date"><?= format_age(time() - $snippet['created_at']) ?></span></td>
                        <td><span class="ttl-label"><?= htmlspecialchars(TTL_PRESETS[$snippet['ttl_seconds']] ?? $snippet['ttl_seconds'] . 's') ?></span></td>
                        <td><span class="created-date"><?= format_bytes(strlen($snippet['html_content'])) ?></span></td>
                        <td>
                            <a class="action-link" href="/s/<?= htmlspecialchars($snippet['guid']) ?>?t=<?= htmlspecialchars($snippet['access_token']) ?>" target="_blank">Edit Link</a>
                            <button class="delete-btn" onclick="adminDelete('<?= htmlspecialchars($snippet['guid']) ?>')">Delete</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <div class="toast" id="toast"></div>
    <script>
    function showToast(msg) {
        var el = document.getElementById('toast');
        el.textContent = msg;
        el.classList.add('visible');
        setTimeout(function() { el.classList.remove('visible'); }, 2000);
    }

    function adminDelete(guid) {
        if (!confirm('Delete snippet ' + guid + '?')) return;
        fetch('/api/admin/delete', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({guid: guid})
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.ok) {
                document.querySelector('tr[data-guid="' + guid + '"]').remove();
                var h2 = document.querySelector('.admin-body h2');
                var count = document.querySelectorAll('.snippet-table tbody tr').length;
                h2.textContent = count + ' snippet' + (count !== 1 ? 's' : '') + ' stored';
                if (count === 0) {
                    var tbody = document.querySelector('.snippet-table tbody');
                    if (!tbody.querySelector('tr')) {
                        document.querySelector('.snippet-table').style.display = 'none';
                        var empty = document.createElement('div');
                        empty.className = 'empty-state';
                        empty.textContent = 'No snippets in the database.';
                        h2.parentNode.appendChild(empty);
                    }
                }
                showToast('Deleted ' + guid);
            } else {
                showToast('Error: ' + (data.error || 'Unknown'));
            }
        })
        .catch(function() { showToast('Request failed'); });
    }
    </script>
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
    require __DIR__ . '/components/header.php';
?>
<body>
    <?php require __DIR__ . '/components/not-found.php'; ?>
</body>
</html>
    <?php
}
