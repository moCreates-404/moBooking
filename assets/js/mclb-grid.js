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
    this.dateInput = wrap.querySelector('.mclb-grid__date');
    this.selections = [];      // committed runs (derived from --selected cells)
    this.light = null;         // the single touch pre-select cell, or null
    this.drag = null;          // active MOUSE drag
    this.touch = null;         // pending TOUCH gesture
    this.scrolledOnce = false;
    this.bind();
    this.setInstructions();
    this.render();
    this.rebuild();
  }

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

    var mbAdd = this.minibar ? this.minibar.querySelector('.mclb-grid__minibar-add') : null;
    if (this.addBtn) { this.addBtn.disabled = true; }
    if (mbAdd) { mbAdd.disabled = true; }

    fetch(CFG.cartUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce || '' },
      body: JSON.stringify(payload)
    })
      .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); })
      .then(function (res) {
        if (res.ok && res.body && res.body.redirect) { window.location = res.body.redirect; return; }
        if (res.ok) { self.setMsg((res.body && res.body.message) || 'Added.', true); }
        else {
          self.setMsg((res.body && res.body.message) || I18N.cartSoon || 'Could not add to cart.', false);
          if (self.addBtn) { self.addBtn.disabled = false; }
          if (mbAdd) { mbAdd.disabled = false; }
        }
      })
      .catch(function () {
        self.setMsg(I18N.cartSoon || 'Could not add to cart.', false);
        if (self.addBtn) { self.addBtn.disabled = false; }
        if (mbAdd) { mbAdd.disabled = false; }
      });
  };

  // ── Date navigation ─────────────────────────────────────────────────────────

  MclbGrid.prototype.shiftDate = function (days) {
    var d = new Date(this.wrap.getAttribute('data-date') + 'T00:00:00');
    d.setDate(d.getDate() + days);
    this.loadDate(iso(d));
  };

  MclbGrid.prototype.loadDate = function (date) {
    if (!date) { return; }
    var self = this;
    var url = CFG.restUrl + '?date=' + encodeURIComponent(date) + '&type=' + encodeURIComponent(this.type);
    fetch(url, { headers: { 'X-WP-Nonce': CFG.nonce || '' } })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(r); })
      .then(function (data) {
        self.wrap.setAttribute('data-date', data.date);
        if (self.dateInput) { self.dateInput.value = data.date; }
        var lbl = self.wrap.querySelector('.mclb-grid__datelabel');
        if (lbl && data.date_label) { lbl.textContent = data.date_label; }
        self.grid.innerHTML = data.html;
        // Single-date-per-order: a fresh day has no selection and no light.
        self.light = null;
        self.rebuild();
        self.render();
      })
      .catch(function () { self.setMsg('Could not load that date.', false); });
  };

  // Re-assert prev-button disabled state at "today" + the scroll hint.
  MclbGrid.prototype.render = function () {
    var prev = this.wrap.querySelector('.mclb-nav--prev');
    if (prev && this.dateInput && this.dateInput.min) {
      prev.disabled = this.wrap.getAttribute('data-date') <= this.dateInput.min;
    }
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
