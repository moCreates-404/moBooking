# Switchover: `mclb-lane-booking` → `mobooking` (v1.6.0)

For existing live installs (Cricketers Club WA). Takes about 15 minutes.
Do it at a quiet time — not during a busy booking window.

## Why this is safe

- Every booking, pass, closure, setting and role lives in the database under
  `mclb_*` names. The folder name isn't part of any of it, and v1.6.0 doesn't
  change the database version (still `MCLB_DB_VERSION` 8).
- Activation never overwrites saved settings, and the cron/Action Scheduler
  jobs re-register themselves without duplicating.
- Admin page URLs (`admin.php?page=mclb-settings`, `mclb-bookings-view`, …) are
  unchanged, so staff bookmarks keep working.
- Belt and braces on the licence: the old stub key gives an automatic 14-day
  grace window, and `MCLB_LICENSE_UNLOCK` keeps bookings open regardless.

## The one thing NOT to do

**Never click "Delete" on the old plugin in WP Admin.** That runs the OLD
`uninstall.php`, which wipes the settings. Move the folder instead (step 3).

## Before you start

1. Freemius product set up with slug **and** premium slug = `mobooking`.
2. `includes/freemius-init.php` filled in with the real Product ID and Public
   Key, release zip built from a tag, and the same zip uploaded to Freemius
   as a release.
3. A complimentary **Pro lifetime** licence for Cricketers Club issued in
   Freemius (keep the key handy). It must be **Pro** — an Essentials key would
   hide their coaches, roster, day sheets and passes.
4. Tested on staging first (same steps below).
5. Fresh backup of the live site (files + database). phpMyAdmin is blocked on
   the GoDaddy delegate login, so use a backup plugin or GoDaddy's backup tool.

## Cutover

1. **Unlock bookings during the swap.** cPanel → File Manager → `wp-config.php`,
   add above the "That's all, stop editing" line:

   ```php
   define('MCLB_LICENSE_UNLOCK', true);
   ```

2. **Note the counts** you'll check afterwards: moBooking → Bookings (total for
   today and this week), Passes, and one or two settings (price, hours).

3. **Move the old plugin out of the plugins folder** (don't rename it in place —
   a renamed copy would still be picked up by WordPress):
   `wp-content/plugins/mclb-lane-booking` → `wp-content/mclb-lane-booking-backup`.
   WordPress deactivates it automatically; no deactivation or uninstall code runs.

4. **Install the new plugin:** WP Admin → Plugins → Add New → Upload Plugin →
   `mobooking-v1.6.0.zip` → Install → **Activate**.

5. **Activate the licence:** Freemius shows its activation screen (or go to
   moBooking → Account). Enter the Cricketers Club lifetime key.
   moBooking → Settings → License should read **Active — Lifetime licence active**.

6. **Clear WP Rocket's cache** (asset URLs moved from `/mclb-lane-booking/` to
   `/mobooking/`).

7. **Check it:**
   - Bookings, Passes and settings match what you noted in step 2.
   - Front-end grid loads (logged out, in a private window) and a slot can be
     selected through to the cart. No need to pay.
   - moBooking → Add booking works for staff.
   - The coach roster / day sheets page loads.
   - Settings → General → Wording still says Lane / Coach, and the License tab
     shows **Plan: Pro**. No "Welcome to moBooking" setup notice appears.

8. **Remove the unlock line** from `wp-config.php`. Reload the License tab — it
   should still say Active.

9. Leave `wp-content/mclb-lane-booking-backup` for a week, then delete it via
   File Manager (not WP Admin).

## Rollback

1. Plugins → deactivate moBooking.
2. Move `wp-content/plugins/mobooking` out to `wp-content/mobooking-failed`.
3. Move `wp-content/mclb-lane-booking-backup` back to
   `wp-content/plugins/mclb-lane-booking` and activate it.

The database wasn't migrated, so the old version picks straight back up.
