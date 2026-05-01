# HTML Scratchpad

A lightweight **HTML playground** — write, preview, save, and share HTML snippets with zero setup. Built with PHP + SQLite. No accounts, no logins, no dependencies.

## Features

- ✍️ **Live HTML editor** with split-view preview in a sandboxed `<iframe>`
- 📝 **Markdown support** — write Markdown with client-side rendering via markdown-it
- 💾 **Save & share** — get a clean short URL for any snippet
- ⏱️ **Auto-expire** — snippets self-delete after a configurable TTL (1h / 1d / 7d)
- 🔐 **Token-based access** — edit or delete your snippets with a secret token
- 📋 **One-click copy** — copy share URLs to clipboard
- ⬇️ **Download** — export snippets as `.html` or `.md` files
- 🔒 **Sandboxed rendering** — no inline JS execution in previews
- 📐 **Resizeable panels** — drag the divider to adjust the split view
- ⌨️ **Keyboard shortcut** — `Ctrl+S` to save
- 📱 **Responsive** — works on desktop and mobile
- 🛠️ **Admin page** — list, view, and delete all snippets (IP-whitelisted)

## Security

| Measure | Details |
|---|---|
| **Iframe sandbox** | `sandbox="allow-same-origin"` by default (strict). Toggle "Enable JS / Canvas" to opt-in → `sandbox="allow-scripts allow-same-origin"` |
| **CSP header** | `default-src 'none'; frame-src 'self'; object-src 'none'` |
| **X-Frame-Options** | `SAMEORIGIN` |
| **Referrer-Policy** | `strict-origin-when-cross-origin` |
| **Rate limiting** | 10 saves / 10 min per IP; 50 reads / 1 min per IP |
| **Max snippet size** | 50 KB enforced server-side |
| **IP hashing** | SHA-256 with server salt — raw IPs never stored |
| **Admin access** | IP whitelist only — non-whitelisted IPs redirected |

## Quick Start

### PHP built-in server (local development)

```bash
cd htmly
php -S localhost:8080
```

Open <http://localhost:8080> in your browser.

### Requirements

- **PHP 8.0+** with `sqlite3` extension
- Apache/Nginx for production (`.htaccess` included for Apache)

### File structure

```
/
├── index.php             # Main entry point, router, UI
├── config.php            # Configuration constants
├── db.php                # Database layer (schema, CRUD, rate limiting)
├── components/           # Reusable PHP templates
│   ├── header.php        # Shared page header
│   ├── edit-mode.php     # Editor UI with live preview
│   └── view-mode.php     # Read-only view with Rendered/Source tabs
├── markdown-it.min.js    # Client-side Markdown renderer (v14.1.0)
├── prism.min.js          # Prism.js (bundled, HTML syntax highlighting)
├── prism.css             # Dark theme for syntax highlighting
├── migrate-database.php  # Migration script for new schema
├── .htaccess             # Apache rewrite rules
├── snippets.db           # SQLite database (auto-created)
└── README.md             # This file
```

## Usage

1. **Choose a type** — toggle between **HTML** and **Markdown** in the editor toolbar
2. **Write** in the editor panel (left side)
3. **Preview** appears instantly (live preview) or on tab switch
4. **Save** — pick a TTL, click Save, copy the share URL
5. **Share** — send the URL; recipients see the rendered content
6. **Edit** — append `?t={token}` to the URL to re-open in edit mode
7. **Download** — export as a `.html` or `.md` file

## Markdown

Toggle the **Markdown** button to switch the editor to Markdown mode. Markdown is rendered client-side using [markdown-it](https://github.com/markdown-it/markdown-it) (GFM-compliant).

- **Rendering** happens in the preview iframe — the raw Markdown is stored in the database
- **View mode** shows a **Rendered** tab (styled with a dark theme) and a **Source** tab (raw Markdown with syntax highlighting)
- **Download** exports the raw `.md` file
- The toggle preference persists via `localStorage`

### Database Migration

If you have an existing database without the `content_type` column, run the migration script before deploying:

```bash
php migrate-database.php snippets.db snippets-migrated.db
cp snippets-migrated.db snippets.db
```

This creates a new database with the updated schema, copies all snippets (defaulting to `html` content type), and preserves rate limit data. The original file is never overwritten.

## Admin

The admin page at `/admin` lists all snippets with their GUID, content type, creation date, age, TTL, size, and actions (edit link + delete). Access is restricted to IPs listed in the `ADMIN_IP_WHITELIST` config.

To enable, set your IPs in `config.php`:

```php
define('ADMIN_IP_WHITELIST', ['127.0.0.1', '192.168.1.50']);
```

Non-whitelisted IPs are redirected to the homepage. Set to an empty array (default) to disable entirely.

## License

Public domain / MIT — do whatever you want with it.
