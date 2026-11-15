<?php
/**
 * Nexa Shop 2.0 — WooCommerce-style commerce for NexaCMS.
 * Catalog, categories, coupons, cart, checkout (COD / bank transfer /
 * PayPal / Stripe), orders with line items, stock control, customer
 * account area and store pages — created by the setup wizard.
 * shop_orders keeps its legacy "items" text so Nexa Downloads purchase
 * verification keeps working unchanged.
 */

function nexa_shop_ensure(): void {
    if (!table_exists('shop_products')) create_table('shop_products', ['name','sku','price','old_price','stock','image','description','status','created_at','category_id','product_type','download_file','featured','weight']);
    else ensure_fields('shop_products', ['category_id','product_type','download_file','featured','weight']);
    if (!table_exists('shop_orders')) create_table('shop_orders', ['customer_name','email','phone','address','items','total','status','created_at','user_id','subtotal','discount','shipping_cost','tax','coupon','payment_method','payment_link','notes','country','shipping_method']);
    else ensure_fields('shop_orders', ['user_id','subtotal','discount','shipping_cost','tax','coupon','payment_method','payment_link','notes','country','shipping_method']);
    if (!table_exists('shop_categories')) create_table('shop_categories', ['name','slug','description','created_at']);
    if (!table_exists('shop_coupons')) create_table('shop_coupons', ['code','type','value','min_spend','expires','usage_limit','used','status','created_at']);
    if (!table_exists('shop_order_items')) create_table('shop_order_items', ['order_id','product_id','name','price','qty','line_total']);
    if (!table_exists('shop_ship_zones')) create_table('shop_ship_zones', ['name','countries','created_at']);
    if (!table_exists('shop_ship_methods')) create_table('shop_ship_methods', ['zone_id','title','type','cost','per_kg','min_amount','status','sort','created_at']);
}
nexa_shop_ensure();

function nexa_shop_seed(): void {
    if (count(query("SELECT id FROM shop_products")) === 0) foreach ([
        ['Nexa Starter Theme Pack','NEXA-THEME-01','49.00','79.00','25','Premium multi-purpose NexaCMS theme bundle with responsive layouts.'],
        ['SEO Booster Add-on','NEXA-SEO-02','29.00','','100','Advanced meta, schema and sitemap tools for NexaCMS sites.'],
        ['Pro Gallery License','NEXA-GAL-03','19.00','39.00','50','Commercial license for the Nexa lightbox gallery on unlimited sites.'],
        ['1-Year Priority Support','NEXA-SUP-04','99.00','','999','Priority email support and upgrade assistance for one year.'],
    ] as $p) query("INSERT INTO shop_products (name,sku,price,old_price,stock,image,description,status,created_at,product_type) VALUES (?,?,?,?,?,?,?,?,?,?)", [$p[0],$p[1],$p[2],$p[3],$p[4],'',$p[5],'active',date('Y-m-d H:i:s'),'physical']);
}
nexa_shop_seed();

/* ---------------- settings / money ---------------- */
function nexa_shop_setting(string $k, string $d = ''): string { return (string) get_setting('shop_' . $k, $d); }
function nexa_shop_currencies(): array { return ['USD' => '$', 'EUR' => '€', 'GBP' => '£', 'JMD' => '$', 'CAD' => '$', 'AUD' => '$', 'INR' => '₹', 'JPY' => '¥']; }
function nexa_shop_money(float $n): string {
    $cur = nexa_shop_currencies();
    $sym = $cur[nexa_shop_setting('currency', 'USD')] ?? '$';
    return $sym . number_format($n, 2);
}
function nexa_shop_statuses(): array { return ['pending','processing','on-hold','paid','shipped','completed','cancelled','refunded','failed']; }

/* ---------------- pages ---------------- */
function nexa_shop_page_defs(): array {
    return [
        'shop'     => ['title' => 'Shop', 'slug' => 'shop', 'content' => '[shop_catalog]'],
        'cart'     => ['title' => 'Cart', 'slug' => 'cart', 'content' => '[shop_cart]'],
        'checkout' => ['title' => 'Checkout', 'slug' => 'checkout', 'content' => '[shop_checkout]'],
        'account'  => ['title' => 'My Account', 'slug' => 'my-account', 'content' => '[shop_account]'],
    ];
}
function nexa_shop_find_page(string $slug): ?array {
    foreach (get_table('posts')['data'] as $p) {
        if (($p['post_type'] ?? '') === 'page' && ($p['post_name'] ?? '') === $slug && ($p['post_status'] ?? '') !== 'trash') return $p;
    }
    return null;
}
/** Create (or repair) the store pages; optionally add them to the primary menu. Returns key => page id. */
function nexa_shop_create_pages(bool $addToMenu = false): array {
    $out = [];
    foreach (nexa_shop_page_defs() as $key => $def) {
        $page = nexa_shop_find_page($def['slug']);
        if ($page) {
            $id = (int) $page['id'];
            if (trim((string) ($page['post_content'] ?? '')) === '') {
                update_table('posts', ['id' => $id, 'post_content' => $def['content']], 'update');
            }
        } else {
            $id = (int) update_table('posts', [
                'post_author' => (string) ($_SESSION['name'] ?? 'admin'), 'post_date' => date('Y-m-d H:i:s'), 'post_date_gmt' => (string) time(),
                'post_title' => $def['title'], 'post_name' => $def['slug'], 'post_content' => $def['content'],
                'post_status' => 'published', 'post_type' => 'page', 'comment_status' => 'closed', 'post_excerpt' => '', 'post_thumb' => '',
            ], 'insert');
        }
        set_setting('shop_page_' . $key, (string) $id, 'Shop ' . $def['title'] . ' Page');
        $out[$key] = $id;
        if ($addToMenu && $id > 0 && table_exists('menu_items') && $key !== 'checkout') {
            $exists = false;
            foreach (get_table('menu_items')['data'] as $mi) {
                if (($mi['menu'] ?? '1') === '1' && str_contains((string) ($mi['link'] ?? ''), 'p=' . $id)) $exists = true;
            }
            if (!$exists) {
                update_table('menu_items', ['label' => $def['title'], 'link' => '?p=' . $id, 'parent' => '0', 'menu' => '1', 'order' => (string) (90 + count($out)), 'title' => $def['title']], 'insert');
            }
        }
    }
    return $out;
}
function nexa_shop_page_url(string $key): string {
    $id = (int) nexa_shop_setting('page_' . $key, '0');
    if ($id > 0) return post_url($id);
    $defs = nexa_shop_page_defs();
    return home_url(($defs[$key]['slug'] ?? $key) . '/');
}

/* ---------------- catalog helpers ---------------- */
function nexa_shop_product(int $id): ?array {
    $r = query("SELECT * FROM shop_products WHERE id=?", [$id]);
    return $r[0] ?? null;
}
function nexa_shop_categories(): array { return query("SELECT * FROM shop_categories ORDER BY name ASC"); }
function nexa_shop_category_name(int $id): string {
    foreach (nexa_shop_categories() as $c) if ((int) $c['id'] === $id) return (string) $c['name'];
    return '';
}
function nexa_shop_in_stock(array $p): bool {
    if (($p['product_type'] ?? 'physical') === 'digital') return true;
    return (int) ($p['stock'] ?? 0) > 0;
}

/* ---------------- cart / totals ---------------- */
function nexa_shop_cart(): array { if (session_status() === PHP_SESSION_NONE && !headers_sent()) @session_start(); return $_SESSION['nexa_cart'] ?? []; }
function nexa_shop_cart_items(): array {
    $items = [];
    foreach (nexa_shop_cart() as $pid => $qty) {
        $p = nexa_shop_product((int) $pid);
        if (!$p || ($p['status'] ?? '') !== 'active') continue;
        $items[] = ['product' => $p, 'qty' => max(1, (int) $qty), 'line' => (float) $p['price'] * (int) $qty];
    }
    return $items;
}
function nexa_shop_coupon(?string $code, float $subtotal): array {
    $code = strtoupper(trim((string) $code));
    if ($code === '') return ['ok' => false, 'error' => ''];
    $r = query("SELECT * FROM shop_coupons WHERE code=? AND status='active'", [$code]);
    if (empty($r)) return ['ok' => false, 'error' => 'That coupon code is not valid.'];
    $c = $r[0];
    if (!empty($c['expires']) && strtotime((string) $c['expires']) < strtotime(date('Y-m-d'))) return ['ok' => false, 'error' => 'That coupon has expired.'];
    if ((int) ($c['usage_limit'] ?? 0) > 0 && (int) ($c['used'] ?? 0) >= (int) $c['usage_limit']) return ['ok' => false, 'error' => 'That coupon has reached its usage limit.'];
    if ((float) ($c['min_spend'] ?? 0) > $subtotal) return ['ok' => false, 'error' => 'That coupon needs a minimum spend of ' . nexa_shop_money((float) $c['min_spend']) . '.'];
    $discount = ($c['type'] ?? 'percent') === 'percent' ? $subtotal * ((float) $c['value'] / 100) : min((float) $c['value'], $subtotal);
    return ['ok' => true, 'row' => $c, 'discount' => round($discount, 2)];
}
/* ---------------- shipping zones & weight ---------------- */
function nexa_shop_weight_unit(): string { return nexa_shop_setting('weight_unit', 'kg') === 'lb' ? 'lb' : 'kg'; }
function nexa_shop_cart_weight(array $items): float {
    $w = 0.0; $def = (float) nexa_shop_setting('default_weight', '0');
    foreach ($items as $it) {
        $p = $it['product'];
        if (($p['product_type'] ?? 'physical') === 'digital') continue;
        $pw = isset($p['weight']) && $p['weight'] !== '' && $p['weight'] !== null ? (float) $p['weight'] : $def;
        $w += $pw * (int) $it['qty'];
    }
    return round($w, 3);
}
function nexa_shop_zones(): array { return query("SELECT * FROM shop_ship_zones ORDER BY id ASC"); }
function nexa_shop_zone_countries(array $z): array {
    return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string) ($z['countries'] ?? '')) ?: [])));
}
function nexa_shop_zone_methods(int $zoneId, bool $activeOnly = true): array {
    $rows = query("SELECT * FROM shop_ship_methods WHERE zone_id=? ORDER BY sort ASC, id ASC", [$zoneId]);
    if ($activeOnly) $rows = array_values(array_filter($rows, static fn($m) => ($m['status'] ?? 'active') === 'active'));
    return $rows;
}
function nexa_shop_zone_for_country(string $country): ?array {
    $country = trim($country); $fallback = null;
    foreach (nexa_shop_zones() as $z) {
        $list = nexa_shop_zone_countries($z);
        if (!$list) { $fallback ??= $z; continue; }
        foreach ($list as $c) if ($c === '*' || strcasecmp($c, $country) === 0) return $z;
    }
    return $fallback;
}
function nexa_shop_method_cost(array $m, float $afterDiscount, float $weight): ?float {
    return match ((string) ($m['type'] ?? 'flat')) {
        'pickup' => 0.0,
        'free' => $afterDiscount >= (float) ($m['min_amount'] ?? 0) ? 0.0 : null,
        'weight' => round((float) ($m['cost'] ?? 0) + (float) ($m['per_kg'] ?? 0) * $weight, 2),
        default => round((float) ($m['cost'] ?? 0), 2),
    };
}
/**
 * Shipping choices for a cart + destination country.
 * Zone mode when zones exist (first matching zone wins; a zone with an
 * empty country list is the "everywhere else" fallback; no match at all
 * means no shipping offered). Otherwise the legacy Settings rates apply.
 */
function nexa_shop_shipping_options(array $items, string $country = ''): array {
    $hasPhysical = false;
    foreach ($items as $it) if (($it['product']['product_type'] ?? 'physical') !== 'digital') $hasPhysical = true;
    if (!$hasPhysical || !$items || nexa_shop_setting('shipping_enabled', '1') !== '1') return [];
    $subtotal = array_sum(array_column($items, 'line'));
    $cp = nexa_shop_coupon((string) ($_SESSION['nexa_coupon'] ?? ''), $subtotal);
    $after = $subtotal - ($cp['ok'] ? (float) $cp['discount'] : 0.0);
    $weight = nexa_shop_cart_weight($items);
    if (nexa_shop_zones()) {
        $zone = nexa_shop_zone_for_country($country !== '' ? $country : nexa_shop_setting('store_country'));
        if (!$zone) return [];
        $out = [];
        foreach (nexa_shop_zone_methods((int) $zone['id']) as $m) {
            $cost = nexa_shop_method_cost($m, $after, $weight);
            if ($cost === null) continue;
            $out[] = ['key' => 'z:' . (int) $m['id'], 'title' => (string) $m['title'], 'type' => (string) $m['type'], 'cost' => $cost, 'zone' => (string) $zone['name']];
        }
        return $out;
    }
    $flat = (float) nexa_shop_setting('ship_flat', '5');
    $freeOver = (float) nexa_shop_setting('ship_free_over', '0');
    $isFree = $freeOver > 0 && $after >= $freeOver;
    $out = [['key' => 'flat', 'title' => $isFree ? 'Standard shipping (free over ' . nexa_shop_money($freeOver) . ')' : 'Standard shipping', 'type' => 'flat', 'cost' => $isFree ? 0.0 : $flat, 'zone' => '']];
    if (nexa_shop_setting('ship_pickup', '1') === '1') $out[] = ['key' => 'pickup', 'title' => 'Local pickup', 'type' => 'pickup', 'cost' => 0.0, 'zone' => ''];
    return $out;
}
function nexa_shop_country_options(string $selected = ''): array {
    $out = [];
    $push = static function (string $c) use (&$out): void { $c = trim($c); if ($c !== '' && !in_array($c, $out, true)) $out[] = $c; };
    $push(nexa_shop_setting('store_country'));
    foreach (nexa_shop_zones() as $z) foreach (nexa_shop_zone_countries($z) as $c) if ($c !== '*') $push($c);
    if ($selected !== '') $push($selected);
    foreach (['Afghanistan','Argentina','Australia','Bahamas','Bangladesh','Barbados','Belgium','Belize','Brazil','Canada','Chile','China','Colombia','Costa Rica','Cuba','Denmark','Dominican Republic','Egypt','Ethiopia','France','Germany','Ghana','Greece','Grenada','Guatemala','Guyana','Haiti','Honduras','Hong Kong','India','Indonesia','Ireland','Israel','Italy','Jamaica','Japan','Kenya','Malaysia','Mexico','Netherlands','New Zealand','Nigeria','Norway','Pakistan','Panama','Peru','Philippines','Poland','Portugal','Puerto Rico','Romania','Russia','Saudi Arabia','Singapore','South Africa','South Korea','Spain','Sri Lanka','Sweden','Switzerland','Taiwan','Thailand','Trinidad and Tobago','Turkey','Ukraine','United Arab Emirates','United Kingdom','United States','Venezuela','Vietnam'] as $c) $push($c);
    return $out;
}

function nexa_shop_totals(array $items, string $shipMethod = 'flat', ?string $couponCode = null, string $country = ''): array {
    $subtotal = 0.0; $hasPhysical = false;
    foreach ($items as $it) { $subtotal += $it['line']; if (($it['product']['product_type'] ?? 'physical') !== 'digital') $hasPhysical = true; }
    $cp = nexa_shop_coupon($couponCode ?? (string) ($_SESSION['nexa_coupon'] ?? ''), $subtotal);
    $discount = $cp['ok'] ? (float) $cp['discount'] : 0.0;
    $weight = nexa_shop_cart_weight($items);
    $options = nexa_shop_shipping_options($items, $country);
    $chosen = null;
    foreach ($options as $o) if ($o['key'] === $shipMethod) $chosen = $o;
    $chosen ??= $options[0] ?? null;
    $shipping = $chosen ? (float) $chosen['cost'] : 0.0;
    $tax = 0.0;
    if (nexa_shop_setting('tax_enabled', '0') === '1') $tax = round(($subtotal - $discount) * ((float) nexa_shop_setting('tax_rate', '0') / 100), 2);
    return ['subtotal' => round($subtotal, 2), 'discount' => $discount, 'shipping' => round($shipping, 2),
            'tax' => $tax, 'total' => round($subtotal - $discount + $shipping + $tax, 2),
            'has_physical' => $hasPhysical, 'coupon' => $cp,
            'weight' => $weight, 'ship_options' => $options,
            'ship_key' => $chosen['key'] ?? '', 'ship_title' => $chosen['title'] ?? ''];
}

/* ---------------- orders ---------------- */
function nexa_shop_place_order(array $d): array {
    $items = nexa_shop_cart_items();
    if (!$items) return ['error' => 'Your cart is empty.'];
    foreach ($items as $it) {
        $p = $it['product'];
        if (($p['product_type'] ?? 'physical') !== 'digital' && (int) ($p['stock'] ?? 0) < $it['qty']) {
            return ['error' => 'Sorry, "' . $p['name'] . '" does not have enough stock left.'];
        }
    }
    $totals = nexa_shop_totals($items, (string) ($d['ship_method'] ?? 'flat'), (string) ($d['coupon'] ?? ($_SESSION['nexa_coupon'] ?? '')), (string) ($d['country'] ?? ''));
    if ($totals['has_physical'] && $items && nexa_shop_setting('shipping_enabled', '1') === '1' && empty($totals['ship_options'])) {
        return ['error' => 'Sorry, we do not ship to that country yet. Please contact us to arrange delivery.'];
    }
    $legacy = implode('; ', array_map(static fn($it) => $it['product']['name'] . ' x' . $it['qty'], $items));
    $userId = is_user_logged_in() ? (int) ($_SESSION['user_id'] ?? 0) : 0;
    $orderId = (int) query("INSERT INTO shop_orders (customer_name,email,phone,address,items,total,status,created_at,user_id,subtotal,discount,shipping_cost,tax,coupon,payment_method,notes,country,shipping_method) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)", [
        trim((string) $d['name']), trim((string) $d['email']), trim((string) ($d['phone'] ?? '')), trim((string) ($d['address'] ?? '')),
        $legacy, number_format($totals['total'], 2, '.', ''), 'pending', date('Y-m-d H:i:s'), (string) $userId,
        number_format($totals['subtotal'], 2, '.', ''), number_format($totals['discount'], 2, '.', ''), number_format($totals['shipping'], 2, '.', ''),
        number_format($totals['tax'], 2, '.', ''), $totals['coupon']['ok'] ? (string) $totals['coupon']['row']['code'] : '',
        (string) ($d['payment'] ?? 'cod'), trim((string) ($d['notes'] ?? '')),
        trim((string) ($d['country'] ?? '')), (string) ($totals['ship_title'] ?? ''),
    ]);
    // INSERT via query() may return bool on some drivers; fetch the id.
    if ($orderId <= 0) { $r = query("SELECT id FROM shop_orders ORDER BY id DESC LIMIT 1"); $orderId = (int) ($r[0]['id'] ?? 0); }
    foreach ($items as $it) {
        query("INSERT INTO shop_order_items (order_id,product_id,name,price,qty,line_total) VALUES (?,?,?,?,?,?)", [
            $orderId, (int) $it['product']['id'], $it['product']['name'], number_format((float) $it['product']['price'], 2, '.', ''), $it['qty'], number_format($it['line'], 2, '.', '')]);
        if (($it['product']['product_type'] ?? 'physical') !== 'digital') {
            query("UPDATE shop_products SET stock=? WHERE id=?", [max(0, (int) $it['product']['stock'] - $it['qty']), (int) $it['product']['id']]);
        }
    }
    if ($totals['coupon']['ok']) query("UPDATE shop_coupons SET used=used+1 WHERE id=?", [(int) $totals['coupon']['row']['id']]);
    $_SESSION['nexa_cart'] = []; unset($_SESSION['nexa_coupon']);
    $_SESSION['nexa_shop_last_order'] = $orderId;
    nexa_shop_send_order_emails($orderId, $items, $totals, $d);
    $payLink = nexa_shop_payment_link($orderId, $totals['total'], (string) ($d['payment'] ?? 'cod'), (string) $d['email']);
    if ($payLink !== '') query("UPDATE shop_orders SET payment_link=? WHERE id=?", [$payLink, $orderId]);
    return ['ok' => true, 'order_id' => $orderId, 'payment_link' => $payLink, 'totals' => $totals];
}
function nexa_shop_payment_link(int $orderId, float $total, string $method, string $email): string {
    if ($method === 'paypal' && nexa_shop_setting('pay_paypal', '0') === '1' && nexa_shop_setting('paypal_email') !== '') {
        $sandbox = nexa_shop_setting('paypal_mode', 'live') === 'sandbox' ? 'sandbox.' : '';
        return 'https://www.' . $sandbox . 'paypal.com/cgi-bin/webscr?' . http_build_query([
            'cmd' => '_xclick', 'business' => nexa_shop_setting('paypal_email'), 'item_name' => 'Order #' . $orderId . ' — ' . nexa_shop_setting('store_name', 'Our Store'),
            'amount' => number_format($total, 2, '.', ''), 'currency_code' => nexa_shop_setting('currency', 'USD'), 'email' => $email,
            'return' => nexa_shop_page_url('checkout'), 'cancel_return' => nexa_shop_page_url('checkout')]);
    }
    if ($method === 'stripe' && nexa_shop_setting('pay_stripe', '0') === '1' && nexa_shop_setting('stripe_secret') !== '' && function_exists('curl_init')) {
        try {
            $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 15,
                CURLOPT_USERPWD => nexa_shop_setting('stripe_secret') . ':',
                CURLOPT_POSTFIELDS => http_build_query([
                    'mode' => 'payment', 'success_url' => nexa_shop_page_url('checkout'), 'cancel_url' => nexa_shop_page_url('checkout'),
                    'customer_email' => $email, 'line_items[0][quantity]' => 1,
                    'line_items[0][price_data][currency]' => strtolower(nexa_shop_setting('currency', 'USD')),
                    'line_items[0][price_data][unit_amount]' => (int) round($total * 100),
                    'line_items[0][price_data][product_data][name]' => 'Order #' . $orderId,
                    'metadata[order_id]' => (string) $orderId])]);
            $resp = json_decode((string) curl_exec($ch), true); curl_close($ch);
            if (!empty($resp['url'])) return (string) $resp['url'];
        } catch (Throwable $e) { /* fall through: store emails the payment link manually */ }
    }
    return '';
}
function nexa_shop_send_order_emails(int $orderId, array $items, array $totals, array $d): void {
    if (!function_exists('nexa_mail')) return;
    $lines = [];
    foreach ($items as $it) $lines[] = $it['product']['name'] . ' x' . $it['qty'] . ' — ' . nexa_shop_money($it['line']);
    $body = "Thank you for your order at " . nexa_shop_setting('store_name', 'our store') . "!\n\nOrder #$orderId\n" . implode("\n", $lines)
        . "\n\nSubtotal: " . nexa_shop_money($totals['subtotal'])
        . ($totals['discount'] > 0 ? "\nDiscount: -" . nexa_shop_money($totals['discount']) : '')
        . "\nShipping: " . nexa_shop_money($totals['shipping'])
        . ($totals['tax'] > 0 ? "\n" . nexa_shop_setting('tax_label', 'Tax') . ': ' . nexa_shop_money($totals['tax']) : '')
        . "\nTotal: " . nexa_shop_money($totals['total'])
        . "\n\nWe will email you when your order status changes.";
    @nexa_mail((string) $d['email'], 'Your order #' . $orderId . ' is confirmed', $body);
    $adminEmail = nexa_shop_setting('store_email');
    if ($adminEmail !== '') @nexa_mail($adminEmail, 'New order #' . $orderId . ' — ' . nexa_shop_money($totals['total']), $body . "\n\nCustomer: " . $d['name'] . ' <' . $d['email'] . '>');
}
function nexa_shop_order_items(int $orderId): array { return query("SELECT * FROM shop_order_items WHERE order_id=? ORDER BY id ASC", [$orderId]); }

/* ---------------- POST handler (pre stage) ---------------- */
function nexa_shop_notice(string $msg): void { $_SESSION['shop_notice'] = $msg; }
function nexa_shop_take_notice(): string { $n = (string) ($_SESSION['shop_notice'] ?? ''); unset($_SESSION['shop_notice']); return $n; }
function nexa_shop_back(array $post): void {
    $to = (string) ($post['redirect'] ?? '');
    if ($to === '' || !str_starts_with($to, home_url())) $to = nexa_shop_page_url('shop');
    header('Location: ' . $to); exit;
}
function nexa_shop_handle(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !empty($GLOBALS['adminarea'])) return;
    if (!isset($_POST['nexa_add_cart']) && !isset($_POST['nexa_update_cart']) && !isset($_POST['nexa_apply_coupon']) && !isset($_POST['nexa_place_order']) && !isset($_POST['nexa_remove_item']) && !isset($_POST['nexa_clear_cart'])) return;
    if (!nexa_verify_csrf($_POST['nexa_csrf_token'] ?? null)) return;
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) @session_start();
    nexa_shop_ensure();
    if (isset($_POST['nexa_add_cart'])) {
        $id = (int) $_POST['nexa_add_cart']; $qty = max(1, (int) ($_POST['qty'] ?? 1));
        $p = nexa_shop_product($id);
        if ($p && ($p['status'] ?? '') === 'active' && nexa_shop_in_stock($p)) {
            $cur = (int) (nexa_shop_cart()[$id] ?? 0);
            if (($p['product_type'] ?? 'physical') !== 'digital' && $cur + $qty > (int) $p['stock']) { $qty = max(0, (int) $p['stock'] - $cur); }
            if ($qty > 0) { $_SESSION['nexa_cart'][$id] = $cur + $qty; nexa_shop_notice('Added to cart: ' . $p['name']); }
            else nexa_shop_notice('No more stock available for ' . $p['name']);
        }
        if (!empty($_POST['buy_now'])) { header('Location: ' . nexa_shop_page_url('checkout')); exit; }
        nexa_shop_back($_POST);
    }
    if (isset($_POST['nexa_update_cart'])) {
        foreach ((array) ($_POST['qty'] ?? []) as $pid => $q) {
            $pid = (int) $pid; $q = (int) $q;
            if ($q <= 0) unset($_SESSION['nexa_cart'][$pid]); else $_SESSION['nexa_cart'][$pid] = $q;
        }
        nexa_shop_notice('Cart updated.');
        nexa_shop_back($_POST);
    }
    if (isset($_POST['nexa_remove_item'])) { unset($_SESSION['nexa_cart'][(int) $_POST['nexa_remove_item']]); nexa_shop_back($_POST); }
    if (isset($_POST['nexa_clear_cart'])) { $_SESSION['nexa_cart'] = []; unset($_SESSION['nexa_coupon']); nexa_shop_back($_POST); }
    if (isset($_POST['nexa_apply_coupon'])) {
        $_SESSION['nexa_coupon'] = strtoupper(trim((string) ($_POST['coupon'] ?? '')));
        $items = nexa_shop_cart_items();
        $sub = array_sum(array_column($items, 'line'));
        $cp = nexa_shop_coupon($_SESSION['nexa_coupon'], $sub);
        nexa_shop_notice($cp['ok'] ? 'Coupon applied: ' . $_SESSION['nexa_coupon'] : ($cp['error'] ?: 'Coupon not valid.'));
        if (!$cp['ok']) unset($_SESSION['nexa_coupon']);
        nexa_shop_back($_POST);
    }
    if (isset($_POST['nexa_place_order'])) {
        $res = nexa_shop_place_order($_POST);
        if (!empty($res['error'])) { nexa_shop_notice($res['error']); nexa_shop_back($_POST); }
        if (!empty($res['payment_link'])) { header('Location: ' . $res['payment_link']); exit; }
        header('Location: ' . nexa_shop_page_url('checkout') . '?placed=' . (int) $res['order_id']); exit;
    }
}

/* ---------------- storefront styles (hooked, once) ---------------- */
function nexa_shop_styles(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    echo <<<'CSS'
<style>
.nxshop-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:18px;margin:16px 0}
.nxshop-card{border:1px solid #e3e3e3;border-radius:10px;overflow:hidden;background:#fff;display:flex;flex-direction:column;position:relative}
.nxshop-thumb img{width:100%;height:170px;object-fit:cover;display:block}
.nxshop-noimg{height:170px;display:flex;align-items:center;justify-content:center;background:#f3f3f3;color:#999;font-size:22px}
.nxshop-noimg.big{height:320px;font-size:64px;border-radius:10px}
.nxshop-card-body{padding:12px;display:flex;flex-direction:column;gap:6px;flex:1}
.nxshop-card-body h3{margin:0;font-size:16px}
.nxshop-card-body h3 a{color:inherit;text-decoration:none}
.nxshop-price{font-weight:700;font-size:17px;margin:0}
.nxshop-price.big{font-size:24px}
.nxshop-price s{color:#999;font-weight:400}
.nxshop-badge{display:inline-block;background:#d9534f;color:#fff;border-radius:8px;padding:2px 8px;font-size:11px;position:absolute;top:8px;left:8px;z-index:2}
.nxshop-btn{display:inline-block;background:#2e7d32;color:#fff!important;padding:8px 14px;border-radius:6px;border:0;cursor:pointer;text-decoration:none;text-align:center;font-size:14px}
.nxshop-btn:hover{background:#256628}
.nxshop-btn.ghost{background:#666}
.nxshop-btn.alt{background:#1565c0}
.nxshop-btn.sm{padding:4px 9px;font-size:12px}
.nxshop-btn.big{padding:12px 20px;font-size:16px;width:100%}
.nxshop-btn[disabled]{background:#bbb;cursor:not-allowed}
.nxshop-filters,.nxshop-toolbar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:10px 0}
.nxshop-filters input[type=search],.nxshop-toolbar select,.nxshop-checkout label input,.nxshop-checkout select,.nxshop-checkout textarea{padding:7px;border:1px solid #ccc;border-radius:6px;width:100%;box-sizing:border-box;margin-top:3px}
.nxshop-checkout label{display:block;margin:8px 0}
.nxshop-cat{font-size:12px;color:#888;text-transform:uppercase;letter-spacing:.04em}
.nxshop-table{width:100%;border-collapse:collapse;margin:10px 0}
.nxshop-table th,.nxshop-table td{border-bottom:1px solid #e5e5e5;padding:8px;text-align:left;vertical-align:top}
.nxshop-table input[type=number]{padding:6px;border:1px solid #ccc;border-radius:6px}
.nxshop-totals{text-align:right;margin:12px 0;line-height:1.7}
.nxshop-totals .grand,.nxshop-summary .grand{font-size:18px;border-top:2px solid #333;padding-top:6px}
.nxshop-notice{background:#e8f5e9;border:1px solid #a5d6a7;border-radius:8px;padding:10px 14px;margin:12px 0}
.nxshop-paybox{background:#fff8e1;border:1px solid #ffe082;border-radius:8px;padding:12px 16px;margin:12px 0}
.nxshop-detail{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin:16px 0}
.nxshop-detail-img img{max-width:100%;border-radius:10px}
.nxshop-detail-info h2{margin-top:0}
.nxshop-meta,.nxshop-stock{color:#666;font-size:13px}
.nxshop-stock .out{color:#d9534f;font-weight:700}
.nxshop-checkout{display:grid;grid-template-columns:1.4fr 1fr;gap:24px;align-items:start}
.nxshop-summary{border:1px solid #e3e3e3;border-radius:10px;padding:14px;background:#fafafa}
.nxshop-summary h4{margin-top:0}
.nxshop-summary p{display:flex;justify-content:space-between;gap:10px;margin:6px 0}
.nxshop-pay{border:1px solid #ddd;border-radius:8px;margin:12px 0;padding:10px}
.nxshop-pay label.radio{display:block;padding:8px;border:1px solid #ddd;border-radius:8px;margin:6px 0;cursor:pointer}
.nxshop-account table{width:100%}
.nxshop-order-detail{border:1px solid #e3e3e3;border-radius:10px;padding:14px;margin:12px 0}
.nxshop-cartlink{margin-left:auto;text-decoration:none;font-weight:600}
.nxshop-coupon{display:flex;gap:8px;align-items:center;margin:10px 0}
.nxshop-coupon input{padding:7px;border:1px solid #ccc;border-radius:6px}
.nxshop-thanks{border:1px solid #a5d6a7;background:#f6fff6;border-radius:10px;padding:16px}
@media(max-width:760px){.nxshop-detail,.nxshop-checkout{grid-template-columns:1fr}}
</style>
CSS;
}
add_action('nexa_head', 'nexa_shop_styles');
add_action('nexa_body', 'nexa_shop_styles');

/* ---------------- storefront shortcodes ---------------- */
function nexa_shop_notice_html(): string {
    $n = nexa_shop_take_notice();
    return $n !== '' ? '<div class="nxshop-notice">' . esc_html($n) . '</div>' : '';
}
/**
 * Shop pages are session-dependent (cart, notices, account). The page
 * cache skips anything containing a CSRF field, so every shortcode
 * output carries one — even "empty cart" states — and is never cached.
 */
function nexa_shop_nocache(): string { return '<span style="display:none;">' . nexa_csrf_field() . '</span>'; }
function nexa_shop_card(array $p): string {
    $sale = (float) ($p['old_price'] ?? 0) > (float) $p['price'];
    $inStock = nexa_shop_in_stock($p);
    $detail = nexa_shop_page_url('shop') . '?shop_product=' . (int) $p['id'];
    $h = '<div class="nxshop-card">'
        . ($sale ? '<span class="nxshop-badge">Sale</span>' : '')
        . (($p['featured'] ?? '') === '1' ? '<span class="nxshop-badge feat">Featured</span>' : '')
        . '<a class="nxshop-thumb" href="' . esc_url($detail) . '">' . (!empty($p['image']) ? '<img src="' . esc_url($p['image']) . '" alt="' . esc_attr($p['name']) . '">' : '<span class="nxshop-noimg">&#128722;</span>') . '</a>'
        . '<div class="nxshop-card-body">'
        . (($p['category_id'] ?? 0) ? '<div class="nxshop-cat">' . esc_html(nexa_shop_category_name((int) $p['category_id'])) . '</div>' : '')
        . '<h3><a href="' . esc_url($detail) . '">' . esc_html($p['name']) . '</a></h3>'
        . '<p class="nxshop-price"><strong>' . nexa_shop_money((float) $p['price']) . '</strong>' . ($sale ? ' <s>' . nexa_shop_money((float) $p['old_price']) . '</s>' : '') . '</p>'
        . '<p class="nxshop-stock">' . (($p['product_type'] ?? 'physical') === 'digital' ? 'Digital delivery' : ($inStock ? 'In stock (' . (int) $p['stock'] . ')' : '<span class="out">Out of stock</span>')) . '</p>'
        . '<form method="post"><input type="hidden" name="redirect" value="' . esc_attr(nexa_shop_page_url('shop')) . '">' . nexa_csrf_field()
        . '<button name="nexa_add_cart" value="' . (int) $p['id'] . '" class="nxshop-btn" ' . ($inStock ? '' : 'disabled') . '>Add to Cart</button> '
        . '<a class="nxshop-btn ghost" href="' . esc_url($detail) . '">View</a></form>'
        . '</div></div>';
    return $h;
}
function nexa_shop_detail(int $id): string {
    $p = nexa_shop_product($id);
    if (!$p || ($p['status'] ?? '') !== 'active') return '<p>That product is not available.</p><p><a href="' . esc_url(nexa_shop_page_url('shop')) . '">&larr; Back to shop</a></p>';
    $inStock = nexa_shop_in_stock($p);
    $h = nexa_shop_notice_html()
        . '<div class="nxshop-detail"><div class="nxshop-detail-img">' . (!empty($p['image']) ? '<img src="' . esc_url($p['image']) . '" alt="' . esc_attr($p['name']) . '">' : '<span class="nxshop-noimg big">&#128722;</span>') . '</div>'
        . '<div class="nxshop-detail-info"><p><a href="' . esc_url(nexa_shop_page_url('shop')) . '">&larr; Back to shop</a></p>'
        . '<h2>' . esc_html($p['name']) . '</h2>'
        . '<p class="nxshop-price big"><strong>' . nexa_shop_money((float) $p['price']) . '</strong>' . ((float) ($p['old_price'] ?? 0) > (float) $p['price'] ? ' <s>' . nexa_shop_money((float) $p['old_price']) . '</s>' : '') . '</p>'
        . '<div class="nxshop-desc">' . nl2br(esc_html((string) $p['description'])) . '</div>'
        . '<p class="nxshop-meta">SKU: ' . esc_html($p['sku'] ?: '—') . '<br>Category: ' . esc_html(nexa_shop_category_name((int) ($p['category_id'] ?? 0)) ?: 'Uncategorized') . '<br>Type: ' . esc_html($p['product_type'] ?? 'physical')
        . (($p['product_type'] ?? 'physical') !== 'digital' && isset($p['weight']) && (float) $p['weight'] > 0 ? '<br>Weight: ' . rtrim(rtrim(number_format((float) $p['weight'], 2), '0'), '.') . ' ' . nexa_shop_weight_unit() : '')
        . '<br>Availability: ' . (($p['product_type'] ?? 'physical') === 'digital' ? 'Instant delivery' : ($inStock ? 'In stock (' . (int) $p['stock'] . ')' : 'Out of stock')) . '</p>'
        . '<form method="post" class="nxshop-buy"><input type="hidden" name="redirect" value="' . esc_attr(nexa_shop_page_url('cart')) . '">' . nexa_csrf_field()
        . '<label>Qty <input type="number" name="qty" value="1" min="1" style="width:70px;"></label> '
        . '<button name="nexa_add_cart" value="' . (int) $p['id'] . '" class="nxshop-btn" ' . ($inStock ? '' : 'disabled') . '>Add to Cart</button> '
        . '<button name="nexa_add_cart" value="' . (int) $p['id'] . '" class="nxshop-btn alt" onclick="this.form.insertAdjacentHTML(\'beforeend\',\'<input type=hidden name=buy_now value=1>\')" ' . ($inStock ? '' : 'disabled') . '>Buy Now</button></form>'
        . '</div></div>';
    $rel = array_filter(query("SELECT * FROM shop_products WHERE status='active' AND category_id=? AND id<>? ORDER BY id DESC LIMIT 4", [(int) ($p['category_id'] ?? 0), $id]), static fn($r) => (int) ($p['category_id'] ?? 0) > 0);
    if ($rel) { $h .= '<h3>Related products</h3><div class="nxshop-grid cols-4">'; foreach ($rel as $r) $h .= nexa_shop_card($r); $h .= '</div>'; }
    return $h;
}

add_shortcode('shop_catalog', function ($a = []): string {
    nexa_shop_ensure();
    if (!empty($_GET['shop_product'])) return nexa_shop_detail((int) $_GET['shop_product']);
    $cols = max(1, min(4, (int) ($a['cols'] ?? 3)));
    $limit = max(1, (int) ($a['limit'] ?? 24));
    $catSlug = trim((string) ($a['category'] ?? ($_GET['shop_cat'] ?? '')));
    $sort = (string) ($_GET['shop_sort'] ?? 'new');
    $rows = query("SELECT * FROM shop_products WHERE status='active'");
    if ($catSlug !== '') {
        $catId = 0;
        foreach (nexa_shop_categories() as $c) if ($c['slug'] === $catSlug || (string) $c['id'] === $catSlug) $catId = (int) $c['id'];
        $rows = array_values(array_filter($rows, static fn($r) => (int) ($r['category_id'] ?? 0) === $catId));
    }
    $q = trim((string) ($_GET['shop_q'] ?? ''));
    if ($q !== '') $rows = array_values(array_filter($rows, static fn($r) => stripos($r['name'] . ' ' . $r['description'], $q) !== false));
    usort($rows, static function ($x, $y) use ($sort) {
        return match ($sort) {
            'price_asc' => (float) $x['price'] <=> (float) $y['price'],
            'price_desc' => (float) $y['price'] <=> (float) $x['price'],
            'name' => strcasecmp($x['name'], $y['name']),
            default => (int) $y['id'] <=> (int) $x['id'],
        };
    });
    $rows = array_slice($rows, 0, $limit);
    $shopUrl = nexa_shop_page_url('shop');
    $h = nexa_shop_notice_html() . '<div class="nxshop-toolbar"><form method="get" class="nxshop-filters">'
        . '<input type="search" name="shop_q" value="' . esc_attr($q) . '" placeholder="Search products&hellip;"> '
        . '<select name="shop_cat"><option value="">All categories</option>';
    foreach (nexa_shop_categories() as $c) $h .= '<option value="' . esc_attr($c['slug']) . '"' . ($catSlug === $c['slug'] ? ' selected' : '') . '>' . esc_html($c['name']) . '</option>';
    $h .= '</select> <select name="shop_sort">'
        . '<option value="new"' . ($sort === 'new' ? ' selected' : '') . '>Newest</option><option value="price_asc"' . ($sort === 'price_asc' ? ' selected' : '') . '>Price: low to high</option><option value="price_desc"' . ($sort === 'price_desc' ? ' selected' : '') . '>Price: high to low</option><option value="name"' . ($sort === 'name' ? ' selected' : '') . '>Name</option></select> '
        . '<button class="nxshop-btn ghost">Filter</button></form>'
        . '<a class="nxshop-cartlink" href="' . esc_url(nexa_shop_page_url('cart')) . '">&#128722; Cart (' . array_sum(nexa_shop_cart()) . ')</a></div>';
    if (!$rows) return $h . '<p>No products found.</p>' . nexa_shop_nocache();
    $h .= '<div class="nxshop-grid cols-' . $cols . '">';
    foreach ($rows as $p) $h .= nexa_shop_card($p);
    return $h . '</div>' . nexa_shop_nocache();
});

add_shortcode('shop_cart', function ($a = []): string {
    nexa_shop_ensure();
    $items = nexa_shop_cart_items();
    $h = nexa_shop_notice_html() . '<h3>Your Cart</h3>';
    if (!$items) return $h . '<p>Your cart is empty.</p><p><a class="nxshop-btn" href="' . esc_url(nexa_shop_page_url('shop')) . '">Continue shopping</a></p>' . nexa_shop_nocache();
    $t = nexa_shop_totals($items);
    $h .= '<form method="post"><input type="hidden" name="redirect" value="' . esc_attr(nexa_shop_page_url('cart')) . '">' . nexa_csrf_field()
        . '<table class="nxshop-table"><thead><tr><th>Product</th><th>Price</th><th>Qty</th><th>Total</th><th></th></tr></thead><tbody>';
    foreach ($items as $it) {
        $p = $it['product'];
        $h .= '<tr><td>' . esc_html($p['name']) . '</td><td>' . nexa_shop_money((float) $p['price']) . '</td>'
            . '<td><input type="number" name="qty[' . (int) $p['id'] . ']" value="' . $it['qty'] . '" min="0" style="width:70px;"></td>'
            . '<td>' . nexa_shop_money($it['line']) . '</td>'
            . '<td><button name="nexa_remove_item" value="' . (int) $p['id'] . '" class="nxshop-btn ghost sm">Remove</button></td></tr>';
    }
    $h .= '</tbody></table><button name="nexa_update_cart" value="1" class="nxshop-btn ghost">Update cart</button> <button name="nexa_clear_cart" value="1" class="nxshop-btn ghost">Clear cart</button></form>';
    $h .= '<form method="post" class="nxshop-coupon"><input type="hidden" name="redirect" value="' . esc_attr(nexa_shop_page_url('cart')) . '">' . nexa_csrf_field()
        . '<input name="coupon" placeholder="Coupon code" value="' . esc_attr((string) ($_SESSION['nexa_coupon'] ?? '')) . '"> <button name="nexa_apply_coupon" value="1" class="nxshop-btn ghost">Apply coupon</button></form>';
    $h .= '<div class="nxshop-totals"><p>Subtotal: <strong>' . nexa_shop_money($t['subtotal']) . '</strong></p>'
        . ($t['discount'] > 0 ? '<p>Discount: <strong>-' . nexa_shop_money($t['discount']) . '</strong></p>' : '')
        . ($t['weight'] > 0 ? '<p>Cart weight: <strong>' . rtrim(rtrim(number_format($t['weight'], 2), '0'), '.') . ' ' . nexa_shop_weight_unit() . '</strong></p>' : '')
        . '<p>Shipping (estimate): <strong>' . ($t['shipping'] > 0 ? nexa_shop_money($t['shipping']) : 'Free') . '</strong></p>'
        . ($t['tax'] > 0 ? '<p>' . esc_html(nexa_shop_setting('tax_label', 'Tax')) . ': <strong>' . nexa_shop_money($t['tax']) . '</strong></p>' : '')
        . '<p class="grand">Total: <strong>' . nexa_shop_money($t['total']) . '</strong></p>'
        . '<a class="nxshop-btn big" href="' . esc_url(nexa_shop_page_url('checkout')) . '">Proceed to Checkout</a></div>';
    return $h . nexa_shop_nocache();
});

add_shortcode('shop_checkout', function ($a = []): string {
    nexa_shop_ensure();
    if (!empty($_GET['placed'])) {
        $oid = (int) $_GET['placed'];
        $o = query("SELECT * FROM shop_orders WHERE id=?", [$oid]);
        if ($o) {
            $o = $o[0];
            $h = '<div class="nxshop-thanks"><h3>&#10003; Thank you — order #' . $oid . ' received!</h3>'
                . '<p>Total: <strong>' . nexa_shop_money((float) $o['total']) . '</strong> &middot; Status: ' . esc_html($o['status']) . ' &middot; Payment: ' . esc_html($o['payment_method']) . '</p>';
            if (($o['payment_method'] ?? '') === 'bank') $h .= '<div class="nxshop-paybox"><h4>Bank transfer details</h4><p>' . nl2br(esc_html(nexa_shop_setting('bank_instructions', 'Please contact us for bank details.'))) . '</p><p>Use order <strong>#' . $oid . '</strong> as your payment reference.</p></div>';
            if (($o['payment_method'] ?? '') === 'paypal' && !empty($o['payment_link'])) $h .= '<p><a class="nxshop-btn big" href="' . esc_url($o['payment_link']) . '">Pay now with PayPal</a></p>';
            if (($o['payment_method'] ?? '') === 'stripe' && !empty($o['payment_link'])) $h .= '<p><a class="nxshop-btn big" href="' . esc_url($o['payment_link']) . '">Pay now by card</a></p>';
            if (($o['payment_method'] ?? '') === 'cod') $h .= '<p>Please have payment ready when your order arrives.</p>';
            $h .= '<p><a href="' . esc_url(nexa_shop_page_url('shop')) . '">Continue shopping</a> &middot; <a href="' . esc_url(nexa_shop_page_url('account')) . '">View your orders</a></p></div>';
            return $h . nexa_shop_nocache();
        }
    }
    $items = nexa_shop_cart_items();
    $h = nexa_shop_notice_html() . '<h3>Checkout</h3>';
    if (!$items) return $h . '<p>Your cart is empty.</p><p><a class="nxshop-btn" href="' . esc_url(nexa_shop_page_url('shop')) . '">Browse the shop</a></p>' . nexa_shop_nocache();
    $user = null;
    if (is_user_logged_in() && !empty($_SESSION['name'])) $user = di_find_user_by_login((string) $_SESSION['name']);
    $shipCountry = trim((string) ($_GET['ship_country'] ?? nexa_shop_setting('store_country')));
    $t = nexa_shop_totals($items, 'flat', null, $shipCountry);
    $h .= '<div class="nxshop-checkout"><form method="post" class="nxshop-co-form" id="nxshop-co"><input type="hidden" name="redirect" value="' . esc_attr(nexa_shop_page_url('checkout')) . '">' . nexa_csrf_field()
        . '<label>Full name<input name="name" required value="' . esc_attr($user ? di_user_display_name($user) : '') . '"></label>'
        . '<label>Email<input name="email" type="email" required value="' . esc_attr($user['email'] ?? '') . '"></label>'
        . '<label>Phone<input name="phone" value=""></label>';
    if ($t['has_physical']) {
        $countries = nexa_shop_country_options($shipCountry);
        $h .= '<label>Country<select name="country" id="nxshop-country">';
        foreach ($countries as $c) $h .= '<option' . (strcasecmp($c, $shipCountry) === 0 ? ' selected' : '') . '>' . esc_html($c) . '</option>';
        if (!$countries) $h .= '<option>' . esc_html($shipCountry) . '</option>';
        $h .= '</select></label>';
    }
    $h .= '<label>Delivery address<textarea name="address" rows="3" placeholder="Street, city, parish/state, postal code"></textarea></label>';
    if ($t['has_physical'] && nexa_shop_setting('shipping_enabled', '1') === '1') {
        if ($t['ship_options']) {
            $h .= '<fieldset class="nxshop-pay"><legend>Shipping method' . ($t['weight'] > 0 ? ' (cart weight: ' . rtrim(rtrim(number_format($t['weight'], 2), '0'), '.') . ' ' . nexa_shop_weight_unit() . ')' : '') . '</legend>';
            $first = true;
            foreach ($t['ship_options'] as $o) {
                $h .= '<label class="radio"><input type="radio" name="ship_method" value="' . esc_attr($o['key']) . '" data-cost="' . (float) $o['cost'] . '"' . ($first ? ' checked' : '') . '> ' . esc_html($o['title'])
                    . ($o['zone'] ? ' <small>(' . esc_html($o['zone']) . ')</small>' : '')
                    . ' — <strong>' . ($o['cost'] > 0 ? nexa_shop_money($o['cost']) : 'Free') . '</strong></label>';
                $first = false;
            }
            $h .= '</fieldset>';
        } else {
            $h .= '<div class="nxshop-paybox"><p><strong>No shipping available to ' . esc_html($shipCountry ?: 'that country') . '.</strong> Please contact us to arrange delivery.</p></div>';
        }
    }
    $methods = [];
    if (nexa_shop_setting('pay_cod', '1') === '1') $methods['cod'] = 'Cash on delivery';
    if (nexa_shop_setting('pay_bank', '0') === '1') $methods['bank'] = 'Direct bank transfer';
    if (nexa_shop_setting('pay_paypal', '0') === '1') $methods['paypal'] = 'PayPal';
    if (nexa_shop_setting('pay_stripe', '0') === '1') $methods['stripe'] = 'Credit / debit card (Stripe)';
    if (!$methods) $methods['cod'] = 'Cash on delivery';
    $h .= '<fieldset class="nxshop-pay"><legend>Payment method</legend>';
    $first = true;
    foreach ($methods as $val => $label) { $h .= '<label class="radio"><input type="radio" name="payment" value="' . $val . '"' . ($first ? ' checked' : '') . '> ' . esc_html($label) . '</label>'; $first = false; }
    $noShip = $t['has_physical'] && nexa_shop_setting('shipping_enabled', '1') === '1' && !$t['ship_options'];
    $h .= '</fieldset><label>Order notes (optional)<textarea name="notes" rows="2"></textarea></label>'
        . '<button name="nexa_place_order" value="1" class="nxshop-btn big" id="nxshop-place"' . ($noShip ? ' disabled' : '') . '>Place Order — ' . nexa_shop_money($t['total']) . '</button></form>'
        . '<aside class="nxshop-summary" id="nxshop-summary" data-base="' . (float) ($t['subtotal'] - $t['discount'] + $t['tax']) . '" data-symbol="' . esc_attr((nexa_shop_currencies()[nexa_shop_setting('currency', 'USD')] ?? '$')) . '"><h4>Your order</h4>';
    foreach ($items as $it) $h .= '<p>' . esc_html($it['product']['name']) . ' x' . $it['qty'] . ' <span>' . nexa_shop_money($it['line']) . '</span></p>';
    $h .= '<p>Subtotal <span>' . nexa_shop_money($t['subtotal']) . '</span></p>'
        . ($t['discount'] > 0 ? '<p>Discount <span>-' . nexa_shop_money($t['discount']) . '</span></p>' : '')
        . '<p>Shipping <span id="nxshop-ship-cost">' . ($t['shipping'] > 0 ? nexa_shop_money($t['shipping']) : 'Free') . '</span></p>'
        . ($t['ship_title'] ? '<p>Method <span id="nxshop-ship-title">' . esc_html($t['ship_title']) . '</span></p>' : '')
        . ($t['tax'] > 0 ? '<p>' . esc_html(nexa_shop_setting('tax_label', 'Tax')) . ' <span>' . nexa_shop_money($t['tax']) . '</span></p>' : '')
        . '<p class="grand">Total <span id="nxshop-grand">' . nexa_shop_money($t['total']) . '</span></p></aside></div>'
        . '<script>(function(){var f=document.getElementById("nxshop-co");if(!f)return;'
        . 'try{var saved=JSON.parse(sessionStorage.getItem("nxshop_co")||"null");if(saved){["name","email","phone","address","notes"].forEach(function(k){var el=f.querySelector("[name="+k+"]");if(el&&saved[k])el.value=saved[k];});sessionStorage.removeItem("nxshop_co");}}catch(e){}'
        . 'var c=document.getElementById("nxshop-country");if(c)c.addEventListener("change",function(){try{var d={};["name","email","phone","address","notes"].forEach(function(k){var el=f.querySelector("[name="+k+"]");if(el)d[k]=el.value;});sessionStorage.setItem("nxshop_co",JSON.stringify(d));}catch(e){}'
        . 'location.href=' . json_encode(nexa_shop_page_url('checkout')) . '+"?ship_country="+encodeURIComponent(c.value);});'
        . 'var sum=document.getElementById("nxshop-summary"),btn=document.getElementById("nxshop-place");'
        . 'function fmt(n){return sum.dataset.symbol+Number(n).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});}'
        . 'f.querySelectorAll("input[name=ship_method]").forEach(function(r){r.addEventListener("change",function(){var cost=parseFloat(r.dataset.cost||"0"),base=parseFloat(sum.dataset.base||"0");'
        . 'document.getElementById("nxshop-ship-cost").textContent=cost>0?fmt(cost):"Free";document.getElementById("nxshop-grand").textContent=fmt(base+cost);'
        . 'var tt=document.getElementById("nxshop-ship-title");if(tt)tt.textContent=r.closest("label").textContent.replace(/\s+/g," ").trim().replace(/\s*—.*$/,"");'
        . 'if(btn)btn.textContent="Place Order — "+fmt(base+cost);});});})();</script>';
    return $h . nexa_shop_nocache();
});

add_shortcode('shop_account', function ($a = []): string {
    nexa_shop_ensure();
    if (!is_user_logged_in()) {
        return '<div class="nxshop-account"><h3>My Account</h3><p>Please sign in to view your orders and downloads.</p><p><a class="nxshop-btn" href="' . esc_url(home_url('admin/login.php')) . '">Sign in</a></p></div>' . nexa_shop_nocache();
    }
    $user = !empty($_SESSION['name']) ? di_find_user_by_login((string) $_SESSION['name']) : null;
    $email = strtolower((string) ($user['email'] ?? ''));
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    $orders = array_reverse(array_filter(get_table('shop_orders')['data'], static fn($o) => ($uid > 0 && (int) ($o['user_id'] ?? 0) === $uid) || ($email !== '' && strtolower((string) ($o['email'] ?? '')) === $email)));
    $h = '<div class="nxshop-account"><h3>My Account</h3><p>Signed in as <strong>' . esc_html($_SESSION['display-name'] ?? ($_SESSION['name'] ?? '')) . '</strong></p>';
    if (!$orders) return $h . '<p>You have no orders yet.</p><p><a class="nxshop-btn" href="' . esc_url(nexa_shop_page_url('shop')) . '">Start shopping</a></p></div>' . nexa_shop_nocache();
    $detailId = (int) ($_GET['shop_order'] ?? 0);
    $h .= '<table class="nxshop-table"><thead><tr><th>Order</th><th>Date</th><th>Total</th><th>Status</th><th></th></tr></thead><tbody>';
    foreach ($orders as $o) {
        $h .= '<tr><td>#' . (int) $o['id'] . '</td><td>' . esc_html($o['created_at']) . '</td><td>' . nexa_shop_money((float) $o['total']) . '</td><td>' . esc_html($o['status']) . '</td>'
            . '<td><a href="' . esc_url(nexa_shop_page_url('account') . '?shop_order=' . (int) $o['id']) . '">View</a></td></tr>';
    }
    $h .= '</tbody></table>';
    if ($detailId > 0) {
        foreach ($orders as $o) if ((int) $o['id'] === $detailId) {
            $h .= '<div class="nxshop-order-detail"><h4>Order #' . $detailId . ' — ' . esc_html($o['status']) . '</h4>';
            $lines = nexa_shop_order_items($detailId);
            if ($lines) foreach ($lines as $li) {
                $h .= '<p>' . esc_html($li['name']) . ' x' . (int) $li['qty'] . ' — ' . nexa_shop_money((float) $li['line_total']);
                $prod = nexa_shop_product((int) $li['product_id']);
                if ($prod && ($prod['product_type'] ?? '') === 'digital' && !empty($prod['download_file']) && in_array(strtolower((string) $o['status']), ['paid', 'processing', 'shipped', 'completed'], true)) {
                    $file = (string) $prod['download_file'];
                    $href = preg_match('#^https?://#i', $file) ? $file : home_url($file);
                    $h .= ' &nbsp;<a class="nxshop-btn sm" href="' . esc_url($href) . '">Download</a>';
                }
                $h .= '</p>';
            } else $h .= '<p>' . esc_html($o['items']) . '</p>';
            $h .= '<p>Payment: ' . esc_html($o['payment_method'] ?? 'cod') . ' &middot; Shipping address: ' . esc_html($o['address']) . '</p></div>';
        }
    }
    return $h . '</div>' . nexa_shop_nocache();
});

// Editor support: Admin -> Edit -> Insert Plugin Shortcode.
if (function_exists('register_editor_shortcode')) {
    register_editor_shortcode(['plugin' => 'Nexa Shop', 'plugin_slug' => 'nexa-shop', 'label' => 'Product catalog', 'tag' => 'shop_catalog',
        'description' => 'Product grid with search, category filter and sorting.', 'shortcode' => '[shop_catalog]',
        'fields' => [['key' => 'category', 'label' => 'Category slug (optional)', 'default' => ''], ['key' => 'cols', 'label' => 'Columns', 'type' => 'select', 'options' => ['2', '3', '4'], 'default' => '3'], ['key' => 'limit', 'label' => 'Max products', 'type' => 'number', 'default' => '24']]]);
    register_editor_shortcode(['plugin' => 'Nexa Shop', 'plugin_slug' => 'nexa-shop', 'label' => 'Cart', 'tag' => 'shop_cart', 'description' => 'Cart with quantities, coupons and totals.', 'shortcode' => '[shop_cart]']);
    register_editor_shortcode(['plugin' => 'Nexa Shop', 'plugin_slug' => 'nexa-shop', 'label' => 'Checkout', 'tag' => 'shop_checkout', 'description' => 'Checkout form and order confirmation.', 'shortcode' => '[shop_checkout]']);
    register_editor_shortcode(['plugin' => 'Nexa Shop', 'plugin_slug' => 'nexa-shop', 'label' => 'My Account (orders)', 'tag' => 'shop_account', 'description' => 'Customer orders and digital downloads.', 'shortcode' => '[shop_account]']);
}
