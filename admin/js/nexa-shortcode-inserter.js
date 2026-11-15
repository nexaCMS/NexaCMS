/**
 * NexaCMS editor shortcode inserter.
 *
 * The server supplies plugin-registered builders as JSON. This script renders
 * the dropdown + fields, keeps a live generated-shortcode preview, and inserts
 * it into TinyMCE, the Nexa WYSIWYG visual/source editor, or a plain textarea.
 */
(function () {
  'use strict';

  var data = { supported: [], basic: [] };
  var current = null;
  var savedRange = null;

  function $(selector, root) { return (root || document).querySelector(selector); }
  function esc(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }
  function attrValue(value) {
    return String(value == null ? '' : value)
      .replace(/[\r\n]+/g, ' ')
      .replace(/"/g, '&quot;')
      .replace(/\[/g, '&#91;').replace(/\]/g, '&#93;')
      .trim();
  }
  function parseData() {
    var node = document.getElementById('nexa-shortcode-inserter-data');
    if (!node) return;
    try { data = JSON.parse(node.textContent || '{}'); } catch (e) { data = { supported: [], basic: [] }; }
    if (!Array.isArray(data.supported)) data.supported = [];
    if (!Array.isArray(data.basic)) data.basic = [];
  }
  function allProviders() { return data.supported.concat(data.basic); }
  function findProvider(id) {
    var found = null;
    allProviders().forEach(function (p) { if (p.id === id) found = p; });
    return found;
  }

  function insertIntoTextarea(textarea, text) {
    if (!textarea) return false;
    var start = typeof textarea.selectionStart === 'number' ? textarea.selectionStart : textarea.value.length;
    var end = typeof textarea.selectionEnd === 'number' ? textarea.selectionEnd : start;
    textarea.focus();
    textarea.setRangeText(text, start, end, 'end');
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
    textarea.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
  }

  function rememberSelection() {
    var area = $('.nexa-editor-area');
    if (!area || !window.getSelection) return;
    var sel = window.getSelection();
    if (sel.rangeCount && area.contains(sel.anchorNode)) savedRange = sel.getRangeAt(0).cloneRange();
  }

  function insertIntoEditor(text) {
    var textarea = document.getElementById('di_post_content');
    if (!textarea || !text) return false;

    // TinyMCE owns the textarea while it is initialized and visible.
    if (window.tinymce && typeof tinymce.get === 'function') {
      var ed = tinymce.get('di_post_content');
      if (ed && !ed.isHidden()) {
        ed.focus();
        ed.insertContent(text);
        ed.undoManager.add();
        return true;
      }
    }

    var wrap = textarea.closest ? textarea.closest('.nexa-wysiwyg') : null;
    var area = wrap ? wrap.querySelector('.nexa-editor-area') : $('.nexa-editor-area');
    var sourceVisible = textarea.style.display !== 'none' && textarea.offsetParent !== null;
    if (area && !sourceVisible) {
      area.focus();
      var inserted = false;
      if (savedRange && area.contains(savedRange.commonAncestorContainer)) {
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(savedRange);
      }
      try { inserted = document.execCommand('insertText', false, text); } catch (e) { inserted = false; }
      if (!inserted) {
        var sel2 = window.getSelection();
        var range;
        if (sel2.rangeCount && area.contains(sel2.anchorNode)) {
          range = sel2.getRangeAt(0);
        } else {
          range = document.createRange();
          range.selectNodeContents(area);
          range.collapse(false);
        }
        range.deleteContents();
        range.insertNode(document.createTextNode(text));
        range.collapse(false);
        sel2.removeAllRanges();
        sel2.addRange(range);
      }
      area.dispatchEvent(new Event('input', { bubbles: true }));
      return true;
    }
    return insertIntoTextarea(textarea, text);
  }

  function fieldInput(field) {
    var common = ' data-nexa-field="' + esc(field.name) + '"';
    var value = field.default || '';
    if (field.type === 'select') {
      var options = (field.options || []).map(function (o) {
        return '<option value="' + esc(o.value) + '"' + (String(o.value) === String(value) ? ' selected' : '') + '>' + esc(o.label) + '</option>';
      }).join('');
      if (!options) options = '<option value="' + esc(value) + '">' + esc(value) + '</option>';
      return '<select class="form-control" ' + common + '>' + options + '</select>';
    }
    if (field.type === 'textarea') {
      return '<textarea class="form-control" rows="3" placeholder="' + esc(field.placeholder || '') + '" ' + common + '>' + esc(value) + '</textarea>';
    }
    if (field.type === 'checkbox') {
      return '<label style="font-weight:normal;margin-top:7px;"><input type="checkbox" ' + common + ' value="' + esc(field.checked_value || '1') + '"> ' + esc(field.label) + '</label>';
    }
    var attrs = '';
    if (field.min !== '') attrs += ' min="' + esc(field.min) + '"';
    if (field.max !== '') attrs += ' max="' + esc(field.max) + '"';
    if (field.step !== '') attrs += ' step="' + esc(field.step) + '"';
    return '<input type="' + esc(field.type || 'text') + '" class="form-control" value="' + esc(value) + '" placeholder="' + esc(field.placeholder || '') + '"' + attrs + ' ' + common + '>';
  }

  function renderFields(provider) {
    var box = document.getElementById('nexa-shortcode-fields');
    var desc = document.getElementById('nexa-shortcode-description');
    if (!box) return;
    current = provider;
    if (desc) {
      desc.innerHTML = provider ? '<strong>' + esc(provider.plugin) + '</strong>' +
        (provider.description ? '<br>' + esc(provider.description) : '') +
        (provider.basic ? '<br><span class="label label-default">Basic registered shortcode</span>' : ' <span class="label label-success">Plugin builder</span>') : '';
    }
    if (!provider) {
      box.innerHTML = '<div class="alert alert-warning" style="margin:0;">No active plugin has registered an editor shortcode yet. Activate a shortcode plugin, or let a plugin call <code>register_editor_shortcode()</code>.</div>';
      updatePreview();
      return;
    }
    var html = '';
    (provider.fields || []).forEach(function (field) {
      if (field.type === 'checkbox') {
        html += '<div class="form-group">' + fieldInput(field) +
          (field.help ? '<p class="help-block">' + esc(field.help) + '</p>' : '') + '</div>';
      } else {
        html += '<div class="form-group"><label>' + esc(field.label) + (field.required ? ' <span class="text-danger">*</span>' : '') + '</label>' +
          fieldInput(field) + (field.help ? '<p class="help-block">' + esc(field.help) + '</p>' : '') + '</div>';
      }
    });
    if (provider.content && provider.content.enabled) {
      html += '<div class="form-group"><label>' + esc(provider.content.label || 'Enclosed content') + '</label>' +
        '<textarea class="form-control" rows="3" data-nexa-content="1" placeholder="' + esc(provider.content.placeholder || '') + '">' + esc(provider.content.default || '') + '</textarea>' +
        (provider.content.help ? '<p class="help-block">' + esc(provider.content.help) + '</p>' : '') + '</div>';
    }
    if (!html) html = '<p class="text-muted" style="margin:6px 0 0;">This shortcode needs no settings — just insert it.</p>';
    box.innerHTML = html;
    box.querySelectorAll('input,select,textarea').forEach(function (input) {
      input.addEventListener('input', updatePreview);
      input.addEventListener('change', updatePreview);
    });
    updatePreview();
  }

  function collect() {
    var values = {};
    var valid = true;
    var firstBad = null;
    if (!current) return { values: values, content: '', valid: false };
    (current.fields || []).forEach(function (field) {
      var input = document.querySelector('[data-nexa-field="' + field.name + '"]');
      var value = '';
      if (input) {
        value = input.type === 'checkbox' ? (input.checked ? (input.value || field.checked_value || '1') : '') : input.value;
        input.style.borderColor = '';
        if (field.required && String(value).trim() === '') {
          valid = false;
          firstBad = firstBad || input;
          input.style.borderColor = '#a94442';
        }
      }
      values[field.name] = value;
    });
    var contentInput = document.querySelector('[data-nexa-content="1"]');
    return { values: values, content: contentInput ? contentInput.value : '', valid: valid, firstBad: firstBad };
  }

  function buildShortcode() {
    if (!current) return '';
    var collected = collect();
    var values = collected.values;
    var content = collected.content || '';

    if (current.basic) {
      var attrs = String(values._attributes || '').trim();
      attrs = attrs.replace(/^\[/, '').replace(/\]$/, '').trim();
      var basic = '[' + current.tag + (attrs ? ' ' + attrs : '') + ']';
      if (content !== '') basic += content + '[/' + current.tag + ']';
      return basic;
    }

    if (current.template) {
      var hadContentPlaceholder = current.template.indexOf('{content}') !== -1;
      var out = current.template;
      Object.keys(values).forEach(function (name) {
        out = out.split('{' + name + '}').join(attrValue(values[name]));
      });
      out = out.split('{content}').join(content);
      // A template such as class="{class}" should not keep an empty attribute.
      out = out.replace(/\s+[A-Za-z0-9_-]+\=(["'])\1/g, '');
      out = out.replace(/\s+\]/g, ']');
      if (content !== '' && !hadContentPlaceholder && current.content && current.content.enabled && !new RegExp('\\[/' + current.tag + '\\]').test(out)) {
        // If a custom template forgot {content}, wrap safely rather than losing text.
        if (/\]$/.test(out)) out = out + content + '[/' + current.tag + ']';
      }
      return out;
    }

    var parts = ['[' + current.tag];
    (current.fields || []).forEach(function (field) {
      var value = values[field.name];
      if (field.type === 'checkbox') {
        if (value !== '') parts.push(field.name + '="' + attrValue(value) + '"');
        return;
      }
      if (String(value).trim() === '' && !field.always) return;
      parts.push(field.name + '="' + attrValue(value) + '"');
    });
    var code = parts.join(' ') + ']';
    if (content !== '' || (current.content && current.content.enabled && current.content.default !== '')) {
      code += content + '[/' + current.tag + ']';
    }
    return code;
  }

  function updatePreview() {
    var preview = document.getElementById('nexa-shortcode-preview');
    if (preview) preview.value = buildShortcode();
  }

  function populate() {
    var select = document.getElementById('nexa-shortcode-provider');
    if (!select) return;
    var html = '';
    if (data.supported.length) {
      html += '<optgroup label="Supported plugins — shortcode builders">';
      data.supported.forEach(function (p) {
        html += '<option value="' + esc(p.id) + '">' + esc(p.plugin + ' — ' + p.label + ' [' + p.tag + ']') + '</option>';
      });
      html += '</optgroup>';
    }
    if (data.basic.length) {
      html += '<optgroup label="Other active registered shortcodes (basic)">';
      data.basic.forEach(function (p) {
        html += '<option value="' + esc(p.id) + '">' + esc(p.label) + '</option>';
      });
      html += '</optgroup>';
    }
    select.innerHTML = html || '<option value="">No shortcode plugins are active</option>';
    renderFields(findProvider(select.value));
  }

  function init() {
    parseData();
    populate();
    document.addEventListener('selectionchange', rememberSelection);
    document.addEventListener('mouseup', rememberSelection);
    document.addEventListener('keyup', rememberSelection);

    var select = document.getElementById('nexa-shortcode-provider');
    if (select) select.addEventListener('change', function () { renderFields(findProvider(this.value)); });

    var insert = document.getElementById('nexa-shortcode-insert');
    if (insert) insert.addEventListener('click', function () {
      var collected = collect();
      if (!collected.valid) {
        if (collected.firstBad) collected.firstBad.focus();
        alert('Please complete the required shortcode field(s) first.');
        return;
      }
      var code = buildShortcode();
      if (code && insertIntoEditor(code)) {
        insert.innerHTML = '<i class="fa fa-check"></i> Inserted';
        setTimeout(function () { insert.innerHTML = '<i class="fa fa-plus"></i> Insert into content'; }, 1400);
      }
    });

    var copy = document.getElementById('nexa-shortcode-copy');
    if (copy) copy.addEventListener('click', function () {
      var preview = document.getElementById('nexa-shortcode-preview');
      if (!preview || !preview.value) return;
      preview.select();
      var done = function () {
        copy.innerHTML = '<i class="fa fa-check"></i>';
        setTimeout(function () { copy.innerHTML = '<i class="fa fa-copy"></i>'; }, 1200);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(preview.value).then(done, function () { document.execCommand('copy'); done(); });
      else { document.execCommand('copy'); done(); }
    });
  }

  window.nexaInsertIntoEditor = insertIntoEditor;
  window.nexaInsertEditorText = insertIntoEditor;
  window.nexaOpenShortcodeInserter = function () {
    var panel = document.getElementById('nexa-shortcode-inserter');
    if (panel) panel.scrollIntoView({ behavior: 'smooth', block: 'center' });
    var select = document.getElementById('nexa-shortcode-provider');
    if (select) setTimeout(function () { select.focus(); }, 350);
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
