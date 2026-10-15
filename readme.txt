=== moBooking ===
Contributors: mocreates
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 8.0
Stable tag: 1.2.2
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
