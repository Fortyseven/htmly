<?php
/**
 * View Mode component — read-only, two tabs (Rendered / Source).
 * No editing is possible.
 *
 * @param string $guid          Snippet GUID (for download + copy endpoints).
 * @param string $htmlContent   Raw HTML/Markdown content to display.
 * @param string $contentType   'html' or 'markdown'.
 */

$toolbarId = 'view-toolbar';
$previewId = 'view-preview';
$sourceId  = 'view-source';
$contentType = $contentType ?? 'html';
?>

<!-- Toolbar: tabs + Download + Copy -->
<div class="toolbar" id="<?= $toolbarId ?>">
    <div class="tab-group">
        <button class="tab-btn active" data-tab="rendered" id="<?= $previewId ?>-tab">Rendered</button>
        <button class="tab-btn" data-tab="source" id="<?= $sourceId ?>-tab">Source</button>
    </div>
    <?php if ($contentType !== 'markdown'): ?>
    <div class="divider"></div>
    <label class="toggle">
        <input type="checkbox" id="view-js-toggle"<?= DEFAULT_JS_ENABLED ? ' checked' : '' ?>>
        Enable JS / Canvas
    </label>
    <?php endif; ?>
    <div class="divider"></div>
    <button class="btn btn-secondary" id="view-download-btn">⬇️ Download<?= $contentType === 'markdown' ? ' Markdown' : ' HTML' ?></button>
    <button class="btn btn-secondary" id="view-copy-btn">📋 Copy<?= $contentType === 'markdown' ? ' Markdown' : ' HTML' ?> to Clipboard</button>
</div>

<!-- Hidden input for the GUID -->
<input type="hidden" id="view-guid" value="<?= htmlspecialchars($guid) ?>">

<!-- Main: Rendered tab shows iframe, Source tab shows read-only content -->
<div class="main" id="<?= $previewId ?>">
    <div id="view-preview-content" style="flex:1;display:flex;flex-direction:column;background:#fff"></div>
    <div id="view-source-content" class="source-panel" style="display:none" data-content="<?= htmlspecialchars($htmlContent, ENT_QUOTES, 'UTF-8') ?>" data-content-type="<?= htmlspecialchars($contentType) ?>"></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var previewContent = document.getElementById('view-preview-content');
    var sourceContent  = document.getElementById('view-source-content');
    var previewTab     = document.getElementById('<?= $previewId ?>-tab');
    var sourceTab      = document.getElementById('<?= $sourceId ?>-tab');
    var downloadBtn    = document.getElementById('view-download-btn');
    var copyBtn        = document.getElementById('view-copy-btn');
    var jsToggle       = document.getElementById('view-js-toggle');
    var guid           = document.getElementById('view-guid').value;
    var currentTab     = 'rendered';
    var currentContentType = sourceContent.getAttribute('data-content-type') || 'html';
    var markdownLoaded = false;
    var renderedMarkdown = '';

    function getRawContent() {
        return sourceContent.getAttribute('data-content') || '';
    }

    /* ── Restore JS toggle preference ────────────── */
    if (jsToggle) {
        var storedJs = sessionStorage.getItem('view-js-toggle');
        jsToggle.checked = storedJs !== null ? (storedJs === 'true') : <?= DEFAULT_JS_ENABLED ? 'true' : 'false' ?>;
        jsToggle.addEventListener('change', function() {
            sessionStorage.setItem('view-js-toggle', jsToggle.checked);
            updatePreview();
        });
    }

    /* ── Load markdown-it on demand ────────────────── */

    /* ── Load markdown-it on demand ────────────────── */

    function loadMarkdownIt() {
        if (markdownLoaded) return Promise.resolve();
        return new Promise(function(resolve, reject) {
            var script = document.createElement('script');
            script.src = '/markdown-it.min.js';
            script.onload = function() {
                markdownLoaded = true;
                resolve();
            };
            script.onerror = function() {
                reject(new Error('Failed to load markdown-it'));
            };
            document.head.appendChild(script);
        });
    }

    /* ── Preview update ──────────────────────────── */

    var debounceTimer = null;

    function updatePreview() {
        if (currentTab !== 'rendered') return;

        var iframe = document.createElement('iframe');
        iframe.setAttribute('sandbox', (jsToggle && jsToggle.checked) ? 'allow-scripts allow-same-origin' : 'allow-same-origin');
        iframe.style.height = '100%';
        iframe.style.flex = '1';
        previewContent.innerHTML = '';
        previewContent.appendChild(iframe);

        if (currentContentType === 'markdown') {
            // Build the dark theme CSS
            var darkTheme = '<style>'
                + 'body{background:#1a1b26;color:#c0caf5;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen,Ubuntu,sans-serif;font-size:15px;line-height:1.7;padding:20px 24px;max-width:800px;margin:0 auto;}'
                + 'h1,h2,h3,h4,h5,h6{color:#c0caf5;font-weight:600;margin:1.4em 0 .6em;line-height:1.3;}'
                + 'h1{font-size:1.8em;border-bottom:1px solid #24283b;padding-bottom:.3em;}'
                + 'h2{font-size:1.4em;border-bottom:1px solid #24283b;padding-bottom:.3em;}'
                + 'h3{font-size:1.2em;color:#bb9af7;}'
                + 'h4{font-size:1em;color:#7dcfff;}'
                + 'p{margin:1em 0;}'
                + 'a{color:#7aa2f7;text-decoration:none;border-bottom:1px solid transparent;transition:border-color .2s;}'
                + 'a:hover{border-bottom-color:#7aa2f7;}'
                + 'strong{color:#e0af68;font-weight:600;}'
                + 'em{color:#9ece6a;font-style:italic;}'
                + 'code{background:#24283b;color:#f7768e;padding:2px 6px;border-radius:4px;font-family:"SF Mono","Fira Code","Cascadia Code",Consolas,monospace;font-size:.88em;}'
                + 'pre{background:#16161e;border-radius:8px;padding:16px 20px;overflow-x:auto;margin:1.2em 0;box-shadow:inset 0 0 0 1px #24283b;}'
                + 'pre code{background:none;padding:0;color:#c0caf5;font-size:.88em;}'
                + 'blockquote{border-left:3px solid #7aa2f7;padding:8px 16px;margin:1.2em 0;background:#16161e;border-radius:0 6px 6px 0;color:#a9b1d6;}'
                + 'blockquote p{margin:.5em 0;}'
                + 'ul,ol{padding-left:24px;margin:1em 0;}'
                + 'li{margin:.4em 0;}'
                + 'li::marker{color:#bb9af7;}'
                + 'hr{border:none;border-top:1px solid #24283b;margin:2em 0;}'
                + 'table{border-collapse:collapse;width:100%;margin:1.2em 0;font-size:.92em;}'
                + 'th,td{border:1px solid #24283b;padding:8px 12px;text-align:left;}'
                + 'th{background:#24283b;color:#bb9af7;font-weight:600;}'
                + 'tr:nth-child(even){background:#16161e;}'
                + 'img{max-width:100%;border-radius:8px;margin:1em 0;}'
                + ':not(pre)>code{background:#24283b;color:#f7768e;padding:2px 6px;border-radius:4px;}'
                + '</style>';

            function setSrcdoc(content) {
                iframe.setAttribute('srcdoc', darkTheme + content);
            }

            if (renderedMarkdown) {
                // Already cached — set immediately
                setSrcdoc(renderedMarkdown);
            } else {
                // Need to load markdown-it and render asynchronously
                loadMarkdownIt().then(function() {
                    if (typeof markdownit === 'function') {
                        renderedMarkdown = markdownit().render(getRawContent());
                        setSrcdoc(renderedMarkdown);
                    } else {
                        renderedMarkdown = getRawContent();
                        setSrcdoc(renderedMarkdown);
                    }
                }).catch(function() {
                    renderedMarkdown = getRawContent();
                    setSrcdoc(renderedMarkdown);
                });
            }
        } else {
            iframe.setAttribute('srcdoc', getRawContent());
        }
    }

    function switchTab(tab) {
        currentTab = tab;

        if (tab === 'rendered') {
            previewTab.className = 'tab-btn active';
            sourceTab.className = 'tab-btn';
            previewContent.style.display = '';
            sourceContent.style.display = 'none';
            updatePreview();
        } else {
            sourceTab.className = 'tab-btn active';
            previewTab.className = 'tab-btn';
            previewContent.style.display = 'none';
            sourceContent.style.display = '';

            if (!sourceContent.hasAttribute('data-populated')) {
                sourceContent.setAttribute('data-populated', 'true');
                sourceContent.innerHTML = '';
                var preEl = document.createElement('pre');
                var codeEl = document.createElement('code');
                codeEl.className = 'language-' + currentContentType;
                codeEl.textContent = getRawContent();
                preEl.appendChild(codeEl);
                sourceContent.appendChild(preEl);
                if (typeof Prism !== 'undefined') {
                    Prism.highlightElement(codeEl);
                }
            }
        }
    }

    previewTab.addEventListener('click', function() { switchTab('rendered'); });
    sourceTab.addEventListener('click', function() { switchTab('source'); });

    function copyToClipboard(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text);
        } else {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
        }
    }

    // Copy content to clipboard
    copyBtn.addEventListener('click', function() {
        copyToClipboard(getRawContent());
        copyBtn.textContent = '✓ Copied!';
        var label = currentContentType === 'markdown' ? '📋 Copy Markdown to Clipboard' : '📋 Copy HTML to Clipboard';
        setTimeout(function() { copyBtn.textContent = label; }, 1500);
    });

    // Download content
    downloadBtn.addEventListener('click', function() {
        window.open('/api/download?guid=' + guid, '_blank');
    });

    // Initial preview
    if (getRawContent().trim()) {
        updatePreview();
    }
});
</script>
