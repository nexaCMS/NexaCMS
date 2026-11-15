<?php
/**
 * NexaCMS Theme Uploader (admin/theme-install.php)
 */
require_once __DIR__ . '/inc.php';
require_once __DIR__ . '/includes/auth_validate.php';

include_once __DIR__ . '/includes/header.php';
?>
<div id="page-wrapper">
  <div class="row">
    <div class="col-lg-12">
      <h1 class="page-header">Upload Theme <a href="themes.php" class="btn btn-default btn-sm pull-right">&larr; Back to Themes</a></h1>
    </div>
  </div>

  <?php
  include __DIR__ . '/includes/flash_messages.php';
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && di_verify_csrf()) {
      $msSite = function_exists('nexa_current_site') ? nexa_current_site() : null;
      $msPolicy = function_exists('nexa_multisite_upload_policy') ? nexa_multisite_upload_policy() : 'allow';
      if ($msSite && $msPolicy === 'deny') {
          echo errorMessage('Uploading themes is disabled for this subsite by the network admin.');
      } elseif ($msSite && $msPolicy === 'approval' && !empty($_FILES['theme']['tmp_name']) && is_uploaded_file($_FILES['theme']['tmp_name'])) {
          $pendingDir = dirname(__DIR__) . '/content/network-pending';
          if (!is_dir($pendingDir)) @mkdir($pendingDir, 0755, true);
          if (!is_file($pendingDir . '/.htaccess')) @file_put_contents($pendingDir . '/.htaccess', "Require all denied\nDeny from all\n");
          if (!is_file($pendingDir . '/index.html')) @file_put_contents($pendingDir . '/index.html', '');
          $origName = basename((string) $_FILES['theme']['name']);
          $pendName = (int) $msSite['id'] . '-' . time() . '-' . preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $origName);
          if (move_uploaded_file($_FILES['theme']['tmp_name'], $pendingDir . '/' . $pendName)) {
              nexa_network_call(static function () use ($msSite, $pendName, $origName) {
                  update_table('network_package_requests', ['site_id' => (string) $msSite['id'], 'site_slug' => (string) $msSite['slug'], 'type' => 'theme', 'file' => $pendName, 'orig_name' => $origName, 'requested_by' => (string) ($_SESSION['name'] ?? 'subsite admin'), 'status' => 'pending', 'created_at' => date('Y-m-d H:i:s')], 'insert');
              });
              echo successMessage('Your theme was received and is <strong>waiting for network-admin approval</strong>. It has not been installed yet.');
          } else echo errorMessage('Could not store the uploaded package for approval.');
      } else {
          // Inside a subsite, a freely-allowed upload installs into that
          // subsite's OWN theme folder (content/themes-<slug>/) so it can
          // run its own theme/version without touching the shared copy.
          if ($msSite && function_exists('nexa_site_themes_dir')) upload('theme', nexa_site_themes_dir($msSite));
          else upload('theme');
      }
  }
  ?>

  <?php
  $msSiteNow = function_exists('nexa_current_site') ? nexa_current_site() : null;
  $msPolicyNow = function_exists('nexa_multisite_upload_policy') ? nexa_multisite_upload_policy() : 'allow';
  $myRequests = [];
  if ($msSiteNow) {
      try {
          $siteId = (int) $msSiteNow['id'];
          $myRequests = nexa_network_call(static function () use ($siteId) {
              nexa_multisite_ensure_registry();
              return array_reverse(array_values(array_filter(get_table('network_package_requests')['data'], static fn($r) => (int) ($r['site_id'] ?? 0) === $siteId && (string) ($r['type'] ?? '') === 'theme')));
          });
      } catch (Throwable $e) { $myRequests = []; }
  }
  if ($msSiteNow && $msPolicyNow === 'approval'): ?>
  <div class="alert alert-info">This subsite's uploads need <strong>network-admin approval</strong>. Your package is sent to the main site's Network Admin — nothing is installed until a super admin approves it there.</div>
  <?php endif; ?>
  <?php if ($msSiteNow): ?>
  <div class="alert alert-info">Themes you upload here install <strong>for this subsite only</strong> (your own copy under <code>content/themes-<?= esc_html($msSiteNow['slug']) ?>/</code>). Themes installed on the main site stay shared with every site.</div>
  <?php endif; ?>
  <?php if ($myRequests): $statusText = ['pending' => ['warning', 'Waiting for network-admin approval'], 'installed' => ['success', 'Approved & installed for your site only — find it under Appearance → Themes'], 'rejected' => ['danger', 'Rejected by the network admin'], 'approved-failed' => ['danger', 'Approval failed — ask the network admin to check the package']]; ?>
  <div class="panel panel-default"><div class="panel-heading"><strong>Your theme upload requests</strong></div><div class="panel-body">
    <?php foreach (array_slice($myRequests, 0, 10) as $r): [$cls, $txt] = $statusText[(string) $r['status']] ?? ['default', (string) $r['status']]; ?>
      <div style="border-bottom:1px solid #f3f4f6;padding:6px 0;">
        <code><?= esc_html($r['orig_name']) ?></code> <span class="label label-<?= $cls ?>"><?= esc_html($txt) ?></span>
        <small class="text-muted"><?= esc_html($r['created_at']) ?><?php if (!empty($r['reviewed_at'])): ?> · decided <?= esc_html($r['reviewed_at']) ?><?php endif; ?></small>
      </div>
    <?php endforeach; ?>
  </div></div>
  <?php endif; ?>

  <div class="row">
    <div class="col-md-6">
      <div class="panel panel-default">
        <div class="panel-heading">Install a Theme in <code>.zip</code> Format</div>
        <div class="panel-body">
          <form method="post" enctype="multipart/form-data" action="theme-install.php">
            <?= di_csrf_field() ?>
            <div class="form-group">
              <label>Select Theme Archive (.zip)</label>
              <input type="file" name="theme" accept=".zip" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fa fa-upload"></i> Install Now</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include_once __DIR__ . '/includes/footer.php'; ?>
