# PRD: HTML Scratchpad — Temporary HTML Playground & Snippet Sharer

## Introduction

A lightweight PHP + SQLite web application that serves as a disposable HTML playground. The app has two modes:

- **Edit Mode** (home page `/` and `/s/{guid}?t={token}`): A split-pane layout with an HTML source `<textarea>` on the left and a rendered iframe preview on the right. Users write and modify HTML live.
- **View Mode** ( `/s/{guid}` without token): A read-only view with two tabs — "Rendered" (iframe preview) and "Source" (read-only HTML). No editing is possible.

Users write HTML (including inline CSS and JS — though JS is sandboxed at render time), preview it live rendered in a sandboxed `<iframe>`, and optionally share the result via a short, clean URL. Snippets auto-expire after a per-snippet configurable TTL (default 7 days), but the creator can also manually delete them using a secret access token embedded in the URL. The app is designed for developers to quickly prototype, test, and share small HTML snippets with zero setup — no accounts, no logins, no dependencies beyond PHP and SQLite.

---

## Goals

- Let users write, preview, save, share, and download HTML in a single-page interface with zero friction
- Render user HTML safely inside a sandboxed `<iframe>` with strict CSP
- Share snippets via a short, shareable URL containing a unique GUID
- Auto-expire snippets after a per-snippet configurable TTL (default 7 days)
- Let users toggle between live auto-refresh (every keystroke) and manual preview update
- Allow snippet creators to re-open and edit their snippet using a secret token in the URL
- Let users download their snippet as a `.html` file for offline use
- Keep the codebase minimal — no frameworks, pure PHP + SQLite, optionally a few files

---

## User Stories

### US-001: Write and preview HTML in a split-view editor
**Description:** As a user, I want to type HTML on one side and see it rendered live in an iframe on the other, so I can iterate quickly.

**Acceptance Criteria:**
- [ ] Page loads in **Edit Mode** with a split-pane layout (no tabs): HTML source `<textarea>` on the left, rendered iframe preview on the right
- [ ] A "Live Update" checkbox toggle:
  - When checked: preview auto-refreshes ~300ms after typing stops
  - When unchecked: preview only updates on each keystroke manually via a separate "Update Preview" button (or every keystroke regardless)
- [ ] Default to checked (live update on)
- [ ] The pane divider is resizable (user can drag to adjust widths)
- [ ] Buttons in the controls bar: Download HTML, Copy HTML to Clipboard, Delete snippet
- [ ] Typecheck/static analysis passes (PHP lint: `php -l`)
- [ ] Verify in browser using dev-browser skill

### US-002: Save snippet and generate shareable URL
**Description:** As a user, I want to save my HTML snippet and get a shareable URL, so I can send it to someone else.

**Acceptance Criteria:**
- [ ] A "Save" button (on the home page, not at `/s/{guid}`) generates a short GUID (8–10 alphanumeric characters) and stores the snippet in SQLite
- [ ] A TTL selector with preset options (1 hour, 1 day, 7 days) is visible in the controls bar; default is 7 days
- [ ] After saving, the page displays the shareable URL (e.g., `https://example.com/s/AbC3dEf`) in an `<input>` with a "Copy" button
- [ ] The URL is copied to clipboard when the "Copy" button is clicked
- [ ] A secret access token (random string, ≥32 characters) is also generated and displayed to the user after save
- [ ] The access token is shown **only once** at save time (stored in DB, never echoed again)
- [ ] On successful save, the browser URL updates to `/s/{guid}` via `history.pushState` (no page reload); the page then re-renders in Edit Mode with the token appended as a query parameter so editing is available
- [ ] If save fails (DB error), show a user-friendly error message
- [ ] Typecheck passes (`php -l`)
- [ ] Verify in browser using dev-browser skill

### US-003: Load and display a shared snippet via GUID
**Description:** As a visitor who received a share link, I want to open the URL and immediately see the HTML rendered in the iframe.

**Acceptance Criteria:**
- [ ] Visiting `/s/{guid}` loads the snippet from the database
- [ ] The page loads in **View Mode** (read-only)
- [ ] Two tabs: "Rendered" and "Source"
- [ ] The "Rendered" tab shows the HTML rendered in a sandboxed iframe
- [ ] The "Source" tab shows the raw HTML as read-only text (no editing possible)
- [ ] Buttons adjacent to the tabs: Download HTML, Copy HTML to Clipboard
- [ ] No editing is possible in View Mode — no textarea, no source editor
- [ ] If the snippet does not exist, show a "Snippet not found" page with a "Create your own" link
- [ ] Typecheck passes (`php -l`)
- [ ] Verify in browser using dev-browser skill

### US-004: Edit a saved snippet using a secret access token
**Description:** As the snippet creator, I want to re-open a shared snippet in edit mode so I can update it.

**Acceptance Criteria:**
- [ ] The share URL includes a token query parameter: `/s/{guid}?t={token}`
- [ ] When the token matches the stored token for that GUID, the page loads in **Edit Mode**:
  - No tabs — instead, two side-by-side panes: HTML source `<textarea>` (left), rendered iframe preview (right)
  - A "Live Update" checkbox toggle is visible
  - Buttons: Download HTML, Copy HTML to Clipboard, Delete snippet
  - The textarea is editable — the user can modify, add, or delete HTML content
  - Saving an updated snippet preserves the same GUID (URL does not change)
- [ ] When the token does **not** match or is absent, the page loads in **View Mode** (see US-003):
  - Two tabs: "Rendered" and "Source" (read-only)
  - Adjacent buttons: Download HTML, Copy HTML to Clipboard
  - No editing is possible
- [ ] Typecheck passes (`php -l`)
- [ ] Verify in browser using dev-browser skill

### US-005: Delete a snippet
**Description:** As the snippet creator, I want to manually delete my snippet before it expires.

**Acceptance Criteria:**
- [ ] In Edit Mode (valid token present), a "Delete snippet" button is visible in the controls bar
- [ ] Clicking "Delete" shows a confirmation dialog ("Are you sure? This cannot be undone.")
- [ ] Confirming deletes the snippet from the database
- [ ] After deletion, redirect to the home page with a "Snippet deleted" flash message
- [ ] Deleting a snippet also removes its access token from the database
- [ ] The Delete button is **not** visible in View Mode (no token)
- [ ] Typecheck passes (`php -l`)
- [ ] Verify in browser using dev-browser skill

### US-006: Auto-expire snippets based on per-snippet TTL
**Description:** As the system, I want to automatically clean up old snippets so the database stays small.

**Acceptance Criteria:**
- [ ] Snippets are stored with a `created_at` timestamp and a `ttl_seconds` (INTEGER) column in the database
- [ ] The `ttl_seconds` column defaults to 604800 (7 days) but can be set by the creator when saving
- [ ] On every page load, the app deletes snippets where `created_at + ttl_seconds < current_time` and logs the count
- [ ] Cleanup runs efficiently (indexed on `created_at`)
- [ ] Typecheck passes (`php -l`)

### US-007: Enforce security measures on all rendered content
**Description:** As a developer, I need to ensure that rendering user HTML in an iframe cannot be exploited to execute malicious scripts or escape the sandbox.

**Acceptance Criteria:**
- [ ] The iframe uses the `sandbox` attribute with a restrictive allowlist:
  - `allow-same-origin` — needed for CSS/fonts to work within the snippet
  - **Do NOT** include `allow-scripts` — user JS embedded inline in the HTML will not execute in the iframe (user should be aware of this via a note in the UI)
  - **Do NOT** include `allow-forms` — prevents data theft
  - **Do NOT** include `allow-popups` — prevents redirect attacks
- [ ] CSP headers are set on all HTTP responses:
  - `Content-Security-Policy: default-src 'none'; frame-src 'self'; object-src 'none'`
  - (This prevents loading external scripts, styles, images from the app itself)
- [ ] Save rate limiting: max 10 saves per IP per 10 minutes (tracked in `rate_limits` SQLite table)
- [ ] Read rate limiting: max 50 reads per IP per 1 minute (tracked in `rate_limits` SQLite table)
- [ ] Rate limit exceeded responses return HTTP 429 with a short error message
- [ ] Include `Retry-After` header on 429 responses
- [ ] Max snippet size: 50KB (enforced server-side before DB insert); rejects with error
- [ ] The app sets `X-Frame-Options: SAMEORIGIN` to prevent being embedded in other sites' iframes
- [ ] Referrer-Policy: `strict-origin-when-cross-origin`
- [ ] Typecheck passes (`php -l`)

### US-008: Provide a clean, minimal UI
**Description:** As a user, I want a simple, distraction-free interface so I can focus on writing HTML.

**Acceptance Criteria:**
- [ ] Clean, centered layout with a monospace font for the editor
- [ ] **Edit Mode:** Two side-by-side panes — HTML textarea (left), rendered iframe (right), with a draggable divider
- [ ] **View Mode:** Full-width with "Rendered" tab (default, shows iframe) and "Source" tab (read-only HTML), plus adjacent Download HTML and Copy HTML to Clipboard buttons
- [ ] A "Live Update" checkbox toggle is visible in Edit Mode (default: checked)
- [ ] A small informational note near the editor: "Inline JS is blocked for security — CSS will work."
- [ ] "Download HTML" and "Copy HTML to Clipboard" buttons are visible in both modes
- [ ] "Delete snippet" button is only visible in Edit Mode (valid token present)
- [ ] Responsive: works on desktop (primary) and basic mobile support
- [ ] No external CSS/JS frameworks — vanilla CSS + vanilla JS only (except bundled Prism.js for syntax highlighting)
- [ ] Verify in browser using dev-browser skill

### US-009: Download snippet as HTML file
**Description:** As a user, I want to download my snippet as a `.html` file so I can use it locally or elsewhere.

**Acceptance Criteria:**
- [ ] A "Download HTML" button is visible in both Edit Mode and View Mode (adjacent to the tabs in View Mode; in the controls bar in Edit Mode)
- [ ] Clicking "Download HTML" triggers a file download named `snippet-{guid}.html`
- [ ] The downloaded file contains only the raw HTML content (no wrapper, no iframe)
- [ ] No page reload is triggered by the download
- [ ] Typecheck passes (`php -l`)
- [ ] Verify in browser using dev-browser skill

---

## Functional Requirements

- FR-1: The system must store HTML snippets in a SQLite database file (`snippets.db`) with fields: `guid` (TEXT PRIMARY KEY, 8–10 chars alphanumeric), `html_content` (TEXT), `access_token` (TEXT UNIQUE, ≥32 chars), `created_at` (INTEGER, Unix timestamp), `ttl_seconds` (INTEGER, default 604800)
- FR-2: The system must generate an 8–10 character alphanumeric GUID for each new snippet; if the GUID already exists in the DB, retry up to 3 times before showing an error
- FR-3: The system must generate a ≥32 character random access token for each snippet
- FR-4: The system must serve the home page at `/` with a split-pane editor (textarea left, iframe preview right), Live Update toggle, TTL selector, Save button, and Download HTML / Copy HTML to Clipboard buttons
- FR-5: The system must serve a snippet at `/s/{guid}` — View Mode (no token) with Rendered/Source tabs and Download HTML / Copy HTML to Clipboard buttons; Edit Mode (valid token) with split-pane editor, Live Update toggle, Download HTML / Copy HTML to Clipboard / Delete snippet buttons
- FR-5b: The home page `/` always operates in Edit Mode (the user is creating a new snippet)
- FR-6: The system must auto-delete snippets older than their per-snippet TTL on each page load
- FR-6b: The `ttl_seconds` column in the snippets table must default to 604800 (7 days)
- FR-7: The system must enforce a 50KB maximum snippet size
- FR-8: The system must enforce save rate limiting (10 saves per IP per 10 minutes) and read rate limiting (50 reads per IP per 1 minute) using a `rate_limits` SQLite table
- FR-8b: The system must return HTTP 429 with a `Retry-After` header when rate limits are exceeded
- FR-9: The system must render user HTML in a sandboxed `<iframe>` without `allow-scripts`, `allow-forms`, or `allow-popups`
- FR-10: The system must set CSP, X-Frame-Options, and Referrer-Policy headers on all responses
- FR-11: The system must provide a clipboard copy action for the share URL
- FR-11b: On successful save from the home page, the browser URL must update to `/s/{guid}` via `history.pushState` (no page reload)
- FR-11c: The save form must include a TTL selector with preset options: 1 hour, 1 day, 7 days (default: 7 days)
- FR-12: The system must support two modes at `/s/{guid}`:
  - **View Mode** (no valid token): two tabs ("Rendered" shows iframe, "Source" shows read-only HTML), Download HTML and Copy HTML to Clipboard buttons. No editing is possible.
  - **Edit Mode** (valid token present): two side-by-side panes (HTML textarea left, iframe preview right), Live Update checkbox, Download HTML, Copy HTML to Clipboard, and Delete snippet buttons.
- FR-13: The system must prevent its own page from being embedded in iframes by other sites (`X-Frame-Options: SAMEORIGIN`)
- FR-14: The system must handle SQLite errors gracefully with user-friendly messages
- FR-15: The system must use a flat file structure (e.g., `index.php`, `config.php`, `db.php`) — no autoloader, no Composer
- FR-15b: The system must serve a downloadable `.html` file when the user clicks "Download" — the file must be named `snippet-{guid}.html` and contain only the raw HTML content
- FR-15c: The download endpoint must work for both edit mode and view-only mode (no token required)
- FR-15d: The system must create and maintain a `rate_limits` SQLite table with columns: `ip_hash` (TEXT, SHA-256 hash of the client IP + server-side salt), `action` (TEXT — 'save' or 'read'), `window_start` (INTEGER), `count` (INTEGER), with a composite primary key on `(ip_hash, action, window_start)`

---

## Non-Goals

- **No user accounts or authentication** — access is token-based and anonymous
- **No Markdown support** — only raw HTML (with optional inline CSS and JS in the HTML; JS is sandboxed at render time)
- **No live collaboration** — single user per session
- **No version history** — no save/load of previous versions
- **No analytics or logging of snippet content** — only metadata for cleanup
- **No mobile app** — web only
- **No support for PHP execution in the iframe** — user JS is blocked entirely
- **No CDN or external assets** — everything is self-contained
- **No image/file upload support**

---

## Design Considerations

- **Layout (Edit Mode — home page `/` and `/s/{guid}?t={token}`):** Two-panel split view — HTML source `<textarea>` on the left, rendered iframe preview on the right. A draggable divider between panes.
- **Layout (View Mode — `/s/{guid}` without token):** Full-width layout with two tabs ("Rendered" and "Source") at the top, plus Download HTML and Copy HTML to Clipboard buttons adjacent to the tabs. No split-pane, no editor.
- **Editor:** Use `<textarea>` with monospace font (`JetBrains Mono` or `Fira Code` if available, otherwise `monospace`). Editable only in Edit Mode.
- **Syntax highlighting:** Use [Prism.js](https://prismjs.com/) — bundle `prism.min.js` and `prism.css` (HTML mode only) locally; no CDN, no network requests. In View Mode, the Source tab displays the HTML with Prism.js syntax highlighting. In Edit Mode, the textarea remains plain text (true syntax highlighting in a textarea is not possible).
- **Rendered tab (View Mode):** Shows the HTML rendered in a sandboxed `<iframe>`.
- **Source tab (View Mode):** Shows the raw HTML as read-only text with Prism.js syntax highlighting. No editing is possible.
- **Controls bar (Edit Mode):** Contains Live Update checkbox toggle, Download HTML button, Copy HTML to Clipboard button, and Delete snippet button (only visible when valid token present).
- **Color scheme:** Dark or light neutral background, high contrast text
- **Existing components to reuse:** None (this is a greenfield project)

---

## Technical Considerations

### Rewrite Rules (Clean URLs)
- Use a `/.htaccess` file (or equivalent server rewrite) to route `/s/{guid}` to `index.php`
- Example for Apache:
  ```apache
  RewriteEngine On
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteRule ^s/([a-zA-Z0-9]+)$ index.php?guid=$1 [L,QSA]
  ```
- For PHP built-in server, `index.php` can detect the route via `$_SERVER['REQUEST_URI']`

### File Structure (suggested)
```
/
├── index.php        # Main entry point, router
├── config.php       # TTL, rate limits, max size, etc.
├── db.php           # SQLite connection, snippet CRUD, rate limiting, and cleanup logic
├── prism.min.js     # Prism.js (bundled, HTML mode only)
├── prism.css        # Prism.js styling (bundled)
├── snippets.db      # SQLite database (created automatically)
└── README.md        # Project description
```

### PHP Requirements
- PHP 8.0+ with SQLite3 extension (`php80-sqlite3` or built-in)
- No Composer, no external dependencies
- Use PHP's built-in web server for local development: `php -S localhost:8080`

### Database Schema
```sql
CREATE TABLE IF NOT EXISTS snippets (
    guid          TEXT PRIMARY KEY,
    html_content  TEXT NOT NULL,
    access_token  TEXT NOT NULL,
    created_at    INTEGER NOT NULL,
    ttl_seconds   INTEGER NOT NULL DEFAULT 604800  -- 7 days in seconds
);
CREATE INDEX IF NOT EXISTS idx_created_at ON snippets(created_at);

CREATE TABLE IF NOT EXISTS rate_limits (
    ip_hash     TEXT NOT NULL,       -- SHA-256 of client IP + server salt
    action      TEXT NOT NULL,       -- 'save' or 'read'
    window_start INTEGER NOT NULL,  -- Unix timestamp of window start
    count       INTEGER NOT NULL DEFAULT 1,
    PRIMARY KEY (ip_hash, action, window_start)
);
```

### GUID Generation
- Use `bin2hex(random_bytes(5))` for a 10-character hex GUID (48 bits of entropy), then take the first 8–10 characters as the display GUID
- Alternatively, use `substr(strtr(base64_encode(random_bytes(6)), '+/', '-_'), 0, 8)` for 8 random alphanumeric characters
- If a generated GUID already exists in the DB, retry up to 3 times with a new random value
- After 3 failed retries, show a user-friendly error (extremely unlikely to hit)

### Access Token Generation
- Use `bin2hex(random_bytes(20))` for a 40-character hex token

### Security Headers (PHP)
```php
header("Content-Security-Policy: default-src 'none'; frame-src 'self'; object-src 'none'");
header("X-Frame-Options: SAMEORIGIN");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("X-Content-Type-Options: nosniff");
```

### Rate Limiting
- Persist in SQLite `rate_limits` table with columns: `ip_hash` (TEXT, SHA-256 hash of client IP + server-side salt), `action` (TEXT — 'save' or 'read'), `window_start` (INTEGER, Unix timestamp), `count` (INTEGER)
- Composite primary key on `(ip_hash, action, window_start)` for efficient lookups
- Hash every incoming client IP before storing: `ip_hash = sha256($_SERVER['REMOTE_ADDR'] . $SALT)` where `$SALT` is a fixed server-side string in `config.php`
- **Save limit:** 10 saves per IP per 10 minutes
- **Read limit:** 50 reads per IP per 1 minute
- Before each save or read, check the count for the current window; if exceeded, return HTTP 429
- Clean up old `rate_limits` entries (older than 10 minutes) on every page load alongside snippet cleanup
- The raw IP is never stored — only the irreversible hash

### iframe Sandbox Attributes
```html
<iframe sandbox="allow-same-origin" srcdoc="..."></iframe>
```
- `srcdoc` is preferred over `src` to avoid separate HTTP requests
- `allow-same-origin` lets embedded CSS/fonts work inside the snippet
- **No** `allow-scripts`, `allow-forms`, `allow-popups`

---

## Success Metrics

- A new user can write HTML and see it rendered in under 5 seconds
- A snippet can be saved and shared via copy-paste in under 10 seconds
- Live preview auto-refresh toggle works smoothly (no jank or lag)
- The iframe sandbox prevents any script execution in the preview (verified by test)
- Database stays under 1MB for typical usage (auto-cleanup ensures this)
- No successful XSS or iframe-escape attempts in testing

---
