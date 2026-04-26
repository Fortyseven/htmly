<?php
/**
 * Edit Mode component — split-pane (HTML textarea left, iframe preview right).
 * No tabs. Editable content with live preview.
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
    <div class="divider"></div>
    <select class="ttl-select" id="edit-ttl-select" title="Snippet TTL">
        <?php foreach ($ttlPreset as $secs => $label): ?>
        <option value="<?= $secs ?>" <?= $secs === $defaultTtl ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn btn-primary" id="edit-save-btn">💾 Save</button>
    <div class="divider"></div>
    <button class="btn btn-secondary" id="edit-copy-btn">📋 Copy HTML to Clipboard</button>
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
        <textarea id="<?= $editorId ?>" placeholder="Type your HTML here..." autofocus><?= htmlspecialchars($htmlContent) ?></textarea>
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
    var saveBtn = document.getElementById('edit-save-btn');
    var copyBtn = document.getElementById('edit-copy-btn');
    var downloadBtn = document.getElementById('edit-download-btn');
    var deleteBtn = document.getElementById('edit-delete-btn');
    var resizeHandle = document.getElementById('<?= $resizeId ?>');
    var editorPanel = document.getElementById('<?= $editorPanel ?>');
    var mainArea = document.getElementById('<?= $mainId ?>');
    var guid = document.getElementById('edit-guid').value;
    var token = document.getElementById('edit-token').value;
    var debounceTimer = null;

    /* ── Preview update ──────────────────────────── */

    function updatePreview() {
        var html = editor.value;
        var iframe = document.createElement('iframe');
        iframe.setAttribute('sandbox', 'allow-same-origin');
        iframe.setAttribute('srcdoc', html);
        iframe.style.height = '100%';
        iframe.style.flex = '1';
        preview.innerHTML = '';
        preview.appendChild(iframe);
    }

    editor.addEventListener('input', function() {
        if (liveToggle.checked) {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(updatePreview, <?= LIVE_PREVIEW_DEBOUNCE ?>);
        }
    });

    // Initial preview
    if (editor.value.trim()) {
        updatePreview();
    }

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
        // Create a temporary flash message at the top of the page
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

        // Auto-dismiss after 4 seconds
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
