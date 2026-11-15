# Contributing to NexaCMS

Thanks for helping! NexaCMS is GPL-3.0; contributions are accepted
under the same license.

## Getting set up

```bash
git clone <repo> nexacms && cd nexacms
php -S 127.0.0.1:8080 router.php   # dev server; visit / and run the installer wizard
```

There is no build step or dependency install. PHP 8.1+ is required
(8.4 recommended). The default storage driver is CSV, so a fresh clone
runs anywhere.

## Before you open a pull request

1. **Run the driver parity harness** on at least CSV and SQLite:
   `php test_drivers.php` and `NEXACMS_DB_TYPE=sqlite php test_drivers.php`
2. **Lint** everything you touched: `php -l path/to/file.php`
3. If you changed behaviour, say how you tested it (manual steps,
   suite, driver). Behaviour changes to authentication, updates,
   uploads or multisite need extra care — call them out explicitly.
4. Keep changes scoped; separate refactors from fixes.

## Conventions

- Core API lives in `includes/common-core.php` — read the section
  banners before adding helpers; prefer extending core functions over
  one-off logic in pages (password/session policy is the model: one
  central implementation, every entry point calls it).
- Escape output (`esc_html`/`esc_attr`/`esc_url`), verify CSRF
  (`nexa_verify_csrf()`/`di_csrf_field()`) on every state-changing
  request, and never trust posted ids/flags for authorization.
- Plugins and themes: follow [PLUGIN_DEVELOPMENT.md](PLUGIN_DEVELOPMENT.md)
  (stage system, hooks, the auth-challenge contract in §6A, repository
  protocol). Plugins must not fork login or session handling.
- User-facing strings are plain English for now (no i18n layer yet).

## Building release packages (maintainers)

```bash
php build_release.php                       # full zip + full updat.zip + manifest
php build_release.php partial dist/manifest-<base>.json   # partial update (writes update.json too)
php build_release.php minimal               # minimal fresh-install package
```

Each build refreshes `dist/SHA256SUMS`. Publish the five release
assets (`nexacms-<v>.zip`, `nexacms-<v>-minimal.zip`, `updat.zip`,
`update.json`, `SHA256SUMS`) together, bump `includes/version.php`,
and add a CHANGELOG entry.

## Reporting bugs / security issues

- Bugs: GitHub Issues (template provided).
- Security: **never a public issue** — see [SECURITY.md](SECURITY.md).
