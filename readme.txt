=== moBooking ===
Contributors: mocreates
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 8.0
Stable tag: 1.4.1
License: GPL-2.0-or-later

Book out time-slotted resources (lanes, nets, bays, hoists…) on a live grid,
checking out through WooCommerce.

== Description ==

moBooking (internal prefix `mclb_`) is a standalone, theme-independent booking
plugin for any business with bookable, time-slotted resources. A visible grid
of resources × time lets customers pick a unit and drag to set a duration, then
pay through WooCommerce — no service-selection wizard.

Everything is configured per install: what a bookable unit is called
(Lane/Bay/Hoist), the resource types, opening hours, price, booking increment,
and the grid's colours all live in settings, not in code. Cricketers Club WA is
the first install; its real values are the seeded defaults.

Phase 0 (this build): plugin scaffold + settings screen only. Booking data
model, availability engine, front-end grid, WooCommerce integration and admin
management follow in later phases.

== Deploying an update ==

Build the release zip from a committed tag (never a working copy):

    powershell -File bin/build-release.ps1 v1.1.2

This writes dist/mclb-lane-booking.zip with the plugin inside a top-level
"mclb-lane-booking/" folder. In WP Admin -> Plugins -> Add New -> Upload Plugin,
upload that zip; when WordPress detects the existing plugin, choose "Replace
current with uploaded". Do NOT use GitHub's "Download ZIP" — its folder is named
after the repo/ref and installs as a second, duplicate copy of the plugin.

== Changelog ==

= 1.4.1 =
* Partial coach time: a coach can now be booked for only part of a session. The
  New-booking and booking-detail panels gain a "Coach time" control (start/end,
  snapped to the booking increment and bounded by the session, defaulting to the
  whole booking). The coach fee is charged on the coach's actual hours (rate ×
  coach hours), the live "Lane + Coach = Due" line reflects it, and the coach
  double-book guard checks only the coach's own window — so one coach can take
  back-to-back half-sessions on different lanes. Recurring series carry one coach
  sub-range applied to every occurrence; editing a series booking's coach can
  apply to "this session only" or "this and all following". Moving a booking to a
  shorter slot clamps the coach window to fit (with an audit line + toast). A new
  coach-fee override (reason required) mirrors the lane-price override and is
  allowed on any booking while the coach fee is unpaid. Day sheets, the coach's
  "View my day" page, the calendar chip and the tooltip all show the coach's
  actual hours, with the lane booking time in smaller text when they differ.
* Block-out release / book-over: staff can free a blockout for a single date
  without changing the recurring rule — "Release this date" (whole day) or
  "Ended early at HH:MM" — from the blockout panel (one-off and recurring alike);
  "Delete whole blockout" still removes a one-off entirely. Released dates free
  the slot everywhere immediately (customer grid, REST, add-to-cart and checkout
  all honour the single availability point). A "Released" marker on the day shows
  who released it and offers "Undo release", which re-applies the blockout unless
  a booking has since been taken in that window ("Can't restore: #123 is booked
  in this window"). Dragging a new booking over a blockout pre-ticks override,
  names the blockout and records the reason in History only. DB v6 adds the coach
  sub-range + coach-fee-original columns and the mclb_closure_exceptions table
  (dbDelta + self-heal); a deleted closure drops its exceptions.

= 1.4.0 =
* Recurring bookings (staff only): the manual-create panel (front Manage +
  wp-admin Calendar) gains a Repeat option — weekly or fortnightly, for a number
  of times or until a date (max 52). A preview lists every date as available or a
  clash (lane / coach / closure / hours) with the series total; staff book the
  available dates only (skip-only — any date that clashes at save is skipped and
  reported, never aborting the rest). Each occurrence is a normal booking (own
  price, counter, coach, notes, History, movable) linked by a series id; blocks
  and the panel show "Series · N of M". DB v5 adds the series_id column
  (dbDelta + self-heal).
* Series cancel from the panel: "This one" (the existing cancel), "This and
  following", or "Whole series" (future-only; a second confirm is required for any
  prepaid session and nothing is auto-refunded). Each cancel is audited.
* Optional "Paid upfront for whole series" marks every occurrence counter-paid and
  records "Paid upfront $X for N sessions, XX" on each, so the counter sees it was
  prepaid on the day.
* One email, not 52: the customer gets a single confirmation listing all booked
  dates (and any skipped), and staff get at most one "created" alert for the
  series. Both reuse the branded mailer (Reply-To Bookings).
* The customer booking-window check now runs through one shared helper across the
  grid, add-to-cart and cart/checkout validation so the three layers can't drift
  (staff exempt everywhere); covered by tests/test-window.php.

= 1.3.2 =
* WooCommerce admin order-email routing: New / Cancelled / Failed order emails are
  routed by order type — lane bookings → the Bookings address, shop orders → the
  Reception address, mixed → both (General → Emails). If an address is blank,
  WooCommerce's own recipient is left unchanged for that type (nothing changes on
  deploy until the addresses are set). Customer-facing order emails are untouched.
* Manual-booking confirmation email to the customer (optional, when an address is
  on file) with a "Resend confirmation" action on the booking panel; a "your
  booking has moved" email on a move, with a per-move "Notify customer" toggle
  (skips a no-change move). Both use WooCommerce's branded header/footer, include
  the coach if assigned, and set Reply-To to the Bookings address.
* Staff alerts (to the Bookings address, Reply-To the customer), each a setting:
  customer self-cancel (on), online coach request (on), manual booking created
  (off), booking moved (off).
* One global "Email test recipient" (General → Emails) now governs EVERY plugin
  email including day sheets: on a non-production site nothing sends unless it's
  set, and when set every email reroutes there — so a dev site can never email a
  real customer. The old day-sheet test recipient migrates into it.

= 1.3.1 =
* Customer booking window: a new setting "Customer booking window" (General →
  Booking rules, default 14 days; 0 = no limit) caps how far ahead customers can
  book. Enforced server-side — the availability API marks days beyond the limit
  not bookable, and add-to-cart + checkout reject them — with a friendly
  "Online bookings open N days in advance." message (plus an optional
  later-dates note, e.g. a phone number) instead of an all-closed grid. The grid's
  date picker caps its max and the next-day arrow stops at the limit, computed from
  the server's own "today" so a page-cached grid never drifts a day. Staff
  (Booking Staff / admins) are never limited — on the staff calendar, wp-admin
  Calendar, manual create, Move, or even the customer grid. Existing bookings
  beyond the window are untouched.

= 1.3.0 =
* Move a booking (staff Manage view + wp-admin Calendar). Drag a booking block to
  another lane and/or start time (same day) with a live green/red drop preview, or
  use the "Move…" button for any day (date + resource + start + duration). Touch:
  long-press to drag; the Move… button is the everywhere fallback. Staff can
  override opening hours / closures / past; a real double-booking is never allowed.
* The move runs in one locked transaction (the same FOR UPDATE / overlap guard as
  holds and manual creates, plus the per-coach lock when a coach is assigned), so a
  staff move and a customer checkout of the same slot can never double-book — the
  loser gets a clean "slot taken". The booking keeps its id, order link, source,
  payment state, coach and notes; an audit line records the from → to.
* Price differences: more → added to the counter "to collect" (never re-charges the
  card); less on a counter-unpaid booking → counter reduced; less on a card-paid
  booking → a choice to refund the difference (capped) or not (reason logged); same
  price → no change. Online bookings also get their order "When"/resource meta
  updated and an order note; payment is otherwise untouched.

= 1.2.2 =
* Customer booking grid: fixes stale availability on page-cached (WP Rocket)
  pages. The grid now always re-fetches the current day from the REST API
  (no-store + cache-buster) whenever it becomes visible (incl. the drawer
  opening), when the tab regains focus, every 60s while visible, and after any
  add-to-cart failure — keeping the customer's still-free picks. Until fresh
  data lands the grid shows a "Checking availability…" veil and is
  non-interactive, so a cache-stale slot can never be tapped. If a pick is
  removed because it was just taken, a brief inline notice names the slot.
  The availability REST response now sends no-cache headers.
  (Add-to-cart already re-validates every slot server-side under a row lock, so
  a stale UI can never create a double-booking.)

= 1.2.1 =
* Customer booking grid: new tap flow — tap a free slot to pre-select (light),
  tap it again to select (dark); tapping another slot moves the pre-select, a dark
  slot is removed, adjacent dark slots in a lane merge into one run. Scrolls and
  swipes never change the selection. Mobile sticky "N slots · $X · Add to cart"
  mini-bar; instruction line (touch vs mouse). Desktop keeps click + drag-select.
* Cart: expired / cancelled / past-date holds are removed from the cart on the
  cart and checkout pages with a re-pick notice, and checkout can never accept an
  expired hold (blocked at submission). The "slot no longer available" message now
  names the lane + time.

= 1.2.0 =
* Phase 8a-1: booking details. DB v4 adds customer_phone, staff_note (editable,
  separate from the append-only audit) and price_original. Phone on manual
  bookings (AU format) + snapshotted from WC billing; editable name/phone/email/
  note on any booking with an audit trail; lane-price override (reason required,
  unpaid/manual only, original kept); "who's on now" highlight; internal
  _mclb_* order item meta hidden on the order screen and emails.

= 1.1.2 =
* Friendly long dates ("Monday, 26 October 2026") across customer and staff
  views. Manage calendar readability: taller rows, compact blocks, hatched
  closures behind bookings, side-by-side genuine overlaps, grouped To-collect
  strip. Staff app shell on the Manage page. bin/build-release.ps1 release zip.

= 1.1.1 =
* Mobile booking-grid fix (lanes no longer pushed off-screen) with a scroll
  affordance. Online bookings snapshot the WooCommerce billing name/email onto
  the booking row, so guest checkouts no longer show "Guest".

= 0.1.0 =
* Phase 0: plugin scaffold, activation/deactivation, tabbed settings
  (General / Appearance / License), CSS custom-property output, stubbed
  license check.
