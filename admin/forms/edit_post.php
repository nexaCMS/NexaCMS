<div id="page-wrapper">
  <div class="row">
    <div class="col-lg-12">
      <h1 class="page-header">
        <?= $editing ? 'Edit ' . esc_html($singular) : 'Add New ' . esc_html($singular) ?>
        <?php if ($editing): ?>
          <a href="../?p=<?= urlencode((string) $item['id']) ?>" target="_blank" class="btn btn-default btn-sm pull-right">
            <i class="fa fa-external-link"></i> View <?= esc_html($singular) ?>
          </a>
        <?php endif; ?>
      </h1>
    </div>
  </div>

  <?php include __DIR__ . '/../includes/flash_messages.php'; ?>

  <form method="post" action="edit_post.php" enctype="multipart/form-data">
    <?= di_csrf_field() ?>
    <?php if ($editing): ?>
      <input type="hidden" name="id" value="<?= esc_attr($item['id']) ?>">
    <?php endif; ?>

    <div class="row">
      <div class="col-md-8">
        <div class="panel panel-default">
          <div class="panel-body">
            <div class="form-group">
              <label>Title</label>
              <input type="text" name="post_title" id="di_post_title" class="form-control input-lg" placeholder="Enter <?= esc_attr(strtolower($singular)) ?> title..." value="<?= esc_attr($item['post_title']) ?>" required>
            </div>

            <div class="form-group">
              <label>URL Slug / Permalink Alias</label>
              <input type="text" name="post_alias" id="di_post_alias" class="form-control" placeholder="auto-generated-from-title" value="<?= esc_attr($item['post_alias'] ?: $item['post_name']) ?>">
            </div>

            <div class="form-group">
              <label>Content</label>
              <textarea name="post_content" id="di_post_content" class="form-control" rows="14" style="font-family:monospace;"><?= esc_textarea($item['post_content']) ?></textarea>
              <p class="help-block">Supports HTML and NexaCMS shortcodes (e.g. <code>[microAd no="1"]</code>, <code>[echo]Hello[/echo]</code>, <code>[age dob="1995-05-15"]</code>). Drop any menu anywhere with <code>[menu id="1" style="horizontal" level="0"]</code> (styles: horizontal, vertical, pills, simple; level 1 = top level only).</p>
            </div>

            <div class="form-group">
              <label>Excerpt / Summary <small class="text-muted">(optional)</small></label>
              <textarea name="post_excerpt" class="form-control" rows="3"><?= esc_textarea($item['post_excerpt']) ?></textarea>
            </div>

            <div class="form-group">
              <label>Meta Keywords <small class="text-muted">(comma-separated)</small></label>
              <input type="text" name="post_keywords" class="form-control" value="<?= esc_attr($item['post_keywords']) ?>">
            </div>
          </div>
        </div>

        <?php $nexaShortcodeUi = function_exists('nexa_editor_shortcode_ui_data') ? nexa_editor_shortcode_ui_data() : ['supported' => [], 'basic' => []]; ?>
        <div class="panel panel-info nexa-shortcode-inserter" id="nexa-shortcode-inserter">
          <div class="panel-heading">
            <i class="fa fa-puzzle-piece"></i> <strong>Insert Plugin Shortcode</strong>
            <span class="label label-success"><?= count($nexaShortcodeUi['supported'] ?? []) ?> plugin builders</span>
            <?php if (!empty($nexaShortcodeUi['basic'])): ?><span class="label label-default"><?= count($nexaShortcodeUi['basic']) ?> basic shortcodes</span><?php endif; ?>
          </div>
          <div class="panel-body">
            <div class="row">
              <div class="col-sm-5">
                <div class="form-group">
                  <label for="nexa-shortcode-provider">Supported plugin / shortcode</label>
                  <select id="nexa-shortcode-provider" class="form-control"></select>
                  <p class="help-block" id="nexa-shortcode-description" style="margin-bottom:0;"></p>
                </div>
              </div>
              <div class="col-sm-7" id="nexa-shortcode-fields"></div>
            </div>
            <div class="form-group" style="margin-bottom:6px;">
              <label for="nexa-shortcode-preview">Generated shortcode</label>
              <div class="input-group">
                <input type="text" id="nexa-shortcode-preview" class="form-control" readonly style="font-family:monospace;">
                <span class="input-group-btn">
                  <button type="button" class="btn btn-default" id="nexa-shortcode-copy" title="Copy shortcode"><i class="fa fa-copy"></i></button>
                  <button type="button" class="btn btn-primary" id="nexa-shortcode-insert"><i class="fa fa-plus"></i> Insert into content</button>
                </span>
              </div>
            </div>
            <p class="help-block" style="margin:0;">Pick an active plugin, fill in its options, then insert at the cursor. Plugin developers can support this section with <code>register_editor_shortcode()</code> — see <code>PLUGIN_DEVELOPMENT.md</code>.</p>
          </div>
        </div>
        <script type="application/json" id="nexa-shortcode-inserter-data"><?= json_encode($nexaShortcodeUi, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
        <?php if (function_exists('do_action')) do_action('post_edit_main_fields', $item); ?>
      </div>

      <div class="col-md-4">
        <div class="panel panel-primary">
          <div class="panel-heading">Publish Settings</div>
          <div class="panel-body">
            <div class="form-group">
              <label>Status</label>
              <select name="post_status" class="form-control">
                <option value="published" <?= ($item['post_status'] ?? '') === 'published' ? 'selected' : '' ?>>Published</option>
                <option value="draft" <?= ($item['post_status'] ?? '') === 'draft' ? 'selected' : '' ?>>Draft</option>
              </select>
            </div>

            <div class="form-group">
              <label>Content Type</label>
              <select name="post_type" class="form-control">
                <option value="post" <?= ($item['post_type'] ?? 'post') === 'post' ? 'selected' : '' ?>>Post</option>
                <option value="page" <?= ($item['post_type'] ?? '') === 'page' ? 'selected' : '' ?>>Page</option>
              </select>
            </div>

            <div class="form-group">
              <label>Comments</label>
              <select name="comment_status" class="form-control">
                <option value="open" <?= ($item['comment_status'] ?? 'open') === 'open' ? 'selected' : '' ?>>Allow Comments</option>
                <option value="closed" <?= ($item['comment_status'] ?? '') === 'closed' ? 'selected' : '' ?>>Closed</option>
              </select>
            </div>

            <div class="form-group">
              <label>Sidebar on This Page</label>
              <select name="post_layout" class="form-control">
                <option value="" <?= ($item['post_layout'] ?? '') === '' ? 'selected' : '' ?>>Theme default (Appearance &rarr; Theme Settings)</option>
                <option value="right" <?= ($item['post_layout'] ?? '') === 'right' ? 'selected' : '' ?>>Right sidebar</option>
                <option value="left" <?= ($item['post_layout'] ?? '') === 'left' ? 'selected' : '' ?>>Left sidebar</option>
                <option value="none" <?= ($item['post_layout'] ?? '') === 'none' ? 'selected' : '' ?>>No sidebar &mdash; full width</option>
              </select>
              <p class="help-block">Overrides the theme sidebar for <strong>this page only</strong> (landing pages, checkout, etc.).</p>
            </div>

            <div class="form-group">
              <label>Menu Order</label>
              <input type="number" name="menu_order" class="form-control" value="<?= (int) ($item['menu_order'] ?? 0) ?>">
            </div>

            <button type="submit" class="btn btn-primary btn-block"><i class="fa fa-save"></i> <?= $editing ? 'Update ' . esc_html($singular) : 'Publish ' . esc_html($singular) ?></button>
            <a href="posts.php?type=<?= esc_attr($type) ?>" class="btn btn-default btn-block" style="margin-top:6px;">Back to All <?= esc_html($singular) ?>s</a>
          </div>
        </div>

        <div class="panel panel-default">
          <div class="panel-heading">Featured Image</div>
          <div class="panel-body">
            <div class="form-group">
              <label>Image URL or Path</label>
              <input type="text" name="post_thumb" class="form-control" placeholder="content/uploads/..." value="<?= esc_attr($item['post_thumb']) ?>">
            </div>
            <div class="form-group">
              <label>Or Upload Image</label>
              <input type="file" name="thumb_upload" accept="image/*" class="form-control">
            </div>
          </div>
        </div>

        <?php if (function_exists('do_action')) do_action('post_edit_sidebar_fields', $item); ?>
      </div>
    </div>
  </form>
</div>
<?php $engine = function_exists('nexa_editor_engine') ? nexa_editor_engine() : 'nexa'; ?>
<div class="panel panel-default" style="margin-bottom:10px;">
  <div class="panel-body" style="padding:8px 12px;">
    <strong><i class="fa fa-pencil"></i> Content Editor:</strong>&nbsp;
    <a href="edit_post.php?editor=nexa<?= $editing ? '&id=' . urlencode((string) $item['id']) : '' ?>&type=<?= esc_attr($type) ?>" class="btn btn-sm <?= $engine === 'nexa' ? 'btn-primary' : 'btn-default' ?>">Nexa WYSIWYG</a>
    <?php if (function_exists('nexa_tinymce_available') && nexa_tinymce_available()): ?>
      <a href="edit_post.php?editor=tinymce<?= $editing ? '&id=' . urlencode((string) $item['id']) : '' ?>&type=<?= esc_attr($type) ?>" class="btn btn-sm <?= $engine === 'tinymce' ? 'btn-primary' : 'btn-default' ?>">TinyMCE</a>
      <span class="text-muted"><small>Currently using <strong><?= $engine === 'tinymce' ? 'TinyMCE' : 'Nexa WYSIWYG' ?></strong> &middot; global default: Settings &rarr; Writing</small></span>
    <?php else: ?>
      <button class="btn btn-sm btn-default" disabled>TinyMCE</button>
      <span class="text-danger"><small><strong>TinyMCE is not installed</strong> on this site (missing <code>admin/vendor/tinymce/</code>) — apply a FULL update package or see Settings &rarr; Writing.</small></span>
    <?php endif; ?>
  </div>
</div>
<script src="js/nexa-shortcode-inserter.js"></script>
<?php if ($engine === 'tinymce'): ?>
<script src="vendor/tinymce/tinymce.min.js"></script>
<script src="js/nexa-tinymce.js"></script>
<?php else: ?>
<link href="css/wysiwyg.css" rel="stylesheet">
<script src="js/nexa-wysiwyg.js"></script>
<?php endif; ?>
