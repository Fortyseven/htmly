<?php
/**
 * View Mode component — read-only, two tabs (Rendered / Source).
 * No editing is possible.
 *
 * @param string $guid          Snippet GUID (for download + copy endpoints).
 * @param string $htmlContent   Raw HTML content to display.
 */

$toolbarId = 'view-toolbar';
$previewId = 'view-preview';
$sourceId  = 'view-source';
?>

<!-- Toolbar: tabs + Download HTML + Copy HTML to Clipboard -->
<div class="toolbar" id="<?= $toolbarId ?>">
    <div class="tab-group">
        <button class="tab-btn active" data-tab="rendered" id="<?= $previewId ?>-tab">Rendered</button>
        <button class="tab-btn" data-tab="source" id="<?= $sourceId ?>-tab">Source</button>
    </div>
    <div class="divider"></div>
    <label class="toggle">
        <input type="checkbox" id="view-js-toggle"<?= DEFAULT_JS_ENABLED ? ' checked' : '' ?>>
        Enable JS / Canvas
    </label>
    <div class="divider"></div>
    <button class="btn btn-secondary" id="view-download-btn">⬇️ Download HTML</button>
    <button class="btn btn-secondary" id="view-copy-btn">📋 Copy HTML to Clipboard</button>
</div>

<!-- Hidden input for the GUID -->
<input type="hidden" id="view-guid" value="<?= htmlspecialchars($guid) ?>">

<!-- Main: Rendered tab shows iframe, Source tab shows read-only HTML -->
<div class="main" id="<?= $previewId ?>">
    <div id="view-preview-content" style="flex:1;display:flex;flex-direction:column;background:#fff"></div>
    <div id="view-source-content" class="source-panel" style="display:none" data-html="<?= htmlspecialchars($htmlContent, ENT_QUOTES, 'UTF-8') ?>"></div>
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

    /* ── Restore JS toggle preference ────────────── */
    var storedJs = sessionStorage.getItem('view-js-toggle');
    jsToggle.checked = storedJs !== null ? (storedJs === 'true') : <?= DEFAULT_JS_ENABLED ? 'true' : 'false' ?>;

    /* ── JS / Canvas toggle ──────────────────────── */

    jsToggle.addEventListener('change', function() {
        sessionStorage.setItem('view-js-toggle', jsToggle.checked);
        updatePreview();
    });

    var debounceTimer = null;

    function updatePreview() {
        if (currentTab !== 'rendered') return;
        var html = sourceContent.getAttribute('data-html') || '';
        var iframe = document.createElement('iframe');
        iframe.setAttribute('sandbox', jsToggle.checked ? 'allow-scripts allow-same-origin' : 'allow-same-origin');
        iframe.setAttribute('srcdoc', html);
        iframe.style.height = '100%';
        iframe.style.flex = '1';
        previewContent.innerHTML = '';
        previewContent.appendChild(iframe);
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
                codeEl.className = 'language-html';
                codeEl.textContent = sourceContent.getAttribute('data-html');
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

    // Copy HTML content to clipboard
    copyBtn.addEventListener('click', function() {
        copyToClipboard(sourceContent.getAttribute('data-html') || '');
        copyBtn.textContent = '✓ Copied!';
        setTimeout(function() { copyBtn.textContent = '📋 Copy HTML to Clipboard'; }, 1500);
    });

    // Download HTML
    downloadBtn.addEventListener('click', function() {
        window.open('/api/download?guid=' + guid, '_blank');
    });

    // Initial preview
    if (sourceContent.getAttribute('data-html') && sourceContent.getAttribute('data-html').trim()) {
        updatePreview();
    }
});
</script>
