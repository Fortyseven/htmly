#!/usr/bin/env php
<?php
declare(strict_types=1);

if ($argc < 3) {
    fwrite(STDERR, "Usage: php migrate-database.php <old-db> <new-db>\n");
    exit(1);
}

$oldPath = $argv[1];
$newPath = $argv[2];

if (!file_exists($oldPath)) {
    fwrite(STDERR, "Error: Old database not found at '{$oldPath}'\n");
    exit(1);
}

if (file_exists($newPath)) {
    fwrite(STDERR, "Error: New database already exists at '{$newPath}' - aborting to avoid overwriting.\n");
    exit(1);
}

$oldDB = new SQLite3($oldPath);
$newDB = new SQLite3($newPath);
$newDB->exec('PRAGMA journal_mode=WAL');

// Step 1: Check if content_type column exists
$schemaQuery = $oldDB->query('PRAGMA table_info(snippets)');
$hasContentType = false;
while ($row = $schemaQuery->fetchArray(SQLITE3_ASSOC)) {
    if ($row['name'] === 'content_type') {
        $hasContentType = true;
        break;
    }
}

$oldHasCT = $hasContentType ? 'yes' : 'no';
echo "[1/4] content_type column in old DB: {$oldHasCT}\n";

// Step 2: Create new schema
$newDB->exec('
    CREATE TABLE snippets (
        guid          TEXT PRIMARY KEY,
        html_content  TEXT NOT NULL DEFAULT \'\',
        content_type  TEXT NOT NULL DEFAULT \'html\',
        access_token  TEXT NOT NULL DEFAULT \'\',
        created_at    INTEGER NOT NULL DEFAULT 0,
        ttl_seconds   INTEGER NOT NULL DEFAULT 604800
    )
');
$newDB->exec('CREATE INDEX idx_created_at ON snippets(created_at)');

$newDB->exec('
    CREATE TABLE rate_limits (
        ip_hash      TEXT NOT NULL,
        action       TEXT NOT NULL,
        window_start INTEGER NOT NULL,
        count        INTEGER NOT NULL DEFAULT 1,
        PRIMARY KEY (ip_hash, action, window_start)
    )
');
echo "[2/4] Schema created\n";

// Step 3: Migrate data
if (!$hasContentType) {
    $oldRows = $oldDB->query('SELECT guid, html_content, access_token, created_at, ttl_seconds FROM snippets');
} else {
    $oldRows = $oldDB->query('SELECT * FROM snippets');
}

$stmt = $newDB->prepare(
    'INSERT INTO snippets (guid, html_content, content_type, access_token, created_at, ttl_seconds) ' .
    'VALUES (:guid, :html, :ct, :token, :created, :ttl)'
);

$count = 0;
while ($row = $oldRows->fetchArray(SQLITE3_ASSOC)) {
    $stmt->bindValue(':guid', $row['guid'], SQLITE3_TEXT);
    $stmt->bindValue(':html', $row['html_content'] ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':ct', $row['content_type'] ?? 'html', SQLITE3_TEXT);
    $stmt->bindValue(':token', $row['access_token'] ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':created', $row['created_at'] ?? 0, SQLITE3_INTEGER);
    $stmt->bindValue(':ttl', $row['ttl_seconds'] ?? 604800, SQLITE3_INTEGER);
    $stmt->execute();
    $stmt->reset();
    $count++;
}
echo "[3/4] Migrated {$count} snippets\n";

// Migrate rate_limits (only if table exists)
$hasRateLimits = (bool) $oldDB->querySingle(
    "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='rate_limits'"
);

if ($hasRateLimits) {
    $rateRows = $oldDB->query('SELECT * FROM rate_limits');
    $rateStmt = $newDB->prepare(
        'INSERT INTO rate_limits (ip_hash, action, window_start, count) VALUES (:ip, :action, :start, :count)'
    );
    $rateCount = 0;
    while ($r = $rateRows->fetchArray(SQLITE3_ASSOC)) {
        $rateStmt->bindValue(':ip', $r['ip_hash'] ?? '', SQLITE3_TEXT);
        $rateStmt->bindValue(':action', $r['action'] ?? '', SQLITE3_TEXT);
        $rateStmt->bindValue(':start', $r['window_start'] ?? 0, SQLITE3_INTEGER);
        $rateStmt->bindValue(':count', $r['count'] ?? 1, SQLITE3_INTEGER);
        $rateStmt->execute();
        $rateStmt->reset();
        $rateCount++;
    }
    echo "    Migrated {$rateCount} rate limits\n";
} else {
    echo "    No rate_limits table - skipping\n";
}

// Step 4: Verify
$oldCount = $oldDB->querySingle('SELECT COUNT(*) FROM snippets');
$newCount = $newDB->querySingle('SELECT COUNT(*) FROM snippets');

if ($oldCount !== $newCount) {
    fwrite(STDERR, "WARNING: Row count mismatch! Old: {$oldCount}, New: {$newCount}\n");
} else {
    echo "[4/4] Verified: {$newCount} snippets match\n";
}

$oldDB->close();
$newDB->close();

echo "\nMigration complete!\n";
echo "  Old: {$oldPath}\n";
echo "  New: {$newPath}\n";
echo "\nTo use the new database:\n";
echo "  cp {$newPath} {$oldPath}\n";
