<?php
/**
 * Edit Mode component — split-pane (HTML/Markdown textarea left, iframe preview right).
 * No tabs. Editable content with live preview and syntax highlighting.
 *
 * @param string $htmlContent   Initial HTML/Markdown content.
 * @param string $guid          Snippet GUID (optional, for edit-mode endpoints).
 * @param string $token         Access token (optional, for edit-mode endpoints).
 * @param array  $ttlPreset     TTL presets array [seconds => label].
 * @param int    $currentTtl    Current TTL to pre-select (defaults to DEFAULT_TTL_SECONDS).
 * @param bool   $isAdmin       True if the client IP is in ADMIN_IP_WHITELIST.
 * @param string $contentType   'html' or 'markdown' (defaults to 'html').
 */

$toolbarId    = 'edit-toolbar';
$editorId     = 'edit-editor';
$previewId    = 'edit-preview';
$resizeId     = 'edit-resize';
$mainId       = 'edit-main';
$editorPanel  = 'edit-editor-panel';
$previewPanel = 'edit-preview-panel';
$contentType  = $contentType ?? 'html';
?>

<!-- Toolbar: Live Update + Content Type + TTL selector + buttons -->
<div class="toolbar" id="<?= $toolbarId ?>">
    <label class="toggle">
        <input type="checkbox" id="edit-live-toggle" checked>
        Live Update
    </label>
    <div class="divider"></div>
    <div class="tab-group" id="edit-ct-group">
        <button class="tab-btn<?= $contentType === 'html' ? ' active' : '' ?>" data-ct="html" id="edit-ct-html" type="button">HTML</button>
        <button class="tab-btn<?= $contentType === 'markdown' ? ' active' : '' ?>" data-ct="markdown" id="edit-ct-markdown" type="button">Markdown</button>
    </div>
    <div class="divider"></div>
    <label class="toggle">
        <input type="checkbox" id="edit-wrap-toggle" checked>
        Word Wrap
    </label>
    <div class="divider"></div>
    <label class="toggle">
        <input type="checkbox" id="edit-js-toggle"<?= DEFAULT_JS_ENABLED ? ' checked' : '' ?>>
        Enable JS / Canvas
    </label>
    <div class="divider"></div>
    <select class="ttl-select" id="edit-ttl-select" title="Snippet TTL">
        <?php foreach ($ttlPreset as $secs => $label): ?>
        <option value="<?= $secs ?>" <?= $secs === ($currentTtl ?? DEFAULT_TTL_SECONDS) ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
        <?php if ($isAdmin ?? false): ?>
        <option value="0" <?= (($currentTtl ?? DEFAULT_TTL_SECONDS) === TTL_PERMANENT) ? 'selected' : '' ?>>♾️ Permanent</option>
        <?php endif; ?>
    </select>
    <button class="btn btn-primary" id="edit-save-btn">💾 Save</button>
    <div class="divider"></div>
    <button class="btn btn-secondary" id="edit-copy-btn">📋 Copy HTML to Clipboard</button>
    <button class="btn btn-secondary" id="edit-share-btn">🔗 Copy Share Link</button>
    <button class="btn btn-secondary" id="edit-download-btn">⬇️ Download HTML</button>
    <?php if (!empty($guid)): ?>
    <button class="btn btn-danger" id="edit-delete-btn">🗑️ Delete snippet</button>
    <?php endif; ?>
</div>

<!-- Hidden GUID, token, and content type for API calls -->
<input type="hidden" id="edit-guid" value="<?= htmlspecialchars($guid ?? '') ?>">
<input type="hidden" id="edit-token" value="<?= htmlspecialchars($token ?? '') ?>">
<input type="hidden" id="edit-content-type" value="<?= htmlspecialchars($contentType) ?>">

<!-- Main: Split-pane with resizable divider -->
<div class="main" id="<?= $mainId ?>">
    <div class="editor-panel" id="<?= $editorPanel ?>">
        <textarea id="<?= $editorId ?>" spellcheck="false" wrap="off"></textarea>
        <pre id="highlight-overlay"><code id="highlight-code" class="language-<?= htmlspecialchars($contentType) ?>"></code></pre>
    </div>
    <div class="divider" id="<?= $resizeId ?>"></div>
    <div class="preview-panel" id="<?= $previewPanel ?>">
        <div id="<?= $previewId ?>"></div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var editor = document.getElementById('<?= $editorId ?>');
    var preview = document.getElementById('<?= $previewId ?>');
    var liveToggle = document.getElementById('edit-live-toggle');
    var jsToggle = document.getElementById('edit-js-toggle');
    var wrapToggle = document.getElementById('edit-wrap-toggle');
    var saveBtn = document.getElementById('edit-save-btn');
    var copyBtn = document.getElementById('edit-copy-btn');
    var downloadBtn = document.getElementById('edit-download-btn');
    var deleteBtn = document.getElementById('edit-delete-btn');
    var resizeHandle = document.getElementById('<?= $resizeId ?>');
    var highlightOverlay = document.getElementById('highlight-overlay');
    var highlightCode  = document.getElementById('highlight-code');
    var editorPanel = document.getElementById('<?= $editorPanel ?>');
    var mainArea = document.getElementById('<?= $mainId ?>');
    var guid = document.getElementById('edit-guid').value;
    var token = document.getElementById('edit-token').value;
    var contentTypeEl = document.getElementById('edit-content-type');
    var currentContentType = contentTypeEl.value || 'html';
    var debounceTimer = null;

    /* ── JS / Canvas toggle ──────────────────────── */

    /* Restore preference or use server default */
    var storedJs = sessionStorage.getItem('edit-js-toggle');
    jsToggle.checked = storedJs !== null ? (storedJs === 'true') : <?= DEFAULT_JS_ENABLED ? 'true' : 'false' ?>;

    jsToggle.addEventListener('change', function() {
        sessionStorage.setItem('edit-js-toggle', jsToggle.checked);
        updatePreview();
    });

    /* ── Word wrap toggle ────────────────────────── */

    /* Restore preference or default on */
    var storedWrap = sessionStorage.getItem('edit-wrap-toggle');
    wrapToggle.checked = storedWrap !== null ? (storedWrap === 'true') : true;
    if (wrapToggle.checked) {
        editor.classList.add('word-wrap');
        highlightCode.classList.add('word-wrap');
    }

    wrapToggle.addEventListener('change', function() {
        sessionStorage.setItem('edit-wrap-toggle', wrapToggle.checked);
        editor.classList.toggle('word-wrap', wrapToggle.checked);
        highlightCode.classList.toggle('word-wrap', wrapToggle.checked);
    });

    /* ── Content type toggle ─────────────────────── */

    function switchContentType(newType) {
        if (newType === currentContentType) return;
        currentContentType = newType;
        contentTypeEl.value = newType;

        // Update button states
        document.getElementById('edit-ct-html').classList.toggle('active', newType === 'html');
        document.getElementById('edit-ct-markdown').classList.toggle('active', newType === 'markdown');

        // Update highlight code class
        highlightCode.className = 'language-' + newType;

        // Update placeholder
        var placeholder = newType === 'html' ? 'Type your HTML here...' : 'Type your Markdown here...';
        var textareaEl = document.getElementById('<?= $editorId ?>');
        var style = textareaEl.style; // we use ::before pseudo-element, adjust via data attr
        textareaEl.setAttribute('data-placeholder', placeholder);

        // Update copy button text
        if (newType === 'markdown') {
            copyBtn.textContent = '📋 Copy Markdown to Clipboard';
        } else {
            copyBtn.textContent = '📋 Copy HTML to Clipboard';
        }

        // Update preview
        updatePreview();
        updateHighlight();
    }

    document.getElementById('edit-ct-html').addEventListener('click', function() {
        switchContentType('html');
    });
    document.getElementById('edit-ct-markdown').addEventListener('click', function() {
        switchContentType('markdown');
    });

    /* ── Markdown rendering: inline md-it into srcdoc ─ */

    function getMarkdownItScript() {
        return fetch('/markdown-it.min.js')
            .then(function(r) { return r.text(); })
            .catch(function() { return null; });
    }

    function buildMdSrcdoc(mdJs, mdContent) {
        if (mdJs) {
            /* Pass markdown via data attribute — avoids all JS string escaping issues.
               decodeURIComponent safely reverses encodeURIComponent (only URL-safe chars). */
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
            var js = '<script>' + mdJs + '<\/script>'
                + '<script>(function(){try{document.body.innerHTML=markdownit().render(decodeURIComponent(document.body.dataset.md))}catch(e){document.body.innerHTML="<p>Render error</p>"}})()<\/script>';
            return '<!DOCTYPE html><html><head>' + darkTheme + '<\/head><body data-md="' + encodeURIComponent(mdContent) + '">' + js + '</body></html>';
        } else {
            var escaped = mdContent.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
            return '<!DOCTYPE html><html><body><p style="padding:16px;color:#565f89;">⚠️ Failed to load Markdown renderer. Showing raw content.</p><pre style="white-space:pre-wrap;">' + escaped + '<\/pre></body></html>';
        }
    }

    /* ── Preview update ──────────────────────────── */

    var mdScriptPromise = null;
    var mdCache = null;

    function updatePreview() {
        var content = editor.value;
        var iframe = document.createElement('iframe');
        iframe.setAttribute('sandbox', jsToggle.checked ? 'allow-scripts allow-same-origin' : 'allow-same-origin');
        iframe.style.height = '100%';
        iframe.style.flex = '1';
        preview.innerHTML = '';
        preview.appendChild(iframe);

        if (currentContentType === 'markdown') {
            if (mdCache && mdCache.content === content) {
                iframe.setAttribute('srcdoc', mdCache.srcdoc);
                return;
            }
            if (!mdScriptPromise) {
                mdScriptPromise = getMarkdownItScript();
            }
            var srcdoc = '<!DOCTYPE html><html><body><p style="padding:16px;color:#888;">Loading markdown renderer…</p></body></html>';
            iframe.setAttribute('srcdoc', srcdoc);
            mdScriptPromise.then(function(mdJs) {
                srcdoc = buildMdSrcdoc(mdJs, content);
                mdCache = { content: content, srcdoc: srcdoc };
                iframe.setAttribute('srcdoc', srcdoc);
            });
        } else {
            iframe.setAttribute('srcdoc', content);
            mdCache = null;
        }
    }

    /* ── Syntax highlight ────────────────────────── */

    function updateHighlight() {
        var text = editor.value;
        // Handle trailing newline: Prism ignores empty final line
        if (text[text.length - 1] === '\n') {
            text += ' ';
        }
        // HTML-escape < and & so Prism doesn't interpret them as tags
        highlightCode.innerHTML = text
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;');
        try { Prism.highlightElement(highlightCode); } catch(e) {}
    }

    /* ── Scroll sync (transform-based) ───────────── */

    /* The highlight overlay has overflow:hidden and uses CSS transform
       instead of scrollLeft/scrollTop. This avoids visual drift caused
       by width mismatches between plain-text textarea and Prism <span>
       tokens — the overlay content always follows the textarea exactly. */

    function syncScroll() {
        var sLeft = editor.scrollLeft;
        var sTop  = editor.scrollTop;
        highlightCode.style.transform = 'translate(' + (-sLeft) + 'px, ' + (-sTop) + 'px)';
    }

    editor.addEventListener('scroll', syncScroll);

    /* ── Tab key handling ────────────────────────── */

    editor.addEventListener('keydown', function(e) {
        if (e.key === 'Tab') {
            e.preventDefault();
            var start = editor.selectionStart;
            var end = editor.selectionEnd;
            // Insert tab character
            editor.value = editor.value.substring(0, start) + '\t' + editor.value.substring(end);
            editor.selectionStart = editor.selectionEnd = start + 1;
            updateHighlight();
            if (liveToggle.checked) {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(updatePreview, <?= LIVE_PREVIEW_DEBOUNCE ?>);
            }
        }
    });

    /* ── Input handler ───────────────────────────── */

    editor.addEventListener('input', function() {
        updateHighlight();
        if (liveToggle.checked) {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(updatePreview, <?= LIVE_PREVIEW_DEBOUNCE ?>);
        }
    });

    /* ── Paste as plain text ─────────────────────── */

    editor.addEventListener('paste', function(e) {
        e.preventDefault();
        var text = (e.clipboardData || window.clipboardData).getData('text/plain');
        var start = editor.selectionStart;
        var end = editor.selectionEnd;
        editor.value = editor.value.substring(0, start) + text + editor.value.substring(end);
        editor.selectionStart = editor.selectionEnd = start + text.length;
        updateHighlight();
        if (liveToggle.checked) {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(updatePreview, <?= LIVE_PREVIEW_DEBOUNCE ?>);
        }
    });

    /* ── Set initial content & highlight ─────────── */

    var initialPlaceholder = currentContentType === 'html' ? 'Type your HTML here...' : 'Type your Markdown here...';
    editor.setAttribute('data-placeholder', initialPlaceholder);
    copyBtn.textContent = currentContentType === 'html' ? '📋 Copy HTML to Clipboard' : '📋 Copy Markdown to Clipboard';
    downloadBtn.title = currentContentType === 'html' ? 'Download HTML' : 'Download Markdown';
    editor.value = <?= json_encode($htmlContent ?: '') ?>;
    if (editor.value.trim()) {
        updateHighlight();
        updatePreview();
    }
    editor.focus();

    /* ── Split pane resize ───────────────────────── */

    var isResizing = false;

    resizeHandle.addEventListener('mousedown', function(e) {
        isResizing = true;
        resizeHandle.classList.add('active');
        document.body.style.cursor = 'col-resize';
        document.body.style.userSelect = 'none';
        e.preventDefault();
    });

    document.addEventListener('mousemove', function(e) {
        if (!isResizing) return;
        var rect = mainArea.getBoundingClientRect();
        var pct = ((e.clientX - rect.left) / rect.width) * 100;
        pct = Math.max(15, Math.min(70, pct));
        editorPanel.style.width = pct + '%';
    });

    document.addEventListener('mouseup', function() {
        isResizing = false;
        resizeHandle.classList.remove('active');
        document.body.style.cursor = '';
        document.body.style.userSelect = '';
    });

    /* ── Clipboard ───────────────────────────────── */

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

    /* ── Copy HTML to Clipboard ──────────────────── */

    copyBtn.addEventListener('click', function() {
        copyToClipboard(editor.value);
        copyBtn.textContent = '✓ Copied!';
        setTimeout(function() { copyBtn.textContent = '📋 Copy HTML to Clipboard'; }, 1500);
    });

    /* ── Copy Share Link (strips token from URL) ─── */

    var shareBtn = document.getElementById('edit-share-btn');
    if (shareBtn) {
        shareBtn.addEventListener('click', function() {
            var url = window.location.href;
            // Remove token query parameter
            url = url.replace(/\?.*$/, '');
            copyToClipboard(url);
            shareBtn.textContent = '✓ Copied!';
            setTimeout(function() { shareBtn.textContent = '🔗 Copy Share Link'; }, 1500);
        });
    }

    /* ── Download HTML ───────────────────────────── */

    downloadBtn.addEventListener('click', function() {
        window.open('/api/download?guid=' + guid, '_blank');
    });

    /* ── Delete snippet ──────────────────────────── */

    function handleDelete(g, t) {
        if (!confirm('Are you sure? This cannot be undone.')) return;
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '/api/delete', true);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.onreadystatechange = function() {
            if (xhr.readyState !== 4) return;
            if (xhr.status === 200) {
                flashMessage('Snippet deleted');
                window.location.href = '/';
            } else {
                try { alert(JSON.parse(xhr.responseText).error || 'Delete failed'); }
                catch(e) { alert('Delete failed'); }
            }
        };
        xhr.send(JSON.stringify({ guid: g, token: t }));
    }

    if (deleteBtn) {
        deleteBtn.addEventListener('click', function() {
            handleDelete(guid, token);
        });
    }

    /* ── Save ────────────────────────────────────── */

    function resetSaveBtn() {
        saveBtn.removeAttribute('disabled');
        saveBtn.textContent = '💾 Save';
    }

    saveBtn.addEventListener('click', function() {
        saveBtn.setAttribute('disabled', '');
        saveBtn.textContent = '⏳ Saving...';

        var xhr = new XMLHttpRequest();
        xhr.open('POST', '/api/save', true);
        xhr.setRequestHeader('Content-Type', 'application/json');

        xhr.onload = function() {
            if (xhr.status === 200) {
                var data = JSON.parse(xhr.responseText);

                // Only navigate and update GUID/token for new snippets
                if (!data.updated) {
                    var fullUrl = window.location.origin + data.url + '?t=' + data.token;
                    history.pushState(null, '', fullUrl);
                    document.getElementById('edit-guid').value = data.guid;
                    document.getElementById('edit-token').value = data.token;
                    if (deleteBtn) {
                        deleteBtn.onclick = function() { handleDelete(data.guid, data.token); };
                    }
                    flashMessage('Snippet saved! Share URL: ' + data.url);
                } else {
                    flashMessage('Snippet updated');
                }

                updatePreview();
                resetSaveBtn();
            } else {
                try { alert(JSON.parse(xhr.responseText).error || 'Save failed'); }
                catch(e) { alert('Save failed'); }
                resetSaveBtn();
            }
        };

        xhr.onerror = function() {
            alert('Network error');
            resetSaveBtn();
        };

        var ttlSelect = document.getElementById('edit-ttl-select');
        var editGuid = document.getElementById('edit-guid').value;
        var editToken = document.getElementById('edit-token').value;
        var payload = { html: editor.value, ttl: parseInt(ttlSelect.value), contentType: currentContentType };
        if (editGuid && editToken) {
            payload.guid = editGuid;
            payload.token = editToken;
        }
        xhr.send(JSON.stringify(payload));
    });

    /* ── Helpers ─────────────────────────────────── */

    function flashMessage(msg) {
        var flashEl = document.getElementById('flash');
        if (!flashEl) {
            flashEl = document.createElement('div');
            flashEl.id = 'flash';
            flashEl.className = 'flash visible';
            var header = document.querySelector('.header');
            header.parentNode.insertBefore(flashEl, header.nextSibling);
        }
        flashEl.querySelector('.msg') ? flashEl.querySelector('.msg').textContent = msg : flashEl.textContent = msg;
        flashEl.classList.add('visible');
        setTimeout(function() {
            flashEl.classList.remove('visible');
        }, 4000);
    }

    /* ── Keyboard shortcut: Ctrl+S to save ───────── */

    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            saveBtn.click();
        }
    });
});
</script>
