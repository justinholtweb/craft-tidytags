/* global Craft, $ */
(function () {
  'use strict';

  if (typeof Craft === 'undefined') {
    return;
  }

  var DEBOUNCE_MS = 400;
  var ENDPOINT = 'tidytags/tags/check-duplicate';

  function debounce(fn, wait) {
    var t;
    return function () {
      var ctx = this;
      var args = arguments;
      clearTimeout(t);
      t = setTimeout(function () {
        fn.apply(ctx, args);
      }, wait);
    };
  }

  function findGroupId($container) {
    var settingsAttr = $container.attr('data-settings');
    if (settingsAttr) {
      try {
        var parsed = JSON.parse(settingsAttr);
        if (parsed && parsed.tagGroupId) {
          return parsed.tagGroupId;
        }
        if (parsed && parsed.sources && parsed.sources.length) {
          var m = String(parsed.sources[0]).match(/taggroup:(\d+)/i);
          if (m) return parseInt(m[1], 10);
        }
      } catch (e) {
        // ignore
      }
    }
    var $hidden = $container.find('input[name*="groupId"]');
    if ($hidden.length) {
      return parseInt($hidden.val(), 10) || null;
    }
    return null;
  }

  function ensureWarningElement($input) {
    var $warning = $input.data('tidytagsWarning');
    if ($warning && $warning.length) {
      return $warning;
    }
    var el = document.createElement('div');
    el.className = 'tidytags-warning';
    el.hidden = true;
    el.setAttribute('role', 'status');
    $warning = $(el);
    $input.after($warning);
    $input.data('tidytagsWarning', $warning);
    return $warning;
  }

  function clearChildren(node) {
    while (node.firstChild) node.removeChild(node.firstChild);
  }

  function renderMatch(match) {
    var li = document.createElement('li');

    var titleNode;
    if (match.cpEditUrl) {
      titleNode = document.createElement('a');
      titleNode.href = match.cpEditUrl;
      titleNode.target = '_blank';
      titleNode.rel = 'noopener';
    } else {
      titleNode = document.createElement('span');
    }
    var strong = document.createElement('strong');
    strong.textContent = match.title;
    titleNode.appendChild(strong);
    li.appendChild(titleNode);

    if (match.differentiator) {
      var diff = document.createElement('span');
      diff.className = 'tidytags-diff';
      diff.textContent = '(' + match.differentiator + ')';
      li.appendChild(diff);
    }

    var meta = document.createElement('span');
    meta.className = 'tidytags-warning-meta';
    var typeLabel = match.sourceType === 'tag' ? 'Tag' : 'Entry';
    meta.appendChild(
      document.createTextNode(' · ' + typeLabel + ' in ' + (match.sourceName || ''))
    );
    li.appendChild(meta);

    if (match.displayValues) {
      var keys = Object.keys(match.displayValues);
      if (keys.length) {
        var ul = document.createElement('ul');
        ul.className = 'tidytags-display';
        keys.forEach(function (k) {
          var item = document.createElement('li');
          var b = document.createElement('strong');
          b.textContent = k + ':';
          item.appendChild(b);
          item.appendChild(document.createTextNode(' ' + match.displayValues[k]));
          ul.appendChild(item);
        });
        li.appendChild(ul);
      }
    }

    return li;
  }

  function renderWarning($warning, matches) {
    var node = $warning.get(0);
    if (!node) return;

    if (!matches || !matches.length) {
      node.hidden = true;
      clearChildren(node);
      return;
    }

    clearChildren(node);

    var heading = document.createElement('div');
    heading.className = 'tidytags-warning-heading';
    heading.appendChild(
      document.createTextNode(
        'Already exists — consider reusing one of these instead of creating a new tag:'
      )
    );
    node.appendChild(heading);

    var list = document.createElement('ul');
    list.className = 'tidytags-warning-list';
    matches.forEach(function (m) {
      list.appendChild(renderMatch(m));
    });
    node.appendChild(list);

    node.hidden = false;
  }

  var checkTitle = debounce(function ($input, groupId, siteId) {
    var value = ($input.val() || '').toString().trim();
    var $warning = ensureWarningElement($input);
    if (value.length < 2) {
      renderWarning($warning, []);
      return;
    }

    var data = { title: value };
    if (groupId) data.groupId = groupId;
    if (siteId) data.siteId = siteId;

    Craft.sendActionRequest('GET', ENDPOINT, { params: data })
      .then(function (response) {
        var body = response && response.data ? response.data : response;
        if (body && body.matches) {
          renderWarning($warning, body.matches);
        }
      })
      .catch(function () {
        renderWarning($warning, []);
      });
  }, DEBOUNCE_MS);

  function attach($container) {
    if ($container.data('tidytagsAttached')) {
      return;
    }
    $container.data('tidytagsAttached', true);

    var groupId = findGroupId($container);
    var siteId = $container.data('siteId') || null;

    var $input = $container.find('input.text').first();
    if (!$input.length) {
      return;
    }

    $input.on('input.tidytags', function () {
      checkTitle($input, groupId, siteId);
    });

    $input.on('blur.tidytags', function () {
      setTimeout(function () {
        var $warning = $input.data('tidytagsWarning');
        if ($warning && $warning.length) $warning.get(0).hidden = true;
      }, 200);
    });
  }

  function scan(root) {
    var $root = $(root || document);
    $root.find('.tagselect, .elementselect[data-single="false"]').each(function () {
      attach($(this));
    });
    $root.find('[data-type="craft\\\\fields\\\\Tags"], .field[data-type*="Tags"]').each(function () {
      attach($(this));
    });
  }

  $(function () {
    scan(document);

    var observer = new MutationObserver(function (mutations) {
      mutations.forEach(function (m) {
        m.addedNodes &&
          Array.prototype.forEach.call(m.addedNodes, function (node) {
            if (node.nodeType === 1) {
              scan(node);
            }
          });
      });
    });
    observer.observe(document.body, { childList: true, subtree: true });
  });
})();
