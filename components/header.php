<?php
/**
 * Shared header component — CSS styles and page head only.
 * Does NOT output any body content.
 */
?>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $pageTitle ?? 'HTML Scratchpad' ?></title>
    <link rel="stylesheet" href="prism.css">
    <script src="prism.js"></script>
    <style>
        /* ── Reset & base ────────────────────────────── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
            display: flex;
            flex-direction: column;
        }

        :root {
            --bg: #1a1b26;
            --bg-surface: #24283b;
            --bg-panel: #1f2335;
            --text: #c0caf5;
            --text-muted: #565f89;
            --accent: #7aa2f7;
            --green: #9ece6a;
            --red: #f7768e;
            --orange: #ff9e64;
            --border: #3b4261;
            --radius: 6px;
        }

        /* ── Header bar ──────────────────────────────── */
        .header {
            padding: 12px 20px;
            background: var(--bg-surface);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 12px;
            flex-shrink: 0;
        }
        .header h1 { font-size: 16px; font-weight: 600; letter-spacing: -0.01em; }
        .header h1 span { color: var(--accent); }
        .header .badge {
            font-size: 11px; padding: 2px 8px; border-radius: 99px;
            background: var(--green); color: var(--bg); font-weight: 600;
        }
        .header .badge.edit { background: var(--orange); }

        /* ── Flash message ───────────────────────────── */
        .flash {
            padding: 10px 20px;
            background: var(--bg-surface);
            border-bottom: 1px solid var(--border);
            font-size: 13px;
            display: none;
        }
        .flash.visible { display: block; }
        .flash .msg { display: inline; }
        .flash .dismiss {
            float: right; background: none; border: none;
            color: var(--text-muted); cursor: pointer; font-size: 16px;
        }

        /* ── Toolbar ─────────────────────────────────── */
        .toolbar {
            padding: 8px 16px;
            background: var(--bg-panel);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            flex-shrink: 0;
        }
        .toolbar .divider {
            width: 1px; height: 20px; background: var(--border); margin: 0 4px;
        }

        /* ── Tabs ────────────────────────────────────── */
        .tab-group { display: flex; align-items: center; gap: 2px; }
        .tab-btn {
            padding: 5px 14px; border: 1px solid var(--border); background: transparent;
            color: var(--text-muted); border-radius: var(--radius); cursor: pointer;
            font-size: 13px; font-weight: 500; transition: all 0.15s;
        }
        .tab-btn:hover { color: var(--text); border-color: var(--accent); }
        .tab-btn.active { background: var(--accent); color: var(--bg); border-color: var(--accent); }

        /* ── Toggle ──────────────────────────────────── */
        .toggle {
            display: flex; align-items: center; gap: 6px;
            font-size: 12px; color: var(--text-muted); user-select: none;
        }
        .toggle input { accent-color: var(--accent); cursor: pointer; }

        /* ── Buttons ─────────────────────────────────── */
        .btn {
            padding: 5px 12px; border: 1px solid var(--border); border-radius: var(--radius);
            font-size: 12px; font-weight: 500; cursor: pointer; transition: all 0.15s;
            display: inline-flex; align-items: center; gap: 4px;
        }
        .btn-primary { background: var(--green); color: var(--bg); border-color: var(--green); }
        .btn-primary:hover { background: #b9fca6; }
        .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
        .btn-danger { background: transparent; color: var(--red); border-color: var(--red); }
        .btn-danger:hover { background: var(--red); color: var(--bg); }
        .btn-secondary { background: transparent; color: var(--text); }
        .btn-secondary:hover { background: var(--border); }

        /* ── TTL selector ────────────────────────────── */
        .ttl-select {
            padding: 4px 8px; border: 1px solid var(--border); border-radius: var(--radius);
            background: var(--bg); color: var(--text); font-size: 12px; cursor: pointer;
        }

        /* ── Main area ───────────────────────────────── */
        .main { flex: 1; display: flex; overflow: hidden; }

        /* Editor panel */
        .editor-panel {
            width: 40%; min-width: 200px; display: flex; flex-direction: column;
            border-right: 1px solid var(--border); background: var(--bg);
        }
        .editor-panel textarea {
            flex: 1; width: 100%; padding: 16px; border: none; outline: none; resize: none;
            background: transparent; color: var(--text);
            font-family: 'JetBrains Mono', 'Fira Code', 'Cascadia Code', Consolas, monospace;
            font-size: 13px; line-height: 1.6; tab-size: 4;
        }
        .editor-panel textarea::placeholder { color: var(--text-muted); }
        .editor-panel textarea:read-only {
            color: var(--text-muted); cursor: default;
        }

        /* Divider / resize handle */
        .divider {
            width: 6px; cursor: col-resize; background: var(--border); flex-shrink: 0;
            transition: background 0.15s;
        }
        .divider:hover, .divider.active { background: var(--accent); }

        /* Preview panel */
        .preview-panel {
            flex: 1; display: flex; flex-direction: column; min-width: 200px;
            background: #fff;
        }
        .preview-panel > #edit-preview {
            flex: 1; display: flex; min-height: 0;
        }
        .preview-panel iframe,
        #view-preview-content iframe {
            flex: 1; width: 100%; height: 100%; border: none; background: #fff;
        }

        /* Source panel (read-only, full-width) */
        .source-panel {
            flex: 1; overflow: auto; background: var(--bg); padding: 16px;
        }
        .source-panel pre {
            margin: 0; font-family: 'JetBrains Mono', 'Fira Code', 'Cascadia Code', Consolas, monospace;
            font-size: 13px; line-height: 1.6;
        }

        /* Token notice */
        .token-notice {
            padding: 10px 16px; background: var(--orange); color: var(--bg);
            font-size: 12px; font-weight: 500; display: none; flex-direction: column;
            gap: 4px; flex-shrink: 0;
        }
        .token-notice.visible { display: flex; }
        .token-notice code {
            background: rgba(0,0,0,0.2); padding: 2px 6px; border-radius: 3px;
            word-break: break-all; font-size: 11px;
        }

        /* Info note */
        .info-note {
            font-size: 11px; color: var(--text-muted); padding: 4px 16px;
        }

        /* ── Not found ───────────────────────────────── */
        .not-found {
            flex: 1; display: flex; align-items: center; justify-content: center;
            flex-direction: column; gap: 16px; padding: 40px;
        }
        .not-found h2 { font-size: 28px; }
        .not-found p { color: var(--text-muted); margin-bottom: 24px; }
        .not-found a {
            display: inline-block; padding: 10px 24px; background: var(--accent);
            color: var(--bg); border-radius: var(--radius); text-decoration: none;
            font-weight: 600; font-size: 14px;
        }
        .not-found a:hover { background: var(--accent-hover); }

        /* ── Responsive ──────────────────────────────── */
        @media (max-width: 768px) {
            .main { flex-direction: column; }
            .editor-panel { width: 100% !important; height: 40%; border-right: none; border-bottom: 1px solid var(--border); }
            .divider { width: auto; height: 6px; cursor: row-resize; }
            .preview-panel { height: 60%; }
        }
    </style>
</head>
