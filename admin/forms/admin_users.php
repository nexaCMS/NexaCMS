<div id="page-wrapper">
  <div class="row">
    <div class="col-lg-12">
      <h1 class="page-header">
        Users
        <a href="add_admin.php" class="btn btn-primary btn-sm" style="margin-left:10px;"><i class="fa fa-plus"></i> Add New User</a>
      </h1>
    </div>
  </div>

  <?php include __DIR__ . '/../includes/flash_messages.php'; ?>

  <?php if (!empty($defaultRiskUser)): ?>
  <div class="alert alert-danger">
    <strong><i class="fa fa-warning"></i> Security risk:</strong>
    this install still has the old factory-seeded <code>admin</code> account whose password is the well-known default
    <code>password</code>. Anyone can sign in with it. Your setup-wizard super admin remains untouched.
    <form method="post" style="display:inline;margin-left:10px;" onsubmit="return confirm('Delete the default admin account now?');">
      <?= di_csrf_field() ?>
      <input type="hidden" name="do" value="delete_default_admin">
      <button class="btn btn-danger btn-sm">Delete the default account</button>
    </form>
  </div>
  <?php endif; ?>

  <div class="well well-sm">
    <form method="get" action="admin_users.php" class="form-inline">
      <input type="text" name="search_string" class="form-control" placeholder="Search username, name, email..." value="<?= esc_attr($search) ?>">
      <button type="submit" class="btn btn-primary"><i class="fa fa-search"></i> Search</button>
      <?php if ($search !== ''): ?>
        <a href="admin_users.php" class="btn btn-default">Reset</a>
      <?php endif; ?>
    </form>
  </div>

  <div class="panel panel-default">
    <div class="panel-body">
      <div class="table-responsive">
        <table class="table table-striped table-bordered table-hover">
          <thead>
            <tr>
              <th width="60">ID</th>
              <th>Username</th>
              <th>Display Name</th>
              <th>Email</th>
              <th width="130">Role</th>
              <th width="160">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($allUsers as $u): ?>
              <tr>
                <td><?= esc_html($u['id']) ?></td>
                <td><strong><?= esc_html($u['user'] ?? '') ?></strong></td>
                <td><?= esc_html(di_user_display_name($u)) ?></td>
                <td><?= esc_html($u['email'] ?? '') ?></td>
                <td><span class="label label-info"><?= esc_html($u['role'] ?: 'subscriber') ?></span></td>
                <td>
                  <a href="edit_admin.php?admin_user_id=<?= urlencode((string) $u['id']) ?>" class="btn btn-xs btn-primary"><i class="fa fa-edit"></i> Edit</a>
                  <?php if ((string) $u['id'] !== '1' && ($u['user'] ?? '') !== ($_SESSION['name'] ?? '')): ?>
                    <form method="post" action="delete_user.php" style="display:inline;" onsubmit="return confirm('Delete user <?= esc_attr($u['user']) ?>?');">
                      <?= di_csrf_field() ?>
                      <input type="hidden" name="del_id" value="<?= esc_attr($u['id']) ?>">
                      <button type="submit" class="btn btn-xs btn-danger"><i class="fa fa-trash"></i> Delete</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
