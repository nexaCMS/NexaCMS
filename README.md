# NexaCMS

A standalone, WordPress-free PHP CMS with a **polyglot database layer** (write once, run on CSV, JSON, PHP Array, SQLite, MySQL or PostgreSQL), a full admin suite, multisite networks, a plugin/theme system with a package-repository protocol, and a hardened account-security stack.

Licensed under the **GNU General Public License v3.0** — see [LICENSE](LICENSE). Third-party components are credited in [CREDITS.md](CREDITS.md).

## Requirements

- **PHP 8.1 or newer** (developed and tested on PHP 8.4), with `zip`, `mbstring` and `pdo` (+ `pdo_sqlite`/`pdo_mysql`/`pdo_pgsql` for those drivers)
- **Apache** with `mod_rewrite` (a protective `.htaccess` ships), **nginx** (see the sample below), or PHP's built-in server for development
- No build step, no Composer, no Node — upload and run

## Quick start

```bash
# Development (built-in server + router)
php -S 127.0.0.1:8080 router.php
# then open http://127.0.0.1:8080/ and follow the setup wizard
```

On a web server: point the document root at the NexaCMS folder and visit the site — the **installer wizard** (`admin/install.php`) asks for a database driver, site name and your administrator account (there are no default credentials; the only admin is the one you create). After installation the installer locks itself.

Choose/change the storage driver in `config.php` (`$config['database']['type']`): `csv` · `json` · `array` · `sqlite` · `mysql` · `pgsql`. Data stores live under `admin/database/` and are created automatically.

## Highlights

- **Content & themes** — posts/pages, menus, a built-in WYSIWYG or TinyMCE, 7 bundled themes, live theme customizer, per-theme settings
- **Plugins** — 36 bundled (shop with shipping zones, downloads, comments, newsletter, gallery, SEO, cache, security hardening, SMTP with mail log, a user dashboard, a repository hub, and more), installable from zip uploads or remote **repositories** — the official catalog (`nexaCMS/nexacms-packages`, all bundled plugins & themes) is pre-registered on fresh installs and installs are SHA-256 verified; any NexaCMS site can also be a package hub
- **Multisite** — path/host subsites with shared or per-site databases, per-site plugins/themes for subsite-*uploaded* packages (everything else is shared), upload approval workflows, per-site settings (including password & session policy)
- **Account security** — admin-configurable password policy (Settings → Password Policy), forced password upgrade at sign-in when the policy tightens, emailed single-use reset links, idle auto-logoff with per-user settings (Settings → Sessions & Login), brute-force lockout, and a generic MFA/challenge contract so 2FA plugins integrate without forking login (see `PLUGIN_DEVELOPMENT.md` §6A)
- **Updates** — Admin → System Update applies full or partial zips (local file, root `updat.zip`, or a remote URL) with automatic pre-update backups; remote downloads require HTTPS and are verified against a published `SHA256SUMS` when present
- **Backups** — Admin → Backups (automatic pre-update backups plus downloadable archives)

## Updating

Releases publish `nexacms-<version>.zip` (full), `updat.zip` + `update.json` (update package; partial builds contain only changed files since the stated base version), `nexacms-<version>-minimal.zip` (core + one theme + six basic plugins), and `SHA256SUMS`. Verify downloads with `sha256sum -c SHA256SUMS`. Apply updates in **Admin → System Update** — fresh installs come with the official source (`https://github.com/nexaCMS/NexaCMS/releases/latest/download/updat.zip`) pre-filled (changeable/removable; checks happen only when you click), and your `config.php`, database, uploads and backups are always preserved.

## nginx

Apache works out of the box via `.htaccess`. For nginx, adapt [`docs/nginx-server-block.conf`](docs/nginx-server-block.conf) (pretty permalinks, subsite admin routing, and blocking direct access to `config.php` and data stores).

## Development

- `php -S 127.0.0.1:8080 router.php` — dev server (router.php is dev-only and never ships)
- `php build_release.php` — full release + manifest; `php build_release.php partial dist/manifest-<base>.json` — partial update; `php build_release.php minimal` — minimal install package (all into `dist/`, with `SHA256SUMS` + `update.json`)
- `php test_drivers.php` — database-driver parity harness (`NEXACMS_DB_TYPE=sqlite php test_drivers.php`, etc.)
- Plugin/theme development: [PLUGIN_DEVELOPMENT.md](PLUGIN_DEVELOPMENT.md). Contributing: [CONTRIBUTING.md](CONTRIBUTING.md). Security reports: [SECURITY.md](SECURITY.md). Release history: [CHANGELOG.md](CHANGELOG.md).

## Layout

| Path | Contents |
|---|---|
| `admin/` | Admin suite, installer, System Update, Backups |
| `includes/` | Core (`common-core.php` API, upload/update/multisite/security), database drivers |
| `content/plugins/`, `content/themes/` | Bundled extensions (per-subsite copies live in `content/plugins-<slug>/`, `content/themes-<slug>/`) |
| `content/uploads/`, `content/backup/`, `content/cache/` | Media, backups, page cache (never shipped) |
| `index.php`, `ajax.php`, `api.php`, `rss.php` | Front end, AJAX dispatcher, public read API, feeds |
