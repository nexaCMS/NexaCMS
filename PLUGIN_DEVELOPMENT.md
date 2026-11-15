# NexaCMS Plugin Development Guide

How to build plugins for NexaCMS 1.0 that work on **every** database driver
(CSV, JSON, PHP Array, SQLite, MySQL, PostgreSQL) — plus the admin-theme system
and the Top-20-WordPress → NexaCMS plugin map.

---

## 1. Plugin anatomy

Every plugin is a folder in `content/plugins/{slug}/`:

```
content/plugins/my-plugin/
├── plugin.json     ← name, version, author, description, default stages
├── functions.php   ← loaded ONCE when any stage is on: hooks, shortcodes, tables
├── pre.php         ← included only if the Pre stage is toggled on
├── head.php        ← included only if the Head stage is toggled on
├── body.php        ← included only if the Body stage is toggled on
├── post.php        ← included only if the Post stage is toggled on
└── admin.php       ← included in the admin area only (see the Admin Rule, §4)
```

`plugin.json`:

```json
{
  "name": "My Plugin",
  "version": "1.0.0",
  "author": "Your Name",
  "description": "One line shown in Admin → Plugins.",
  "admin_page": "my-plugin",
  "default_stages": ["pre", "head"]
}
```

On first discovery (Admin → Plugins, or any bootstrap) NexaCMS inserts a row in
the `plugins` table with those default stages. Admins toggle stages per plugin.

---

## 2. The four stages — and *why* they exist

NexaCMS renders a page in a strict order. Pick the **earliest** stage that can
do the job — that decision is the whole point of the system:

| Stage | When it runs | Use it for | Classic example |
|---|---|---|---|
| `pre` | Before *anything* is output; before the theme loads | Redirects, view counters, spam checks, HTTPS forcing, cache serving, login guards — anything that may `header()`, `exit`, or must count *every* hit | A **view counter**: in `pre` the hit is recorded even if the visitor leaves instantly, the theme errors, or a cached page is served later. In `post` you would *undercount* — the code runs only after the full page renders. |
| `head` | Inside `<head>` (`nexa_head()` / `up_head()`) | Meta/SEO tags, analytics scripts, verification tags, CSS | SEO meta, GA4 gtag |
| `body` | While content renders (`the_content` filter, shortcodes, `nexa_body()`) | Shortcodes, forms, galleries, comments — markup that belongs *in* the page | `[gallery]`, comment threads |
| `post` | After the main content (`nexa_post()` / `up_post()`, near the footer) | Lightbox markup/JS, footer snippets, share bars — things that must exist *after* the content they enhance | A gallery's lightbox overlay: worthless in `head` (no images yet), perfect in `post` |

Rules of thumb:

- Anything calling `header()`, `setcookie()`, `http_response_code()` or
  `exit`/`die` → **`pre` only**. Headers are already sent by `head`.
- Anything that must run on *every* request (hits, redirects, blocks) → `pre`.
- `<meta>`, `<link>`, tracking `<script>` in the head → `head`.
- HTML the visitor reads inside the article → `body` (usually a shortcode).
- JS/DOM that manipulates finished content → `post`.
- Register hooks/shortcodes in `functions.php` and *call* the stage-appropriate
  hook (`pre`, `nexa_head`, `the_content`, `nexa_post`, …). Keep stage files
  thin — one or two lines wiring the right moment, logic in `functions.php`.

### View-counter example (the pattern behind Nexa Analytics)

```php
// functions.php
add_shortcode('site_hits', fn() => (string) (query("SELECT COUNT(*) AS c FROM analytics_hits")[0]['c'] ?? 0));

// pre.php — count BEFORE loading/rendering, not after
add_action('pre', 'my_track_hit');
```

Analytics in `pre` also counts hits that end in redirects or errors — exactly
what a counter should do. Cache works the mirror-image way: **serve** in `pre`
(before rendering), **write** at shutdown, **purge** on `post_saved`.

---

## 3. Database best practices (the golden rules)

1. **Write standard SQL once with `query()` — never branch on the driver.**
   `query("SELECT … WHERE id = ?", [$id])` runs natively via PDO on
   SQLite/MySQL/PostgreSQL and is translated for CSV/JSON/Array. If you write
   `if ($driver === 'mysql') …` you have already broken the plugin.
2. **Always bind parameters** (`?` or `:name`). Never concatenate `$_GET` /
   `$_POST` into SQL strings.
3. **Create tables lazily and idempotently**, at the top of `functions.php`:

   ```php
   function my_plugin_ensure(): void {
       if (!table_exists('my_table')) create_table('my_table', ['name', 'email', 'created_at']);
       ensure_fields('my_table', ['status']); // future migrations
   }
   my_plugin_ensure();
   ```

   Don't list `id` — every table gets an auto-increment `id` automatically.
4. Prefer `query()` for reads/writes; use `update_table()` / `get_result()` /
   `count_records()` when they're clearer. Both layers work on all 6 drivers.
5. Prefix your tables and functions with your slug (`nexa_shop_*`,
   `myplugin_*`) so 24+ plugins never collide.
6. Test on at least two drivers before shipping: run the site once with
   `NEXACMS_DB_TYPE=csv` and once with `NEXACMS_DB_TYPE=sqlite`
   (`php test_drivers.php` shows the parity pattern).

---

## 4. THE ADMIN RULE (enforced by core)

> **Every enabled plugin MUST hook the admin hook and add its menu to the
> admin area.** An enabled plugin with no admin presence is a bug.

In `admin.php` (loaded only in the admin area, only when the plugin is on):

```php
<?php
if (empty($GLOBALS['adminarea'])) return;

// 1) Add your sidebar menu
add_menu_item('dashboard', [
    'name'  => 'my-plugin',
    'class' => (($_GET['plugin_page'] ?? '') === 'my-plugin') ? 'active' : '',
    'icon'  => 'fa fa-star fa-fw',
    'label' => ' My Plugin',
    'link'  => 'admin.php?plugin_page=my-plugin',
], 60);

// 2) Hook the admin hook — your screen renders at admin.php?plugin_page=my-plugin
add_action('nexa_admin', 'my_plugin_admin_render');

function my_plugin_admin_render(): void {
    static $done = false; if ($done) return;           // render once
    if (($_GET['plugin_page'] ?? '') !== 'my-plugin') return;
    $done = true;
    // …your settings/tables UI (verify CSRF on every POST)…
}
```

- `nexa_admin()` fires `nexa_admin`, `di_admin` and `up_admin` for legacy
  compatibility — the `static $done` guard keeps your screen single if you
  hook more than one of them (recommended when shipping zips for older
  DiCimes installs, whose admin host calls `di_admin()`).
- **Forgot? Core has your back:** `nexa_enforce_plugin_admin_menus()` watches
  which plugins register a menu/`nexa_admin` hook while loading. Any enabled
  plugin that registers neither automatically gets a sidebar item and an info
  screen — so it is never invisible. Treat that screen as a warning to fix
  your plugin, not as a feature.
- Also add a **Manage** shortcut from Admin → Plugins via `"admin_page"` in
  `plugin.json` (already wired for all built-ins).

---

## 5. Security & quality checklist

- [ ] `nexa_csrf_field()` in every form; `nexa_verify_csrf()` before any write.
- [ ] Escape all output: `esc_html()` / `esc_attr()` / `esc_url()`. Store raw,
      escape late.
- [ ] Honeypot + time-trap on public forms (see `nexa-contact`,
      `nexa-comments`, `nexa-antispam`) — and let Anti-Spam's `pre` shield help.
- [ ] Never trust file uploads: whitelist extensions, random filenames, store
      under `content/uploads/` (see `nexa-gallery`).
- [ ] No direct access assumptions: guard `admin.php` with `$adminarea`, guard
      renderers with the `plugin_page` check.
- [ ] Shortcode callbacks **return** strings — never `echo` inside a shortcode.
- [ ] Keep `pre` fast: it runs before *everything*, on *every* request. No
      heavy scans, no external HTTP calls in `pre`.
- [ ] Cache pages must never contain per-session CSRF tokens (Nexa Cache
      already skips such pages — follow the same rule in your own caching).
- [ ] Bump `plugin.json` version on release; keep a `README` note of any new
      tables/settings your plugin creates.

---

## 5A. Editor shortcode builders (let users insert your shortcode visually)

If your plugin adds a shortcode, register an **editor builder** in
`functions.php`. It then appears in **Admin → Add/Edit Post/Page → Insert
Plugin Shortcode**: the user picks your plugin from the dropdown, fills in
the fields you declare, watches the generated code update live, and clicks
**Insert into content** (works in Nexa WYSIWYG visual/HTML modes, TinyMCE,
and plain-textarea fallback).

```php
add_shortcode('my_items', 'my_items_render');

if (function_exists('register_editor_shortcode')) {
    register_editor_shortcode([
        'plugin'      => 'My Plugin',
        'plugin_slug' => 'my-plugin',
        'label'       => 'Latest items',
        'tag'         => 'my_items',
        'description' => 'Show the newest items as a grid or list.',
        'fields'      => [
            ['name' => 'limit', 'label' => 'Items to show', 'type' => 'number',
             'default' => '5', 'min' => '1', 'max' => '20', 'always' => true],
            ['name' => 'layout', 'label' => 'Layout', 'type' => 'select',
             'default' => 'grid', 'always' => true,
             'options' => ['grid' => 'Grid', 'list' => 'List']],
            ['name' => 'class', 'label' => 'Extra CSS class', 'type' => 'text'],
        ],
        // {field} placeholders are replaced + attribute-escaped; empty
        // optional attributes are removed automatically.
        'template' => '[my_items limit="{limit}" layout="{layout}" class="{class}"]',
    ]);
}
```

Field rules:

- Types: `text`, `textarea`, `number`, `url`, `email`, `date`, `color`,
  `select`, `checkbox`.
- `required: true` blocks insertion until filled; `always: true` keeps the
  attribute even when empty; otherwise empty fields are omitted.
- Select `options` may be `['value' => 'Label']` or a plain list. Build the
  list dynamically if useful (e.g. query your forms/products table when
  `$GLOBALS['adminarea']` is set) so users pick real records, not IDs from
  memory.
- For enclosing shortcodes add `'content' => ['label' => 'Button text']`
  and `{content}` in the template (or omit `template` and core wraps the
  content in `[tag]…[/tag]`).
- Without a `template`, core generates `[tag attr="value" ...]` from the
  fields. For a no-settings shortcode, `'shortcode' => '[my_tag]'` is enough.
- A plugin that only calls `add_shortcode()` still appears under **Other
  active registered shortcodes (basic)** with a manual-attributes box — but
  registering a builder is the supported, user-friendly path.

---

## 6. Packaging & installing plugins (zip)

1. Zip the **folder**: `my-plugin.zip` containing `my-plugin/functions.php`, …
   (zipping the files at the zip root also works — the installer normalises).
2. Install via **Admin → Plugins → Upload Plugin (.zip)**, or unzip manually
   into `content/plugins/`.
3. Open **Admin → Plugins** — discovery inserts the plugin with its
   `default_stages`; toggle stages, then open its new sidebar menu.
4. Ready-made zips for every built-in plugin (and an all-in-one bundle) are in
   **`plugin-zips/`**. The same zips install on the older DiCimes deployment
   (`di-admin/plugins.php` → Upload) — drop the folder into its plugins
   directory if its uploader expects the legacy path.

### Per-site plugin & theme copies (multisite)

The rule is deliberately simple: **a package is private to a subsite only
when it was uploaded from that subsite. Everything installed from the main
site is shared with every site.**

- Shared copies live in `content/plugins/<slug>/` and
  `content/themes/<slug>/` as usual — installed once on the main site,
  available to all sites (subject to each site's allow-lists).
- A package uploaded **from a subsite** (freely, or after network-admin
  approval of its request) installs into `content/plugins-<siteslug>/<slug>/`
  or `content/themes-<siteslug>/<slug>/` instead. Those folders are
  deliberately the same depth as the shared ones, so
  `dirname(__DIR__, 3)` still finds the install root.
  `nexa_resolve_plugin_path()` / `nexa_resolve_theme_path()` return the
  site copy inside that subsite and the shared copy everywhere else, so a
  subsite can run a **different version** (newer paid, older, or free) than
  the main site and other subsites. Another subsite needs its own upload —
  or it simply uses the shared copy.
- Activation state is per-site already (each site's own settings and
  `plugins` table), and a site copy is always usable by its own site even
  when the site's allow-list would otherwise exclude it.
- Network Admin → subsite card → "Site package copies" lists a site's own
  plugins/themes next to the shared versions; the network admin can
  **remove a site copy** there (the site then falls back to the shared
  copy), but cannot create one — site copies come only from subsite
  uploads. CMS updates only touch the shared copies, so site versions
  stay put.

### Package repositories (Plugins → Repositories)

Any site can install plugins/themes from remote **repositories** — and
any NexaCMS site can *become* one. Nothing is built in or phoned home:
admins add the repositories they trust themselves.

**Consuming** — Admin → Plugins → Repositories. Fresh installs pre-register the official catalog (`nexaCMS/nexacms-packages`, labelled "official", removable; any saved change makes the list fully user-managed, and subsites start clean). Add more via:

- *NexaCMS hub site*: the base URL of a site running the Repository Hub
  plugin; its index is read from `?nexa_repo=1` on that site.
- *GitHub repository*: `owner/repo` + branch; the repo must contain a
  `nexa-repo.json` index at its root (served via
  raw.githubusercontent.com). Package `download_url` values may be paths
  inside the repo or full URLs (e.g. release-asset links).
- *Direct index URL*: any URL serving the index JSON below.

Index format (`nexa-repo.json`):

```json
{
  "name": "My Repository",
  "packages": [
    {"type": "plugin", "slug": "nexa-shop", "name": "Nexa Shop",
     "version": "2.1.0", "author": "…", "description": "…",
     "download_url": "https://…/nexa-shop.zip", "sha256": "optional-hex"}
  ]
}
```

Installs go through the standard package validator/installer and follow
the origin rule above: installing from the main site installs the shared
copy; installing from a subsite installs that site's own copy and
respects its upload policy (denied, or queued for network approval).

**Serving** — install/activate the **Nexa Repository Hub** plugin
(`nexa-repo-hub`, also available as a standalone zip in `plugin-zips/`).
Its admin screen (Repository Hub) lets you tick which of the site's
*shared* plugins/themes to publish and set a display name. It serves
`GET /?nexa_repo=1` (index JSON) and
`GET /?nexa_repo=download&type=plugin|theme&slug=<slug>` (the package
folder zipped on the fly). Per-subsite private copies are never served.

---

## 6A. Authentication challenge providers (2FA / enrollment hooks, v1.29.0)

Core owns the login lifecycle; a plugin can require a **second factor or
mandatory enrollment** without forking login, copying session logic, or
intercepting redirects. All factor data (TOTP secrets, email codes,
recovery codes, policy, audit logs) stays in the plugin — core never
sees codes or password hashes.

**Register** (in `functions.php`, which core loads before login runs):

```php
nexa_register_auth_challenge_provider('my-2fa', [
    // REQUIRED. Return ['action'=>'allow'] |
    // ['action'=>'challenge','mode'=>'verify'|'enroll'] |
    // ['action'=>'deny','message'=>'...']. Throwing fails CLOSED.
    'decide' => function (array $ctx): array { ... },
    // Echo the challenge form's INNER fields (core wraps them in a
    // CSRF-protected form bound to the pending transaction).
    'render' => function (array $ctx, array $pending): void { ... },
    // Server-side check. Return ['ok'=>true] | ['ok'=>false,'error'=>'…'].
    'verify' => function (array $ctx, array $pending, array $post): array { ... },
    // OPTIONAL mid-session veto: return false when an existing session's
    // assurance is stale (e.g. mandatory enrollment was just enabled).
    'session_check' => function (array $ctx, ?array $assurance): bool { ... },
]);
```

**Context** (`$ctx`, server-trusted; no passwords/hashes): `user_id`,
`username`, `role`, `realm` (`main`, or `site:<id>` when the account
lives in a subsite's own database — numeric user IDs are NOT globally
unique, always key factor records by `realm` + `user_id`/`username`),
`site_id` (intended destination subsite, 0 = main), `method`
(`password`|`remember`|`session`), `remember`, `return`.

**Multi-provider semantics (explicit):** multiple providers may be
registered. All are consulted in registration order; the first `deny`
wins and kills the login; **every** provider whose `decide()` returns a
challenge must complete it, in order, within the same pending
transaction (the challenge route advances provider by provider) before
core finalizes. Session assurance records all completions
(`$_SESSION['nexa_auth_assurance']['providers']`, with `provider` =
last completed for convenience). The required-provider list governs
*availability* (fail-closed when missing), not forced challenges — a
required provider whose `decide()` allows a given login does not add a
challenge for it.

**Guarantees**

- Password **and** remember-me logins both pass through
  `nexa_auth_attempt()`; a remember cookie never bypasses a challenge.
- While a challenge is pending there is **no** login flag, user id or
  role in the session, so admin screens, AJAX and protected APIs are
  inaccessible. Pending state is a random, session-bound transaction
  expiring after 10 minutes; only core's `admin/auth-challenge.php`
  route renders/verifies it, and only a server-side `verify()` success
  lets core finalize — posted user ids/flags/URLs grant nothing.
- At completion the account is **re-fetched from its realm and must
  match the pending user ID and username exactly**; a deleted or
  replaced account is denied and the pending state cleared — core never
  synthesizes an account from pending data. Role comes from the live
  account row.
- Finalization (session rotation, role + multisite context, remember-me
  issuance, redirect) happens only in `nexa_auth_finalize_login()`,
  which then fires **`nexa_login_completed`** (`$ctx`). The older
  `nexa_login_succeeded` hook means only "password matched".
- Other hooks: `nexa_auth_challenge_started`, `…_verified`,
  `…_cancelled`, `…_denied`, `nexa_auth_pending_cleared`,
  `nexa_auth_session_invalidated`, `nexa_logout`.
- **Existing sessions:** `nexa_auth_enforce_session_assurance()` is the
  shared gate for cookie-session entry points — the admin guard uses
  `'redirect'` mode (re-challenge or invalidate), `ajax.php` uses
  `'json'` mode (403 `auth_challenge_required` /
  `auth_required_provider_unavailable`, nothing executes). Plugins with
  authenticated endpoints MUST call
  `nexa_auth_enforce_session_assurance('json')` after loading and
  before privileged dispatch. A required provider that disappears
  invalidates existing sessions (fail closed), exactly like fresh
  logins. Guests/public endpoints are untouched.
- **Fail closed:** if your policy marks accounts as requiring a
  challenge, call `nexa_auth_set_required_providers(['my-2fa'])`. If the
  provider is later missing/disabled, logins are denied rather than
  downgraded. Owner recovery is deliberate and server-side only: remove
  the id from the `nexa_auth_required_providers` setting in the site's
  settings store (database/FTP) — there is no web bypass.
- Installer completion creates the first admin before any provider can
  exist; a mandatory policy then applies from the next request via
  `session_check` (existing sessions are challenged, never silently
  trusted). Machine/API credentials are out of scope for interactive
  challenges and keep their own authentication policy.

### Password policy (Settings → Password Policy)

Core owns one site-wide password policy (minimum length, required
character classes, no-username rule, optional common-password blocklist)
— configure it under **Settings → Password Policy**; each subsite has
its own. It is enforced centrally whenever a password is set:
`create_user()`, Add/Edit User, Profile changes, registration and
subsite-admin creation. Plugins calling `create_user()` get enforcement
for free; on failure it returns `false` and
`nexa_password_last_errors()` explains why.
`nexa_password_validate($pw, $username)` and
`nexa_password_policy_summary()` are available for your own forms.

**Upgrade at sign-in:** hashes cannot be re-checked, so when a user
signs in *with a password* and that password no longer meets the
current policy, the login pauses in a pre-auth pending state (nothing
granted — same discipline as auth challenges) until they choose a
compliant password at `admin/password-change-required.php`; the normal
lifecycle (challenges, finalize) resumes afterwards. Controlled by
`pw_prompt_on_login` (default on; hook `nexa_password_change_required`,
plus `nexa_password_changed` on completion). Remember-me sign-ins carry
no plaintext and are never blocked by this. The bundled User Dashboard
applies the same rule for members (forced Password tab until updated).

**Forgot password:** core owns emailed reset links
(`admin/forgot-password.php` → `admin/reset-password.php`, Settings →
Password Policy: `pw_reset_enabled`, `pw_reset_expire_minutes` default
60). Only the token's SHA-256 hash is stored (`users.pass_reset_*`),
links are single-use and throttled, responses never reveal whether an
account exists, and the new password must satisfy the policy. Mail goes
through `nexa_mail()` when available (SMTP plugin), else PHP `mail()`.
Hooks: `nexa_password_reset_requested` (account ctx + link — audit or
alternate delivery), `nexa_password_reset_completed`. A reset does not
log the user in; the normal sign-in lifecycle applies afterwards.

### Session idle timeout (Settings → Sessions & Login)

Core signs an authenticated session out after inactivity: site default
(`sess_idle_minutes`, default 30), optional per-user override
(`users.idle_timeout_minutes`, set in Profile / Edit User) when
`sess_idle_allow_user` is on, capped by `sess_idle_max_minutes`;
"Never" only when `sess_idle_allow_never` is on. Enforced by the admin
guard (`redirect`), `ajax.php` (`403 {"code":"session_idle_timeout"}`,
nothing executes) and member entry points. Plugins with authenticated
endpoints should call `nexa_session_enforce_idle('json')` after loading
and before privileged dispatch, exactly like the assurance gate.
Activity = any guarded request (`nexa_last_activity`, throttled
writes); an idle-but-open tab does not count. Hooks:
`nexa_session_idle_timeout` (plus `nexa_logout`). Helpers:
`nexa_session_idle_limit_minutes()`, `nexa_session_touch_activity()`,
`nexa_session_idle_expired()`.

---

## 7. Admin themes

The admin area is themed independently from the frontend:

```
admin/themes/{slug}/theme.json   {"name":"…","description":"…","swatch":"#1c2331"}
admin/themes/{slug}/theme.css    overrides loaded after css/admin.css
```

Bundled: `default`, `midnight` (dark), `ocean`, `forest`. Pick one in
**Admin → Appearance → Admin Themes** (stored in the `admin_theme` setting, so
it follows the database to any driver). To make your own: copy a bundled
folder, retarget the `body.admin-theme-{slug}` selectors, done. Plugins should
not ship admin themes — style admin screens with the standard Bootstrap
classes so every admin theme looks right.

---

## 8. Top 20 WordPress plugins → NexaCMS equivalents

Researched Nov 2026 — WordPress's most-installed list is led by Elementor,
Yoast SEO and Contact Form 7 (10M+ each), then Classic Editor, LiteSpeed
Cache, WooCommerce, Site Kit, Akismet, WPForms and All-in-One Migration, with
Wordfence, Jetpack, WP Mail SMTP, Really Simple Security, WPCode, Duplicate
Post, Redirection, MonsterInsights, UpdraftPlus and Smush close behind.
NexaCMS covers all twenty jobs:

| # | WordPress plugin | NexaCMS equivalent |
|---|---|---|
| 1 | Elementor | **nexa-builder** `[builder]` blocks |
| 2 | Yoast SEO / Rank Math | **nexa-seo** meta + sitemap |
| 3 | Contact Form 7 | **nexa-contact** + **nexa-forms** |
| 4 | Classic Editor | Built into core post editor |
| 5 | LiteSpeed Cache / WP Rocket | **nexa-cache** |
| 6 | WooCommerce | **nexa-shop** catalog/cart/orders |
| 7 | Site Kit by Google | **nexa-sitekit** GA4 + verification |
| 8 | Akismet Anti-spam | **nexa-antispam** (`pre` shield) |
| 9 | WPForms | **nexa-forms** builder + inbox |
| 10 | All-in-One WP Migration | **nexa-backup** |
| 11 | Wordfence Security | **nexa-security** guard + audit |
| 12 | Jetpack (share/stats) | **nexa-analytics** + snippets |
| 13 | WP Mail SMTP | **nexa-smtp** settings + mail log |
| 14 | Really Simple SSL | **nexa-ssl** HTTPS + headers |
| 15 | WPCode (headers/footers) | **nexa-snippets** |
| 16 | Yoast Duplicate Post | **nexa-duplicate** |
| 17 | Redirection | **nexa-redirects** + 404 log |
| 18 | MonsterInsights | **nexa-analytics** dashboard |
| 19 | UpdraftPlus | **nexa-backup** |
| 20 | Smush / Slider Revolution / Envira | **nexa-image-optimizer**, **nexa-slider**, **nexa-gallery** |

Every equivalent follows this guide: `query()` SQL only, stage-scoped files,
admin hook + menu, CSRF-safe forms. Read any of them as a reference
implementation before writing your own.

---

## 9. Minimal starter plugin (copy-paste)

```php
<?php
// functions.php
function hello_nexa_ensure(): void {
    if (!table_exists('hello_hits')) create_table('hello_hits', ['note', 'created_at']);
}
hello_nexa_ensure();
add_shortcode('hello_nexa', fn($a = []): string =>
    '<p>Hello from NexaCMS — ' . count(query("SELECT id FROM hello_hits")) . ' hits logged.</p>');
```

```php
<?php
// pre.php — log the hit BEFORE the page starts loading
add_action('pre', function () {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET')
        query("INSERT INTO hello_hits (note, created_at) VALUES (?, ?)", ['hit', date('Y-m-d H:i:s')]);
});
```

Then `admin.php` per §4, a `plugin.json` per §1, zip per §6. That's a complete,
driver-proof NexaCMS plugin.

---

## 10. Admin bar & finding plugin screens

- **Admin bar:** when an admin is logged in, the frontend shows a fixed top bar
  (Dashboard, + Post, + Page, Edit This, Plugins, Admin Theme, Log Out) via
  `nexa_render_admin_bar()` on the `nexa_head` hook. Guests never see it; the
  admin area keeps its own topbar.
- **Finding plugin screens:** every enabled plugin appears in THREE places —
  its own sidebar menu (admin-hook rule, §4), the grouped sidebar parent
  **Plugin Screens**, and the **Enabled Plugin Screens** panel on the Dashboard
  home. If you cannot see a plugin, check in Plugins that at least one stage
  is on: a fully deactivated plugin correctly has no admin presence.

## 11. Case study: Nexa AI Agent (plugin-only AI access)

`nexa-ai-agent` shows how to give an AI site access *without* touching core:

- It is only a plugin: **Plugins → deactivate = API gone**; a softer
  `ai_agent_enabled` kill-switch lives in its settings.
- Admins create **AI users** (`ai_users` table): a `nxai_...` key shown once,
  stored only as a SHA-256 hash, with scoped permissions (`read`,
  `write_posts`, `delete_posts`, `manage_plugins`, `manage_settings`,
  `moderate_comments`, `shop`), optional expiry, and **per-key rate limits**
  (requests/hour and /day, falling back to global defaults + a global cap).
- The `pre` stage answers `POST /?nexa_ai_api=1`
  (`Authorization: Bearer nxai_...`, JSON `{action, params}`) before any page
  renders, audits every call in `ai_audit`, and returns JSON. Actions cover
  posts CRUD, plugin stage toggles, whitelisted settings, comment moderation,
  shop orders and analytics — each gated by its permission.

Copy its structure whenever a feature must be removable: tables + limits +
audit inside the plugin, nothing in core.

---

## 12. Menu categories (decluttering the admin sidebar)

With 20+ plugins enabled, individual plugin menus bury the core sidebar.
Declare a category in `plugin.json` — `"category": "Content"` — one of
`Content`, `Marketing & SEO`, `Commerce`, `Security & System`, `Utilities`
(or anything custom; the organizer falls back to slug keywords, then
`Utilities`). The **Nexa Menu Organizer** plugin reads every enabled plugin's
manifest on the `nexa_head` hook (after all menus exist, before the sidebar
renders), folds plugin menus *and* the Plugin Screens group into category
parents, and lets admins reassign categories in Admin → Menu Organizer
(stored in the `menu_org_map` setting). Deactivate the organizer to get the
flat menu list back. Core menus are never touched.

---

## 13. Theme error templates & post-layout hooks (v1.23.0)

**Error pages.** A theme may ship `404.php`, `403.php`, `500.php`,
`503.php`/`offline.php`, or a single `error.php` used for every status.
Core `nexa_render_error_page($status)` (already wired for 404s and for
`?nexa_error=403|500|503`) renders the theme template first — the template
controls the full page (call `get_header()`/`get_footer()`), with
`$GLOBALS['nexa_error'] = ['status','title','message']` set. Themes without
a template fall back to the **Nexa Error Pages** plugin's admin-created
content (filter `nexa_error_page_custom`), then to the legacy 404.

**Post listing layouts.** Inside the posts loop of `blog.php`, themes fire:

```php
if (!$isSingleView) do_action('nexa_post_layout_before');
$nexaItemNo = 0;
while (have_posts()): ... <article class="my-card nexa-post-item"> ...
  $nexaItemNo++; if (!$isSingleView) do_action('nexa_post_layout_after_item', $nexaItemNo);
endwhile;
if (!$isSingleView) do_action('nexa_post_layout_after');
```

and add `<?= apply_filters('nexa_post_layout_list_class', '') ?>` to the
list container's class. The **Nexa Post Layouts** plugin then injects
before/after blocks, ad blocks after every Nth post, column classes
(`nexa-cols-2|3|4`) and card styles — no theme code changes needed.
