# Changelog

All notable changes to NexaCMS. Versions follow the in-product version
(`includes/version.php`); update packages state their base version in
`update.json`.

## 1.33.5 - Fix: admin bar leaked to ordinary logged-in users

- **Frontend admin bar is now admins-only, as documented.** The bar
  (Dashboard / + Post / + Page / Edit This / Plugins) rendered for
  *every* logged-in account — subscribers, members and shop customers
  included — because it only checked that a session existed. It now
  applies the same `super_admin`/`admin` role check used by the
  theme-preview bar and the other frontend admin affordances. Ordinary
  users see the plain site; admins keep the bar unchanged.

## 1.33.4 - Manage your repository from inside the site (Hub 1.2.0)

- **Repository Hub - Upload & Publish:** the hub site's admin now has
  an Upload & Publish box. Drop in a plugin or theme .zip and it is
  detected, installed into the shared folder, and published to your
  repository in the same step - the live-generated index serves it
  immediately. Re-uploading a newer version goes through the standard
  overwrite warning; once confirmed, every consumer site sees the new
  version flagged as an update. Untick to unpublish, as before.
  Repository hosting is managed from the main site (subsites see a
  pointer there).

## 1.33.3 - Repository search & package thumbnails

- **Repositories page:** search box filtering packages by name, slug,
  author and description (combines with the Plugins/Themes tabs), and
  package thumbnails - themes show a wide shot, plugins a square icon,
  with a graceful initial-letter fallback when a repo provides none.
- **Protocol:** repo indexes may carry an optional `thumbnail`
  (aliases `icon`/`screenshot`) per package, absolute or relative to
  the index; only http(s) URLs are rendered.
- **Repository Hub 1.1.0:** publishes a `thumbnail` for any package
  folder containing a screenshot/thumbnail/icon/preview image and
  serves it at `?nexa_repo=thumb&type=..&slug=..` (published packages
  only).
- Official catalog: generated thumbnails for all 45 packages.

## 1.33.2 - Database switch fix + upload overwrite warning

- **Fixed:** Database Engine page - "Snapshot Active DB to CSV",
  "Import CSV", and switching drivers from a non-CSV backend all
  failed with `di_export_active_db_to_csv(): Argument #1 ($tables)
  must be of type array, null given`. The migrator now accepts
  "all tables / default CSV directory" (`?array $tables = null`),
  and the admin page calls it correctly. Verified on SQLite, JSON
  and CSV drivers.
- **Upload overwrite warning:** uploading a plugin/theme zip whose
  slug is already installed no longer silently overwrites it. The
  admin sees the installed vs uploaded versions (with an extra
  caution when the upload is older), and chooses **Replace** or
  **Cancel**; nothing is written until they confirm. Network-approval
  installs (pre-reviewed by an admin) are unaffected.

## 1.33.1 - Official update source & packages store

- **Pre-wired official sources (removable):** fresh installs come with
  the official GitHub release source pre-filled in System Update
  (`nexa_update_remote_url()`) and the official packages repository
  ("NexaCMS Official Packages", `nexaCMS/nexacms-packages`)
  pre-registered under Plugins - Repositories, labelled "official".
  Saving any change (including clearing/removing them) makes the
  configuration fully user-managed; subsites keep a clean slate.
  NexaCMS contacts these only when an admin opens System Update /
  Repositories and clicks - nothing phones home in the background.
- Official catalog launched: `nexa-repo.json` with all 36 bundled
  plugins + 7 themes, each as an installable zip with a SHA-256 that
  the installer verifies before installing.

## 1.33.0 — First public release (GPL-3.0)

- LICENSE (GPL-3.0), rewritten README, this changelog, SECURITY.md,
  CONTRIBUTING.md, third-party CREDITS, nginx server-block sample.
- Cleaner release packages: dev-only files (`router.php`) and stray
  fragments no longer ship; the runtime subsite seeder moved from
  `tools/multisite-seed.php` to `includes/multisite-seed.php`.
- **Licensing cleanup:** the legacy third-party CSV classes
  (MyCSV / MyArray / MyCSVAdmin, "personal use only" terms) are gone.
  The CSV engine is now an original GPL-3.0 implementation reading and
  writing the identical on-disk format — existing CSV databases upgrade
  in place with no migration.
- **Update integrity:** remote update downloads require HTTPS (HTTP
  loopback only) and are verified against a `SHA256SUMS` file published
  next to the zip; mismatch aborts, match is shown as verified.
  `build_release.php` now emits `dist/SHA256SUMS` and `dist/update.json`.

## 1.32.0 — Forgot password

- Emailed, single-use reset links (only the SHA-256 token hash is
  stored), configurable lifetime (default 60 min), per-account
  throttling, enumeration-safe responses; the new password must meet
  the password policy; a reset invalidates all remember-me cookies.
- "Forgot your password?" on the admin sign-in and member login
  (User Dashboard 1.4.0). Hooks: `nexa_password_reset_requested`,
  `nexa_password_reset_completed`.

## 1.31.0 — Idle auto-logoff + password upgrade at sign-in

- **Sessions & Login settings:** idle timeout (site default 30 min,
  optional per-user setting capped by an admin maximum, opt-in
  "Never"), enforced for admin pages, AJAX (403
  `session_idle_timeout`) and member sessions.
- **Password upgrade prompt:** when a password sign-in's password no
  longer meets the policy, the login pauses (granting nothing) until a
  compliant password is chosen; 2FA challenges still run afterwards.
  Members: forced Password tab in User Dashboard 1.3.0.

## 1.30.0 — Password policy

- **Settings → Password Policy:** minimum length, required character
  classes, no-username rule, common-password blocklist — enforced
  everywhere a password is set (`create_user()`, Add/Edit User,
  Profile, registration, installer, subsite admins), per-site in
  multisite. User Dashboard 1.2.0 follows the same policy.

## 1.29.1 — Auth-challenge hardening (external review)

- Required-provider availability enforced for existing sessions
  (fail closed), deleted/replaced accounts denied at challenge
  completion, shared session-assurance gate for AJAX, sequenced
  multi-provider completion.

## 1.29.0 — Authentication challenge contract (§6A)

- Core-owned login lifecycle for MFA/enrollment plugins:
  `nexa_auth_attempt()` → provider challenges → the single finalize
  point; remember-me can never bypass a challenge.

## 1.28.0 — Repositories & minimal profile

- Remote plugin/theme repositories (hub sites, GitHub, direct index
  URLs), Network-admin lockdown for subsites, and the `minimal`
  distribution profile (core + `multi` theme + 6 basic plugins).

## 1.27.x — Per-site packages

- 1.27.1: the origin rule — per-site plugins/themes apply only to
  packages a subsite itself uploaded; everything else is shared.
- 1.27.0: per-site package versions with update flags.

## 1.26.0 — Multisite upload approvals

- Subsite uploads can require network approval; per-subsite deletion
  cleanup; System Update restricted for subsite-assigned admins.

## 1.25.0 / 1.24.0 — Nexa Shop 2.1 / 2.0

- Shipping zones and methods (flat, per-weight, free-over-amount,
  pickup), discount-safe totals, paid digital delivery.

## ≤ 1.23.x — Foundations

- Polyglot database drivers (CSV/JSON/Array/SQLite/MySQL/PostgreSQL),
  multisite with per-site databases, plugin/theme management with
  stage toggles, editor shortcode builders, backups, and the initial
  plugin set (SEO, comments, newsletter, gallery, cache, security,
  SMTP, user dashboard, shop, downloads, and more).
