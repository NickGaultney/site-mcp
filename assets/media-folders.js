/*
 * Media Folders (replaces FileBird). Folder tree beside Media > Library in grid and list view, drag files or folders
 * onto a folder, right-click (or the ... button) for New subfolder / Rename / Download / Delete, folder filter in every
 * media picker, and uploads go into the chosen folder. Server side: includes/class-media-folders.php (REST: site-mcp/v1/media-folders).
 */
(function ($) {
  'use strict';
  var D = window.smcpmfData;
  if (!D) return;

  var S = {
    tree: D.tree,                 // { folders: [{id, name, parent, ord, count}], all, uncategorized }
    current: +D.current,          // -1 All files, 0 Uncategorized, >0 folder
    upload: +D.current > 0 ? +D.current : 0,
    open: load('smcpmf_open', {}),  // expanded folders
    rev: 0                        // bumped after changes so the media grid fetches fresh results
  };
  var onLibrary = D.screen === 'upload';
  var $side = null;
  var drag = null;
  var filterViews = [];

  /* ---------- helpers ---------- */

  function load(k, d) { try { var v = window.localStorage.getItem(k); return v ? JSON.parse(v) : d; } catch (e) { return d; } }
  function store(k, v) { try { window.localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }
  function listView() { return onLibrary && !!document.getElementById('the-list'); }

  function api(path, method, body) {
    return window.fetch(D.rest + path, {
      method: method || 'GET',
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': D.nonce, 'Content-Type': 'application/json' },
      body: body ? JSON.stringify(body) : undefined
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok) throw new Error((j && j.message) || 'Something went wrong. Please try again.');
        return j;
      });
    });
  }

  function notice(msg, isError) {
    if (window.wp && wp.a11y && wp.a11y.speak) wp.a11y.speak(msg);
    if (!$side) { if (isError) window.alert(msg); return; }
    var $t = $side.find('.smcpmf-toast');
    $t.removeClass('is-error').toggleClass('is-error', !!isError).text(msg).prop('hidden', false);
    clearTimeout(notice.timer);
    notice.timer = setTimeout(function () { $t.prop('hidden', true); }, isError ? 8000 : 4000);
  }
  function fail(e) { notice(e && e.message ? e.message : 'Something went wrong. Please try again.', true); }

  /* ---------- tree data ---------- */

  function byId(id) { for (var i = 0; i < S.tree.folders.length; i++) if (S.tree.folders[i].id === id) return S.tree.folders[i]; return null; }
  function kids(pid) {
    return S.tree.folders.filter(function (f) { return f.parent === pid; })
      .sort(function (a, b) { return a.ord - b.ord || a.id - b.id; });
  }
  function descendants(id) {
    var out = [];
    (function walk(p) { kids(p).forEach(function (c) { out.push(c.id); walk(c.id); }); })(id);
    return out;
  }
  function flat() {
    var out = [];
    (function walk(p, d) { kids(p).forEach(function (c) { out.push({ id: c.id, name: c.name, depth: d }); walk(c.id, d + 1); }); })(0, 0);
    return out;
  }
  function openAncestors(id) {
    var f = byId(id);
    while (f && f.parent) { S.open[f.parent] = true; f = byId(f.parent); }
  }
  function setTree(t) {
    if (!t || !t.folders) return;
    S.tree = t;
    if (S.current > 0 && !byId(S.current)) S.current = -1;
    if (S.upload > 0 && !byId(S.upload)) S.upload = 0;
    renderTree();
    syncPickers();
  }
  var refreshTimer;
  function refresh() {
    clearTimeout(refreshTimer);
    refreshTimer = setTimeout(function () { api('tree').then(setTree).catch(function () {}); }, 300);
  }

  /* ---------- sidebar ---------- */

  var ICON = {
    folder: '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="currentColor" d="M10 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-8l-2-2z"/></svg>',
    all: '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="currentColor" d="M4 5h7v6H4zm9 0h7v6h-7zM4 13h7v6H4zm9 0h7v6h-7z"/></svg>',
    unc: '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="none" stroke="currentColor" stroke-width="2" d="M10 5H4a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1V8a1 1 0 0 0-1-1h-8z"/></svg>'
  };

  function rowHtml(o) {
    // o: { id, name, count, depth, hasKids, isOpen, kind: 'folder'|'all'|'unc'|'new' }
    var cur = S.current === o.id && o.kind !== 'new';
    var real = o.kind === 'folder';
    return '<div class="smcpmf-row' + (cur ? ' is-current' : '') + '" data-id="' + o.id + '" role="treeitem" tabindex="' + (cur ? 0 : -1) + '"' +
      ' aria-selected="' + cur + '"' + (o.hasKids ? ' aria-expanded="' + !!o.isOpen + '"' : '') +
      (real ? ' draggable="true"' : '') + ' style="--depth:' + (o.depth || 0) + '">' +
      (o.hasKids ? '<button type="button" class="smcpmf-toggle" tabindex="-1" aria-label="' + (o.isOpen ? 'Collapse' : 'Expand') + '"></button>' : '<span class="smcpmf-toggle is-empty"></span>') +
      '<span class="smcpmf-icon">' + (o.kind === 'all' ? ICON.all : o.kind === 'unc' ? ICON.unc : ICON.folder) + '</span>' +
      '<span class="smcpmf-name">' + esc(o.name) + '</span>' +
      (o.kind === 'new' ? '' : '<span class="smcpmf-count">' + o.count + '</span>') +
      (real ? '<button type="button" class="smcpmf-more" tabindex="-1" aria-label="Actions for ' + esc(o.name) + '" aria-haspopup="menu"><span aria-hidden="true">&hellip;</span></button>' : '') +
      '</div>';
  }

  function renderTree() {
    if (!$side) return;
    var focused = document.activeElement && $(document.activeElement).closest('#smcpmf-sidebar .smcpmf-row').attr('data-id');
    $side.find('.smcpmf__fixed').html(
      '<li role="none">' + rowHtml({ id: -1, name: 'All files', count: S.tree.all, kind: 'all' }) + '</li>' +
      '<li role="none">' + rowHtml({ id: 0, name: 'Uncategorized', count: S.tree.uncategorized, kind: 'unc' }) + '</li>'
    );
    var q = ($side.find('.smcpmf__search').val() || '').trim().toLowerCase();
    var hit = function (f) { return f.name.toLowerCase().indexOf(q) > -1; };
    function branch(pid, depth) {
      var html = '';
      kids(pid).forEach(function (f) {
        if (q && !hit(f) && !descendants(f.id).some(function (d) { return hit(byId(d)); })) return;
        var has = kids(f.id).length > 0;
        var isOpen = q ? true : !!S.open[f.id];
        html += '<li role="none">' + rowHtml({ id: f.id, name: f.name, count: f.count, depth: depth, hasKids: has, isOpen: isOpen, kind: 'folder' }) +
          (has && isOpen ? '<ul role="group">' + branch(f.id, depth + 1) + '</ul>' : '') + '</li>';
      });
      return html;
    }
    var html = branch(0, 0);
    $side.find('.smcpmf__tree').html(html || '<li class="smcpmf-empty" role="none">' + (q ? 'No folders match.' : 'No folders yet.') + '</li>');
    // Keep one row in the tab order.
    if (!$side.find('.smcpmf-row[tabindex="0"]').length) $side.find('.smcpmf-row').first().attr('tabindex', 0);
    if (focused) { var el = $side.find('.smcpmf-row[data-id="' + focused + '"]')[0]; if (el) { $side.find('.smcpmf-row').attr('tabindex', -1); el.setAttribute('tabindex', 0); el.focus(); } }
  }

  function buildSidebar() {
    var wrap = document.querySelector('#wpbody-content > .wrap');
    if (!wrap) return;
    document.body.classList.add('smcpmf-on');
    $side = $(
      '<aside id="smcpmf-sidebar" class="smcpmf" aria-label="Media folders"><div class="smcpmf__inner">' +
        '<div class="smcpmf__head"><h2 class="smcpmf__title">Folders</h2>' +
        '<button type="button" class="button button-small smcpmf__new">New folder</button></div>' +
        '<input type="search" class="smcpmf__search" placeholder="Search folders" aria-label="Search folders">' +
        '<ul class="smcpmf__list smcpmf__fixed" role="tree" aria-label="Library views"></ul>' +
        '<ul class="smcpmf__list smcpmf__tree" role="tree" aria-label="Folders"></ul>' +
        '<p class="smcpmf-toast" role="status" hidden></p>' +
        '<p class="smcpmf__hint">Drag files onto a folder to move them. Right-click a folder for more options.</p>' +
      '</div></aside>'
    );
    $(wrap).before($side);
    openAncestors(S.current);
    renderTree();
    bindSidebar();
    placeSidebar();
    $(window).on('resize', placeSidebar);
    $('#show-settings-link, #contextual-help-link').on('click', function () { setTimeout(placeSidebar, 400); });
  }

  function placeSidebar() {
    var wrap = document.querySelector('#wpbody-content > .wrap');
    if ($side && wrap) $side.css('top', wrap.offsetTop + 'px');
  }

  function toggle(id) {
    S.open[id] = !S.open[id];
    store('smcpmf_open', S.open);
    renderTree();
    var r = $side.find('.smcpmf-row[data-id="' + id + '"]')[0];
    if (r) r.focus();
  }

  function bindSidebar() {
    $side.on('click', '.smcpmf-row', function (e) {
      if ($(e.target).closest('.smcpmf-input').length) return;
      var id = +this.getAttribute('data-id');
      if ($(e.target).closest('.smcpmf-toggle').length && id > 0) { toggle(id); return; }
      if ($(e.target).closest('.smcpmf-more').length) { var r = e.target.getBoundingClientRect(); menu(id, r.left, r.bottom + 2); return; }
      if (this.classList.contains('is-editing')) return;
      select(id);
    });
    $side.on('contextmenu', '.smcpmf-row', function (e) {
      var id = +this.getAttribute('data-id');
      if (id <= 0 || this.classList.contains('is-editing')) return;
      e.preventDefault();
      menu(id, e.clientX, e.clientY);
    });
    $side.on('keydown', '.smcpmf-row', function (e) {
      if (this.classList.contains('is-editing')) return;
      var id = +this.getAttribute('data-id');
      var rows = $side.find('.smcpmf-row').toArray();
      var i = rows.indexOf(this);
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); select(id); }
      else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        var next = rows[i + (e.key === 'ArrowDown' ? 1 : -1)];
        if (next) { rows.forEach(function (r) { r.setAttribute('tabindex', -1); }); next.setAttribute('tabindex', 0); next.focus(); }
      }
      else if (e.key === 'ArrowRight' && id > 0 && kids(id).length && !S.open[id]) { e.preventDefault(); toggle(id); }
      else if (e.key === 'ArrowLeft' && id > 0 && S.open[id]) { e.preventDefault(); toggle(id); }
      else if (e.key === 'F2' && id > 0) { e.preventDefault(); rename(id); }
      else if (e.key === 'Delete' && id > 0) { e.preventDefault(); remove(id); }
      else if ((e.key === 'ContextMenu' || (e.shiftKey && e.key === 'F10')) && id > 0) {
        e.preventDefault();
        var b = this.getBoundingClientRect();
        menu(id, b.left + 24, b.bottom);
      }
    });
    $side.on('click', '.smcpmf__new', function () { newFolder(0); });
    $side.on('input', '.smcpmf__search', renderTree);
  }

  /* ---------- open a folder ---------- */

  function select(id) {
    S.current = id;
    S.upload = id > 0 ? id : 0;
    openAncestors(id);
    renderTree();
    syncPickers();
    if (listView()) {
      var u = new URL(window.location.href);
      u.searchParams.set('smcp_folder', id);
      u.searchParams.delete('paged');
      window.location.href = u.toString();
      return;
    }
    api('state', 'POST', { folder: id }).catch(function () {});
    applyGrid();
  }

  function gridLibrary() {
    try { return wp.media.frame.state().get('library'); } catch (e) { return null; }
  }

  function applyGrid() {
    var lib = gridLibrary();
    if (lib && lib.props) lib.props.set({ smcp_folder: S.current >= 0 ? S.current : null, smcp_rev: S.rev });
  }

  /* ---------- folder actions ---------- */

  var $menu = null;
  function closeMenu() { if ($menu) { $menu.remove(); $menu = null; } }

  function menu(id, x, y) {
    closeMenu();
    var f = byId(id);
    if (!f) return;
    $menu = $('<div class="smcpmf-menu" role="menu" aria-label="Folder actions">' +
      '<button type="button" role="menuitem" data-act="new">New subfolder</button>' +
      '<button type="button" role="menuitem" data-act="rename">Rename</button>' +
      (D.zip ? '<button type="button" role="menuitem" data-act="zip">Download as zip</button>' : '') +
      '<button type="button" role="menuitem" data-act="delete" class="is-danger">Delete</button></div>').appendTo(document.body);
    var w = $menu.outerWidth(), h = $menu.outerHeight();
    $menu.css({ left: Math.max(4, Math.min(x, window.innerWidth - w - 8)), top: Math.max(4, Math.min(y, window.innerHeight - h - 8)) });
    $menu.on('click', 'button', function () {
      var act = this.getAttribute('data-act');
      closeMenu();
      if (act === 'new') newFolder(id);
      else if (act === 'rename') rename(id);
      else if (act === 'zip') download(id);
      else if (act === 'delete') remove(id);
    });
    $menu.on('keydown', function (e) {
      var items = $menu.find('button').toArray(), i = items.indexOf(document.activeElement);
      if (e.key === 'Escape' || e.key === 'Tab') { e.preventDefault(); closeMenu(); var r = $side && $side.find('.smcpmf-row[data-id="' + id + '"]')[0]; if (r) r.focus(); }
      else if (e.key === 'ArrowDown') { e.preventDefault(); items[(i + 1) % items.length].focus(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); items[(i - 1 + items.length) % items.length].focus(); }
    });
    $menu.find('button').first().trigger('focus');
  }
  $(document).on('mousedown', function (e) { if ($menu && !$(e.target).closest('.smcpmf-menu').length) closeMenu(); });
  $(window).on('scroll resize blur', closeMenu);

  /** Swap a row's name for a text box; commit(name) returns a promise. */
  function edit($row, value, commit, onCancel) {
    var $in = $('<input type="text" class="smcpmf-input" maxlength="200" aria-label="Folder name">').val(value);
    $row.find('.smcpmf-name').replaceWith($in);
    $row.addClass('is-editing').attr('draggable', 'false');
    $in.trigger('focus').trigger('select');
    var done = false;
    function finish(save) {
      if (done) return;
      done = true;
      var v = $.trim($in.val());
      if (!save || !v || v === value) { if (onCancel) onCancel(); renderTree(); return; }
      $in.prop('disabled', true);
      commit(v).catch(function (e) { fail(e); renderTree(); });
    }
    $in.on('keydown', function (e) {
      e.stopPropagation();
      if (e.key === 'Enter') { e.preventDefault(); finish(true); }
      else if (e.key === 'Escape') { e.preventDefault(); finish(false); }
    });
    $in.on('blur', function () { finish(true); });
    $in.on('mousedown click dragstart', function (e) { e.stopPropagation(); });
  }

  function newFolder(parent) {
    if (!$side) {
      var n = window.prompt('Folder name');
      if (n) api('folders', 'POST', { name: n, parent: parent }).then(function (res) { setTree(res.tree); }).catch(fail);
      return;
    }
    $side.find('.smcpmf__search').val('');
    if (parent > 0) { S.open[parent] = true; store('smcpmf_open', S.open); }
    renderTree();
    var depth = 0, $ul;
    if (parent > 0) {
      var $prow = $side.find('.smcpmf-row[data-id="' + parent + '"]');
      depth = (+$prow[0].style.getPropertyValue('--depth') || 0) + 1;
      $ul = $prow.siblings('ul');
      if (!$ul.length) $ul = $('<ul role="group"></ul>').insertAfter($prow);
    } else {
      $ul = $side.find('.smcpmf__tree');
      $ul.find('.smcpmf-empty').remove();
    }
    var $li = $('<li role="none">' + rowHtml({ id: -99, name: '', depth: depth, kind: 'new' }) + '</li>').appendTo($ul);
    $li[0].scrollIntoView({ block: 'nearest' });
    edit($li.find('.smcpmf-row'), '', function (name) {
      return api('folders', 'POST', { name: name, parent: parent }).then(function (res) {
        setTree(res.tree);
        var f = byId(res.id);
        notice('Folder “' + (f ? f.name : name) + '” created.');
      });
    });
  }

  function rename(id) {
    var f = byId(id);
    if (!f) return;
    if (!$side) {
      var n = window.prompt('Rename folder', f.name);
      if (n && n !== f.name) api('folders/' + id, 'POST', { name: n }).then(function (res) { setTree(res.tree); }).catch(fail);
      return;
    }
    edit($side.find('.smcpmf-row[data-id="' + id + '"]'), f.name, function (name) {
      return api('folders/' + id, 'POST', { name: name }).then(function (res) { setTree(res.tree); notice('Folder renamed.'); });
    });
  }

  function remove(id) {
    var f = byId(id);
    if (!f) return;
    var subs = descendants(id);
    var files = [id].concat(subs).reduce(function (n, i) { var x = byId(i); return n + (x ? x.count : 0); }, 0);
    var msg = 'Delete the folder “' + f.name + '”' + (subs.length ? ' and its ' + plural(subs.length, 'subfolder', 'subfolders') : '') + '?\n\n' +
      (files ? 'The ' + plural(files, 'file', 'files') + ' inside will not be deleted. They move to Uncategorized.' : 'It has no files in it.');
    if (!window.confirm(msg)) return;
    var gone = [id].concat(subs);
    api('folders/' + id, 'DELETE').then(function (res) {
      S.rev++;
      setTree(res.tree);
      notice('Folder deleted.' + (files ? ' Its files are now in Uncategorized.' : ''));
      if (gone.indexOf(S.current) > -1) select(-1);
      else if (listView()) window.location.reload();
      else applyGrid();
    }).catch(fail);
  }

  function download(id) {
    window.location.href = D.ajax + '?action=smcpmf_download&folder=' + id + '&_wpnonce=' + encodeURIComponent(D.zip);
  }

  /* ---------- moving files ---------- */

  function moveFiles(ids, folder) {
    ids = ids.map(Number).filter(Boolean);
    if (!ids.length) return;
    api('assign', 'POST', { folder: folder, ids: ids }).then(function (res) {
      S.rev++;
      setTree(res.tree);
      var f = byId(folder);
      notice(plural(res.moved, 'file', 'files') + ' moved to ' + (folder > 0 && f ? '“' + f.name + '”' : 'Uncategorized') + '.');
      var leaves = S.current !== -1 && S.current !== folder;
      if (listView()) {
        ids.forEach(function (id) {
          var $tr = $('#post-' + id);
          if (leaves) $tr.fadeOut(200, function () { $tr.remove(); });
          else $tr.find('td.column-smcpmf_folder').html(folder > 0 && f ? '<a href="?mode=list&amp;smcp_folder=' + folder + '">' + esc(f.name) + '</a>' : '<a href="?mode=list&amp;smcp_folder=0">Uncategorized</a>');
          $tr.find('input[type="checkbox"]').prop('checked', false);
        });
        return;
      }
      var lib = gridLibrary();
      ids.forEach(function (id) {
        var m = wp.media.attachment(id);
        if (!m) return;
        m.set('smcpFolder', folder);
        if (lib && leaves) lib.remove(m);
      });
      try { var sel = wp.media.frame.state().get('selection'); if (sel) sel.reset(); } catch (e) {}
    }).catch(fail);
  }

  /* ---------- drag and drop ---------- */

  // Media items: the tile (grid) or row (list) is the drag source; its image is not, so the browser
  // does not treat it as a file drag (which would open WordPress's "Drop files to upload" overlay).
  $(document).on('mouseover', '.attachments-browser li.attachment, #the-list tr[id^="post-"]', function () {
    if (!onLibrary) return;
    if (!this.hasAttribute('draggable')) this.setAttribute('draggable', 'true');
    $(this).find('img, a').attr('draggable', 'false');
  });

  document.addEventListener('dragstart', function (e) {
    if (!onLibrary || !(e.target instanceof Element)) return;
    var t = e.target;
    var frow = t.closest('#smcpmf-sidebar .smcpmf-row[draggable="true"]');
    if (frow) {
      drag = { type: 'folder', id: +frow.getAttribute('data-id') };
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', 'folder:' + drag.id);
      setTimeout(function () { frow.classList.add('is-dragging'); }, 0);
      document.body.classList.add('smcpmf-dragging');
      return;
    }
    var li = t.closest('.attachments-browser li.attachment');
    var tr = t.closest('#the-list tr[id^="post-"]');
    var ids = [];
    if (li) {
      var id = +li.getAttribute('data-id');
      ids = [id];
      try {
        var sel = wp.media.frame.state().get('selection');
        if (sel && sel.get(id) && sel.length > 1) ids = sel.pluck('id');
      } catch (err) {}
    } else if (tr) {
      var rid = +tr.id.replace('post-', '');
      var checked = $('#the-list input[name="media[]"]:checked').map(function () { return +this.value; }).get();
      ids = checked.indexOf(rid) > -1 ? checked : [rid];
    } else {
      return;
    }
    drag = { type: 'files', ids: ids };
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', ids.join(','));
    var ghost = document.createElement('div');
    ghost.className = 'smcpmf-ghost';
    ghost.textContent = 'Move ' + plural(ids.length, 'file', 'files');
    document.body.appendChild(ghost);
    e.dataTransfer.setDragImage(ghost, 12, 12);
    setTimeout(function () { ghost.remove(); }, 0);
    document.body.classList.add('smcpmf-dragging');
  });

  function clearDrop() { if ($side) $side.find('.is-drop, .is-before, .is-after').removeClass('is-drop is-before is-after'); }
  document.addEventListener('dragend', function () {
    drag = null;
    document.body.classList.remove('smcpmf-dragging');
    clearDrop();
    if ($side) $side.find('.is-dragging').removeClass('is-dragging');
  });

  function zone(row, e) {
    var id = +row.getAttribute('data-id');
    if (!drag || drag.type !== 'folder' || id <= 0) return 'inside';
    var r = row.getBoundingClientRect(), y = e.clientY - r.top;
    return y < r.height * 0.28 ? 'before' : y > r.height * 0.72 ? 'after' : 'inside';
  }
  function canDrop(row) {
    var id = +row.getAttribute('data-id');
    if (!drag || id === -99) return false;
    if (drag.type === 'files') return id >= 0;          // a folder or Uncategorized
    if (id === drag.id || id === 0) return false;        // folders cannot go into Uncategorized
    if (id === -1) return true;                          // "All files": move to the top level
    return descendants(drag.id).indexOf(id) === -1;      // not into its own subfolder
  }

  $(document).on('dragover', '#smcpmf-sidebar .smcpmf-row', function (ev) {
    var e = ev.originalEvent;
    if (!canDrop(this)) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    var z = zone(this, e);
    clearDrop();
    this.classList.add(z === 'inside' ? 'is-drop' : 'is-' + z);
  });
  $(document).on('dragleave', '#smcpmf-sidebar .smcpmf-row', function (ev) {
    if (!this.contains(ev.originalEvent.relatedTarget)) this.classList.remove('is-drop', 'is-before', 'is-after');
  });
  $(document).on('drop', '#smcpmf-sidebar .smcpmf-row', function (ev) {
    var e = ev.originalEvent;
    if (!canDrop(this)) return;
    e.preventDefault();
    var id = +this.getAttribute('data-id'), z = zone(this, e), d = drag;
    drag = null;
    clearDrop();
    document.body.classList.remove('smcpmf-dragging');
    if (d.type === 'files') { moveFiles(d.ids, id); return; }
    var parent, index;
    if (id === -1) {
      parent = 0;
      index = kids(0).filter(function (f) { return f.id !== d.id; }).length;
    } else if (z === 'inside') {
      parent = id;
      index = kids(id).filter(function (f) { return f.id !== d.id; }).length;
      S.open[id] = true;
      store('smcpmf_open', S.open);
    } else {
      var target = byId(id);
      parent = target.parent;
      var sib = kids(parent).filter(function (f) { return f.id !== d.id; });
      index = sib.map(function (f) { return f.id; }).indexOf(id) + (z === 'after' ? 1 : 0);
    }
    api('folders/' + d.id, 'POST', { parent: parent, index: index }).then(function (res) { setTree(res.tree); }).catch(fail);
  });

  /* ---------- folder pickers (upload, media pickers) ---------- */

  function optionsHtml(first, selected) {
    return '<option value="0"' + (selected === 0 ? ' selected' : '') + '>' + esc(first) + '</option>' +
      flat().map(function (f) {
        return '<option value="' + f.id + '"' + (selected === f.id ? ' selected' : '') + '>' + new Array(f.depth + 1).join('   ') + esc(f.name) + '</option>';
      }).join('');
  }

  function syncPickers() {
    $('.smcpmf-upload-folder').each(function () { this.innerHTML = optionsHtml('Uncategorized', S.upload); });
    filterViews = filterViews.filter(function (v) { return document.body.contains(v.el); });
    filterViews.forEach(function (v) { v.rebuild(); });
  }
  $(document).on('change', '.smcpmf-upload-folder', function () { S.upload = +this.value || 0; });
  $(document).on('focusin', '.smcpmf-upload-folder', function () { var v = +this.value; this.innerHTML = optionsHtml('Uncategorized', v); });

  // Uploads carry the chosen folder (Media Library grid, media pickers).
  if (window.wp && wp.Uploader) {
    var origInit = wp.Uploader.prototype.init;
    wp.Uploader.prototype.init = function () {
      if (origInit) origInit.apply(this, arguments);
      var up = this.uploader;
      if (!up || !up.bind) return;
      up.bind('BeforeUpload', function (u) {
        u.settings.multipart_params = u.settings.multipart_params || {};
        u.settings.multipart_params.smcp_folder = S.upload || 0;
      });
      up.bind('UploadComplete', function () { S.rev++; refresh(); });
    };
  }

  // Folder filter in media pickers (Add Media, Featured image, ACF image fields...). The Media Library itself uses the sidebar.
  if (window.wp && wp.media && wp.media.view && wp.media.view.AttachmentFilters && wp.media.view.AttachmentsBrowser) {
    var Filters = wp.media.view.AttachmentFilters;
    var FolderFilter = Filters.extend({
      id: 'media-attachment-smcpmf-filter',
      className: 'attachment-filters smcpmf-filter',
      createFilters: function () {
        var filters = {
          all: { text: 'All folders', props: { smcp_folder: null }, priority: 1 },
          f0: { text: 'Uncategorized', props: { smcp_folder: 0 }, priority: 2 }
        };
        flat().forEach(function (f, i) {
          filters['f' + f.id] = { text: new Array(f.depth + 1).join('   ') + esc(f.name), props: { smcp_folder: f.id }, priority: 3 + i };
        });
        this.filters = filters;
      },
      initialize: function () {
        Filters.prototype.initialize.apply(this, arguments);
        filterViews.push(this);
      },
      rebuild: function () {
        this.createFilters();
        this.$el.html(_.chain(this.filters).map(function (filter, value) {
          return { el: $('<option></option>').val(value).html(filter.text)[0], priority: filter.priority || 50 };
        }).sortBy('priority').pluck('el').value());
        this.select();
      },
      change: function () {
        Filters.prototype.change.apply(this, arguments);
        var v = this.model.get('smcp_folder');
        S.upload = v > 0 ? v : 0;
        $('.smcpmf-upload-folder').val(String(S.upload));
      }
    });
    var Browser = wp.media.view.AttachmentsBrowser;
    wp.media.view.AttachmentsBrowser = Browser.extend({
      createToolbar: function () {
        Browser.prototype.createToolbar.apply(this, arguments);
        if (onLibrary || !this.options.filters || !this.collection || !this.collection.props) return;
        this.toolbar.set('smcpmfFilterLabel', new wp.media.view.Label({
          value: 'Filter by folder', attributes: { 'for': 'media-attachment-smcpmf-filter' }, priority: -76
        }).render());
        this.toolbar.set('smcpmfFilter', new FolderFilter({ controller: this.controller, model: this.collection.props, priority: -75 }).render());
      }
    });
  }

  // Media Library grid: open the remembered folder from the first query.
  if (onLibrary && window._wpMediaGridSettings && window._wpMediaGridSettings.queryVars && S.current >= 0) {
    window._wpMediaGridSettings.queryVars.smcp_folder = S.current;
  }

  /* ---------- start ---------- */

  $(function () {
    if (onLibrary) buildSidebar();
    syncPickers();

    // Media > Add New (plupload without wp.Uploader).
    if (window.uploader && window.uploader.bind && !(window.wp && wp.Uploader && window.uploader instanceof wp.Uploader)) {
      window.uploader.bind('BeforeUpload', function (u) {
        u.settings.multipart_params = u.settings.multipart_params || {};
        u.settings.multipart_params.smcp_folder = S.upload || 0;
      });
    }

    // Counts change when a file's Folder field is saved, or files are deleted.
    $(document).ajaxSuccess(function (e, xhr, opts) {
      var data = opts && typeof opts.data === 'string' ? opts.data : '';
      if (/(^|&)action=(save-attachment-compat|delete-post|save-attachment)(&|$)/.test(data)) { S.rev++; refresh(); }
    });
  });
})(jQuery);
