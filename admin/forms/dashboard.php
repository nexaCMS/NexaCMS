<div id="page-wrapper">
  <div class="row">
    <div class="col-lg-12">
      <h1 class="page-header">
        Dashboard
        <small>Welcome back, <?= esc_html($_SESSION['display-name'] ?? 'Administrator') ?></small>
        <a href="../" target="_blank" class="btn btn-default btn-sm pull-right"><i class="fa fa-external-link"></i> View Live Site</a>
      </h1>
    </div>
  </div>

  <?php include __DIR__ . '/../includes/flash_messages.php'; ?>

  <div class="row">
    <div class="col-lg-12">
      <div class="panel panel-default">
        <div class="panel-heading"><i class="fa fa-th-large"></i> Enabled Plugin Screens <a href="plugins.php" class="btn btn-xs btn-default pull-right">Manage Plugins &amp; Stages</a></div>
        <div class="panel-body">
          <?php
          $screens = [];
          foreach ((array) ($GLOBALS['active_plugins']['admin'] ?? []) as $plug) {
              $slug = (string) ($plug['name'] ?? '');
              if ($slug === '') continue;
              $meta = di_read_plugin_manifest(nexa_resolve_plugin_path($slug), $slug);
              $screens[] = [$slug, $meta['displayed-name'] ?? $slug];
          }
          if (!$screens) echo '<p class="text-muted">No plugins are enabled. Enable some in Plugins.</p>';
          foreach ($screens as [$slug, $label]): ?>
            <a class="btn btn-primary btn-sm" style="margin:0 6px 8px 0" href="admin.php?plugin_page=<?= urlencode($slug) ?>"><i class="fa fa-plug"></i> <?= esc_html($label) ?></a>
          <?php endforeach; ?>
          <p class="text-muted" style="margin-top:6px">Every enabled plugin also appears in the sidebar under <strong>Plugin Screens</strong>, and adds its own sidebar menu via the admin hook.</p>
        </div>
      </div>
    </div>
  </div>


  <div class="row">
    <div class="col-lg-3 col-md-6">
      <div class="panel panel-primary">
        <div class="panel-heading">
          <div class="row">
            <div class="col-xs-3"><i class="fa fa-thumb-tack fa-5x"></i></div>
            <div class="col-xs-9 text-right">
              <div class="huge"><?= (int) $numPosts ?></div>
              <div>Posts (<?= (int) $numPages ?> Pages)</div>
            </div>
          </div>
        </div>
        <a href="posts.php?type=post">
          <div class="panel-footer">
            <span class="pull-left">Manage Posts</span>
            <span class="pull-right"><i class="fa fa-arrow-circle-right"></i></span>
            <div class="clearfix"></div>
          </div>
        </a>
      </div>
    </div>

    <div class="col-lg-3 col-md-6">
      <div class="panel panel-green">
        <div class="panel-heading">
          <div class="row">
            <div class="col-xs-3"><i class="fa fa-plug fa-5x"></i></div>
            <div class="col-xs-9 text-right">
              <div class="huge"><?= (int) $numActivePlugs ?> / <?= count($allPluginRows) ?></div>
              <div>Active Plugins</div>
            </div>
          </div>
        </div>
        <a href="plugins.php">
          <div class="panel-footer">
            <span class="pull-left">Manage Plugins</span>
            <span class="pull-right"><i class="fa fa-arrow-circle-right"></i></span>
            <div class="clearfix"></div>
          </div>
        </a>
      </div>
    </div>

    <div class="col-lg-3 col-md-6">
      <div class="panel panel-yellow">
        <div class="panel-heading">
          <div class="row">
            <div class="col-xs-3"><i class="fa fa-users fa-5x"></i></div>
            <div class="col-xs-9 text-right">
              <div class="huge"><?= (int) $numUsers ?></div>
              <div>Users (<?= (int) $numCustomers ?> Customers)</div>
            </div>
          </div>
        </div>
        <a href="admin_users.php">
          <div class="panel-footer">
            <span class="pull-left">Manage Users</span>
            <span class="pull-right"><i class="fa fa-arrow-circle-right"></i></span>
            <div class="clearfix"></div>
          </div>
        </a>
      </div>
    </div>

    <div class="col-lg-3 col-md-6">
      <div class="panel panel-red">
        <div class="panel-heading">
          <div class="row">
            <div class="col-xs-3"><i class="fa fa-database fa-5x"></i></div>
            <div class="col-xs-9 text-right">
              <div class="huge" style="font-size:28px;text-transform:uppercase;"><?= esc_html($GLOBALS['di_db_driver'] ?? 'csv') ?></div>
              <div>Active Storage Driver</div>
            </div>
          </div>
        </div>
        <a href="database.php">
          <div class="panel-footer">
            <span class="pull-left">Switch / Migrate DB</span>
            <span class="pull-right"><i class="fa fa-arrow-circle-right"></i></span>
            <div class="clearfix"></div>
          </div>
        </a>
      </div>
    </div>
  </div>

  <div class="row">
    <div class="col-lg-8">
      <div class="panel panel-default">
        <div class="panel-heading">
          <i class="fa fa-clock-o fa-fw"></i> Recent Posts &amp; Pages
          <div class="pull-right">
            <a href="edit_post.php?type=post" class="btn btn-xs btn-primary"><i class="fa fa-plus"></i> New Post</a>
            <a href="edit_post.php?type=page" class="btn btn-xs btn-default"><i class="fa fa-plus"></i> New Page</a>
          </div>
        </div>
        <div class="panel-body">
          <div class="table-responsive">
            <table class="table table-striped table-hover">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Title</th>
                  <th>Type</th>
                  <th>Status</th>
                  <th>Date</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($recentItems as $item): ?>
                  <tr>
                    <td><?= esc_html($item['id'] ?? '') ?></td>
                    <td><strong><?= esc_html($item['post_title'] ?? '(Untitled)') ?></strong></td>
                    <td><span class="label label-default"><?= esc_html($item['post_type'] ?? 'post') ?></span></td>
                    <td>
                      <span class="label label-<?= ($item['post_status'] ?? '') === 'published' ? 'success' : 'warning' ?>">
                        <?= esc_html($item['post_status'] ?? 'draft') ?>
                      </span>
                    </td>
                    <td><?= esc_html($item['post_date'] ?? '') ?></td>
                    <td>
                      <a href="edit_post.php?id=<?= urlencode((string) $item['id']) ?>&type=<?= urlencode((string) ($item['post_type'] ?? 'post')) ?>" class="btn btn-xs btn-primary"><i class="fa fa-edit"></i> Edit</a>
                      <a href="../?p=<?= urlencode((string) $item['id']) ?>" target="_blank" class="btn btn-xs btn-default"><i class="fa fa-eye"></i> View</a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="panel panel-default">
        <div class="panel-heading"><i class="fa fa-info-circle fa-fw"></i> System Summary</div>
        <div class="panel-body">
          <ul class="list-group" style="margin-bottom:12px;">
            <li class="list-group-item"><strong>NexaCMS Version:</strong> <span class="pull-right"><?= esc_html(DICIMES_VERSION) ?></span></li>
            <li class="list-group-item"><strong>PHP Version:</strong> <span class="pull-right"><?= esc_html(PHP_VERSION) ?></span></li>
            <li class="list-group-item"><strong>Active Theme:</strong> <span class="pull-right"><a href="themes.php"><?= esc_html(get_setting('template', 'multi')) ?></a></span></li>
            <li class="list-group-item"><strong>Database Driver:</strong> <span class="pull-right"><code><?= esc_html($GLOBALS['di_db_driver'] ?? 'csv') ?></code></span></li>
            <li class="list-group-item"><strong>JSON API:</strong> <span class="pull-right"><a href="../api.php" target="_blank">/api.php</a></span></li>
            <li class="list-group-item"><strong>RSS 2.0 Feed:</strong> <span class="pull-right"><a href="../rss.php" target="_blank">/rss.php</a></span></li>
          </ul>
          <a href="backups.php" class="btn btn-success btn-block"><i class="fa fa-archive"></i> Create Site Backup</a>
        </div>
      </div>
    </div>
  </div>
</div>
