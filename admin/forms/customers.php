<div id="page-wrapper">
  <div class="row">
    <div class="col-lg-12">
      <h1 class="page-header">
        Customers
        <a href="add_customer.php" class="btn btn-primary btn-sm" style="margin-left:10px;"><i class="fa fa-plus"></i> Add Customer</a>
      </h1>
    </div>
  </div>

  <?php include __DIR__ . '/../includes/flash_messages.php'; ?>

  <div class="well well-sm">
    <form method="get" action="customers.php" class="form-inline">
      <input type="text" name="search_string" class="form-control" placeholder="Search name, email, phone..." value="<?= esc_attr($search) ?>">
      <button type="submit" class="btn btn-primary"><i class="fa fa-search"></i> Search</button>
      <?php if ($search !== ''): ?>
        <a href="customers.php" class="btn btn-default">Reset</a>
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
              <th>Name</th>
              <th>Gender</th>
              <th>Phone</th>
              <th>Email</th>
              <th width="160">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($customers)): ?>
              <tr><td colspan="6" class="text-center text-muted">No customers found.</td></tr>
            <?php else: ?>
              <?php foreach ($customers as $c): ?>
                <tr>
                  <td><?= esc_html($c['id']) ?></td>
                  <td><strong><?= esc_html(trim(($c['f_name'] ?? '') . ' ' . ($c['l_name'] ?? ''))) ?></strong></td>
                  <td><?= esc_html(ucfirst((string) ($c['gender'] ?? ''))) ?></td>
                  <td><?= esc_html($c['phone'] ?? '') ?></td>
                  <td><?= esc_html($c['email'] ?? '') ?></td>
                  <td>
                    <a href="edit_customer.php?customer_id=<?= urlencode((string) $c['id']) ?>" class="btn btn-xs btn-primary"><i class="fa fa-edit"></i> Edit</a>
                    <form method="post" action="delete_customer.php" style="display:inline;" onsubmit="return confirm('Delete this customer record?');">
                      <?= di_csrf_field() ?>
                      <input type="hidden" name="del_id" value="<?= esc_attr($c['id']) ?>">
                      <button type="submit" class="btn btn-xs btn-danger"><i class="fa fa-trash"></i> Delete</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
