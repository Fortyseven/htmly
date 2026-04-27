<?php
/**
 * Edit Mode component — split-pane (HTML textarea left, iframe preview right).
 * No tabs. Editable content with live preview and syntax highlighting.
 *
 * @param string $htmlContent   Initial HTML content.
 * @param string $guid          Snippet GUID (optional, for edit-mode endpoints).
 * @param string $token         Access token (optional, for edit-mode endpoints).
 * @param array  $ttlPreset     TTL presets array [seconds => label].
 * @param int    $defaultTtl    Default TTL in seconds.
 */

$toolbarId    = 'edit-toolbar';
$editorId     = 'edit-editor';
$previewId    = 'edit-preview';
$resizeId     = 'edit-resize';
$mainId       = 'edit-main';
$editorPanel  = 'edit-editor-panel';
$previewPanel = 'edit-preview-panel';
?>

<!-- Toolbar: Live Update + TTL selector + buttons -->
<div class="toolbar" id="<?= $toolbarId ?>">
    <label class="toggle">
        <input type="checkbox" id="edit-live-toggle" checked>
        Live Update
    </label>
    <label class="toggle">
        <input type="checkbox" id="edit-js-toggle">
        Enable JS / Canvas
    </label>
    <div class="divider"></div>
    <select class="ttl-select" id="edit-ttl-select" title="Snippet TTL">
        <?php foreach ($ttlPreset as $secs => $label): ?>
        <option value="<?= $secs ?>" <?= $secs === $defaultTtl ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
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

<!-- Hidden GUID and token for API calls -->
<input type="hidden" id="edit-guid" value="<?= htmlspecialchars($guid ?? '') ?>">
<input type="hidden" id="edit-token" value="<?= htmlspecialchars($token ?? '') ?>">

<!-- Main: Split-pane with resizable divider -->
<div class="main" id="<?= $mainId ?>">
    <div class="editor-panel" id="<?= $editorPanel ?>">
        <textarea id="<?= $editorId ?>" spellcheck="false" wrap="off"></textarea>
        <pre id="highlight-overlay"><code id="highlight-code" class="language-html"></code></pre>
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
    var debounceTimer = null;

    /* ── JS / Canvas toggle ──────────────────────── */

    /* Restore preference */
    var storedJs = sessionStorage.getItem('edit-js-toggle');
    if (storedJs === 'true') {
        jsToggle.checked = true;
    }

    jsToggle.addEventListener('change', function() {
        sessionStorage.setItem('edit-js-toggle', jsToggle.checked);
        updatePreview();
    });

    /* ── Preview update ──────────────────────────── */

    function updatePreview() {
        var html = editor.value;
        var iframe = document.createElement('iframe');
        iframe.setAttribute('sandbox', jsToggle.checked ? 'allow-scripts allow-same-origin' : 'allow-same-origin');
        iframe.setAttribute('srcdoc', html);
        iframe.style.height = '100%';
        iframe.style.flex = '1';
        preview.innerHTML = '';
        preview.appendChild(iframe);
    }

    /* ── Syntax highlight ────────────────────────── */

    function updateHighlight() {
        var text = editor.value;
        // Handle trailing newline: Prism ignores empty final line
        var originalText = text;
        if (text[text.length - 1] === '\n') {
            text += ' ';
        }
        // HTML-escape < and & so Prism doesn't interpret them as tags
        highlightCode.innerHTML = text
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;');
        try { Prism.highlightElement(highlightCode); } catch(e) {}
    }

    /* ── Scroll sync ─────────────────────────────── */

    function syncScroll(source, target) {
        target.scrollTop = source.scrollTop;
        target.scrollLeft = source.scrollLeft;
    }

    var syncingScroll = false;
    editor.addEventListener('scroll', function() {
        if (syncingScroll) return;
        syncingScroll = true;
        syncScroll(editor, highlightOverlay);
        syncingScroll = false;
    });
    highlightOverlay.addEventListener('scroll', function() {
        if (syncingScroll) return;
        syncingScroll = true;
        syncScroll(highlightOverlay, editor);
        syncingScroll = false;
    });

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
        var payload = { html: editor.value, ttl: parseInt(ttlSelect.value) };
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
