<div id="page-wrapper">
  <div class="row">
    <div class="col-lg-12">
      <h1 class="page-header">
        <?= esc_html($plural) ?>
        <a href="edit_post.php?type=<?= esc_attr($type) ?>" class="btn btn-primary btn-sm" style="margin-left:10px;">
          <i class="fa fa-plus"></i> Add New <?= esc_html($singular) ?>
        </a>
      </h1>
    </div>
  </div>

  <?php include __DIR__ . '/../includes/flash_messages.php'; ?>

  <div class="row" style="margin-bottom:15px;">
    <div class="col-sm-7">
      <ul class="nav nav-pills">
        <li class="<?= $status === 'all' ? 'active' : '' ?>"><a href="posts.php?type=<?= esc_attr($type) ?>&status=all">All <span class="badge"><?= $counts['all'] ?></span></a></li>
        <li class="<?= $status === 'published' ? 'active' : '' ?>"><a href="posts.php?type=<?= esc_attr($type) ?>&status=published">Published <span class="badge"><?= $counts['published'] ?></span></a></li>
        <li class="<?= $status === 'draft' ? 'active' : '' ?>"><a href="posts.php?type=<?= esc_attr($type) ?>&status=draft">Drafts <span class="badge"><?= $counts['draft'] ?></span></a></li>
        <li class="<?= $status === 'trash' ? 'active' : '' ?>"><a href="posts.php?type=<?= esc_attr($type) ?>&status=trash">Trash <span class="badge"><?= $counts['trash'] ?></span></a></li>
      </ul>
    </div>
    <div class="col-sm-5">
      <form method="get" action="posts.php" class="form-inline pull-right">
        <input type="hidden" name="type" value="<?= esc_attr($type) ?>">
        <input type="hidden" name="status" value="<?= esc_attr($status) ?>">
        <div class="input-group">
          <input type="text" name="s" class="form-control" placeholder="Search <?= esc_attr(strtolower($plural)) ?>..." value="<?= esc_attr($search) ?>">
          <span class="input-group-btn">
            <button type="submit" class="btn btn-default"><i class="fa fa-search"></i></button>
          </span>
        </div>
      </form>
    </div>
  </div>

  <div class="panel panel-default">
    <div class="panel-body">
      <div class="table-responsive">
        <table class="table table-striped table-bordered table-hover">
          <thead>
            <tr>
              <th width="60">ID</th>
              <th>Title</th>
              <th width="140">Author</th>
              <th width="110">Status</th>
              <th width="160">Date</th>
              <th width="230">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr><td colspan="6" class="text-center text-muted">No <?= esc_html(strtolower($plural)) ?> found.</td></tr>
            <?php else: ?>
              <?php foreach ($rows as $row): ?>
                <tr>
                  <td><?= esc_html($row['id']) ?></td>
                  <td>
                    <strong><a href="edit_post.php?id=<?= urlencode((string) $row['id']) ?>&type=<?= esc_attr($type) ?>"><?= esc_html($row['post_title'] ?: '(Untitled)') ?></a></strong>
                    <?php if (!empty($row['post_alias'])): ?>
                      <br><small class="text-muted">Slug: <code><?= esc_html($row['post_alias']) ?></code></small>
                    <?php endif; ?>
                  </td>
                  <td><?= esc_html($row['post_author'] ?: 'Administrator') ?></td>
                  <td>
                    <?php $st = strtolower((string) ($row['post_status'] ?? 'draft')); ?>
                    <span class="label label-<?= $st === 'published' ? 'success' : ($st === 'trash' ? 'danger' : 'warning') ?>">
                      <?= esc_html($st) ?>
                    </span>
                  </td>
                  <td><?= esc_html($row['post_date'] ?? '') ?></td>
                  <td>
                    <a href="edit_post.php?id=<?= urlencode((string) $row['id']) ?>&type=<?= esc_attr($type) ?>" class="btn btn-xs btn-primary"><i class="fa fa-edit"></i> Edit</a>
                    <a href="../?p=<?= urlencode((string) $row['id']) ?>" target="_blank" class="btn btn-xs btn-default"><i class="fa fa-eye"></i> View</a>
                    <?php if ($st !== 'trash'): ?>
                      <form method="post" action="trash_post.php" style="display:inline;">
                        <?= di_csrf_field() ?>
                        <input type="hidden" name="id" value="<?= esc_attr($row['id']) ?>">
                        <input type="hidden" name="type" value="<?= esc_attr($type) ?>">
                        <input type="hidden" name="action" value="trash">
                        <button type="submit" class="btn btn-xs btn-warning" title="Move to Trash"><i class="fa fa-trash-o"></i> Trash</button>
                      </form>
                    <?php else: ?>
                      <form method="post" action="trash_post.php" style="display:inline;">
                        <?= di_csrf_field() ?>
                        <input type="hidden" name="id" value="<?= esc_attr($row['id']) ?>">
                        <input type="hidden" name="type" value="<?= esc_attr($type) ?>">
                        <input type="hidden" name="action" value="restore">
                        <button type="submit" class="btn btn-xs btn-success"><i class="fa fa-undo"></i> Restore</button>
                      </form>
                      <form method="post" action="trash_post.php" style="display:inline;" onsubmit="return confirm('Permanently delete this item?');">
                        <?= di_csrf_field() ?>
                        <input type="hidden" name="id" value="<?= esc_attr($row['id']) ?>">
                        <input type="hidden" name="type" value="<?= esc_attr($type) ?>">
                        <input type="hidden" name="action" value="delete">
                        <button type="submit" class="btn btn-xs btn-danger"><i class="fa fa-times"></i> Delete</button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <?php if ($totalPages > 1): ?>
        <ul class="pagination">
          <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <li class="<?= $i === $currPage ? 'active' : '' ?>">
              <a href="posts.php?type=<?= esc_attr($type) ?>&status=<?= esc_attr($status) ?>&s=<?= urlencode($search) ?>&pg=<?= $i ?>"><?= $i ?></a>
            </li>
          <?php endfor; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
</div>
