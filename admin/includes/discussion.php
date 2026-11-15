<div class="checkbox">
  <label>
    <input type="checkbox" name="default_comment_status" value="open" <?= get_setting('default_comment_status', 'open') === 'open' ? 'checked' : '' ?>>
    Allow people to submit comments on new posts by default
  </label>
</div>
<div class="checkbox">
  <label>
    <input type="checkbox" name="require_name_email" value="1" <?= get_setting('require_name_email', '1') === '1' ? 'checked' : '' ?>>
    Comment author must fill out name and email
  </label>
</div>
<div class="checkbox">
  <label>
    <input type="checkbox" name="comment_registation" value="1" <?= get_setting('comment_registation', '0') === '1' ? 'checked' : '' ?>>
    Users must be registered and logged in to comment
  </label>
</div>
<div class="checkbox">
  <label>
    <input type="checkbox" name="comments_notify" value="1" <?= get_setting('comments_notify', '1') === '1' ? 'checked' : '' ?>>
    Notify site administrator whenever a comment is posted
  </label>
</div>
<div class="checkbox">
  <label>
    <input type="checkbox" name="thread_comments" value="1" <?= get_setting('thread_comments', '1') === '1' ? 'checked' : '' ?>>
    Enable threaded (nested) comments
  </label>
</div>
<div class="checkbox">
  <label>
    <input type="checkbox" name="show_avatars" value="1" <?= get_setting('show_avatars', '1') === '1' ? 'checked' : '' ?>>
    Display commenter avatars
  </label>
</div>
