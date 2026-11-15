/**
 * NexaCMS WYSIWYG - turns <textarea id="di_post_content"> into a rich editor.
 * Self-contained (execCommand-based), HTML-source toggle, shortcode inserter.
 * The original textarea stays in the form and is synced on submit.
 */
(function () {
  function sanitize(html) {
    var d = document.createElement('div');
    d.innerHTML = html || '';
    d.querySelectorAll('script,iframe,object,embed,form').forEach(function (n) { n.remove(); });
    d.querySelectorAll('*').forEach(function (n) {
      Array.prototype.slice.call(n.attributes || []).forEach(function (a) {
        if (/^on/i.test(a.name) || /^\s*javascript:/i.test(a.value || '')) n.removeAttribute(a.name);
      });
    });
    return d.innerHTML;
  }

  function init(textarea) {
    if (!textarea || textarea.dataset.wysiwygDone) return;
    textarea.dataset.wysiwygDone = '1';

    var wrap = document.createElement('div');
    wrap.className = 'nexa-wysiwyg';
    wrap.innerHTML =
      '<div class="nexa-wysiwyg-toolbar">' +
        '<select class="form-control js-block" title="Paragraph style">' +
          '<option value="p">Paragraph</option><option value="h2">Heading 2</option>' +
          '<option value="h3">Heading 3</option><option value="h4">Heading 4</option>' +
          '<option value="blockquote">Quote</option><option value="pre">Code block</option>' +
        '</select>' +
        '<div class="btn-group btn-group-sm">' +
          '<button type="button" class="btn btn-default" data-cmd="bold" title="Bold"><b>B</b></button>' +
          '<button type="button" class="btn btn-default" data-cmd="italic" title="Italic"><i>I</i></button>' +
          '<button type="button" class="btn btn-default" data-cmd="underline" title="Underline"><u>U</u></button>' +
          '<button type="button" class="btn btn-default" data-cmd="strikeThrough" title="Strikethrough"><s>S</s></button>' +
          '<button type="button" class="btn btn-default" data-cmd="removeFormat" title="Clear formatting">Tx</button>' +
        '</div>' +
        '<div class="btn-group btn-group-sm">' +
          '<button type="button" class="btn btn-default" data-cmd="insertUnorderedList" title="Bullet list">&#8226; List</button>' +
          '<button type="button" class="btn btn-default" data-cmd="insertOrderedList" title="Numbered list">1. List</button>' +
          '<button type="button" class="btn btn-default" data-cmd="outdent" title="Outdent">&laquo;</button>' +
          '<button type="button" class="btn btn-default" data-cmd="indent" title="Indent">&raquo;</button>' +
        '</div>' +
        '<div class="btn-group btn-group-sm">' +
          '<button type="button" class="btn btn-default" data-cmd="justifyLeft" title="Align left">L</button>' +
          '<button type="button" class="btn btn-default" data-cmd="justifyCenter" title="Center">C</button>' +
          '<button type="button" class="btn btn-default" data-cmd="justifyRight" title="Align right">R</button>' +
        '</div>' +
        '<div class="btn-group btn-group-sm">' +
          '<button type="button" class="btn btn-default js-link" title="Insert/edit link">Link</button>' +
          '<button type="button" class="btn btn-default" data-cmd="unlink" title="Remove link">Unlink</button>' +
          '<button type="button" class="btn btn-default js-image" title="Insert image by URL">Image</button>' +
          '<button type="button" class="btn btn-default js-table" title="Insert table">Table</button>' +
          '<button type="button" class="btn btn-default js-hr" title="Horizontal rule">― HR</button>' +
        '</div>' +
        '<div class="btn-group btn-group-sm">' +
          '<button type="button" class="btn btn-default js-shortcode" title="Insert NexaCMS shortcode">[ ] Shortcode</button>' +
          '<button type="button" class="btn btn-default" data-cmd="undo" title="Undo">&#8630;</button>' +
          '<button type="button" class="btn btn-default" data-cmd="redo" title="Redo">&#8631;</button>' +
          '<button type="button" class="btn btn-default js-source" title="Toggle HTML source">&lt;/&gt; HTML</button>' +
        '</div>' +
      '</div>' +
      '<div class="nexa-editor-area" contenteditable="true"></div>' +
      '<div class="nexa-wysiwyg-status"><span class="js-count">0 words</span> &middot; Visual editor &middot; HTML source available via &lt;/&gt;</div>';

    textarea.parentNode.insertBefore(wrap, textarea);
    var area = wrap.querySelector('.nexa-editor-area');
    var count = wrap.querySelector('.js-count');
    area.innerHTML = sanitize(textarea.value);
    textarea.style.display = 'none';
    var sourceMode = false;

    function sync() {
      if (!sourceMode) textarea.value = sanitize(area.innerHTML);
      var text = area.textContent || '';
      count.textContent = (text.trim() ? text.trim().split(/\s+/).length : 0) + ' words';
    }
    function exec(cmd, val) { document.execCommand(cmd, false, val || null); area.focus(); sync(); }

    wrap.querySelectorAll('[data-cmd]').forEach(function (b) {
      b.addEventListener('mousedown', function (e) { e.preventDefault(); exec(b.getAttribute('data-cmd')); });
    });
    wrap.querySelector('.js-block').addEventListener('change', function () { exec('formatBlock', this.value); this.value = 'p'; });
    wrap.querySelector('.js-link').addEventListener('mousedown', function (e) {
      e.preventDefault();
      var url = prompt('Link URL:', 'https://');
      if (url) exec('createLink', url);
    });
    wrap.querySelector('.js-image').addEventListener('mousedown', function (e) {
      e.preventDefault();
      var url = prompt('Image URL (or path like content/uploads/pic.jpg):', '');
      if (url) exec('insertImage', url);
    });
    wrap.querySelector('.js-table').addEventListener('mousedown', function (e) {
      e.preventDefault();
      exec('insertHTML', '<table><tr><th>Header</th><th>Header</th></tr><tr><td>Cell</td><td>Cell</td></tr></table><p></p>');
    });
    wrap.querySelector('.js-hr').addEventListener('mousedown', function (e) { e.preventDefault(); exec('insertHorizontalRule'); });
    wrap.querySelector('.js-shortcode').addEventListener('mousedown', function (e) {
      e.preventDefault();
      if (typeof window.nexaOpenShortcodeInserter === 'function') {
        window.nexaOpenShortcodeInserter();
      } else {
        var pick = prompt('Shortcode to insert:', '[gallery]');
        if (pick) exec('insertText', pick);
      }
    });
    wrap.querySelector('.js-source').addEventListener('mousedown', function (e) {
      e.preventDefault();
      if (!sourceMode) { // Visual -> HTML
        textarea.value = sanitize(area.innerHTML);
        area.style.display = 'none';
        textarea.style.display = 'block';
        textarea.classList.add('nexa-wysiwyg-source');
        textarea.rows = 18;
        sourceMode = true;
      } else {            // HTML -> Visual
        area.innerHTML = sanitize(textarea.value);
        textarea.style.display = 'none';
        area.style.display = 'block';
        sourceMode = false;
        sync();
      }
    });
    area.addEventListener('input', sync);
    area.addEventListener('paste', function () { setTimeout(sync, 0); });
    var form = textarea.closest('form');
    if (form) form.addEventListener('submit', sync);
    sync();
  }

  document.addEventListener('DOMContentLoaded', function () {
    init(document.getElementById('di_post_content'));
  });
})();
