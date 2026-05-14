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
    el.style.cssText =
      'display:none;margin-top:6px;padding:8px 10px;border-left:3px solid #e5a50a;' +
      'background:#fff8e1;border-radius:3px;font-size:12px;color:#594500;';
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
    li.style.cssText = 'padding:2px 0;';

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
      diff.style.cssText = 'color:#0b5394;margin-left:4px;';
      diff.textContent = '(' + match.differentiator + ')';
      li.appendChild(diff);
    }

    var meta = document.createElement('span');
    meta.style.cssText = 'color:#8a6e00;margin-left:6px;font-size:11px;';
    var typeLabel = match.sourceType === 'tag' ? 'Tag' : 'Entry';
    meta.appendChild(
      document.createTextNode(' · ' + typeLabel + ' in ' + (match.sourceName || ''))
    );
    li.appendChild(meta);

    if (match.displayValues) {
      var keys = Object.keys(match.displayValues);
      if (keys.length) {
        var ul = document.createElement('ul');
        ul.style.cssText =
          'margin:2px 0 0 16px;padding:0;list-style:none;color:#594500;font-size:11px;';
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
      node.style.display = 'none';
      clearChildren(node);
      return;
    }

    clearChildren(node);

    var heading = document.createElement('div');
    heading.style.cssText = 'margin-bottom:4px;';
    heading.appendChild(
      document.createTextNode(
        'Already exists — consider reusing one of these instead of creating a new tag:'
      )
    );
    node.appendChild(heading);

    var list = document.createElement('ul');
    list.style.cssText = 'margin:0;padding:0 0 0 16px;list-style:disc;';
    matches.forEach(function (m) {
      list.appendChild(renderMatch(m));
    });
    node.appendChild(list);

    node.style.display = 'block';
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
        if ($warning) $warning.fadeOut(300);
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
