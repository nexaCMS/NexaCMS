<?php
/**
 * NexaCMS RSS 2.0 Feed (rss.php)
 */
require_once __DIR__ . '/inc.php';
load_active_plugins();

header('Content-Type: application/rss+xml; charset=UTF-8');

$limit = max(1, min(50, (int) get_setting('posts_per_rss', 15)));
$all   = get_result('posts', 1, ['*'], ['by' => 'id', 'order' => 'DESC'], -1);
$items = array_slice(array_values(array_filter($all, static fn($r) => ($r['post_status'] ?? '') === 'published')), 0, $limit);

$siteName = get_setting('blogname', 'NexaCMS');
$siteDesc = get_setting('blogdescription', 'NexaCMS RSS Feed');
$siteLink = home_url();

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
  <channel>
    <title><?= esc_html($siteName) ?></title>
    <link><?= esc_url($siteLink) ?></link>
    <description><?= esc_html($siteDesc) ?></description>
    <generator>NexaCMS <?= esc_html(NEXACMS_VERSION) ?></generator>
    <atom:link href="<?= esc_url(home_url('rss.php')) ?>" rel="self" type="application/rss+xml" />
    <lastBuildDate><?= gmdate(DATE_RSS) ?></lastBuildDate>
<?php foreach ($items as $item):
    $link = home_url('?p=' . urlencode((string) $item['id']));
    $ts = !empty($item['post_date_gmt']) && is_numeric($item['post_date_gmt'])
        ? (int) $item['post_date_gmt']
        : (strtotime((string) ($item['post_date'] ?? 'now')) ?: time());
    $summary = trim((string) ($item['post_excerpt'] ?? ''));
    if ($summary === '') {
        $summary = mb_substr(strip_tags((string) ($item['post_content'] ?? '')), 0, 300);
    }
?>
    <item>
      <title><?= esc_html($item['post_title'] ?? 'Untitled') ?></title>
      <link><?= esc_url($link) ?></link>
      <guid isPermaLink="true"><?= esc_url($link) ?></guid>
      <pubDate><?= gmdate(DATE_RSS, $ts) ?></pubDate>
      <author><?= esc_html($item['post_author'] ?? 'Administrator') ?></author>
      <description><?= esc_html($summary) ?></description>
    </item>
<?php endforeach; ?>
  </channel>
</rss>
