# AGENTS.md — HTML Scratchpad

## Project Overview

**HTML Scratchpad** is a lightweight HTML playground — write, preview, save, and share HTML snippets. Built with PHP + SQLite. Zero dependencies, zero accounts, zero logins.

## Tech Stack

- **Language:** PHP 8.0+
- **Database:** SQLite (WAL mode, persistent singleton connection)
- **Frontend:** Vanilla PHP templates + vanilla JS + Prism.js for syntax highlighting
- **Deployment:** PHP built-in server (dev) or Apache/Nginx (prod). `.htaccess` included for Apache.

## Architecture

```
index.php      ← Single entry point. Routing, security headers, API endpoints, UI rendering
config.php     ← All tunable constants. Zero logic.
db.php         ← Database layer: schema, CRUD, rate limiting, TTL cleanup
components/    ← Reusable template partials (header, edit-mode, view-mode, not-found)
prism.js/css   ← Bundled Prism.js (HTML syntax highlighting, dark theme)
snippets.db    ← SQLite database (auto-created on first run)
```

**There is no framework.** Everything is vanilla PHP — includes, functions, `switch`/`if` routing.

## Key Patterns

### Routing
- `index.php` parses `$_SERVER['REQUEST_URI']` and matches against routes:
  - `POST /api/save` → `handle_save()`
  - `POST /api/delete` → `handle_delete()`
  - `GET /api/download?guid=…` → `handle_download()`
  - `GET /s/{guid}` → `handle_snippet_view()`
  - Everything else → `render_home()`

### Database
- Lazy-init singleton via `db()` — returns shared `SQLite3` instance.
- All queries use **prepared statements** (`:bind` parameters).
- Two tables: `snippets` and `rate_limits`.
- Cleanup runs on every page load (expired snippets + old rate-limit rows).

### Token Auth
- Every snippet gets a random 40-byte hex `access_token` on creation.
- Edit access: append `?t={token}` to the snippet URL.
- Verification via `verify_token()` using `hash_equals()` (constant-time).

### Rate Limiting
- In-memory-ish: stored per IP hash in SQLite `rate_limits` table.
- **Saves:** 10 per 10 min per IP.
- **Reads:** 50 per 1 min per IP.
- Raw IPs are SHA-256 hashed with a server salt before storage.

### Component Rendering
- UI is assembled via `include __DIR__ . '/components/…'` — variables like `$htmlContent`, `$pageTitle` are set before includes.
- No templating engine, no framework view layer.

## Security Posture

| Layer | Detail |
|---|---|
| Iframe sandbox | `sandbox="allow-same-origin"` only — no scripts, forms, or popups |
| CSP header | `default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'` |
| X-Frame-Options | `SAMEORIGIN` |
| Max snippet | 50 KB server-side enforcement |
| Rate limits | Per-IP hashing, sliding windows |

## Configuration

All tunables are in `config.php`. When modifying:
- Constants only — no logic.
- Naming convention: `UPPER_SNAKE_CASE` with `define()`.

## Conventions

- `declare(strict_types=1)` at the top of every PHP file.
- PHPDoc blocks on all functions: `@param`, `@return`, `@throws`.
- Error responses: always JSON with HTTP status codes (`400`, `403`, `413`, `429`, `500`).
- Redirects use `303 See Other` after POST.
- Flash messages via `$_SESSION` — one-shot display on next page load.
- Output escaping: `htmlspecialchars()` on all user data in HTML.
- Functions use `: never` return type for `exit`-ing helpers (`redirect`, `json_response`).

## Adding Features

1. **New API endpoint** → Add route in the routing section of `index.php`, then add a handler function.
2. **New config** → Add to `config.php` using `define()`.
3. **New DB operation** → Add to `db.php`, use prepared statements.
4. **New UI component** → Create in `components/`, include from `index.php`.

## Running

```bash
# Local dev
cd htmly && php -S localhost:8080

# Requires
php --extensions sqlite3   # sqlite3 extension must be loaded
```

## Known Constraints

- Inline JS is intentionally blocked in previews.
- Snippets self-delete after TTL expiration (checked on each page load).
- No authentication or user accounts — token-based edit access is the only auth.
- Single-file architecture: no MVC, no autoloader, no vendor directory.
