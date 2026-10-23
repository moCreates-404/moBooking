/*
 * moBooking front-end grid interactions (vanilla JS, no framework, no build step).
 *
 * v1.2.1 FINAL tap flow:
 *  - Touch (pointer:coarse): tap a free slot → ONE light-yellow pre-select; tap the
 *    SAME slot again → dark-yellow selected (committed). Tapping another free slot
 *    moves the light highlight (never promotes the old one). Tap a dark slot →
 *    removes it. Multiple dark slots, any lane / any time; adjacent dark slots in a
 *    lane merge into one run (rebuild). Scrolls, swipes, outside-taps and taps on
 *    booked/closed cells never change dark slots; an outside-tap only clears light.
 *  - Mouse (desktop): click to select, drag to select several (as before).
 *
 * Committed slots feed the existing multi-run summary + Add to cart; on <=768px a
 * sticky mini-bar mirrors "N slots · $X · Add to cart".
 *
 * Supports multiple [mclb_grid] instances on one page (each wrap is independent).
 */
(function () {
  'use strict';

  var CFG = window.mclbGrid || {};
  var I18N = CFG.i18n || {};
  var fmtMoney = function (n) { return (CFG.currency || '$') + Number(n || 0).toFixed(2); };
  var TAP_SLOP = 10; // px of movement still counted as a tap
  var COARSE = !!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches);

  function ready(fn) {
    if (document.readyState !== 'loading') { fn(); }
    else { document.addEventListener('DOMContentLoaded', fn); }
  }

  ready(function () {
    document.querySelectorAll('.mclb-grid-wrap').forEach(function (wrap) {
      new MclbGrid(wrap);
    });
  });

  function MclbGrid(wrap) {
    this.wrap = wrap;
    this.type = wrap.getAttribute('data-type') || '';
    this.grid = wrap.querySelector('.mclb-grid');
    this.scrollWrap = wrap.querySelector('.mclb-grid__scroll');
    this.selectionBox = wrap.querySelector('.mclb-selection');
    this.list = wrap.querySelector('.mclb-selection__list');
    this.totalEl = wrap.querySelector('.mclb-selection__total');
    this.addBtn = wrap.querySelector('.mclb-add-to-cart');
    this.msg = wrap.querySelector('.mclb-selection__msg');
    this.minibar = wrap.querySelector('.mclb-grid__minibar');
    this.notice = wrap.querySelector('.mclb-grid__notice');
    this.noticeTimer = null;
    this.dateInput = wrap.querySelector('.mclb-grid__date');
    this.selections = [];      // committed runs (derived from --selected cells)
    this.light = null;         // the single touch pre-select cell, or null
    this.drag = null;          // active MOUSE drag
    this.touch = null;         // pending TOUCH gesture
    this.scrolledOnce = false;
    this.refreshing = false;   // a fresh-availability fetch is in flight
    this.visible = false;      // grid is on-screen (drawer open / in viewport)
    this.poll = null;
    this.setLoading(true);     // server-rendered cells may be cache-stale: lock until fresh
    this.bind();
    this.setInstructions();
    this.render();
    this.rebuild();
    this.watchVisibility();    // triggers the first fresh fetch when visible
    wrap.mclbGridInstance = this; // expose for console debugging on the live site
  }

  // The server-rendered first day can be frozen in page cache (WP Rocket), so
  // never trust it: pull fresh availability whenever the grid becomes visible
  // (incl. the drawer opening), when the tab regains focus, and every 60s while
  // visible. Cells stay non-interactive (is-loading) until a fresh day lands.
  MclbGrid.prototype.watchVisibility = function () {
    var self = this;
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden && self.visible) { self.refresh(); }
    });
    if (window.IntersectionObserver) {
      this.io = new IntersectionObserver(function (entries) {
        var vis = !!(entries[0] && entries[0].isIntersecting);
        if (vis && !self.visible) { self.visible = true; self.refresh(); self.startPolling(); }
        else if (!vis && self.visible) { self.visible = false; self.stopPolling(); }
      }, { threshold: 0.01 });
      this.io.observe(this.wrap);
    } else {
      this.visible = true; this.refresh(); this.startPolling();
    }
  };
  MclbGrid.prototype.startPolling = function () {
    var self = this;
    this.stopPolling();
    this.poll = setInterval(function () { if (self.visible && !document.hidden) { self.refresh(); } }, 60000);
  };
  MclbGrid.prototype.stopPolling = function () { if (this.poll) { clearInterval(this.poll); this.poll = null; } };
  MclbGrid.prototype.setLoading = function (on) { this.wrap.classList.toggle('is-loading', !!on); };

  // Pick the instruction text by input type (touch vs mouse).
  MclbGrid.prototype.setInstructions = function () {
    var el = this.wrap.querySelector('.mclb-grid__instr');
    if (el) { el.textContent = COARSE ? (I18N.instrTouch || '') : (I18N.instrMouse || ''); }
  };

  MclbGrid.prototype.bind = function () {
    var self = this;

    // Pointer interactions bound to the GRID (mouse drag uses pointer capture so
    // its up still lands here if the cursor leaves the grid).
    this.grid.addEventListener('pointerdown', function (e) { self.onPointerDown(e); });
    this.grid.addEventListener('pointermove', function (e) { self.onPointerMove(e); });
    this.grid.addEventListener('pointerup', function (e) { self.onPointerUp(e); });
    this.grid.addEventListener('pointercancel', function () { self.onPointerCancel(); });

    // A genuine click OUTSIDE the grid (and not on the summary / mini-bar) clears
    // the LIGHT pre-select only — never the dark selection. A click never fires on
    // a scroll, so scrolling the page won't clear anything.
    document.addEventListener('click', function (e) {
      if (!self.light) { return; }
      if (self.grid.contains(e.target)) { return; }
      if (self.selectionBox && self.selectionBox.contains(e.target)) { return; }
      if (self.minibar && self.minibar.contains(e.target)) { return; }
      self.clearLight();
    });

    // Scroll affordance.
    if (this.grid) { this.grid.addEventListener('scroll', function () { self.updateScrollHint(); }); }
    window.addEventListener('resize', function () { self.updateScrollHint(); });
    if (window.ResizeObserver && this.grid) {
      this.resizeObs = new ResizeObserver(function () { self.updateScrollHint(); });
      this.resizeObs.observe(this.grid);
    }

    // Date navigation.
    var prev = this.wrap.querySelector('.mclb-nav--prev');
    var next = this.wrap.querySelector('.mclb-nav--next');
    if (prev) { prev.addEventListener('click', function () { self.shiftDate(-1); }); }
    if (next) { next.addEventListener('click', function () { self.shiftDate(1); }); }
    if (this.dateInput) {
      this.dateInput.addEventListener('change', function () { self.loadDate(self.dateInput.value); });
    }

    // Coach note reveal (summary).
    var coachToggle = this.wrap.querySelector('.mclb-coach__toggle');
    var coachNote = this.wrap.querySelector('.mclb-coach__note');
    if (coachToggle && coachNote) {
      coachToggle.addEventListener('change', function () { coachNote.hidden = !coachToggle.checked; });
    }

    if (this.addBtn) { this.addBtn.addEventListener('click', function () { self.addToCart(); }); }
    if (this.minibar) {
      this.minibar.addEventListener('click', function (e) {
        if (e.target.closest('.mclb-grid__minibar-add')) { self.addToCart(); }
        else if (e.target.closest('.mclb-grid__minibar-clear')) { self.clearAll(); }
      });
    }
  };

  // ── Cell helpers ──────────────────────────────────────────────────────────

  function cellFrom(e) {
    return (e.target && e.target.closest) ? e.target.closest('.mclb-cell') : null;
  }
  // During a captured mouse drag, e.target is the grid (pointer capture), so the
  // cell under the pointer must be found by coordinates instead.
  function cellAtPoint(e) {
    var el = document.elementFromPoint(e.clientX, e.clientY);
    return (el && el.closest) ? el.closest('.mclb-cell') : null;
  }
  MclbGrid.prototype.isAvailable = function (cell) {
    return cell && cell.getAttribute('data-state') === 'available';
  };
  MclbGrid.prototype.isSelected = function (cell) {
    return cell && cell.classList.contains('mclb-cell--selected');
  };
  MclbGrid.prototype.laneCells = function (laneId) {
    var cells = this.grid.querySelectorAll('.mclb-cell--available[data-lane="' + laneId + '"]');
    return Array.prototype.slice.call(cells).sort(function (a, b) {
      return (+a.getAttribute('data-start-min')) - (+b.getAttribute('data-start-min'));
    });
  };
  // Contiguous run of available cells from anchor toward target (inclusive).
  MclbGrid.prototype.run = function (anchor, target) {
    if (anchor.getAttribute('data-lane') !== target.getAttribute('data-lane')) { return [anchor]; }
    var cells = this.laneCells(anchor.getAttribute('data-lane'));
    var ai = cells.indexOf(anchor), ti = cells.indexOf(target);
    if (ai === -1 || ti === -1) { return [anchor]; }
    var lo = Math.min(ai, ti), hi = Math.max(ai, ti);
    var out = [];
    for (var i = lo; i <= hi; i++) {
      if (out.length) {
        var prevEnd = +out[out.length - 1].getAttribute('data-end-min');
        if (+cells[i].getAttribute('data-start-min') !== prevEnd) { break; }
      }
      out.push(cells[i]);
    }
    return out;
  };

  MclbGrid.prototype.clearPaint = function (cls) {
    this.grid.querySelectorAll('.' + cls).forEach(function (c) { c.classList.remove(cls); });
  };
  MclbGrid.prototype.paint = function (cells, cls) {
    cells.forEach(function (c) { c.classList.add(cls); });
  };

  // ── Pointer ───────────────────────────────────────────────────────────────
  //
  // Touch: no preventDefault, so the browser pans natively; the genuine-tap test
  // on pointerup decides whether to act. Mouse: drag-select (deselect gesture from
  // a selected cell), a click without a drag is a single select.

  MclbGrid.prototype.onPointerDown = function (e) {
    if (this.adding) { return; } // grid locked while an add-to-cart is in flight
    var cell = cellFrom(e);
    if (e.pointerType === 'touch' || e.pointerType === 'pen') {
      this.touch = { cell: cell, x: e.clientX, y: e.clientY, sl: this.grid.scrollLeft, st: this.grid.scrollTop };
      return;
    }
    if (!cell) { return; }
    if (this.isSelected(cell)) {
      e.preventDefault();
      try { this.grid.setPointerCapture(e.pointerId); } catch (ex) {}
      this.drag = { mode: 'deselect' };
      cell.classList.remove('mclb-cell--selected');
      this.rebuild();
      return;
    }
    if (!this.isAvailable(cell)) { return; }
    e.preventDefault();
    try { this.grid.setPointerCapture(e.pointerId); } catch (ex) {}
    this.drag = { mode: 'select', anchor: cell };
    this.clearPaint('mclb-cell--selecting');
    this.paint([cell], 'mclb-cell--selecting');
  };

  MclbGrid.prototype.onPointerMove = function (e) {
    if (!this.drag) { return; }
    var cell = cellAtPoint(e); // captured drag → find the cell by coordinates
    if (!cell) { return; }
    if (this.drag.mode === 'deselect') {
      if (this.isSelected(cell)) { cell.classList.remove('mclb-cell--selected'); this.rebuild(); }
      return;
    }
    if (!this.isAvailable(cell)) { return; }
    this.clearPaint('mclb-cell--selecting');
    this.paint(this.run(this.drag.anchor, cell), 'mclb-cell--selecting');
  };

  MclbGrid.prototype.onPointerUp = function (e) {
    // TOUCH: only a genuine tap (no move, no scroll) on the same cell acts.
    if (this.touch) {
      var t = this.touch; this.touch = null;
      var moved = Math.abs(e.clientX - t.x) > TAP_SLOP || Math.abs(e.clientY - t.y) > TAP_SLOP;
      var scrolled = Math.abs(this.grid.scrollLeft - t.sl) > 2 || Math.abs(this.grid.scrollTop - t.st) > 2;
      if (moved || scrolled) { return; }
      var tc = cellFrom(e);
      if (tc && tc === t.cell) { this.touchTap(tc); }
      return;
    }
    // MOUSE: finish a drag.
    if (this.drag) {
      var mode = this.drag.mode; this.drag = null;
      try { this.grid.releasePointerCapture(e.pointerId); } catch (ex) {}
      if (mode === 'select') {
        var cells = Array.prototype.slice.call(this.grid.querySelectorAll('.mclb-cell--selecting'));
        if (cells.length) { this.commit(cells); } else { this.clearPaint('mclb-cell--selecting'); }
      }
    }
  };

  MclbGrid.prototype.onPointerCancel = function () {
    this.touch = null;
    if (this.drag && this.drag.mode === 'select') { this.clearPaint('mclb-cell--selecting'); }
    this.drag = null;
  };

  // ── Touch two-tap: light → dark, single light, tap-dark-to-remove ────────────
  MclbGrid.prototype.touchTap = function (cell) {
    if (!this.isAvailable(cell)) { return; } // booked/closed/na never change anything
    if (cell === this.light) {                // tap the SAME light cell → promote to dark
      this.light = null;
      cell.classList.remove('mclb-cell--selecting');
      cell.classList.add('mclb-cell--selected');
      this.rebuild();
      return;
    }
    if (this.isSelected(cell)) {               // tap a dark cell → remove it
      cell.classList.remove('mclb-cell--selected');
      this.rebuild();
      return;
    }
    // move the single light pre-select to this cell (never promotes the old)
    if (this.light) { this.light.classList.remove('mclb-cell--selecting'); }
    this.light = cell;
    cell.classList.add('mclb-cell--selecting');
  };

  MclbGrid.prototype.clearLight = function () {
    if (this.light) { this.light.classList.remove('mclb-cell--selecting'); this.light = null; }
  };
  MclbGrid.prototype.clearAll = function () {
    this.clearLight();
    this.clearPaint('mclb-cell--selected');
    this.clearPaint('mclb-cell--selecting');
    this.rebuild();
  };

  // ── Selection: the grid's --selected cells are the source of truth ───────────
  MclbGrid.prototype.commit = function (cells) {
    this.clearPaint('mclb-cell--selecting');
    if (!cells || !cells.length) { return; }
    this.paint(cells, 'mclb-cell--selected');
    this.rebuild();
  };

  // Derive this.selections from the --selected cells, grouped per lane into
  // contiguous runs (adjacent dark slots in one lane merge into one booking).
  MclbGrid.prototype.rebuild = function () {
    var self = this;
    var byLane = {};
    this.grid.querySelectorAll('.mclb-cell--selected').forEach(function (c) {
      var lane = c.getAttribute('data-lane');
      (byLane[lane] = byLane[lane] || []).push(c);
    });

    var selections = [];
    Object.keys(byLane).forEach(function (lane) {
      var cells = byLane[lane].sort(function (a, b) {
        return (+a.getAttribute('data-start-min')) - (+b.getAttribute('data-start-min'));
      });
      var run = [];
      var flush = function () {
        if (!run.length) { return; }
        var first = run[0], last = run[run.length - 1];
        var price = parseFloat(first.getAttribute('data-price')) || 0;
        var date = self.wrap.getAttribute('data-date');
        var laneId = parseInt(lane, 10);
        var start = first.getAttribute('data-start'), end = last.getAttribute('data-end');
        selections.push({
          laneId: laneId, laneName: first.getAttribute('data-lane-name') || '', date: date,
          startMin: +first.getAttribute('data-start-min'), endMin: +last.getAttribute('data-end-min'),
          start: start, end: end, slots: run.length, price: price, total: price * run.length,
          cells: run.slice(), key: date + '|' + laneId + '|' + start + '|' + end // date|lane|times (week view)
        });
        run = [];
      };
      cells.forEach(function (c) {
        if (run.length && +c.getAttribute('data-start-min') !== +run[run.length - 1].getAttribute('data-end-min')) { flush(); }
        run.push(c);
      });
      flush();
    });

    this.selections = selections;
    this.renderList();
  };

  MclbGrid.prototype.removeSelection = function (index) {
    var sel = this.selections[index];
    if (!sel) { return; }
    sel.cells.forEach(function (c) { c.classList.remove('mclb-cell--selected'); });
    this.rebuild();
  };

  MclbGrid.prototype.renderList = function () {
    var self = this;
    this.list.innerHTML = '';
    var grand = 0;

    this.selections.forEach(function (sel, i) {
      grand += sel.total;
      var li = document.createElement('li');
      var label = document.createElement('span');
      label.textContent = sel.laneName + ' · ' + hm(sel.startMin) + '–' + hm(sel.endMin) + ' · ' + fmtMoney(sel.total);
      var rm = document.createElement('button');
      rm.type = 'button';
      rm.className = 'mclb-selection__remove';
      rm.textContent = I18N.remove || 'Remove';
      rm.addEventListener('click', function () { self.removeSelection(i); });
      li.appendChild(label);
      li.appendChild(rm);
      self.list.appendChild(li);
    });

    var has = this.selections.length > 0;
    this.selectionBox.hidden = !has;
    this.totalEl.textContent = has ? ((I18N.total || 'Total') + ': ' + fmtMoney(grand)) : '';
    if (this.addBtn) { this.addBtn.disabled = !has || CFG.canBook === false; }
    if (this.msg) { this.msg.textContent = ''; this.msg.className = 'mclb-selection__msg'; }
    if (has && CFG.canBook === false) { this.setMsg(I18N.demo || 'Bookings are unavailable in demo mode.', false); }
    this.renderMinibar(grand);
  };

  // Sticky mobile mini-bar: "N slots · $X · Add to cart" (+ ✕). Shown <=768px
  // (CSS) whenever at least one dark slot exists.
  MclbGrid.prototype.renderMinibar = function (grand) {
    if (!this.minibar) { return; }
    var slots = 0;
    this.selections.forEach(function (s) { slots += s.slots; });
    if (!slots) {
      this.minibar.hidden = true;
      this.wrap.classList.remove('mclb-grid-wrap--minibar');
      return;
    }
    this.minibar.hidden = false;
    this.wrap.classList.add('mclb-grid-wrap--minibar');
    var info = this.minibar.querySelector('.mclb-grid__minibar-info');
    if (info) { info.textContent = slots + ' ' + (slots === 1 ? (I18N.slot || 'slot') : (I18N.slots || 'slots')) + ' · ' + fmtMoney(grand); }
    var add = this.minibar.querySelector('.mclb-grid__minibar-add');
    if (add) { add.disabled = CFG.canBook === false; }
  };

  MclbGrid.prototype.setMsg = function (text, ok) {
    if (!this.msg) { return; }
    this.msg.textContent = text;
    this.msg.className = 'mclb-selection__msg ' + (ok ? 'mclb-selection__msg--ok' : 'mclb-selection__msg--err');
  };

  // ── Add to cart ──────────────────────────────────────────────────────────────
  MclbGrid.prototype.addToCart = function () {
    var self = this;
    if (this.adding) { return; }                 // double-submit guard
    if (!this.selections.length) { return; }
    if (CFG.canBook === false) { this.setMsg(I18N.demo || 'Bookings are unavailable in demo mode.', false); return; }

    var coachToggle = this.wrap.querySelector('.mclb-coach__toggle');
    var coachNote = this.wrap.querySelector('.mclb-coach__note');
    var payload = {
      selections: this.selections.map(function (s) { return { lane_id: s.laneId, starts_at: s.start, ends_at: s.end }; }),
      coach_requested: !!(coachToggle && coachToggle.checked),
      coach_note: (coachToggle && coachToggle.checked && coachNote) ? coachNote.value : ''
    };
    this.wrap.dispatchEvent(new CustomEvent('mclb:addtocart', { bubbles: true, detail: payload }));

    // Busy state: label both buttons "Adding…", disable them, and lock the grid
    // so no slot can be tapped mid-request. All restored on failure.
    var mbAdd = this.minibar ? this.minibar.querySelector('.mclb-grid__minibar-add') : null;
    var addingLabel = I18N.adding || 'Adding…';
    this.adding = true;
    this.wrap.classList.add('is-adding');
    if (this.addBtn) { this.addBtn._label = this.addBtn.textContent; this.addBtn.textContent = addingLabel; this.addBtn.disabled = true; }
    if (mbAdd) { mbAdd._label = mbAdd.textContent; mbAdd.textContent = addingLabel; mbAdd.disabled = true; }
    function restore() {
      self.adding = false;
      self.wrap.classList.remove('is-adding');
      if (self.addBtn) { self.addBtn.disabled = false; if (self.addBtn._label != null) { self.addBtn.textContent = self.addBtn._label; } }
      if (mbAdd) { mbAdd.disabled = false; if (mbAdd._label != null) { mbAdd.textContent = mbAdd._label; } }
    }

    fetch(CFG.cartUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce || '' },
      body: JSON.stringify(payload)
    })
      .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); })
      .then(function (res) {
        if (res.ok && res.body && res.body.redirect) { window.location = res.body.redirect; return; } // leave locked during navigation
        if (res.ok) { self.setMsg((res.body && res.body.message) || 'Added.', true); restore(); }
        else {
          self.setMsg((res.body && res.body.message) || I18N.cartSoon || 'Could not add to cart.', false);
          restore();
          self.refresh(); // a slot was just taken: re-pull fresh, keeping the customer's still-free picks
        }
      })
      .catch(function () {
        self.setMsg(I18N.cartSoon || 'Could not add to cart.', false);
        restore();
      });
  };

  // ── Date navigation ─────────────────────────────────────────────────────────

  MclbGrid.prototype.shiftDate = function (days) {
    var d = new Date(this.wrap.getAttribute('data-date') + 'T00:00:00');
    d.setDate(d.getDate() + days);
    this.loadDate(iso(d));
  };

  // Navigate to a chosen day — a fresh day, so the selection resets.
  MclbGrid.prototype.loadDate = function (date) { this.loadDay(date, 'nav'); };

  // Re-pull the day currently shown and keep the customer's still-free picks.
  MclbGrid.prototype.refresh = function () { this.loadDay(this.wrap.getAttribute('data-date'), 'refresh'); };

  // Fetch a day's availability fresh (never cached: no-store + cache-buster) and
  // swap it into the grid. mode 'nav' = jumped to a new day (selection resets);
  // mode 'refresh' = same day re-checked (keep selections whose slots survive).
  // While the request is in flight the grid is non-interactive (is-loading), so
  // a cache-stale server render can never be tapped before fresh data lands.
  MclbGrid.prototype.loadDay = function (date, mode) {
    if (!date || this.refreshing) { return; }
    var self = this;
    this.refreshing = true;
    this.setLoading(true);
    this.clearNotice();
    var prev = (mode === 'refresh') ? this.selections.slice() : [];
    var url = CFG.restUrl + '?date=' + encodeURIComponent(date) + '&type=' + encodeURIComponent(this.type) + '&_=' + Date.now();
    fetch(url, { cache: 'no-store', headers: { 'X-WP-Nonce': CFG.nonce || '' } })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(r); })
      .then(function (data) {
        self.wrap.setAttribute('data-date', data.date);
        if (self.dateInput) { self.dateInput.value = data.date; }
        var lbl = self.wrap.querySelector('.mclb-grid__datelabel');
        if (lbl && data.date_label) { lbl.textContent = data.date_label; }
        self.applyWindow(data); // date-picker min/max from the server's fresh "today"
        self.grid.innerHTML = data.html;
        self.light = null; // a fresh grid has no pre-select
        if (mode === 'refresh') { self.showDroppedNotice(self.reapplySelections(prev)); }
        self.rebuild();
        self.render();
      })
      .catch(function () { if (mode === 'nav') { self.setMsg('Could not load that date.', false); } })
      .then(function () { self.setLoading(false); self.refreshing = false; });
  };

  // On a refresh, re-mark the previously selected runs whose every slot is still
  // free on the fresh grid; return the runs that are now (partly) taken so the
  // caller can tell the customer which picks were removed.
  MclbGrid.prototype.reapplySelections = function (prev) {
    var self = this;
    var dropped = [];
    prev.forEach(function (sel) {
      var inRange = Array.prototype.slice.call(
        self.grid.querySelectorAll('.mclb-cell--available[data-lane="' + sel.laneId + '"]')
      ).filter(function (c) {
        var s = +c.getAttribute('data-start-min');
        return s >= sel.startMin && s < sel.endMin;
      }).sort(function (a, b) {
        return (+a.getAttribute('data-start-min')) - (+b.getAttribute('data-start-min'));
      });
      var ok = inRange.length > 0
        && +inRange[0].getAttribute('data-start-min') === sel.startMin
        && +inRange[inRange.length - 1].getAttribute('data-end-min') === sel.endMin;
      if (ok) {
        for (var i = 1; i < inRange.length; i++) {
          if (+inRange[i].getAttribute('data-start-min') !== +inRange[i - 1].getAttribute('data-end-min')) { ok = false; break; }
        }
      }
      if (ok) { inRange.forEach(function (c) { c.classList.add('mclb-cell--selected'); }); }
      else { dropped.push(sel); }
    });
    return dropped;
  };

  // Brief inline alert above the summary when a refresh removes picks that were
  // taken by someone else in the meantime. Auto-dismisses; cleared on next load.
  MclbGrid.prototype.showDroppedNotice = function (dropped) {
    if (!this.notice || !dropped || !dropped.length) { return; }
    var labels = dropped.map(function (s) { return s.laneName + ' · ' + hm(s.startMin) + '–' + hm(s.endMin); });
    var text = (labels.length === 1)
      ? labels[0] + ' was just booked by someone else and has been removed.'
      : 'These were just booked by someone else and have been removed: ' + labels.join('; ') + '.';
    this.notice.textContent = text;
    this.notice.hidden = false;
    if (this.noticeTimer) { clearTimeout(this.noticeTimer); }
    var self = this;
    this.noticeTimer = setTimeout(function () { self.clearNotice(); }, 12000);
  };
  MclbGrid.prototype.clearNotice = function () {
    if (this.noticeTimer) { clearTimeout(this.noticeTimer); this.noticeTimer = null; }
    if (this.notice) { this.notice.hidden = true; this.notice.textContent = ''; }
  };

  // Apply the customer booking window from the server's fresh response: date-picker
  // min = today, max = last bookable date (so the picker can't choose beyond it),
  // and remember the max for the › next-day arrow. Nothing is baked into page HTML,
  // so a WP Rocket-cached page can't drift the limit by a day.
  MclbGrid.prototype.applyWindow = function (data) {
    this.maxDate = data && data.max_date ? data.max_date : null;
    if (!this.dateInput) { return; }
    if (data && data.today) { this.dateInput.min = data.today; }
    if (this.maxDate) { this.dateInput.max = this.maxDate; }
    else { this.dateInput.removeAttribute('max'); }
  };

  // Re-assert prev/next-button disabled state at the window edges + the scroll hint.
  MclbGrid.prototype.render = function () {
    var date = this.wrap.getAttribute('data-date');
    var prev = this.wrap.querySelector('.mclb-nav--prev');
    if (prev && this.dateInput && this.dateInput.min) {
      prev.disabled = date <= this.dateInput.min;
    }
    var next = this.wrap.querySelector('.mclb-nav--next');
    if (next) { next.disabled = !!(this.maxDate && date >= this.maxDate); }
    this.updateScrollHint();
  };

  MclbGrid.prototype.updateScrollHint = function () {
    if (!this.scrollWrap || !this.grid) { return; }
    var remaining = this.grid.scrollWidth - this.grid.clientWidth - this.grid.scrollLeft;
    var scrollable = remaining > 4;
    this.scrollWrap.classList.toggle('is-scrollable', scrollable);
    if (this.grid.scrollLeft > 0) { this.scrolledOnce = true; }
    var hint = this.wrap.querySelector('.mclb-grid__hint');
    if (hint) { hint.hidden = !(scrollable && !this.scrolledOnce); }
  };

  // ── small utils ─────────────────────────────────────────────────────────────

  function hm(mins) {
    var h = Math.floor(mins / 60), m = mins % 60;
    var ampm = h >= 12 ? 'pm' : 'am';
    var hh = h % 12; if (hh === 0) { hh = 12; }
    return hh + (m ? ':' + (m < 10 ? '0' + m : m) : '') + ampm;
  }
  function iso(d) {
    var mo = d.getMonth() + 1, da = d.getDate();
    return d.getFullYear() + '-' + (mo < 10 ? '0' + mo : mo) + '-' + (da < 10 ? '0' + da : da);
  }
})();
