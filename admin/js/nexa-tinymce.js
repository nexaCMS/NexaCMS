/** NexaCMS TinyMCE init (posts/pages + comments). Loaded only when TinyMCE is the chosen editor. */
(function () {
  if (typeof tinymce === 'undefined') return;
  var postEl = document.getElementById('di_post_content');
  if (postEl && !postEl.dataset.tinymceDone) {
    postEl.dataset.tinymceDone = '1';
    tinymce.init({
      selector: '#di_post_content', license_key: 'gpl', promotion: false,
      height: 480, menubar: 'file edit view insert format tools table help',
      plugins: 'advlist autolink lists link image charmap preview anchor searchreplace visualblocks code fullscreen insertdatetime media table codesample wordcount emoticons autosave',
      toolbar: 'undo redo | blocks | bold italic underline strikethrough | alignleft aligncenter alignright | bullist numlist outdent indent | link image media table codesample emoticons charmap | nexashortcode | code fullscreen preview | removeformat',
      setup: function (editor) {
        editor.ui.registry.addButton('nexashortcode', {
          text: '[ ] Shortcode',
          tooltip: 'Insert a plugin shortcode (opens the Insert Plugin Shortcode section below)',
          onAction: function () {
            if (typeof window.nexaOpenShortcodeInserter === 'function') window.nexaOpenShortcodeInserter();
          }
        });
      },
      autosave_ask_before_unload: true,
      content_style: 'body{font-family:Helvetica,Arial,sans-serif;font-size:15px;line-height:1.65} img{max-width:100%;height:auto}'
    });
  }
  document.querySelectorAll('textarea.nexa-comment-body').forEach(function (el) {
    if (el.dataset.tinymceDone) return;
    el.dataset.tinymceDone = '1';
    tinymce.init({
      target: el, license_key: 'gpl', promotion: false,
      height: 190, menubar: false, statusbar: false,
      plugins: 'lists link emoticons code wordcount autolink',
      toolbar: 'bold italic underline | bullist numlist | link emoticons code | removeformat',
      content_style: 'body{font-family:Helvetica,Arial,sans-serif;font-size:14px}'
    });
  });
})();
