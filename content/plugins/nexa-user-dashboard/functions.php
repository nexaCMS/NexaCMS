<?php
/** Nexa User Dashboard — front-end member area (no /admin/ visits needed). */

/* ---------- helpers ---------- */
function nexa_udash_user(): ?array {
    if (!is_user_logged_in()) return null;
    $id = get_current_user_id();
    if ($id <= 0) return null;
    $rows = get_result('users', 1, ['*'], ['search' => ['id']], 1, [$id], true);
    return $rows[0] ?? null;
}
function nexa_udash_names(array $u): array {
    return array_values(array_unique(array_filter([
        (string) ($u['displayname'] ?? ''), (string) ($u['user'] ?? ''),
        (string) ($_SESSION['display-name'] ?? ''), (string) ($_SESSION['name'] ?? ''),
    ])));
}
/** Own = credited to the member, or the member is the recorded real author behind a pen name (Nexa Fake Author). */
function nexa_udash_owns(array $p, array $names): bool {
    if (in_array((string) ($p['post_author'] ?? ''), $names, true)) return true;
    $real = (string) ($p['post_real_author'] ?? '');
    return $real !== '' && in_array($real, $names, true);
}
function nexa_udash_my_posts(array $u): array {
    $names = nexa_udash_names($u);
    $mine = [];
    foreach (get_table('posts')['data'] as $p) {
        if (nexa_udash_owns($p, $names)) $mine[] = $p;
    }
    usort($mine, static fn($a, $b) => ((int) $b['id']) <=> ((int) $a['id']));
    return $mine;
}
function nexa_udash_page_url(): string {
    $pid = (string) get_setting('nexa_udash_page_id', '');
    if ($pid !== '' && is_numeric($pid)) return post_url((int) $pid);
    return home_url('dashboard/');
}
function nexa_udash_redirect(string $tab = '', string $msg = '', bool $ok = true): void {
    if ($msg !== '') $_SESSION['udash_flash'] = [$ok ? 'success' : 'error', $msg];
    $url = nexa_udash_page_url();
    if ($tab !== '') $url .= (str_contains($url, '?') ? '&' : '?') . 'tab=' . urlencode($tab);
    header('Location: ' . $url);
    exit;
}

/** Auto-create the /dashboard/ page (once) so members have somewhere to go. */
function nexa_udash_ensure_page(): void {
    if (!empty($GLOBALS['adminarea'])) return;
    if ((string) get_setting('nexa_udash_page_id', '') !== '') return;
    try {
        foreach (get_table('posts')['data'] as $p) {
            if (($p['post_name'] ?? '') === 'dashboard' && ($p['post_type'] ?? '') === 'page') {
                set_setting('nexa_udash_page_id', (string) $p['id']);
                return;
            }
        }
        $id = update_post([
            'post_title' => 'My Dashboard', 'post_name' => 'dashboard', 'post_alias' => 'dashboard',
            'post_content' => '[user_dashboard]', 'post_status' => 'published', 'post_type' => 'page',
            'post_author' => 'Administrator', 'post_date' => date('Y-m-d H:i:s'), 'post_date_gmt' => (string) time(),
            'comment_status' => 'closed',
        ], 'insert');
        if ($id) set_setting('nexa_udash_page_id', (string) $id);
    } catch (Throwable $e) { /* dashboard page creation is best-effort */ }
}
add_action('up_pre', 'nexa_udash_ensure_page', [], 1);

/** Core idle-timeout guard for member sessions (Settings > Sessions & Login). */
function nexa_udash_session_guard(): void {
    if (!empty($GLOBALS['adminarea'])) return;
    if (!is_user_logged_in()) return;
    if (function_exists('nexa_session_idle_expired') && nexa_session_idle_expired()) {
        if (function_exists('nexa_session_clear_auth_state')) nexa_session_clear_auth_state();
        $_SESSION['udash_flash'] = ['error', 'You were signed out due to inactivity. Please log in again.'];
        return;
    }
    if (function_exists('nexa_session_touch_activity')) nexa_session_touch_activity();
}
add_action('up_pre', 'nexa_udash_session_guard', [], 1);

/* ---------- request handling (runs before output, can redirect) ---------- */
function nexa_udash_handle(): void {
    if (!empty($GLOBALS['adminarea'])) return;

    // Logout
    if (isset($_GET['udash_logout'])) {
        $key = 'user_logged_in' . ($GLOBALS['config']['auth_key'] ?? '');
        unset($_SESSION[$key], $_SESSION['user_id'], $_SESSION['name'], $_SESSION['admin_type'], $_SESSION['display-name']);
        header('Location: ' . home_url());
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_POST['udash_action'])) return;
    if (!nexa_verify_csrf()) return;
    $action = (string) $_POST['udash_action'];
    global $config;
    $sessFlag = 'user_logged_in' . ($config['auth_key'] ?? '');

    if ($action === 'login') {
        $login = trim((string) ($_POST['user_login'] ?? ''));
        $pass = (string) ($_POST['user_pass'] ?? '');
        if ($login !== '' && checkpass($login, $pass, false, false)) {
            $account = di_find_user_by_login($login);
            session_regenerate_id(true);
            $_SESSION[$sessFlag] = true;
            $_SESSION['user_id'] = $account['id'] ?? '1';
            $_SESSION['name'] = $account['user'] ?? $login;
            $_SESSION['admin_type'] = ($account['role'] ?? '') !== '' ? $account['role'] : 'subscriber';
            $_SESSION['display-name'] = $account ? di_user_display_name($account) : $login;
            // Core session policy: sign-in counts as activity (idle
            // timeout), and a password that no longer meets the site
            // password policy must be updated before anything else.
            $_SESSION['nexa_idle_pref'] = (string) ($account['idle_timeout_minutes'] ?? '');
            if (function_exists('nexa_session_touch_activity')) nexa_session_touch_activity(true);
            if ((string) get_setting('pw_prompt_on_login', '1') === '1'
                && function_exists('nexa_password_validate')
                && nexa_password_validate($pass, (string) ($account['user'] ?? $login))) {
                $_SESSION['nexa_pw_upgrade_required'] = (int) ($account['id'] ?? 0);
                nexa_udash_redirect('password', 'Your password no longer meets the site password policy. Please choose a new password to continue.');
            }
            unset($_SESSION['nexa_pw_upgrade_required']);
            nexa_udash_redirect('', 'Welcome back, ' . ($_SESSION['display-name'] ?? '') . '!');
        }
        $_SESSION['udash_flash'] = ['error', 'Invalid username/email or password.'];
        header('Location: ' . nexa_udash_page_url());
        exit;
    }

    if ($action === 'register') {
        if ((string) get_setting('users_can_register', '0') !== '1') {
            $_SESSION['udash_flash'] = ['error', 'Registration is currently closed.'];
            header('Location: ' . nexa_udash_page_url());
            exit;
        }
        $user = trim((string) ($_POST['reg_user'] ?? ''));
        $email = trim((string) ($_POST['reg_email'] ?? ''));
        $pass = (string) ($_POST['reg_pass'] ?? '');
        if ($user === '' || $email === '' || $pass === '') {
            $_SESSION['udash_flash'] = ['error', 'Please fill all fields.'];
            header('Location: ' . nexa_udash_page_url());
            exit;
        }
        // Core password policy (Settings → Password Policy) — enforced
        // again centrally by create_user(); checked here for a clear error.
        if (function_exists('nexa_password_validate') && ($pwErrs = nexa_password_validate($pass, $user))) {
            $_SESSION['udash_flash'] = ['error', implode(' ', $pwErrs)];
            header('Location: ' . nexa_udash_page_url());
            exit;
        }
        $id = create_user(['user' => $user, 'email' => $email, 'pass' => $pass, 'displayname' => $user]);
        if ($id) {
            $account = di_find_user_by_login($user);
            session_regenerate_id(true);
            $_SESSION[$sessFlag] = true;
            $_SESSION['user_id'] = $account['id'] ?? $id;
            $_SESSION['name'] = $user;
            $_SESSION['admin_type'] = ($account['role'] ?? '') !== '' ? $account['role'] : 'subscriber';
            $_SESSION['display-name'] = $user;
            nexa_udash_redirect('', 'Account created — welcome, ' . $user . '!');
        }
        $pwLeft = function_exists('nexa_password_last_errors') ? nexa_password_last_errors() : [];
        $_SESSION['udash_flash'] = ['error', $pwLeft ? implode(' ', $pwLeft) : 'Could not register: that username or email is already in use.'];
        header('Location: ' . nexa_udash_page_url());
        exit;
    }

    // Everything below requires a logged-in member.
    $u = nexa_udash_user();
    if ($u === null) return;

    // Password-policy upgrade pending: nothing else runs until the
    // member chooses a compliant password.
    if (!empty($_SESSION['nexa_pw_upgrade_required']) && $action !== 'change_password') {
        nexa_udash_redirect('password', 'Please update your password to meet the current policy before continuing.', false);
    }

    if ($action === 'save_post') {
        $title = trim((string) ($_POST['post_title'] ?? '')) ?: '(Untitled)';
        $slug = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $title), '-')) ?: ('post-' . time());
        $canPublish = current_user_can('edit_posts');
        $status = $canPublish && (($_POST['post_status'] ?? 'draft') === 'published') ? 'published' : 'draft';
        $type = (($_POST['post_type'] ?? 'post') === 'page' && $canPublish) ? 'page' : 'post';
        $editId = (int) ($_POST['post_id'] ?? 0);
        $payload = [
            'post_title' => $title, 'post_content' => (string) ($_POST['post_content'] ?? ''),
            'post_excerpt' => trim((string) ($_POST['post_excerpt'] ?? '')),
            'post_status' => $status, 'post_type' => $type, 'post_modified' => date('Y-m-d H:i:s'),
        ];
        if ($editId > 0) {
            $rows = get_result('posts', 1, ['*'], ['search' => ['id']], 1, [$editId], true);
            $mine = $rows[0] ?? null;
            $names = nexa_udash_names($u);
            if ($mine === null || (!nexa_udash_owns($mine, $names) && !current_user_can('manage_options') && !in_array(($_SESSION['admin_type'] ?? ''), ['super_admin', 'admin'], true))) {
                nexa_udash_redirect('posts', 'You can only edit your own posts.', false);
            }
            $payload['id'] = $editId;
            update_post($payload, 'update');
            nexa_udash_redirect('posts', 'Post updated.');
        }
        $payload['post_name'] = $slug;
        $payload['post_alias'] = $slug;
        $payload['post_author'] = (string) ($u['displayname'] ?: $u['user']);
        $payload['post_date'] = date('Y-m-d H:i:s');
        $payload['post_date_gmt'] = (string) time();
        update_post($payload, 'insert');
        nexa_udash_redirect('posts', $status === 'published' ? 'Post published!' : 'Draft saved.');
    }

    if ($action === 'delete_post') {
        $delId = (int) ($_POST['post_id'] ?? 0);
        $rows = get_result('posts', 1, ['*'], ['search' => ['id']], 1, [$delId], true);
        $mine = $rows[0] ?? null;
        $names = nexa_udash_names($u);
        if ($mine !== null && (nexa_udash_owns($mine, $names) || in_array(($_SESSION['admin_type'] ?? ''), ['super_admin', 'admin'], true))) {
            delete_records('posts', $delId);
            nexa_udash_redirect('posts', 'Post deleted.');
        }
        nexa_udash_redirect('posts', 'You can only delete your own posts.', false);
    }

    if ($action === 'save_profile') {
        $upd = [
            'id' => $u['id'],
            'displayname' => trim((string) ($_POST['displayname'] ?? '')) ?: $u['user'],
            'fullname' => trim((string) ($_POST['fullname'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'url' => trim((string) ($_POST['url'] ?? '')),
            'phone' => trim((string) ($_POST['phone'] ?? '')),
            'address' => trim((string) ($_POST['address'] ?? '')),
        ];
        update_table('users', $upd, 'update');
        $_SESSION['display-name'] = $upd['displayname'];
        nexa_udash_redirect('profile', 'Profile saved.');
    }

    if ($action === 'change_password') {
        $cur = (string) ($_POST['current_pass'] ?? '');
        $new = (string) ($_POST['new_pass'] ?? '');
        $conf = (string) ($_POST['confirm_pass'] ?? '');
        if (!checkpass((string) $u['user'], $cur, false, false)) {
            nexa_udash_redirect('password', 'Current password is incorrect.', false);
        }
        if ($new !== $conf) {
            nexa_udash_redirect('password', 'New passwords must match.', false);
        }
        // Core password policy (Settings → Password Policy).
        if (function_exists('nexa_password_validate') && ($pwErrs = nexa_password_validate($new, (string) $u['user']))) {
            nexa_udash_redirect('password', implode(' ', $pwErrs), false);
        }
        update_table('users', ['id' => $u['id'], 'pass' => password_hash($new, PASSWORD_BCRYPT)], 'update');
        unset($_SESSION['nexa_pw_upgrade_required']);
        if (function_exists('nexa_session_touch_activity')) nexa_session_touch_activity(true);
        nexa_udash_redirect('password', 'Password changed.');
    }
}
add_action('up_pre', 'nexa_udash_handle', [], 2);

/* ---------- rendering ---------- */
function nexa_udash_css(): string {
    return '<style>'
        . '.udash{max-width:980px;margin:0 auto;font-family:inherit}.udash a{text-decoration:none}'
        . '.udash-head{display:flex;gap:16px;align-items:center;background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;border-radius:14px;padding:22px 24px;margin-bottom:18px}'
        . '.udash-ava{width:58px;height:58px;border-radius:50%;background:rgba(255,255,255,.25);display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:700}'
        . '.udash-tabs{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:16px}.udash-tabs a{padding:8px 16px;border-radius:999px;background:rgba(0,0,0,.06);color:inherit}'
        . '.udash-tabs a.on{background:#4f46e5;color:#fff}'
        . '.udash-card{background:#fff;color:#222;border:1px solid rgba(0,0,0,.1);border-radius:12px;padding:20px 22px;margin-bottom:16px}'
        . '.udash label{display:block;font-weight:600;margin:12px 0 4px}.udash input[type=text],.udash input[type=email],.udash input[type=password],.udash input[type=url],.udash select,.udash textarea{width:100%;padding:10px 12px;border:1px solid #ccc;border-radius:8px;box-sizing:border-box;background:#fff;color:#222}'
        . '.udash .btn{display:inline-block;background:#4f46e5;color:#fff!important;padding:10px 22px;border-radius:999px;border:0;cursor:pointer;font-weight:600}'
        . '.udash .btn.alt{background:rgba(0,0,0,.08);color:inherit!important}.udash .btn.danger{background:#dc2626}'
        . '.udash table{width:100%;border-collapse:collapse}.udash th,.udash td{padding:9px 8px;border-bottom:1px solid rgba(0,0,0,.08);text-align:left;vertical-align:top}'
        . '.udash .flash{padding:12px 16px;border-radius:10px;margin-bottom:14px}.udash .flash.success{background:#dcfce7;color:#166534}.udash .flash.error{background:#fee2e2;color:#991b1b}'
        . '.udash .stats{display:flex;gap:12px;flex-wrap:wrap}.udash .stat{flex:1;min-width:130px;background:rgba(0,0,0,.04);border-radius:10px;padding:14px;text-align:center}.udash .stat b{font-size:24px;display:block}'
        . '.udash .muted{opacity:.65;font-size:13px}'
        . '</style>';
}

function nexa_user_dashboard_shortcode($atts = []): string {
    $out = nexa_udash_css();
    if (!empty($_SESSION['udash_flash'])) {
        [$kind, $msg] = $_SESSION['udash_flash'];
        unset($_SESSION['udash_flash']);
        $out .= '<div class="udash"><div class="flash ' . esc_attr($kind) . '">' . esc_html($msg) . '</div></div>';
    }
    $u = nexa_udash_user();

    /* ----- guest: login (and register when enabled) ----- */
    if ($u === null) {
        $reg = (string) get_setting('users_can_register', '0') === '1';
        $out .= '<div class="udash"><div class="udash-card"><h2 style="margin-top:0;">Member Login</h2>'
            . '<form method="post" action="">' . (function_exists('di_csrf_field') ? di_csrf_field() : '')
            . '<input type="hidden" name="udash_action" value="login">'
            . '<label>Username or Email</label><input type="text" name="user_login" required autocomplete="username">'
            . '<label>Password</label><input type="password" name="user_pass" required autocomplete="current-password">'
            . '<p style="margin-top:16px;"><button class="btn">Log In</button></p>'
            . ((string) get_setting('pw_reset_enabled', '1') === '1'
                ? '<p style="text-align:center;"><a href="' . esc_url(home_url('admin/forgot-password.php')) . '">Forgot your password?</a></p>' : '')
            . '</form></div>';
        if ($reg) {
            $out .= '<div class="udash-card"><h2 style="margin-top:0;">Create an Account</h2>'
                . '<form method="post" action="">' . (function_exists('di_csrf_field') ? di_csrf_field() : '')
                . '<input type="hidden" name="udash_action" value="register">'
                . '<label>Username</label><input type="text" name="reg_user" required>'
                . '<label>Email</label><input type="email" name="reg_email" required>'
                . '<label>Password</label><input type="password" name="reg_pass" required>'
                . (function_exists('nexa_password_policy_summary') ? '<p style="color:#666;font-size:13px">' . htmlspecialchars(nexa_password_policy_summary(), ENT_QUOTES, 'UTF-8') . '</p>' : '')
                . '<p style="margin-top:16px;"><button class="btn">Register</button></p></form></div>';
        }
        return $out . '</div>';
    }

    /* ----- member area ----- */
    $tab = (string) ($_GET['tab'] ?? 'overview');
    if (!in_array($tab, ['overview', 'posts', 'new', 'profile', 'password'], true)) $tab = 'overview';
    $upgradeRequired = !empty($_SESSION['nexa_pw_upgrade_required']);
    if ($upgradeRequired) $tab = 'password'; // must update before anything else
    $base = nexa_udash_page_url();
    $sep = str_contains($base, '?') ? '&' : '?';
    $name = (string) ($u['displayname'] ?: $u['user']);
    $initial = strtoupper(mb_substr($name, 0, 1));
    $out .= '<div class="udash"><div class="udash-head"><div class="udash-ava">' . esc_html($initial) . '</div><div>'
        . '<div style="font-size:22px;font-weight:700;">' . esc_html($name) . '</div>'
        . '<div style="opacity:.85;">@' . esc_html((string) $u['user']) . ' &middot; ' . esc_html(ucwords(str_replace('_', ' ', (string) ($u['role'] ?? 'member')))) . ' &middot; member since ' . esc_html(substr((string) ($u['register'] ?? ''), 0, 10)) . '</div></div></div>';

    $tabs = ['overview' => 'Overview', 'posts' => 'My Posts', 'new' => 'Write New', 'profile' => 'Profile', 'password' => 'Password'];
    $out .= '<div class="udash-tabs">';
    foreach ($tabs as $k => $label) {
        $out .= '<a class="' . ($tab === $k ? 'on' : '') . '" href="' . esc_url($base . $sep . 'tab=' . $k) . '">' . $label . '</a>';
    }
    $out .= '<a href="' . esc_url($base . $sep . 'udash_logout=1') . '">Log Out</a></div>';
    if ($upgradeRequired) {
        $out .= '<div class="flash error" style="margin:12px 0;">Your password no longer meets the site password policy. Please choose a new password below to continue using your account.</div>';
    }

    if ($tab === 'overview') {
        $mine = nexa_udash_my_posts($u);
        $pub = count(array_filter($mine, static fn($p) => ($p['post_status'] ?? '') === 'published'));
        $dra = count($mine) - $pub;
        $out .= '<div class="udash-card"><h3 style="margin-top:0;">Welcome back, ' . esc_html($name) . '</h3>'
            . '<div class="stats"><div class="stat"><b>' . count($mine) . '</b>Total posts</div>'
            . '<div class="stat"><b>' . $pub . '</b>Published</div><div class="stat"><b>' . $dra . '</b>Drafts</div></div>'
            . '<p style="margin-top:16px;"><a class="btn" href="' . esc_url($base . $sep . 'tab=new') . '">Write a New Post</a> '
            . '<a class="btn alt" href="' . esc_url($base . $sep . 'tab=posts') . '">Manage My Posts</a></p>'
            . '<p class="muted">Everything here lives on the front end of the site — you never need to visit the admin area to write, edit, or manage your account.</p></div>';
    }

    if ($tab === 'posts') {
        $editId = (int) ($_GET['edit'] ?? 0);
        $names = nexa_udash_names($u);
        if ($editId > 0) {
            $rows = get_result('posts', 1, ['*'], ['search' => ['id']], 1, [$editId], true);
            $p = $rows[0] ?? null;
            if ($p !== null && nexa_udash_owns($p, $names)) {
                $out .= '<div class="udash-card"><h3 style="margin-top:0;">Edit: ' . esc_html((string) $p['post_title']) . '</h3>'
                    . '<form method="post" action="">' . (function_exists('di_csrf_field') ? di_csrf_field() : '')
                    . '<input type="hidden" name="udash_action" value="save_post"><input type="hidden" name="post_id" value="' . (int) $p['id'] . '">'
                    . '<label>Title</label><input type="text" name="post_title" value="' . esc_attr((string) $p['post_title']) . '" required>'
                    . '<label>Content (HTML allowed)</label><textarea name="post_content" rows="12">' . esc_textarea((string) $p['post_content']) . '</textarea>'
                    . '<label>Excerpt</label><textarea name="post_excerpt" rows="2">' . esc_textarea((string) ($p['post_excerpt'] ?? '')) . '</textarea>'
                    . '<label>Status</label><select name="post_status"><option value="draft"' . (($p['post_status'] ?? '') === 'draft' ? ' selected' : '') . '>Draft</option><option value="published"' . (($p['post_status'] ?? '') === 'published' ? ' selected' : '') . '>Published</option></select>'
                    . '<input type="hidden" name="post_type" value="' . esc_attr((string) ($p['post_type'] ?? 'post')) . '">'
                    . '<p style="margin-top:14px;"><button class="btn">Save Changes</button> <a class="btn alt" href="' . esc_url($base . $sep . 'tab=posts') . '">Back to My Posts</a> '
                    . '<a class="btn alt" target="_blank" href="' . esc_url(post_url($p['id'])) . '">View</a></p></form></div>';
                return $out . '</div>';
            }
            $out .= '<div class="flash error">That post is not yours to edit.</div>';
        }
        $mine = nexa_udash_my_posts($u);
        $out .= '<div class="udash-card"><h3 style="margin-top:0;">My Posts (' . count($mine) . ')</h3>';
        if (!$mine) {
            $out .= '<p>You have not written anything yet. <a href="' . esc_url($base . $sep . 'tab=new') . '">Write your first post &rarr;</a></p>';
        } else {
            $out .= '<table><tr><th>Title</th><th>Type</th><th>Status</th><th>Date</th><th></th></tr>';
            foreach ($mine as $p) {
                $out .= '<tr><td><a href="' . esc_url(post_url($p['id'])) . '" target="_blank">' . esc_html((string) $p['post_title']) . '</a></td>'
                    . '<td>' . esc_html((string) ($p['post_type'] ?? 'post')) . '</td>'
                    . '<td>' . esc_html((string) ($p['post_status'] ?? '')) . '</td>'
                    . '<td>' . esc_html(substr((string) ($p['post_date'] ?? ''), 0, 10)) . '</td>'
                    . '<td style="white-space:nowrap;"><a class="btn alt" style="padding:6px 14px;" href="' . esc_url($base . $sep . 'tab=posts&edit=' . (int) $p['id']) . '">Edit</a> '
                    . '<form method="post" action="" style="display:inline;" onsubmit="return confirm(\'Delete this post permanently?\');">' . (function_exists('di_csrf_field') ? di_csrf_field() : '')
                    . '<input type="hidden" name="udash_action" value="delete_post"><input type="hidden" name="post_id" value="' . (int) $p['id'] . '">'
                    . '<button class="btn danger" style="padding:6px 14px;">Delete</button></form></td></tr>';
            }
            $out .= '</table>';
        }
        $out .= '</div>';
    }

    if ($tab === 'new') {
        $canPublish = current_user_can('edit_posts');
        $out .= '<div class="udash-card"><h3 style="margin-top:0;">Write New</h3>'
            . '<form method="post" action="">' . (function_exists('di_csrf_field') ? di_csrf_field() : '')
            . '<input type="hidden" name="udash_action" value="save_post">'
            . '<label>Title</label><input type="text" name="post_title" required>'
            . '<label>Content (HTML allowed — menus, galleries and other shortcodes work here)</label><textarea name="post_content" rows="12"></textarea>'
            . '<label>Excerpt</label><textarea name="post_excerpt" rows="2"></textarea>'
            . '<div style="display:flex;gap:12px;flex-wrap:wrap;"><div style="flex:1;min-width:160px;"><label>Type</label><select name="post_type"><option value="post">Post</option>' . ($canPublish ? '<option value="page">Page</option>' : '') . '</select></div>'
            . '<div style="flex:1;min-width:160px;"><label>Status</label><select name="post_status"><option value="draft">Draft</option>' . ($canPublish ? '<option value="published">Published</option>' : '') . '</select></div></div>'
            . ($canPublish ? '' : '<p class="muted">Your account saves new items as drafts; an editor can publish them.</p>')
            . '<p style="margin-top:14px;"><button class="btn">Save</button></p></form></div>';
    }

    if ($tab === 'profile') {
        $out .= '<div class="udash-card"><h3 style="margin-top:0;">My Profile</h3>'
            . '<form method="post" action="">' . (function_exists('di_csrf_field') ? di_csrf_field() : '')
            . '<input type="hidden" name="udash_action" value="save_profile">'
            . '<label>Display Name</label><input type="text" name="displayname" value="' . esc_attr((string) ($u['displayname'] ?? '')) . '">'
            . '<label>Full Name</label><input type="text" name="fullname" value="' . esc_attr((string) ($u['fullname'] ?? '')) . '">'
            . '<label>Email</label><input type="email" name="email" value="' . esc_attr((string) ($u['email'] ?? '')) . '">'
            . '<label>Website</label><input type="url" name="url" value="' . esc_attr((string) ($u['url'] ?? '')) . '">'
            . '<label>Phone</label><input type="text" name="phone" value="' . esc_attr((string) ($u['phone'] ?? '')) . '">'
            . '<label>Address</label><input type="text" name="address" value="' . esc_attr((string) ($u['address'] ?? '')) . '">'
            . '<p class="muted">Login username <strong>' . esc_html((string) $u['user']) . '</strong> and role <strong>' . esc_html((string) ($u['role'] ?? '')) . '</strong> can only be changed by an administrator.</p>'
            . '<p style="margin-top:14px;"><button class="btn">Save Profile</button></p></form></div>';
    }

    if ($tab === 'password') {
        $out .= '<div class="udash-card"><h3 style="margin-top:0;">Change Password</h3>'
            . '<form method="post" action="">' . (function_exists('di_csrf_field') ? di_csrf_field() : '')
            . '<input type="hidden" name="udash_action" value="change_password">'
            . '<label>Current Password</label><input type="password" name="current_pass" required autocomplete="current-password">'
            . '<label>New Password</label><input type="password" name="new_pass" required autocomplete="new-password">'
            . (function_exists('nexa_password_policy_summary') ? '<p style="color:#666;font-size:13px">' . htmlspecialchars(nexa_password_policy_summary(), ENT_QUOTES, 'UTF-8') . '</p>' : '')
            . '<label>Confirm New Password</label><input type="password" name="confirm_pass" required autocomplete="new-password">'
            . '<p style="margin-top:14px;"><button class="btn">Change Password</button></p></form></div>';
    }

    return $out . '</div>';
}
if (function_exists('add_shortcode')) {
    add_shortcode('user_dashboard', 'nexa_user_dashboard_shortcode');
}

// Editor support: Admin -> Edit -> Insert Plugin Shortcode.
if (function_exists('register_editor_shortcode')) {
    register_editor_shortcode([
        'plugin' => 'Nexa User Dashboard', 'plugin_slug' => 'nexa-user-dashboard', 'label' => 'Member dashboard', 'tag' => 'user_dashboard',
        'description' => 'Embed login/register, member posts and profile tools. Best on a dedicated page.', 'shortcode' => '[user_dashboard]',
    ]);
}
