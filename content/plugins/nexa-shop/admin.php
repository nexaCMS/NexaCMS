<?php
/**
 * Nexa Shop — Admin UI: setup wizard (pops up once after install) and a
 * tabbed manager (Dashboard / Products / Categories / Orders / Coupons /
 * Settings). Open via Plugins sidebar or admin.php?plugin_page=nexa-shop
 */
if (empty($GLOBALS['adminarea'])) return;
add_menu_item('dashboard', [
    'name' => 'nexa-shop', 'class' => (($_GET['plugin_page'] ?? '') === 'nexa-shop') ? 'active' : '',
    'icon' => 'fa fa-shopping-cart fa-fw', 'label' => ' Shop & Orders', 'link' => 'admin.php?plugin_page=nexa-shop',
], 50);
add_action('nexa_admin', 'nexa_shop_admin_render');

/* Setup wizard pop-up: the first time an admin loads any admin screen
 * after the plugin is installed/activated (and setup is unfinished),
 * send them straight into the wizard — once per session. */
$nexaShopLoggedIn = function_exists('is_user_logged_in') ? is_user_logged_in() : !empty($_SESSION['name']);
if ($nexaShopLoggedIn
    && (string) get_setting('shop_setup_done', '0') !== '1'
    && empty($_SESSION['nexa_shop_wizard_prompted'])
    && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && ($_GET['plugin_page'] ?? '') !== 'nexa-shop'
    && !in_array(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')), ['login.php', 'authenticate.php', 'logout.php', 'ajax.php', 'install.php'], true)) {
    $_SESSION['nexa_shop_wizard_prompted'] = 1;
    header('Location: admin.php?plugin_page=nexa-shop&wizard=1');
    exit;
}

function nexa_shop_admin_render(): void {
    static $done = false;
    if ($done || ($_GET['plugin_page'] ?? '') !== 'nexa-shop') return;
    $done = true;
    nexa_shop_ensure();
    $S = static fn(string $k, string $d = ''): string => nexa_shop_setting($k, $d);
    $step = max(1, min(5, (int) ($_GET['step'] ?? 1)));

    /* ---------------- POST handlers ---------------- */
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && nexa_verify_csrf()) {
        $do = (string) ($_POST['do'] ?? '');
        if ($do === 'wizard_save') {
            $wstep = (int) ($_POST['step'] ?? 1);
            if ($wstep === 1) {
                set_setting('shop_store_name', trim((string) $_POST['store_name']), 'Store Name');
                set_setting('shop_store_email', trim((string) $_POST['store_email']), 'Store Email');
                set_setting('shop_store_country', trim((string) $_POST['store_country']), 'Store Country');
                set_setting('shop_store_city', trim((string) $_POST['store_city']), 'Store City');
                set_setting('shop_store_address', trim((string) $_POST['store_address']), 'Store Address');
                set_setting('shop_store_zip', trim((string) $_POST['store_zip']), 'Store Postal Code');
                set_setting('shop_store_phone', trim((string) $_POST['store_phone']), 'Store Phone');
                $cur = (string) ($_POST['currency'] ?? 'USD');
                set_setting('shop_currency', isset(nexa_shop_currencies()[$cur]) ? $cur : 'USD', 'Shop Currency');
                if (trim((string) $_POST['store_name']) !== '') set_setting('blogname', trim((string) $_POST['store_name']), 'Site Name');
            } elseif ($wstep === 2) {
                set_setting('shop_sell_type', in_array($_POST['sell_type'] ?? '', ['physical', 'digital', 'both'], true) ? (string) $_POST['sell_type'] : 'both', 'Shop Sell Type');
                set_setting('shop_shipping_enabled', !empty($_POST['shipping_enabled']) ? '1' : '0', 'Shop Shipping Enabled');
                set_setting('shop_ship_flat', (string) max(0, (float) ($_POST['ship_flat'] ?? 5)), 'Shop Flat Shipping');
                set_setting('shop_ship_free_over', (string) max(0, (float) ($_POST['ship_free_over'] ?? 0)), 'Shop Free Shipping Over');
                set_setting('shop_ship_pickup', !empty($_POST['ship_pickup']) ? '1' : '0', 'Shop Local Pickup');
                set_setting('shop_weight_unit', ($_POST['weight_unit'] ?? 'kg') === 'lb' ? 'lb' : 'kg', 'Shop Weight Unit');
                set_setting('shop_tax_enabled', !empty($_POST['tax_enabled']) ? '1' : '0', 'Shop Tax Enabled');
                set_setting('shop_tax_label', trim((string) ($_POST['tax_label'] ?? 'Tax')) ?: 'Tax', 'Shop Tax Label');
                set_setting('shop_tax_rate', (string) max(0, (float) ($_POST['tax_rate'] ?? 0)), 'Shop Tax Rate');
            } elseif ($wstep === 3) {
                set_setting('shop_pay_cod', !empty($_POST['pay_cod']) ? '1' : '0', 'Shop Pay COD');
                set_setting('shop_pay_bank', !empty($_POST['pay_bank']) ? '1' : '0', 'Shop Pay Bank');
                set_setting('shop_bank_instructions', trim((string) ($_POST['bank_instructions'] ?? '')), 'Shop Bank Instructions');
                set_setting('shop_pay_paypal', !empty($_POST['pay_paypal']) ? '1' : '0', 'Shop Pay PayPal');
                set_setting('shop_paypal_email', trim((string) ($_POST['paypal_email'] ?? '')), 'Shop PayPal Email');
                set_setting('shop_paypal_mode', ($_POST['paypal_mode'] ?? 'live') === 'sandbox' ? 'sandbox' : 'live', 'Shop PayPal Mode');
                set_setting('shop_pay_stripe', !empty($_POST['pay_stripe']) ? '1' : '0', 'Shop Pay Stripe');
                set_setting('shop_stripe_pk', trim((string) ($_POST['stripe_pk'] ?? '')), 'Shop Stripe Publishable');
                if (trim((string) ($_POST['stripe_secret'] ?? '')) !== '') set_setting('shop_stripe_secret', trim((string) $_POST['stripe_secret']), 'Shop Stripe Secret');
            } elseif ($wstep === 4) {
                set_setting('shop_wiz_pages', !empty($_POST['wiz_pages']) ? '1' : '0');
                set_setting('shop_wiz_menu', !empty($_POST['wiz_menu']) ? '1' : '0');
                set_setting('shop_wiz_sample', !empty($_POST['wiz_sample']) ? '1' : '0');
            }
            echo '<div class="alert alert-success">Saved — continue to the next step.</div>';
            $step = min(5, $wstep + 1);
            $_GET['wizard'] = '1';
        } elseif ($do === 'wizard_skip') {
            set_setting('shop_setup_done', '1', 'Shop Setup Done');
            echo '<div class="alert alert-info">Setup skipped. You can run the wizard any time from Shop &gt; Settings.</div>';
        } elseif ($do === 'wizard_finish') {
            $created = [];
            if ($S('wiz_pages', '1') === '1') $created = nexa_shop_create_pages($S('wiz_menu', '1') === '1');
            if ($S('wiz_sample', '0') === '1') {
                query("INSERT INTO shop_products (name,sku,price,old_price,stock,image,description,status,created_at,product_type,category_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
                    ['Sample Product', 'SAMPLE-001', '25.00', '35.00', '20', '', 'A sample product — edit or delete it from Shop > Products.', 'active', date('Y-m-d H:i:s'), 'physical', '0']);
            }
            set_setting('shop_setup_done', '1', 'Shop Setup Done');
            if (function_exists('nexa_cache_purge')) nexa_cache_purge();
            echo '<div class="alert alert-success"><strong>Your store is ready!</strong>'
                . ($created ? ' Pages created: ' . esc_html(implode(', ', array_keys($created))) . '.' : '')
                . ' <a class="btn btn-xs btn-primary" href="' . esc_attr(nexa_shop_page_url('shop')) . '" target="_blank">Visit your shop</a> '
                . '<a class="btn btn-xs btn-default" href="admin.php?plugin_page=nexa-shop&tab=products">Add products</a></div>';
        } elseif ($do === 'product_save') {
            $row = [
                'name' => trim((string) $_POST['name']), 'sku' => trim((string) $_POST['sku']),
                'price' => number_format(max(0, (float) $_POST['price']), 2, '.', ''), 'old_price' => trim((string) ($_POST['old_price'] ?? '')) !== '' ? number_format(max(0, (float) $_POST['old_price']), 2, '.', '') : '',
                'stock' => (string) max(0, (int) ($_POST['stock'] ?? 0)), 'weight' => (string) max(0, (float) ($_POST['weight'] ?? 0)), 'image' => trim((string) $_POST['image']),
                'description' => trim((string) $_POST['description']), 'status' => in_array($_POST['status'] ?? '', ['active', 'draft'], true) ? (string) $_POST['status'] : 'active',
                'category_id' => (string) (int) ($_POST['category_id'] ?? 0),
                'product_type' => in_array($_POST['product_type'] ?? '', ['physical', 'digital'], true) ? (string) $_POST['product_type'] : 'physical',
                'download_file' => trim((string) ($_POST['download_file'] ?? '')), 'featured' => !empty($_POST['featured']) ? '1' : '0',
            ];
            if ((int) ($_POST['id'] ?? 0) > 0) { $row['id'] = (int) $_POST['id']; update_table('shop_products', $row, 'update'); }
            else { $row['created_at'] = date('Y-m-d H:i:s'); update_table('shop_products', $row, 'insert'); }
            echo '<div class="alert alert-success">Product saved.</div>';
        } elseif ($do === 'product_del') {
            query("DELETE FROM shop_products WHERE id=?", [(int) $_POST['id']]);
            echo '<div class="alert alert-success">Product deleted.</div>';
        } elseif ($do === 'cat_save') {
            $name = trim((string) $_POST['name']);
            $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', $name) ?? '');
            if ((int) ($_POST['id'] ?? 0) > 0) update_table('shop_categories', ['id' => (int) $_POST['id'], 'name' => $name, 'slug' => trim($slug, '-'), 'description' => trim((string) $_POST['description'])], 'update');
            else update_table('shop_categories', ['name' => $name, 'slug' => trim($slug, '-'), 'description' => trim((string) $_POST['description']), 'created_at' => date('Y-m-d H:i:s')], 'insert');
            echo '<div class="alert alert-success">Category saved.</div>';
        } elseif ($do === 'cat_del') {
            query("DELETE FROM shop_categories WHERE id=?", [(int) $_POST['id']]);
            query("UPDATE shop_products SET category_id='0' WHERE category_id=?", [(int) $_POST['id']]);
            echo '<div class="alert alert-success">Category deleted; its products are uncategorized.</div>';
        } elseif ($do === 'coupon_save') {
            $row = ['code' => strtoupper(trim((string) $_POST['code'])), 'type' => ($_POST['type'] ?? 'percent') === 'fixed' ? 'fixed' : 'percent',
                'value' => (string) max(0, (float) $_POST['value']), 'min_spend' => (string) max(0, (float) ($_POST['min_spend'] ?? 0)),
                'expires' => trim((string) ($_POST['expires'] ?? '')), 'usage_limit' => (string) max(0, (int) ($_POST['usage_limit'] ?? 0)),
                'status' => ($_POST['status'] ?? 'active') === 'active' ? 'active' : 'inactive'];
            if ((int) ($_POST['id'] ?? 0) > 0) { $row['id'] = (int) $_POST['id']; update_table('shop_coupons', $row, 'update'); }
            else { $row['used'] = '0'; $row['created_at'] = date('Y-m-d H:i:s'); update_table('shop_coupons', $row, 'insert'); }
            echo '<div class="alert alert-success">Coupon saved.</div>';
        } elseif ($do === 'coupon_del') {
            query("DELETE FROM shop_coupons WHERE id=?", [(int) $_POST['id']]);
            echo '<div class="alert alert-success">Coupon deleted.</div>';
        } elseif ($do === 'order_status') {
            $oid = (int) $_POST['id']; $new = (string) $_POST['status'];
            $cur = query("SELECT status FROM shop_orders WHERE id=?", [$oid]);
            $old = (string) ($cur[0]['status'] ?? '');
            if ($old !== '' && $old !== $new && in_array($new, ['cancelled', 'refunded'], true) && !in_array($old, ['cancelled', 'refunded'], true)) {
                foreach (nexa_shop_order_items($oid) as $li) {
                    $p = nexa_shop_product((int) $li['product_id']);
                    if ($p && ($p['product_type'] ?? 'physical') !== 'digital') query("UPDATE shop_products SET stock=? WHERE id=?", [(int) $p['stock'] + (int) $li['qty'], (int) $p['id']]);
                }
            }
            query("UPDATE shop_orders SET status=? WHERE id=?", [$new, $oid]);
            echo '<div class="alert alert-success">Order #' . $oid . ' is now ' . esc_html($new) . '.</div>';
        } elseif ($do === 'order_del') {
            query("DELETE FROM shop_order_items WHERE order_id=?", [(int) $_POST['id']]);
            query("DELETE FROM shop_orders WHERE id=?", [(int) $_POST['id']]);
            echo '<div class="alert alert-success">Order deleted.</div>';
        } elseif ($do === 'settings_save') {
            foreach ([
                'store_name' => 'store_name', 'store_email' => 'store_email', 'store_country' => 'store_country', 'store_city' => 'store_city',
                'store_address' => 'store_address', 'store_zip' => 'store_zip', 'store_phone' => 'store_phone',
                'ship_flat' => 'ship_flat', 'ship_free_over' => 'ship_free_over', 'tax_label' => 'tax_label', 'tax_rate' => 'tax_rate',
                'bank_instructions' => 'bank_instructions', 'paypal_email' => 'paypal_email', 'stripe_pk' => 'stripe_pk',
            ] as $post => $key) set_setting('shop_' . $key, trim((string) ($_POST[$post] ?? '')), 'Shop ' . $key);
            $cur = (string) ($_POST['currency'] ?? 'USD');
            set_setting('shop_currency', isset(nexa_shop_currencies()[$cur]) ? $cur : 'USD', 'Shop Currency');
            set_setting('shop_shipping_enabled', !empty($_POST['shipping_enabled']) ? '1' : '0');
            set_setting('shop_ship_pickup', !empty($_POST['ship_pickup']) ? '1' : '0');
            set_setting('shop_tax_enabled', !empty($_POST['tax_enabled']) ? '1' : '0');
            set_setting('shop_pay_cod', !empty($_POST['pay_cod']) ? '1' : '0');
            set_setting('shop_pay_bank', !empty($_POST['pay_bank']) ? '1' : '0');
            set_setting('shop_pay_paypal', !empty($_POST['pay_paypal']) ? '1' : '0');
            set_setting('shop_paypal_mode', ($_POST['paypal_mode'] ?? 'live') === 'sandbox' ? 'sandbox' : 'live');
            set_setting('shop_pay_stripe', !empty($_POST['pay_stripe']) ? '1' : '0');
            if (trim((string) ($_POST['stripe_secret'] ?? '')) !== '') set_setting('shop_stripe_secret', trim((string) $_POST['stripe_secret']));
            set_setting('shop_low_stock', (string) max(0, (int) ($_POST['low_stock'] ?? 5)));
            echo '<div class="alert alert-success">Shop settings saved.</div>';
        } elseif ($do === 'create_pages') {
            nexa_shop_create_pages(!empty($_POST['add_menu']));
            if (function_exists('nexa_cache_purge')) nexa_cache_purge();
            echo '<div class="alert alert-success">Store pages created/repaired.</div>';
        } elseif ($do === 'ship_settings') {
            set_setting('shop_weight_unit', ($_POST['weight_unit'] ?? 'kg') === 'lb' ? 'lb' : 'kg', 'Shop Weight Unit');
            set_setting('shop_default_weight', (string) max(0, (float) ($_POST['default_weight'] ?? 0)), 'Shop Default Weight');
            echo '<div class="alert alert-success">Shipping settings saved.</div>';
        } elseif ($do === 'zone_save') {
            $row = ['name' => trim((string) $_POST['name']), 'countries' => trim((string) ($_POST['countries'] ?? ''))];
            if ((int) ($_POST['id'] ?? 0) > 0) { $row['id'] = (int) $_POST['id']; update_table('shop_ship_zones', $row, 'update'); }
            else { $row['created_at'] = date('Y-m-d H:i:s'); update_table('shop_ship_zones', $row, 'insert'); }
            echo '<div class="alert alert-success">Shipping zone saved.</div>';
        } elseif ($do === 'zone_del') {
            query("DELETE FROM shop_ship_methods WHERE zone_id=?", [(int) $_POST['id']]);
            query("DELETE FROM shop_ship_zones WHERE id=?", [(int) $_POST['id']]);
            echo '<div class="alert alert-success">Zone and its methods deleted.</div>';
        } elseif ($do === 'method_save') {
            $typeWanted = (string) ($_POST['type'] ?? 'flat');
            $row = [
                'zone_id' => (string) (int) ($_POST['zone_id'] ?? 0),
                'title' => trim((string) $_POST['title']),
                'type' => in_array($typeWanted, ['flat', 'weight', 'free', 'pickup'], true) ? $typeWanted : 'flat',
                'cost' => (string) max(0, (float) ($_POST['cost'] ?? 0)),
                'per_kg' => (string) max(0, (float) ($_POST['per_kg'] ?? 0)),
                'min_amount' => (string) max(0, (float) ($_POST['min_amount'] ?? 0)),
                'status' => ($_POST['status'] ?? 'active') === 'active' ? 'active' : 'inactive',
                'sort' => (string) (int) ($_POST['sort'] ?? 0),
            ];
            if ((int) ($_POST['id'] ?? 0) > 0) { $row['id'] = (int) $_POST['id']; update_table('shop_ship_methods', $row, 'update'); }
            else { $row['created_at'] = date('Y-m-d H:i:s'); update_table('shop_ship_methods', $row, 'insert'); }
            echo '<div class="alert alert-success">Shipping method saved.</div>';
        } elseif ($do === 'method_del') {
            query("DELETE FROM shop_ship_methods WHERE id=?", [(int) $_POST['id']]);
            echo '<div class="alert alert-success">Shipping method deleted.</div>';
        }
    }

    $setupDone = (string) get_setting('shop_setup_done', '0') === '1';
    if (!$setupDone || isset($_GET['wizard'])) { nexa_shop_wizard_render($step, $S); return; }

    $tab = (string) ($_GET['tab'] ?? 'dashboard');
    echo '<h2><i class="fa fa-shopping-cart"></i> ' . esc_html($S('store_name', 'Shop')) . ' <small>Nexa Shop</small></h2>';
    echo '<ul class="nav nav-tabs" style="margin-bottom:16px;">';
    foreach (['dashboard' => 'Dashboard', 'products' => 'Products', 'categories' => 'Categories', 'orders' => 'Orders', 'coupons' => 'Coupons', 'shipping' => 'Shipping', 'settings' => 'Settings'] as $k => $label) {
        echo '<li' . ($tab === $k ? ' class="active"' : '') . '><a href="admin.php?plugin_page=nexa-shop&tab=' . $k . '">' . $label . '</a></li>';
    }
    echo '</ul>';
    if ($tab === 'products') nexa_shop_tab_products($S);
    elseif ($tab === 'categories') nexa_shop_tab_categories();
    elseif ($tab === 'orders') nexa_shop_tab_orders($S);
    elseif ($tab === 'coupons') nexa_shop_tab_coupons();
    elseif ($tab === 'shipping') nexa_shop_tab_shipping($S);
    elseif ($tab === 'settings') nexa_shop_tab_settings($S);
    else nexa_shop_tab_dashboard($S);
}

/* ============================ wizard ============================ */
function nexa_shop_wizard_render(int $step, callable $S): void {
    $names = [1 => 'Store details', 2 => 'Selling & shipping', 3 => 'Payments', 4 => 'Store pages', 5 => 'Finish'];
    echo '<div style="max-width:860px;margin:14px auto;"><div class="panel panel-primary"><div class="panel-heading"><strong><i class="fa fa-shopping-cart"></i> Set up your store</strong> — step ' . $step . ' of 5: ' . esc_html($names[$step]) . '</div><div class="panel-body">';
    echo '<ol class="breadcrumb">';
    foreach ($names as $n => $label) echo '<li' . ($n === $step ? ' class="active"' : '') . '>' . ($n < $step || $n === $step ? esc_html($label) : esc_html($label)) . '</li>';
    echo '</ol>';
    $curOpts = '';
    foreach (nexa_shop_currencies() as $code => $sym) $curOpts .= '<option value="' . $code . '"' . ($S('currency', 'USD') === $code ? ' selected' : '') . '>' . $code . ' (' . $sym . ')</option>';
    $chk = static fn(string $k, string $d = '0'): string => $S($k, $d) === '1' ? ' checked' : '';

    if ($step === 1) {
        echo '<form method="post">' . nexa_csrf_field() . '<input type="hidden" name="do" value="wizard_save"><input type="hidden" name="step" value="1">'
            . '<div class="row"><div class="col-md-6"><div class="form-group"><label>Store name</label><input name="store_name" class="form-control" required value="' . esc_attr($S('store_name')) . '" placeholder="e.g. Kingston Crafts"></div></div>'
            . '<div class="col-md-6"><div class="form-group"><label>Store email (orders inbox)</label><input name="store_email" type="email" class="form-control" value="' . esc_attr($S('store_email')) . '" placeholder="orders@example.com"></div></div></div>'
            . '<div class="row"><div class="col-md-4"><div class="form-group"><label>Country</label><input name="store_country" class="form-control" value="' . esc_attr($S('store_country')) . '" placeholder="Jamaica"></div></div>'
            . '<div class="col-md-4"><div class="form-group"><label>City / Town</label><input name="store_city" class="form-control" value="' . esc_attr($S('store_city')) . '"></div></div>'
            . '<div class="col-md-4"><div class="form-group"><label>Currency</label><select name="currency" class="form-control">' . $curOpts . '</select></div></div></div>'
            . '<div class="form-group"><label>Street address</label><input name="store_address" class="form-control" value="' . esc_attr($S('store_address')) . '"></div>'
            . '<div class="row"><div class="col-md-6"><div class="form-group"><label>Postal / ZIP code</label><input name="store_zip" class="form-control" value="' . esc_attr($S('store_zip')) . '"></div></div>'
            . '<div class="col-md-6"><div class="form-group"><label>Phone</label><input name="store_phone" class="form-control" value="' . esc_attr($S('store_phone')) . '"></div></div></div>'
            . '<button class="btn btn-primary">Save &amp; continue</button></form>';
    } elseif ($step === 2) {
        echo '<form method="post">' . nexa_csrf_field() . '<input type="hidden" name="do" value="wizard_save"><input type="hidden" name="step" value="2">'
            . '<div class="form-group"><label>What will you sell?</label><select name="sell_type" class="form-control"><option value="physical"' . ($S('sell_type', 'both') === 'physical' ? ' selected' : '') . '>Physical products (shipped/picked up)</option><option value="digital"' . ($S('sell_type') === 'digital' ? ' selected' : '') . '>Digital products (downloads)</option><option value="both"' . ($S('sell_type', 'both') === 'both' ? ' selected' : '') . '>Both</option></select></div>'
            . '<label style="font-weight:normal;"><input type="checkbox" name="shipping_enabled" value="1"' . $chk('shipping_enabled', '1') . '> Enable shipping for physical products</label>'
            . '<div class="row"><div class="col-md-4"><div class="form-group"><label>Flat shipping rate</label><input name="ship_flat" type="number" step="0.01" min="0" class="form-control" value="' . esc_attr($S('ship_flat', '5')) . '"></div></div>'
            . '<div class="col-md-4"><div class="form-group"><label>Free shipping over (0 = never)</label><input name="ship_free_over" type="number" step="0.01" min="0" class="form-control" value="' . esc_attr($S('ship_free_over', '0')) . '"></div></div>'
            . '<div class="col-md-4"><div class="form-group"><label>&nbsp;</label><br><label style="font-weight:normal;"><input type="checkbox" name="ship_pickup" value="1"' . $chk('ship_pickup', '1') . '> Offer local pickup (free)</label></div></div></div>'
            . '<div class="row"><div class="col-md-4"><div class="form-group"><label>Product weight unit</label><select name="weight_unit" class="form-control"><option value="kg"' . ($S('weight_unit', 'kg') === 'kg' ? ' selected' : '') . '>Kilograms (kg)</option><option value="lb"' . ($S('weight_unit') === 'lb' ? ' selected' : '') . '>Pounds (lb)</option></select></div></div>'
            . '<div class="col-md-8"><p class="text-muted" style="margin-top:26px;">These are your starting rates. Want to charge more for far-away or heavy orders? After setup, open <strong>Shop &gt; Shipping</strong> to add zones (by country) and weight-based rates.</p></div></div>'
            . '<label style="font-weight:normal;"><input type="checkbox" name="tax_enabled" value="1"' . $chk('tax_enabled') . '> Charge tax</label>'
            . '<div class="row"><div class="col-md-6"><div class="form-group"><label>Tax label</label><input name="tax_label" class="form-control" value="' . esc_attr($S('tax_label', 'Tax')) . '" placeholder="Tax / GCT / VAT"></div></div>'
            . '<div class="col-md-6"><div class="form-group"><label>Tax rate %</label><input name="tax_rate" type="number" step="0.01" min="0" class="form-control" value="' . esc_attr($S('tax_rate', '0')) . '"></div></div></div>'
            . '<button class="btn btn-primary">Save &amp; continue</button></form>';
    } elseif ($step === 3) {
        echo '<form method="post">' . nexa_csrf_field() . '<input type="hidden" name="do" value="wizard_save"><input type="hidden" name="step" value="3">'
            . '<label style="font-weight:normal;display:block;"><input type="checkbox" name="pay_cod" value="1"' . $chk('pay_cod', '1') . '> <strong>Cash on delivery</strong> — customers pay when the order arrives</label>'
            . '<label style="font-weight:normal;display:block;"><input type="checkbox" name="pay_bank" value="1"' . $chk('pay_bank') . '> <strong>Direct bank transfer</strong></label>'
            . '<div class="form-group"><label>Bank instructions shown after checkout</label><textarea name="bank_instructions" class="form-control" rows="3" placeholder="Bank: NCB, Account: 123456789, Name: Your Store Ltd">' . esc_textarea($S('bank_instructions')) . '</textarea></div>'
            . '<label style="font-weight:normal;display:block;"><input type="checkbox" name="pay_paypal" value="1"' . $chk('pay_paypal') . '> <strong>PayPal</strong></label>'
            . '<div class="row"><div class="col-md-6"><div class="form-group"><label>PayPal email</label><input name="paypal_email" type="email" class="form-control" value="' . esc_attr($S('paypal_email')) . '"></div></div>'
            . '<div class="col-md-6"><div class="form-group"><label>Mode</label><select name="paypal_mode" class="form-control"><option value="live"' . ($S('paypal_mode', 'live') === 'live' ? ' selected' : '') . '>Live</option><option value="sandbox"' . ($S('paypal_mode') === 'sandbox' ? ' selected' : '') . '>Sandbox (testing)</option></select></div></div></div>'
            . '<label style="font-weight:normal;display:block;"><input type="checkbox" name="pay_stripe" value="1"' . $chk('pay_stripe') . '> <strong>Credit/debit cards via Stripe</strong> (Stripe Checkout)</label>'
            . '<div class="row"><div class="col-md-6"><div class="form-group"><label>Stripe publishable key</label><input name="stripe_pk" class="form-control" value="' . esc_attr($S('stripe_pk')) . '" placeholder="pk_live_..."></div></div>'
            . '<div class="col-md-6"><div class="form-group"><label>Stripe secret key</label><input name="stripe_secret" type="password" class="form-control" placeholder="sk_live_... (leave blank to keep)"></div></div></div>'
            . '<button class="btn btn-primary">Save &amp; continue</button></form>';
    } elseif ($step === 4) {
        echo '<form method="post">' . nexa_csrf_field() . '<input type="hidden" name="do" value="wizard_save"><input type="hidden" name="step" value="4">'
            . '<p>Your store needs these pages (created automatically with the right shortcodes):</p><ul>'
            . '<li><strong>Shop</strong> — <code>[shop_catalog]</code></li><li><strong>Cart</strong> — <code>[shop_cart]</code></li><li><strong>Checkout</strong> — <code>[shop_checkout]</code></li><li><strong>My Account</strong> — <code>[shop_account]</code> (orders &amp; downloads)</li></ul>'
            . '<label style="font-weight:normal;display:block;"><input type="checkbox" name="wiz_pages" value="1"' . $chk('wiz_pages', '1') . '> Create the store pages when I finish</label>'
            . '<label style="font-weight:normal;display:block;"><input type="checkbox" name="wiz_menu" value="1"' . $chk('wiz_menu', '1') . '> Add Shop, Cart and My Account to the main menu</label>'
            . '<label style="font-weight:normal;display:block;"><input type="checkbox" name="wiz_sample" value="1"' . $chk('wiz_sample') . '> Add one sample product so I can see the store working</label>'
            . '<button class="btn btn-primary">Save &amp; continue</button></form>';
    } else {
        $pages = [];
        foreach (nexa_shop_page_defs() as $k => $def) $pages[] = $def['title'] . ' — ' . nexa_shop_page_url($k);
        echo '<h4>Review &amp; launch</h4><table class="table"><tbody>'
            . '<tr><th style="width:200px;">Store</th><td>' . esc_html($S('store_name')) . ' (' . esc_html($S('store_email')) . ')</td></tr>'
            . '<tr><th>Location</th><td>' . esc_html(trim($S('store_city') . ', ' . $S('store_country'), ', ')) . '</td></tr>'
            . '<tr><th>Currency</th><td>' . esc_html($S('currency', 'USD')) . '</td></tr>'
            . '<tr><th>Selling</th><td>' . esc_html($S('sell_type', 'both')) . ' &middot; shipping ' . ($S('shipping_enabled', '1') === '1' ? 'on (' . nexa_shop_money((float) $S('ship_flat', '5')) . ' flat)' : 'off') . ' &middot; tax ' . ($S('tax_enabled') === '1' ? $S('tax_rate') . '%' : 'off') . '</td></tr>'
            . '<tr><th>Payments</th><td>' . esc_html(implode(', ', array_filter([
                $S('pay_cod', '1') === '1' ? 'Cash on delivery' : null, $S('pay_bank') === '1' ? 'Bank transfer' : null,
                $S('pay_paypal') === '1' ? 'PayPal' : null, $S('pay_stripe') === '1' ? 'Stripe' : null])) ?: 'Cash on delivery') . '</td></tr>'
            . '<tr><th>Pages</th><td>' . ($S('wiz_pages', '1') === '1' ? 'Will create/repair:<br>' . implode('<br>', array_map('esc_html', $pages)) : 'Not creating pages') . '</td></tr>'
            . '</tbody></table>'
            . '<form method="post" style="display:inline;">' . nexa_csrf_field() . '<input type="hidden" name="do" value="wizard_finish"><button class="btn btn-success btn-lg"><i class="fa fa-rocket"></i> Launch my store</button></form> '
            . '<a class="btn btn-default" href="admin.php?plugin_page=nexa-shop&wizard=1&step=1">Back to step 1</a>';
    }
    echo '<form method="post" style="margin-top:18px;">' . nexa_csrf_field() . '<input type="hidden" name="do" value="wizard_skip"><button class="btn btn-link btn-sm">Skip setup for now</button></form>';
    echo '</div></div></div>';
}

/* ============================ tabs ============================ */
function nexa_shop_tab_dashboard(callable $S): void {
    $orders = get_table('shop_orders')['data'];
    $paidStatuses = ['paid', 'processing', 'shipped', 'completed'];
    $revenue = 0.0; $pending = 0;
    foreach ($orders as $o) {
        if (in_array((string) ($o['status'] ?? ''), $paidStatuses, true)) $revenue += (float) ($o['total'] ?? 0);
        if (($o['status'] ?? '') === 'pending') $pending++;
    }
    $products = get_table('shop_products')['data'];
    $lowAt = (int) $S('low_stock', '5');
    $low = array_filter($products, static fn($p) => ($p['product_type'] ?? 'physical') !== 'digital' && (int) ($p['stock'] ?? 0) <= $lowAt && ($p['status'] ?? '') === 'active');
    echo '<div class="row">'
        . '<div class="col-md-3"><div class="panel panel-success"><div class="panel-heading">Revenue</div><div class="panel-body"><h3 style="margin:0;">' . nexa_shop_money($revenue) . '</h3></div></div></div>'
        . '<div class="col-md-3"><div class="panel panel-info"><div class="panel-heading">Orders</div><div class="panel-body"><h3 style="margin:0;">' . count($orders) . '</h3></div></div></div>'
        . '<div class="col-md-3"><div class="panel panel-warning"><div class="panel-heading">Pending orders</div><div class="panel-body"><h3 style="margin:0;">' . $pending . '</h3></div></div></div>'
        . '<div class="col-md-3"><div class="panel panel-default"><div class="panel-heading">Products</div><div class="panel-body"><h3 style="margin:0;">' . count($products) . '</h3></div></div></div></div>';
    if ($low) {
        echo '<div class="alert alert-warning"><strong>Low stock:</strong> ';
        foreach ($low as $p) echo esc_html($p['name']) . ' (' . (int) $p['stock'] . ' left) &middot; ';
        echo '</div>';
    }
    echo '<h3>Recent orders</h3><table class="table table-striped"><thead><tr><th>#</th><th>Date</th><th>Customer</th><th>Total</th><th>Status</th><th></th></tr></thead><tbody>';
    foreach (array_slice(array_reverse($orders), 0, 8) as $o) {
        echo '<tr><td>#' . (int) $o['id'] . '</td><td>' . esc_html($o['created_at']) . '</td><td>' . esc_html($o['customer_name']) . '</td><td>' . nexa_shop_money((float) $o['total']) . '</td><td>' . esc_html($o['status']) . '</td><td><a class="btn btn-xs btn-default" href="admin.php?plugin_page=nexa-shop&tab=orders&order=' . (int) $o['id'] . '">View</a></td></tr>';
    }
    echo '</tbody></table>';
    if ((string) get_setting('shop_setup_done', '0') !== '1') echo '<p><a class="btn btn-primary" href="admin.php?plugin_page=nexa-shop&wizard=1">Run the setup wizard</a></p>';
}

function nexa_shop_tab_products(callable $S): void {
    $edit = null;
    if (!empty($_GET['edit'])) foreach (get_table('shop_products')['data'] as $r) if ((int) $r['id'] === (int) $_GET['edit']) $edit = $r;
    $cats = nexa_shop_categories();
    echo '<div class="panel panel-default"><div class="panel-heading"><strong>' . ($edit ? 'Edit product' : 'Add a product') . '</strong></div><div class="panel-body">'
        . '<form method="post">' . nexa_csrf_field() . '<input type="hidden" name="do" value="product_save"><input type="hidden" name="id" value="' . (int) ($edit['id'] ?? 0) . '">'
        . '<div class="row"><div class="col-md-6"><div class="form-group"><label>Product name</label><input name="name" class="form-control" required value="' . esc_attr($edit['name'] ?? '') . '"></div></div>'
        . '<div class="col-md-3"><div class="form-group"><label>SKU</label><input name="sku" class="form-control" value="' . esc_attr($edit['sku'] ?? '') . '"></div></div>'
        . '<div class="col-md-3"><div class="form-group"><label>Category</label><select name="category_id" class="form-control"><option value="0">Uncategorized</option>';
    foreach ($cats as $c) echo '<option value="' . (int) $c['id'] . '"' . ((int) ($edit['category_id'] ?? 0) === (int) $c['id'] ? ' selected' : '') . '>' . esc_html($c['name']) . '</option>';
    echo '</select></div></div></div>'
        . '<div class="row"><div class="col-md-3"><div class="form-group"><label>Type</label><select name="product_type" class="form-control"><option value="physical"' . (($edit['product_type'] ?? 'physical') === 'physical' ? ' selected' : '') . '>Physical</option><option value="digital"' . (($edit['product_type'] ?? '') === 'digital' ? ' selected' : '') . '>Digital (download)</option></select></div></div>'
        . '<div class="col-md-3"><div class="form-group"><label>Price</label><input name="price" type="number" step="0.01" min="0" class="form-control" required value="' . esc_attr($edit['price'] ?? '') . '"></div></div>'
        . '<div class="col-md-3"><div class="form-group"><label>Old price (sale)</label><input name="old_price" type="number" step="0.01" min="0" class="form-control" value="' . esc_attr($edit['old_price'] ?? '') . '"></div></div>'
        . '<div class="col-md-3"><div class="form-group"><label>Stock</label><input name="stock" type="number" min="0" class="form-control" value="' . esc_attr($edit['stock'] ?? '10') . '"></div></div></div>'
        . '<div class="row"><div class="col-md-3"><div class="form-group"><label>Weight (' . esc_html(nexa_shop_weight_unit()) . ') — for weight-based shipping</label><input name="weight" type="number" step="0.01" min="0" class="form-control" value="' . esc_attr($edit['weight'] ?? '0') . '"></div></div></div>'
        . '<div class="row"><div class="col-md-6"><div class="form-group"><label>Image URL</label><input name="image" class="form-control" value="' . esc_attr($edit['image'] ?? '') . '" placeholder="https://..."></div></div>'
        . '<div class="col-md-6"><div class="form-group"><label>Download file (digital products)</label><input name="download_file" class="form-control" value="' . esc_attr($edit['download_file'] ?? '') . '" placeholder="URL or content/uploads/... path"></div></div></div>'
        . '<div class="form-group"><label>Description</label><textarea name="description" class="form-control" rows="4">' . esc_textarea($edit['description'] ?? '') . '</textarea></div>'
        . '<div class="row"><div class="col-md-4"><div class="form-group"><label>Status</label><select name="status" class="form-control"><option value="active"' . (($edit['status'] ?? 'active') === 'active' ? ' selected' : '') . '>Active (on sale)</option><option value="draft"' . (($edit['status'] ?? '') === 'draft' ? ' selected' : '') . '>Draft (hidden)</option></select></div></div>'
        . '<div class="col-md-4"><div class="form-group"><label>&nbsp;</label><br><label style="font-weight:normal;"><input type="checkbox" name="featured" value="1"' . (($edit['featured'] ?? '') === '1' ? ' checked' : '') . '> Featured</label></div></div></div>'
        . '<button class="btn btn-primary">' . ($edit ? 'Save Product' : 'Add Product') . '</button>'
        . ($edit ? ' <a class="btn btn-default" href="admin.php?plugin_page=nexa-shop&tab=products">Cancel edit</a>' : '')
        . '</form></div></div>';
    echo '<table class="table table-striped"><thead><tr><th>Product</th><th>Category</th><th>Type</th><th>Price</th><th>Stock</th><th>Status</th><th></th></tr></thead><tbody>';
    foreach (query("SELECT * FROM shop_products ORDER BY id DESC") as $p) {
        echo '<tr><td>' . esc_html($p['name']) . '<br><small class="text-muted">' . esc_html($p['sku']) . '</small></td><td>' . esc_html(nexa_shop_category_name((int) ($p['category_id'] ?? 0)) ?: '—') . '</td><td>' . esc_html($p['product_type'] ?? 'physical') . '</td><td>' . nexa_shop_money((float) $p['price']) . '</td><td>' . (int) $p['stock'] . '</td><td>' . esc_html($p['status']) . '</td>'
            . '<td><a class="btn btn-xs btn-primary" href="admin.php?plugin_page=nexa-shop&tab=products&edit=' . (int) $p['id'] . '">Edit</a> '
            . '<form method="post" style="display:inline;" onsubmit="return confirm(\'Delete product?\');">' . nexa_csrf_field() . '<input type="hidden" name="id" value="' . (int) $p['id'] . '"><button name="do" value="product_del" class="btn btn-xs btn-danger">Delete</button></form></td></tr>';
    }
    echo '</tbody></table>';
}

function nexa_shop_tab_categories(): void {
    $edit = null;
    if (!empty($_GET['edit'])) foreach (nexa_shop_categories() as $r) if ((int) $r['id'] === (int) $_GET['edit']) $edit = $r;
    echo '<div class="panel panel-default"><div class="panel-heading"><strong>' . ($edit ? 'Edit category' : 'Add a category') . '</strong></div><div class="panel-body">'
        . '<form method="post" class="form-inline">' . nexa_csrf_field() . '<input type="hidden" name="do" value="cat_save"><input type="hidden" name="id" value="' . (int) ($edit['id'] ?? 0) . '">'
        . '<input name="name" class="form-control" required placeholder="Category name" value="' . esc_attr($edit['name'] ?? '') . '"> <input name="description" class="form-control" placeholder="Description" value="' . esc_attr($edit['description'] ?? '') . '"> <button class="btn btn-primary">Save Category</button>'
        . ($edit ? ' <a class="btn btn-default" href="admin.php?plugin_page=nexa-shop&tab=categories">Cancel</a>' : '') . '</form></div></div>';
    echo '<table class="table table-striped"><thead><tr><th>Category</th><th>Slug</th><th>Products</th><th></th></tr></thead><tbody>';
    $counts = [];
    foreach (get_table('shop_products')['data'] as $p) $counts[(int) ($p['category_id'] ?? 0)] = ($counts[(int) ($p['category_id'] ?? 0)] ?? 0) + 1;
    foreach (nexa_shop_categories() as $c) {
        echo '<tr><td>' . esc_html($c['name']) . '</td><td>' . esc_html($c['slug']) . '</td><td>' . (int) ($counts[(int) $c['id']] ?? 0) . '</td>'
            . '<td><a class="btn btn-xs btn-primary" href="admin.php?plugin_page=nexa-shop&tab=categories&edit=' . (int) $c['id'] . '">Edit</a> '
            . '<form method="post" style="display:inline;" onsubmit="return confirm(\'Delete category?\');">' . nexa_csrf_field() . '<input type="hidden" name="id" value="' . (int) $c['id'] . '"><button name="do" value="cat_del" class="btn btn-xs btn-danger">Delete</button></form></td></tr>';
    }
    echo '</tbody></table>';
}

function nexa_shop_tab_orders(callable $S): void {
    $detailId = (int) ($_GET['order'] ?? 0);
    if ($detailId > 0) {
        $o = query("SELECT * FROM shop_orders WHERE id=?", [$detailId]);
        if ($o) {
            $o = $o[0];
            echo '<div class="panel panel-primary"><div class="panel-heading"><strong>Order #' . $detailId . '</strong> — ' . esc_html($o['created_at']) . ' — ' . esc_html($o['status']) . '</div><div class="panel-body">'
                . '<div class="row"><div class="col-md-6"><h4>Customer</h4><p>' . esc_html($o['customer_name']) . '<br>' . esc_html($o['email']) . '<br>' . esc_html($o['phone']) . '<br>' . nl2br(esc_html($o['address'])) . (!empty($o['country']) ? '<br>' . esc_html($o['country']) : '') . '</p>'
                . ($o['notes'] ? '<p><em>Notes: ' . esc_html($o['notes']) . '</em></p>' : '') . '</div>'
                . '<div class="col-md-6"><h4>Payment</h4><p>Method: ' . esc_html($o['payment_method'] ?? 'cod') . '<br>Coupon: ' . esc_html($o['coupon'] ?: '—') . '<br>Shipping: ' . esc_html(($o['shipping_method'] ?? '') ?: '—') . ' (' . nexa_shop_money((float) ($o['shipping_cost'] ?? 0)) . ')</p>'
                . '<form method="post" class="form-inline">' . nexa_csrf_field() . '<input type="hidden" name="id" value="' . $detailId . '"><select name="status" class="form-control">';
            foreach (nexa_shop_statuses() as $st) echo '<option' . ($st === $o['status'] ? ' selected' : '') . '>' . $st . '</option>';
            echo '</select> <button name="do" value="order_status" class="btn btn-primary btn-sm">Update status</button></form>'
                . '<form method="post" style="margin-top:8px;" onsubmit="return confirm(\'Delete this order?\');">' . nexa_csrf_field() . '<input type="hidden" name="id" value="' . $detailId . '"><button name="do" value="order_del" class="btn btn-danger btn-xs">Delete order</button></form></div></div>'
                . '<h4>Items</h4><table class="table"><thead><tr><th>Item</th><th>Price</th><th>Qty</th><th>Line total</th></tr></thead><tbody>';
            $lines = nexa_shop_order_items($detailId);
            if ($lines) foreach ($lines as $li) echo '<tr><td>' . esc_html($li['name']) . '</td><td>' . nexa_shop_money((float) $li['price']) . '</td><td>' . (int) $li['qty'] . '</td><td>' . nexa_shop_money((float) $li['line_total']) . '</td></tr>';
            else echo '<tr><td colspan="4">' . esc_html($o['items']) . '</td></tr>';
            echo '</tbody></table><p>Subtotal: ' . nexa_shop_money((float) ($o['subtotal'] ?: $o['total'])) . '<br>Discount: -' . nexa_shop_money((float) ($o['discount'] ?? 0)) . '<br>Shipping: ' . nexa_shop_money((float) ($o['shipping_cost'] ?? 0)) . '<br>' . esc_html($S('tax_label', 'Tax')) . ': ' . nexa_shop_money((float) ($o['tax'] ?? 0)) . '<br><strong>Total: ' . nexa_shop_money((float) $o['total']) . '</strong></p>'
                . '</div></div>';
        }
    }
    $filter = (string) ($_GET['status'] ?? '');
    $orders = query("SELECT * FROM shop_orders ORDER BY id DESC");
    if ($filter !== '') $orders = array_values(array_filter($orders, static fn($o) => ($o['status'] ?? '') === $filter));
    echo '<form method="get" class="form-inline" style="margin-bottom:10px;"><input type="hidden" name="plugin_page" value="nexa-shop"><input type="hidden" name="tab" value="orders"><label>Filter: <select name="status" class="form-control"><option value="">All statuses</option>';
    foreach (nexa_shop_statuses() as $st) echo '<option' . ($filter === $st ? ' selected' : '') . '>' . $st . '</option>';
    echo '</select></label> <button class="btn btn-default">Apply</button></form>';
    echo '<table class="table table-striped"><thead><tr><th>#</th><th>Date</th><th>Customer</th><th>Items</th><th>Total</th><th>Payment</th><th>Status</th><th></th></tr></thead><tbody>';
    foreach ($orders as $o) {
        echo '<tr><td>#' . (int) $o['id'] . '</td><td>' . esc_html($o['created_at']) . '</td><td>' . esc_html($o['customer_name'] . ' <' . $o['email'] . '>') . '</td><td>' . esc_html(mb_substr((string) $o['items'], 0, 60)) . '</td><td>' . nexa_shop_money((float) $o['total']) . '</td><td>' . esc_html($o['payment_method'] ?? 'cod') . '</td><td>' . esc_html($o['status']) . '</td>'
            . '<td><a class="btn btn-xs btn-primary" href="admin.php?plugin_page=nexa-shop&tab=orders&order=' . (int) $o['id'] . '">View</a></td></tr>';
    }
    echo '</tbody></table>';
}

function nexa_shop_tab_coupons(): void {
    $edit = null;
    if (!empty($_GET['edit'])) foreach (get_table('shop_coupons')['data'] as $r) if ((int) $r['id'] === (int) $_GET['edit']) $edit = $r;
    echo '<div class="panel panel-default"><div class="panel-heading"><strong>' . ($edit ? 'Edit coupon' : 'Add a coupon') . '</strong></div><div class="panel-body">'
        . '<form method="post">' . nexa_csrf_field() . '<input type="hidden" name="do" value="coupon_save"><input type="hidden" name="id" value="' . (int) ($edit['id'] ?? 0) . '">'
        . '<div class="row"><div class="col-md-3"><div class="form-group"><label>Code</label><input name="code" class="form-control" required value="' . esc_attr($edit['code'] ?? '') . '" placeholder="SAVE10"></div></div>'
        . '<div class="col-md-2"><div class="form-group"><label>Type</label><select name="type" class="form-control"><option value="percent"' . (($edit['type'] ?? 'percent') === 'percent' ? ' selected' : '') . '>% off</option><option value="fixed"' . (($edit['type'] ?? '') === 'fixed' ? ' selected' : '') . '>Fixed amount</option></select></div></div>'
        . '<div class="col-md-2"><div class="form-group"><label>Value</label><input name="value" type="number" step="0.01" min="0" class="form-control" required value="' . esc_attr($edit['value'] ?? '') . '"></div></div>'
        . '<div class="col-md-2"><div class="form-group"><label>Min spend</label><input name="min_spend" type="number" step="0.01" min="0" class="form-control" value="' . esc_attr($edit['min_spend'] ?? '0') . '"></div></div>'
        . '<div class="col-md-3"><div class="form-group"><label>Expires (date)</label><input name="expires" type="date" class="form-control" value="' . esc_attr($edit['expires'] ?? '') . '"></div></div></div>'
        . '<div class="row"><div class="col-md-3"><div class="form-group"><label>Usage limit (0 = unlimited)</label><input name="usage_limit" type="number" min="0" class="form-control" value="' . esc_attr($edit['usage_limit'] ?? '0') . '"></div></div>'
        . '<div class="col-md-3"><div class="form-group"><label>Status</label><select name="status" class="form-control"><option value="active"' . (($edit['status'] ?? 'active') === 'active' ? ' selected' : '') . '>Active</option><option value="inactive"' . (($edit['status'] ?? '') === 'inactive' ? ' selected' : '') . '>Inactive</option></select></div></div></div>'
        . '<button class="btn btn-primary">Save Coupon</button>' . ($edit ? ' <a class="btn btn-default" href="admin.php?plugin_page=nexa-shop&tab=coupons">Cancel</a>' : '') . '</form></div></div>';
    echo '<table class="table table-striped"><thead><tr><th>Code</th><th>Discount</th><th>Min spend</th><th>Used</th><th>Expires</th><th>Status</th><th></th></tr></thead><tbody>';
    foreach (get_table('shop_coupons')['data'] as $c) {
        echo '<tr><td><code>' . esc_html($c['code']) . '</code></td><td>' . (($c['type'] ?? 'percent') === 'percent' ? esc_html($c['value']) . '%' : nexa_shop_money((float) $c['value'])) . '</td><td>' . nexa_shop_money((float) ($c['min_spend'] ?? 0)) . '</td><td>' . (int) ($c['used'] ?? 0) . ((int) ($c['usage_limit'] ?? 0) > 0 ? ' / ' . (int) $c['usage_limit'] : '') . '</td><td>' . esc_html($c['expires'] ?: '—') . '</td><td>' . esc_html($c['status']) . '</td>'
            . '<td><a class="btn btn-xs btn-primary" href="admin.php?plugin_page=nexa-shop&tab=coupons&edit=' . (int) $c['id'] . '">Edit</a> '
            . '<form method="post" style="display:inline;" onsubmit="return confirm(\'Delete coupon?\');">' . nexa_csrf_field() . '<input type="hidden" name="id" value="' . (int) $c['id'] . '"><button name="do" value="coupon_del" class="btn btn-xs btn-danger">Delete</button></form></td></tr>';
    }
    echo '</tbody></table>';
}

function nexa_shop_tab_shipping(callable $S): void {
    $unit = nexa_shop_weight_unit();
    $zones = nexa_shop_zones();
    $editZone = null;
    if (!empty($_GET['editzone'])) foreach ($zones as $z) if ((int) $z['id'] === (int) $_GET['editzone']) $editZone = $z;
    $editMethod = null;
    if (!empty($_GET['editm'])) foreach (query("SELECT * FROM shop_ship_methods") as $m) if ((int) $m['id'] === (int) $_GET['editm']) $editMethod = $m;
    $methodZone = $editMethod ? (int) $editMethod['zone_id'] : (int) ($_GET['mzone'] ?? 0);
    echo '<div class="alert alert-info"><strong>How shipping zones work:</strong> at checkout the customer picks their country, the <strong>first zone</strong> whose country list contains it wins, and only that zone\'s methods are offered. A zone with an <strong>empty country list</strong> is the &ldquo;Everywhere else&rdquo; fallback. If you create <strong>no zones at all</strong>, the fallback flat/pickup rates from <a href="admin.php?plugin_page=nexa-shop&tab=settings">Settings</a> are used. Weight-based methods charge <em>base fee + rate &times; total cart weight</em> — set each product\'s weight in the Products tab.</div>';
    echo '<div class="panel panel-default"><div class="panel-heading"><strong>Units &amp; defaults</strong></div><div class="panel-body">'
        . '<form method="post" class="form-inline">' . nexa_csrf_field() . '<input type="hidden" name="do" value="ship_settings">'
        . '<label>Weight unit <select name="weight_unit" class="form-control"><option value="kg"' . ($unit === 'kg' ? ' selected' : '') . '>kg</option><option value="lb"' . ($unit === 'lb' ? ' selected' : '') . '>lb</option></select></label> '
        . '<label>Default product weight <input name="default_weight" type="number" step="0.01" min="0" class="form-control" style="width:110px;" value="' . esc_attr($S('default_weight', '0')) . '"></label> '
        . '<button class="btn btn-primary">Save</button> <span class="text-muted">(used when a product has no weight set)</span></form></div></div>';
    echo '<div class="panel panel-default"><div class="panel-heading"><strong>' . ($editZone ? 'Edit zone' : 'Add a shipping zone') . '</strong></div><div class="panel-body">'
        . '<form method="post">' . nexa_csrf_field() . '<input type="hidden" name="do" value="zone_save"><input type="hidden" name="id" value="' . (int) ($editZone['id'] ?? 0) . '">'
        . '<div class="row"><div class="col-md-4"><div class="form-group"><label>Zone name</label><input name="name" class="form-control" required placeholder="e.g. Home — Jamaica" value="' . esc_attr($editZone['name'] ?? '') . '"></div></div>'
        . '<div class="col-md-8"><div class="form-group"><label>Countries (one per line, or comma-separated — leave empty for &ldquo;everywhere else&rdquo;)</label><textarea name="countries" class="form-control" rows="2" placeholder="Jamaica">' . esc_textarea($editZone['countries'] ?? '') . '</textarea></div></div></div>'
        . '<button class="btn btn-primary">' . ($editZone ? 'Save Zone' : 'Add Zone') . '</button>' . ($editZone ? ' <a class="btn btn-default" href="admin.php?plugin_page=nexa-shop&tab=shipping">Cancel</a>' : '') . '</form></div></div>';

    if ($editMethod || $methodZone > 0) {
        $em = $editMethod ?? ['zone_id' => $methodZone, 'title' => '', 'type' => 'flat', 'cost' => '', 'per_kg' => '', 'min_amount' => '', 'status' => 'active', 'sort' => '0'];
        echo '<div class="panel panel-primary"><div class="panel-heading"><strong>' . ($editMethod ? 'Edit shipping method' : 'Add a shipping method') . '</strong></div><div class="panel-body">'
            . '<form method="post">' . nexa_csrf_field() . '<input type="hidden" name="do" value="method_save"><input type="hidden" name="id" value="' . (int) ($editMethod['id'] ?? 0) . '">'
            . '<div class="row"><div class="col-md-3"><div class="form-group"><label>Zone</label><select name="zone_id" class="form-control">';
        foreach ($zones as $z) echo '<option value="' . (int) $z['id'] . '"' . ((int) $em['zone_id'] === (int) $z['id'] ? ' selected' : '') . '>' . esc_html($z['name']) . '</option>';
        echo '</select></div></div>'
            . '<div class="col-md-3"><div class="form-group"><label>Customer sees (title)</label><input name="title" class="form-control" required placeholder="e.g. Courier" value="' . esc_attr($em['title']) . '"></div></div>'
            . '<div class="col-md-3"><div class="form-group"><label>Type</label><select name="type" class="form-control"><option value="flat"' . ($em['type'] === 'flat' ? ' selected' : '') . '>Flat rate</option><option value="weight"' . ($em['type'] === 'weight' ? ' selected' : '') . '>By weight (base + per ' . $unit . ')</option><option value="free"' . ($em['type'] === 'free' ? ' selected' : '') . '>Free shipping (min spend)</option><option value="pickup"' . ($em['type'] === 'pickup' ? ' selected' : '') . '>Local pickup</option></select></div></div>'
            . '<div class="col-md-3"><div class="form-group"><label>Status</label><select name="status" class="form-control"><option value="active"' . ($em['status'] === 'active' ? ' selected' : '') . '>Active</option><option value="inactive"' . ($em['status'] === 'inactive' ? ' selected' : '') . '>Inactive</option></select></div></div></div>'
            . '<div class="row"><div class="col-md-3"><div class="form-group"><label>Flat cost / base fee</label><input name="cost" type="number" step="0.01" min="0" class="form-control" value="' . esc_attr($em['cost']) . '"></div></div>'
            . '<div class="col-md-3"><div class="form-group"><label>Plus per ' . $unit . ' (weight type)</label><input name="per_kg" type="number" step="0.01" min="0" class="form-control" value="' . esc_attr($em['per_kg']) . '"></div></div>'
            . '<div class="col-md-3"><div class="form-group"><label>Free over (free type)</label><input name="min_amount" type="number" step="0.01" min="0" class="form-control" value="' . esc_attr($em['min_amount']) . '"></div></div>'
            . '<div class="col-md-3"><div class="form-group"><label>Sort order</label><input name="sort" type="number" class="form-control" value="' . esc_attr($em['sort']) . '"></div></div></div>'
            . '<button class="btn btn-primary">Save Method</button> <a class="btn btn-default" href="admin.php?plugin_page=nexa-shop&tab=shipping">Cancel</a></form></div></div>';
    }

    if (!$zones) {
        echo '<div class="alert alert-warning">No shipping zones yet — checkout currently uses the fallback rates from Settings. Add your first zone above (e.g. your home country with a weight-based courier rate, and an &ldquo;Everywhere else&rdquo; zone with a higher flat rate).</div>';
        return;
    }
    foreach ($zones as $z) {
        $list = nexa_shop_zone_countries($z);
        echo '<div class="panel panel-default"><div class="panel-heading"><strong>' . esc_html($z['name']) . '</strong> <span class="text-muted">' . ($list ? esc_html(implode(', ', $list)) : 'Everywhere else') . '</span>'
            . '<span class="pull-right"><a class="btn btn-xs btn-primary" href="admin.php?plugin_page=nexa-shop&tab=shipping&mzone=' . (int) $z['id'] . '">Add method</a> '
            . '<a class="btn btn-xs btn-default" href="admin.php?plugin_page=nexa-shop&tab=shipping&editzone=' . (int) $z['id'] . '">Edit zone</a> '
            . '<form method="post" style="display:inline;" onsubmit="return confirm(\'Delete this zone and its methods?\');">' . nexa_csrf_field() . '<input type="hidden" name="id" value="' . (int) $z['id'] . '"><button name="do" value="zone_del" class="btn btn-xs btn-danger">Delete zone</button></form></span></div>'
            . '<table class="table" style="margin:0;"><thead><tr><th>Method</th><th>Type</th><th>Price</th><th>Status</th><th></th></tr></thead><tbody>';
        $methods = nexa_shop_zone_methods((int) $z['id'], false);
        if (!$methods) echo '<tr><td colspan="5" class="text-muted">No methods yet — customers in this zone cannot check out until you add one.</td></tr>';
        foreach ($methods as $m) {
            $price = match ((string) $m['type']) {
                'weight' => nexa_shop_money((float) $m['cost']) . ' + ' . nexa_shop_money((float) $m['per_kg']) . ' / ' . $unit,
                'free' => 'Free over ' . nexa_shop_money((float) $m['min_amount']),
                'pickup' => 'Free',
                default => nexa_shop_money((float) $m['cost']),
            };
            echo '<tr><td>' . esc_html($m['title']) . '</td><td>' . esc_html($m['type']) . '</td><td>' . $price . '</td><td>' . esc_html($m['status']) . '</td>'
                . '<td><a class="btn btn-xs btn-primary" href="admin.php?plugin_page=nexa-shop&tab=shipping&editm=' . (int) $m['id'] . '">Edit</a> '
                . '<form method="post" style="display:inline;" onsubmit="return confirm(\'Delete method?\');">' . nexa_csrf_field() . '<input type="hidden" name="id" value="' . (int) $m['id'] . '"><button name="do" value="method_del" class="btn btn-xs btn-danger">Delete</button></form></td></tr>';
        }
        echo '</tbody></table></div>';
    }
}

function nexa_shop_tab_settings(callable $S): void {
    $chk = static fn(string $k, string $d = '0'): string => $S($k, $d) === '1' ? ' checked' : '';
    $curOpts = '';
    foreach (nexa_shop_currencies() as $code => $sym) $curOpts .= '<option value="' . $code . '"' . ($S('currency', 'USD') === $code ? ' selected' : '') . '>' . $code . ' (' . $sym . ')</option>';
    echo '<form method="post">' . nexa_csrf_field() . '<input type="hidden" name="do" value="settings_save">'
        . '<div class="panel panel-default"><div class="panel-heading"><strong>Store</strong></div><div class="panel-body">'
        . '<div class="row"><div class="col-md-4"><div class="form-group"><label>Store name</label><input name="store_name" class="form-control" value="' . esc_attr($S('store_name')) . '"></div></div>'
        . '<div class="col-md-4"><div class="form-group"><label>Store email</label><input name="store_email" class="form-control" value="' . esc_attr($S('store_email')) . '"></div></div>'
        . '<div class="col-md-4"><div class="form-group"><label>Currency</label><select name="currency" class="form-control">' . $curOpts . '</select></div></div></div>'
        . '<div class="row"><div class="col-md-3"><div class="form-group"><label>Country</label><input name="store_country" class="form-control" value="' . esc_attr($S('store_country')) . '"></div></div>'
        . '<div class="col-md-3"><div class="form-group"><label>City</label><input name="store_city" class="form-control" value="' . esc_attr($S('store_city')) . '"></div></div>'
        . '<div class="col-md-3"><div class="form-group"><label>Address</label><input name="store_address" class="form-control" value="' . esc_attr($S('store_address')) . '"></div></div>'
        . '<div class="col-md-3"><div class="form-group"><label>Postal / phone</label><input name="store_zip" class="form-control" value="' . esc_attr($S('store_zip')) . '"><input name="store_phone" class="form-control" style="margin-top:4px;" value="' . esc_attr($S('store_phone')) . '"></div></div></div></div></div>'
        . '<div class="panel panel-default"><div class="panel-heading"><strong>Shipping &amp; tax</strong></div><div class="panel-body">'
        . '<label style="font-weight:normal;"><input type="checkbox" name="shipping_enabled" value="1"' . $chk('shipping_enabled', '1') . '> Shipping enabled</label> &nbsp; '
        . '<label style="font-weight:normal;"><input type="checkbox" name="ship_pickup" value="1"' . $chk('ship_pickup', '1') . '> Local pickup</label> &nbsp; '
        . '<label style="font-weight:normal;"><input type="checkbox" name="tax_enabled" value="1"' . $chk('tax_enabled') . '> Tax enabled</label>'
        . '<div class="row" style="margin-top:8px;"><div class="col-md-3"><div class="form-group"><label>Flat rate</label><input name="ship_flat" class="form-control" value="' . esc_attr($S('ship_flat', '5')) . '"></div></div>'
        . '<div class="col-md-3"><div class="form-group"><label>Free over</label><input name="ship_free_over" class="form-control" value="' . esc_attr($S('ship_free_over', '0')) . '"></div></div>'
        . '<div class="col-md-3"><div class="form-group"><label>Tax label</label><input name="tax_label" class="form-control" value="' . esc_attr($S('tax_label', 'Tax')) . '"></div></div>'
        . '<div class="col-md-3"><div class="form-group"><label>Tax rate %</label><input name="tax_rate" class="form-control" value="' . esc_attr($S('tax_rate', '0')) . '"></div></div></div></div></div>'
        . '<div class="panel panel-default"><div class="panel-heading"><strong>Payments</strong></div><div class="panel-body">'
        . '<label style="font-weight:normal;display:block;"><input type="checkbox" name="pay_cod" value="1"' . $chk('pay_cod', '1') . '> Cash on delivery</label>'
        . '<label style="font-weight:normal;display:block;"><input type="checkbox" name="pay_bank" value="1"' . $chk('pay_bank') . '> Bank transfer</label>'
        . '<div class="form-group"><label>Bank instructions</label><textarea name="bank_instructions" class="form-control" rows="2">' . esc_textarea($S('bank_instructions')) . '</textarea></div>'
        . '<label style="font-weight:normal;display:block;"><input type="checkbox" name="pay_paypal" value="1"' . $chk('pay_paypal') . '> PayPal</label>'
        . '<div class="row"><div class="col-md-6"><div class="form-group"><label>PayPal email</label><input name="paypal_email" class="form-control" value="' . esc_attr($S('paypal_email')) . '"></div></div>'
        . '<div class="col-md-6"><div class="form-group"><label>Mode</label><select name="paypal_mode" class="form-control"><option value="live"' . ($S('paypal_mode', 'live') === 'live' ? ' selected' : '') . '>Live</option><option value="sandbox"' . ($S('paypal_mode') === 'sandbox' ? ' selected' : '') . '>Sandbox</option></select></div></div></div>'
        . '<label style="font-weight:normal;display:block;"><input type="checkbox" name="pay_stripe" value="1"' . $chk('pay_stripe') . '> Stripe (card via Stripe Checkout)</label>'
        . '<div class="row"><div class="col-md-6"><div class="form-group"><label>Publishable key</label><input name="stripe_pk" class="form-control" value="' . esc_attr($S('stripe_pk')) . '"></div></div>'
        . '<div class="col-md-6"><div class="form-group"><label>Secret key</label><input name="stripe_secret" type="password" class="form-control" placeholder="Leave blank to keep existing"></div></div></div>'
        . '<div class="form-group"><label>Low-stock warning at</label><input name="low_stock" type="number" min="0" class="form-control" style="width:120px;" value="' . esc_attr($S('low_stock', '5')) . '"></div>'
        . '</div></div><button class="btn btn-primary">Save Settings</button></form>'
        . '<div class="panel panel-default" style="margin-top:16px;"><div class="panel-heading"><strong>Store pages</strong></div><div class="panel-body"><ul>';
    foreach (nexa_shop_page_defs() as $k => $def) echo '<li>' . esc_html($def['title']) . ': <a href="' . esc_attr(nexa_shop_page_url($k)) . '" target="_blank">' . esc_html(nexa_shop_page_url($k)) . '</a></li>';
    echo '</ul><form method="post" class="form-inline">' . nexa_csrf_field() . '<label style="font-weight:normal;"><input type="checkbox" name="add_menu" value="1" checked> Add to main menu</label> <button name="do" value="create_pages" class="btn btn-default">Create / repair store pages</button></form>'
        . '<p style="margin-top:10px;"><a class="btn btn-default" href="admin.php?plugin_page=nexa-shop&wizard=1&step=1">Run the setup wizard again</a></p></div></div>';
}
