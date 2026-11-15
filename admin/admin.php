<?php
/**
 * NexaCMS Plugin Admin Screen Host (admin/admin.php)
 */
require_once __DIR__ . '/inc.php';
require_once __DIR__ . '/includes/auth_validate.php';

include_once __DIR__ . '/includes/header.php';
?>
<div id="page-wrapper">
  <div class="row">
    <div class="col-lg-12">
      <h1 class="page-header">Plugin Administration</h1>
    </div>
  </div>
  <?php include __DIR__ . '/includes/flash_messages.php'; ?>
  <?php di_admin(); ?>
</div>
<?php include_once __DIR__ . '/includes/footer.php'; ?>
