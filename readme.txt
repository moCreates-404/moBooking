=== moBooking ===
Contributors: mocreates
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 8.0
Stable tag: 0.1.0
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

== Changelog ==

= 0.1.0 =
* Phase 0: plugin scaffold, activation/deactivation, tabbed settings
  (General / Appearance / License), CSS custom-property output, stubbed
  license check.
