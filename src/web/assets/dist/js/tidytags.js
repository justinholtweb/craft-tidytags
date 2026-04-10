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
    $warning = $(
      '<div class="tidytags-warning" style="display:none;margin-top:6px;padding:6px 10px;border-left:3px solid #e5a50a;background:#fff8e1;border-radius:3px;font-size:12px;color:#594500;"></div>'
    );
    $input.after($warning);
    $input.data('tidytagsWarning', $warning);
    return $warning;
  }

  function renderWarning($warning, matches) {
    if (!matches || !matches.length) {
      $warning.hide().empty();
      return;
    }
    var titles = matches
      .map(function (m) {
        return '<strong>' + escapeHtml(m.title) + '</strong>';
      })
      .join(', ');
    $warning
      .html(
        'Did you mean: ' +
          titles +
          '? <span style="opacity:.7">(similar tags already exist)</span>'
      )
      .show();
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
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
