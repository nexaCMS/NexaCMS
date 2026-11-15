<div id="page-wrapper">
  <div class="row">
    <div class="col-lg-12">
      <h1 class="page-header"><?= $edit ? 'Edit User' : 'Add New User' ?></h1>
    </div>
  </div>
  <?php include __DIR__ . '/../includes/flash_messages.php'; ?>
  <div class="row">
    <div class="col-md-8">
      <div class="panel panel-default">
        <div class="panel-body">
          <form method="post" action="">
            <?= di_csrf_field() ?>
            <?php include __DIR__ . '/admin_users_form.php'; ?>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
