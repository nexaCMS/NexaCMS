# Security Policy

## Supported versions

Security fixes are applied to the latest release. Older versions should
be updated (Admin → System Update) — partial update packages are
published for the current release line, and every package is verifiable
against the release `SHA256SUMS`.

| Version | Supported |
|---|---|
| Latest (1.33.x) | ✅ |
| Older | ❌ (please update) |

## Reporting a vulnerability

**Please do not open a public issue for security problems.**

- Preferred: GitHub's private vulnerability reporting — go to the
  repository's **Security** tab → **Report a vulnerability**.
- Include: affected version, driver (CSV/SQLite/MySQL/…), steps to
  reproduce, and impact. A minimal proof of concept helps us verify
  quickly.

We aim to acknowledge reports within 3 business days and to ship a fix
or mitigation as a patch release (e.g. 1.33.1) with credit to the
reporter, unless you prefer to stay anonymous.

## Scope notes

- NexaCMS passwords are hashed with PHP `password_hash()`; reset and
  challenge tokens are stored only as SHA-256 hashes.
- The installer disables itself once the site is installed; there are
  no default credentials.
- Remote updates require HTTPS and are verified against the published
  `SHA256SUMS` when the source provides one — releases should always be
  downloaded from the official repository and verified
  (`sha256sum -c SHA256SUMS`).
- Plugins and themes are executable PHP by design. Only install
  packages from sources you trust, and use the multisite approval flow
  before allowing subsite uploads.
