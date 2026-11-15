<?php
$checks = [
    'PHP 8.0+ Runtime (' . PHP_VERSION . ')'     => version_compare(PHP_VERSION, '8.0.0', '>='),
    'Writable Root Config Directory'              => is_writable(dirname(__DIR__, 2)),
    'Writable Database Directory (admin/database)' => is_writable(dirname(__DIR__) . '/database'),
    'ZipArchive Extension (Backups & Uploads)'   => class_exists('ZipArchive'),
    'PDO Extension (SQLite / MySQL / PostgreSQL)' => class_exists('PDO'),
];
$allOk = !in_array(false, $checks, true);
?>
<h4>Environment Readiness Check</h4>
<ul class="list-group">
  <?php foreach ($checks as $label => $ok): ?>
    <li class="list-group-item">
      <?= htmlspecialchars($label) ?>
      <span class="label label-<?= $ok ? 'success' : 'danger' ?> pull-right"><?= $ok ? 'OK' : 'Missing' ?></span>
    </li>
  <?php endforeach; ?>
</ul>
<a href="install.php?step=2" class="btn btn-primary btn-block <?= $allOk ? '' : 'disabled' ?>">Continue to Configuration &rarr;</a>
