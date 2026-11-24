/*
 * Tidy Tags control panel behaviour.
 *
 * Craft 6 loads this on every CP page, in <head>, before Vue renders. The CP is an Inertia app, so
 * pages change without a reload: everything here hangs off document-level listeners, which keep
 * working across visits, and reads its configuration from data attributes on the markup.
 */
(function () {
  'use strict';

  function format(template, values) {
    return String(template || '').replace(/\{(\w+)\}/g, function (m, key) {
      return Object.prototype.hasOwnProperty.call(values, key) ? values[key] : m;
    });
  }

  function hidden(form, name, value) {
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = value;
    input.setAttribute('data-tidytags-added', '');
    form.appendChild(input);
  }

  function clearAdded(form) {
    form.querySelectorAll('[data-tidytags-added]').forEach(function (n) { n.remove(); });
  }

  function clearChildren(node) {
    while (node.firstChild) node.removeChild(node.firstChild);
  }

  // ---------------------------------------------------------------------------------------------
  // Filters: a site <select> submits its form.

  document.addEventListener('change', function (e) {
    var select = e.target.closest && e.target.closest('[data-tidytags-autosubmit]');
    if (select && select.form) select.form.submit();
  });

  // ---------------------------------------------------------------------------------------------
  // Manage screen: selection and the Rename, Merge and Delete buttons.

  function bulkSelected(form) {
    return Array.prototype.filter.call(form.querySelectorAll('.tidytags-row-check'), function (c) { return c.checked; });
  }

  function bulkUpdate(form) {
    var n = bulkSelected(form).length;
    var count = form.querySelector('.tidytags-selected-count');
    if (count) count.textContent = n + ' selected';
    var set = function (action, disabled) {
      var btn = form.querySelector('[data-action="' + action + '"]');
      if (btn) btn.disabled = disabled;
    };
    set('rename', n !== 1);
    set('merge', n < 2);
    set('delete', n === 0);
  }

  document.addEventListener('change', function (e) {
    var form = e.target.closest && e.target.closest('[data-tidytags-bulk]');
    if (!form) return;
    if (e.target.matches('[data-tidytags-select-all]')) {
      form.querySelectorAll('.tidytags-row-check').forEach(function (c) { c.checked = e.target.checked; });
    }
    if (e.target.matches('[data-tidytags-select-all], .tidytags-row-check')) bulkUpdate(form);
  });

  function titleOf(check) {
    var row = check.closest('tr');
    var t = row && row.querySelector('.tidytags-title');
    return t ? t.textContent.trim() : '';
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('[data-tidytags-bulk] [data-action]');
    if (!btn) return;
    var form = btn.closest('[data-tidytags-bulk]');
    var sel = bulkSelected(form);
    var d = form.dataset;
    clearAdded(form);

    if (btn.dataset.action === 'delete') {
      if (!sel.length || !window.confirm(format(d.confirmDelete, { n: sel.length }))) return;
      sel.forEach(function (c) { hidden(form, 'tagIds[]', c.value); });
      form.action = d.deleteUrl;
    } else if (btn.dataset.action === 'rename') {
      if (sel.length !== 1) return;
      var current = titleOf(sel[0]);
      var next = window.prompt(format(d.promptRename, { title: current }), current);
      if (next === null || next.trim() === '' || next === current) return;
      hidden(form, 'tagId', sel[0].value);
      hidden(form, 'title', next);
      if (d.siteId) hidden(form, 'siteId', d.siteId);
      form.action = d.renameUrl;
    } else if (btn.dataset.action === 'merge') {
      if (sel.length < 2) return;
      var titles = sel.map(titleOf);
      var keep = parseInt(window.prompt(
        format(d.promptKeep, { n: sel.length }) + '\n' + titles.map(function (t, i) { return (i + 1) + '. ' + t; }).join('\n')
      ), 10);
      if (!keep || keep < 1 || keep > sel.length) return;
      var targetId = sel[keep - 1].value;
      var sources = sel.filter(function (c) { return c.value !== targetId; });
      if (!window.confirm(format(d.confirmMerge, { n: sources.length, title: titles[keep - 1] }))) return;
      hidden(form, 'targetId', targetId);
      sources.forEach(function (c) { hidden(form, 'sourceIds[]', c.value); });
      form.action = d.mergeUrl;
    } else {
      return;
    }

    form.submit();
  });

  // ---------------------------------------------------------------------------------------------
  // Duplicates screen: confirm before a cluster posts, and "Show usages".

  // Capture phase: a bubbling listener didn't stop a plain POST inside a CP content template.
  document.addEventListener('submit', function (e) {
    var form = e.target.closest && e.target.closest('[data-tidytags-confirm]');
    if (form && !window.confirm(form.getAttribute('data-tidytags-confirm'))) {
      e.preventDefault();
      e.stopImmediatePropagation();
    }
  }, true);

  function usageMessage(list, text) {
    clearChildren(list);
    var em = document.createElement('em');
    em.className = 'tidytags-note';
    em.textContent = text;
    list.appendChild(em);
  }

  function renderUsages(list, j) {
    if (!j || !j.success) { usageMessage(list, 'Failed to load usages.'); return; }
    if (!j.usages || !j.usages.length) { usageMessage(list, 'No relations — this entry isn’t in use.'); return; }

    clearChildren(list);
    var ul = document.createElement('ul');
    j.usages.forEach(function (u) {
      var li = document.createElement('li');
      var label = document.createElement(u.cpEditUrl ? 'a' : 'span');
      if (u.cpEditUrl) {
        label.href = u.cpEditUrl;
        label.target = '_blank';
        label.rel = 'noopener';
      }
      label.textContent = u.title || '(untitled)';
      li.appendChild(label);

      var meta = document.createElement('span');
      meta.className = 'tidytags-usage-meta';
      var code = document.createElement('code');
      code.textContent = u.fieldHandle || '?';
      meta.appendChild(document.createTextNode(' in field '));
      meta.appendChild(code);
      if (u.siteName) meta.appendChild(document.createTextNode(' · ' + u.siteName));
      meta.appendChild(document.createTextNode(' · ' + (u.elementType || 'element')));
      li.appendChild(meta);
      ul.appendChild(li);
    });
    list.appendChild(ul);

    if (j.hasMore) {
      var more = document.createElement('p');
      more.className = 'tidytags-note';
      more.textContent = 'Showing the first ' + j.usages.length + ' usages.';
      list.appendChild(more);
    }
  }

  // ---------------------------------------------------------------------------------------------
  // "Did you mean?" on entry titles.
  //
  // Craft 6's entry editor is a Vue page with no form or hidden elementId, so the entry comes from
  // the Inertia page in history.state (current across client-side visits). Title fields inside a
  // dialog — the slideout an Entries field opens for "New entry" — belong to another entry than
  // the page, so they're left alone. The server decides whether the entry is in a tag-like
  // section; when it isn't, the first answer switches the check off for that input.

  var DEBOUNCE_MS = 400;
  var titleState = new WeakMap();

  function editorEntry() {
    var page = history.state && history.state.page;
    if (!page || page.component !== 'elements/Edit' || !page.props || !page.props.elementId) return null;
    return { elementId: page.props.elementId, siteId: page.props.siteId || '' };
  }

  function warningFor(input) {
    var state = titleState.get(input);
    if (state.warning && state.warning.isConnected) return state.warning;
    var el = document.createElement('div');
    el.className = 'tidytags-warning';
    el.hidden = true;
    el.setAttribute('role', 'status');
    // craft-field is a web component that only renders slotted children, so the warning goes
    // after it, not inside.
    var anchor = input.closest('craft-field') || input;
    anchor.insertAdjacentElement('afterend', el);
    state.warning = el;
    return el;
  }

  function renderWarning(input, matches) {
    var node = warningFor(input);
    clearChildren(node);
    if (!matches || !matches.length) { node.hidden = true; return; }

    var heading = document.createElement('div');
    heading.className = 'tidytags-warning-heading';
    heading.textContent = 'Similar entries already exist — consider using one of these instead of creating a duplicate:';
    node.appendChild(heading);

    var list = document.createElement('ul');
    list.className = 'tidytags-warning-list';
    matches.forEach(function (m) {
      var li = document.createElement('li');
      var title = document.createElement(m.cpEditUrl ? 'a' : 'span');
      if (m.cpEditUrl) {
        title.href = m.cpEditUrl;
        title.target = '_blank';
        title.rel = 'noopener';
      }
      var strong = document.createElement('strong');
      strong.textContent = m.title;
      title.appendChild(strong);
      li.appendChild(title);
      if (m.differentiator) {
        var diff = document.createElement('span');
        diff.className = 'tidytags-diff';
        diff.textContent = ' (' + m.differentiator + ')';
        li.appendChild(diff);
      }
      var meta = document.createElement('span');
      meta.className = 'tidytags-warning-meta';
      meta.textContent = ' · in ' + (m.sourceName || '');
      li.appendChild(meta);
      list.appendChild(li);
    });
    node.appendChild(list);
    node.hidden = false;
  }

  function checkTitle(input, entry) {
    var state = titleState.get(input);
    var value = (input.value || '').trim();
    if (value.length < 2) { renderWarning(input, []); return; }

    var base = (window.Craft && window.Craft.baseCpUrl) || '';
    var url = base + '/tidytags/tags/check-duplicate?title=' + encodeURIComponent(value) +
      '&elementId=' + encodeURIComponent(entry.elementId) + '&siteId=' + encodeURIComponent(entry.siteId);

    fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
      .then(function (body) {
        if (body && body.applies === false) { state.off = true; renderWarning(input, []); return; }
        renderWarning(input, body && body.matches);
      })
      .catch(function () { state.off = true; renderWarning(input, []); });
  }

  document.addEventListener('input', function (e) {
    var input = e.target;
    if (!input.matches || !input.matches('craft-field input[name="title"], craft-field textarea[name="title"]')) return;
    if (input.closest('dialog, [role="dialog"], [aria-modal="true"]')) return;

    var entry = editorEntry();
    if (!entry) return;

    var state = titleState.get(input);
    if (!state) { state = { off: false, timer: null, warning: null }; titleState.set(input, state); }
    if (state.off) return;

    clearTimeout(state.timer);
    state.timer = setTimeout(function () { checkTitle(input, entry); }, DEBOUNCE_MS);
  });

  document.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('.tidytags-show-usages');
    if (!btn) return;
    var root = btn.closest('[data-tidytags-duplicates]');
    var list = btn.parentElement.querySelector('.tidytags-usages-list');
    if (!root || !list) return;

    if (!list.hidden) {
      list.hidden = true;
      btn.textContent = 'Show usages';
      btn.setAttribute('aria-expanded', 'false');
      return;
    }

    btn.disabled = true;
    btn.textContent = 'Loading…';
    var url = root.dataset.usagesUrl + (root.dataset.usagesUrl.indexOf('?') === -1 ? '?' : '&') +
      'elementId=' + encodeURIComponent(btn.dataset.elementId);

    fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) { renderUsages(list, j); btn.textContent = 'Hide usages'; })
      .catch(function () { usageMessage(list, 'Failed to load usages.'); btn.textContent = 'Show usages'; })
      .then(function () {
        btn.disabled = false;
        list.hidden = false;
        btn.setAttribute('aria-expanded', 'true');
      });
  });
})();
