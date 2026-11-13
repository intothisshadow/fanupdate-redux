# FanUpdate Redux

A lightweight PHP blogging script designed to keep an updates log for an entire
collective of fan websites. Works equally well as a standalone blog.

Originally written by **Jenny Ferenc** in 2005. Ported to PHP 7 by **Ekaterina**
(scripts.robotess.net) in 2020. 

This Redux release updates the codebase to **PHP 8.2+** and includes a full admin UI redesign.

## Troubleshooting

### Image upload says "Security check failed", the lightbox doesn't open, or admin buttons and progress bars do nothing

Your site or host sends a strict `Content-Security-Policy` header (often set in a root `.htaccess`) that blocks inline scripts, so the admin's scripts never ran. From the release after Redux 2.7.0 the admin pages and the public blog output (including the lightbox and comment form) use no inline scripts, so a policy with `script-src 'self'` is enough. If you are on Redux 2.7.0 or earlier, add an `.htaccess` inside the `admin/` folder that replaces the policy with one that allows `'unsafe-inline'` for scripts. To confirm the cause, open the editor page, type `fuCsrfToken` in the browser console, and check whether it prints a long string or says it is not defined.

The admin uses inline `style=""` attributes, so the policy also needs `style-src 'self' 'unsafe-inline'`, and its web fonts load from `fonts.googleapis.com` and `fonts.gstatic.com`. The admin icons are self-hosted from Redux 2.8.0; in 2.7.1 and earlier they load from `cdn.jsdelivr.net`, so a policy that doesn't allow that host shows blank icon boxes in the admin header.

### Saving a new entry fails with "Unknown column 'cat_id' in INSERT INTO blog"

This was a bug in `admin/blog.php` present in Redux 2.0.2 and earlier. The
`blog` table has no `cat_id` column — categories are stored in the `blog_catjoin`
join table. The `add` and `update` actions were incorrectly trying to write
`cat_id` directly to the `blog` row, causing a MySQL error on save.

Resolved in the current build — upload the updated `admin/blog.php` and
`class/FanUpdate.php`.

### The whole admin panel is blank white after deploying a Redux 2.0.x update

This was caused by `FanUpdate::login()` calling `$rateLimiter->isLocked()` when
the correct method name on `LoginRateLimiter` is `isLockedOut()`. The
undefined-method call threw a fatal error before any HTML was rendered.

Resolved in the current build — upload the updated `class/FanUpdate.php`.

### The new-entry form shows only a Title field and stops rendering

This was caused by four methods being absent from `class/FanUpdate.php` after
the PHP 8.2 refactor: `printWysiwyg()`, `printSmileyImgDropdown()`,
`updateConfigKey()`, and `reissueToken()`. The call to `$fu->printWysiwyg('body')`
mid-form threw a fatal undefined-method error, killing all output from that
point onward — the textarea, Settings column, and Action buttons were never
rendered.

Resolved in the current build — upload the updated `class/FanUpdate.php`.

### Fatal error: Cannot redeclare FanUpdate::AddOpt()

The Redux 2.0.x `class/FanUpdate.php` declared `AddOpt()`, `AddErr()`, and
`AddSuccess()` twice — once in the core class body and once as back-compat
aliases appended at the bottom. PHP forbids duplicate method declarations.

Resolved in the current build — upload the updated `class/FanUpdate.php`.

### Comments and comment form are missing on single-post pages (`?id=N`)

This was a bug in `class/FanUpdate.php` present in Redux 2.0.1 and earlier.
`FanUpdate::printBlog()` received the `$single_page` flag but only used it to
count comments for the `{{comment_link}}` link — it never rendered the comments
list or the comment form below the post.

Resolved in the current build — upload the updated `class/FanUpdate.php`.

### The navigation menu is missing on the Display Code (get-code) page

This was a bug in `admin/get-code.php` present in Redux 2.0.0. The file did
not set `$showNav = true` before calling `$fu->getHeader()`, so the nav bar was
never rendered. Resolved in the current build — upload the updated `admin/get-code.php`.

### install.php shows a blank white page

This means a PHP fatal error is being swallowed by the server. Redux 1.1.1+
forces `display_errors = On` at the top of `install.php`, so this should no
longer occur. If you are on an older copy, add these two lines at the very top
of `install.php` (after `<?php`):

```php
ini_set('display_errors', '1');
error_reporting(E_ALL);
```

### install.php shows no form fields (just the button)

You have a pre-1.1.1 copy of `install.php`. The bug was a loose comparison
(`strpos(...) == 0`) that hid every visible field. Download the current
`install.php`.

### Fatal error: Uncaught mysqli_sql_exception … table doesn't exist

PHP 8.0+ changed mysqli's default error reporting mode to throw exceptions
instead of returning `false`. The `@` operator does not catch exceptions.
Redux 1.1.1+ wraps all version-detection queries in `try/catch` and temporarily
disables exception mode inside `SqlConnection::Execute()`. Download the current
`install.php` and `class/SqlConnection.php`.

*(Historical note: as of the Milestone 2 database modernisation, `SqlConnection`
no longer uses `ext/mysqli` at all — see the PDO entry below.)*

### Fatal error: could not find driver (PDOException) / class 'PDO' not found

`class/SqlConnection.php` was migrated from `ext/mysqli` to PDO as part of the
Milestone 2 database modernisation. This requires the `pdo_mysql` PHP
extension to be enabled on the server. Most shared hosts (including the
standard cPanel PHP builds) enable it by default; if you see this error, ask
your host to enable `pdo_mysql` (sometimes listed as `PDO_MYSQL` in the PHP
extension selector), or check `php -m | grep pdo_mysql` on a server you
control.

### WARNING: Could not execute query. Table '…blog_options' doesn't exist

This warning appeared on the install form in pre-1.1.1 builds because
`FanUpdate::addOptFromDb()` triggered a visible warning when the options table
didn't exist yet. Fixed in Redux 1.1.1 — `addOptFromDb()` now passes
`null` as the error message so missing tables fail silently during install.

### I can't log in after installing — no credentials were set

The admin username and password live in the database (plain `options` table
rows — `admin_username`/`admin_password`), not `blog-config.php`. The
install form collects them on a fresh install and writes them for you. If
that didn't happen for some reason, insert them directly:

```sql
INSERT INTO blog_options (optkey, optvalue, optdesc) VALUES
  ('admin_username', 'your-username', 'Your admin login username.'),
  ('admin_password', '$2y$...', 'Your admin login password (bcrypt hash).');
```

Generate the bcrypt hash with:
`php -r "echo password_hash('your-password', PASSWORD_BCRYPT);"`

### I forgot my admin password

This is a different situation from the one above — you have working
credentials, you just don't remember the password. Rather than generating a
bcrypt hash by hand and running an `UPDATE` query yourself, upload
`reset-password.php` (ships alongside `install.php` at the project root) and
visit it in a browser — no login needed. It updates the same
`admin_password` option row and deletes itself automatically on success, the
same as `install.php`. See the README's "Lost your password?" section for
details. There's a login-page link to it ("Forgot your password?") so you
don't need to remember the URL either.

If `reset-password.php` reports success but the new password still doesn't
work and `blog-config.php` doesn't contain a password either, your
installation likely predates admin credentials moving into the database
(see the CHANGELOG's "Admin credentials moved from `blog-config.php` into
the database" entry) — it never had an `admin_username`/`admin_password` row
at all. Current versions of `reset-password.php` detect this and show an
extra **Admin username** field on the form instead of silently doing
nothing; if you're on an older copy of the file, re-download it from the
project and try again.

### After a fresh install, I get logged out the instant I click anything

Fixed — this was caused by `install.php`'s credential-writing regex never
matching `blog-config.sample.php`'s own default `admin_password` line (a live
`password_hash('changeme', PASSWORD_BCRYPT)` expression, re-evaluated with a
new random salt on every request), so the login cookie's HMAC token never
matched between requests. No longer possible at all now that admin
credentials are database rows rather than a `blog-config.php` expression —
see the CHANGELOG's "Change password to database stored" entry for the full
history of this bug and the fix.

### I am locked out of the admin panel

After 5 failed login attempts your IP is locked out for 15 minutes (Redux
1.3.0+). The lockout window doubles on each subsequent lockout, up to a maximum
of 24 hours.

To clear a lockout manually, delete the relevant lock file from
`data/login_attempts/` on the server. Files are named `fu_<md5-of-ip>.json`.
If you do not know which file is yours, deleting all `fu_*.json` files in that
directory is safe — they will be recreated on the next failed attempt.

### Login fails even with the correct password after upgrading to Redux 1.4.0

Redux 1.4.0 adds `Secure=true` to the session cookies, which means the browser
will not send them over plain HTTP. If your admin panel URL begins with `http://`
rather than `https://`, the cookies will never arrive and every page load will
show the login form. The fix is to ensure the site runs on HTTPS and access the
admin panel via `https://`.

### I keep getting logged out while actively using the admin panel

If you run more than one FanUpdate Redux install on the **same domain**
(e.g. `example.com/fanupdate-redux/demo/` and `example.com/update-blog/` —
a normal shared-hosting layout), and you have both admin panels open at
once or switch between them, logging into one can immediately log the
other one out, and vice versa. This looks exactly like a session randomly
expiring, but it isn't — it's cookie scoping.

Before Redux 2.3.0's cookie-path fix, the `fu_username`/`fu_token`/
`fu_session` cookies were all scoped to `path=/` (the whole domain)
regardless of which subdirectory the install actually lived in. Since
browsers store one cookie per (domain, path, name), every install on that
domain shared the exact same three cookie slots — logging into one
overwrote the cookie the other install's login state depended on. Fixed by
scoping each cookie to the install's own subdirectory
(`FanUpdate::cookiePath()`, derived from the `install_url` option) instead
of hardcoding `/`.

If you're on Redux 2.3.0+ and still see this: check that `install_url`
(Admin → Options → Main) is actually set to this specific install's own
URL, not a shared/incorrect value — an install that never had `install_url`
set correctly falls back to the old `path=/` behaviour. You may also need
to log out and back in once (or clear cookies) after upgrading, since a
leftover `path=/` cookie from before the fix can coexist with the new
scoped one in your browser until it naturally expires.

### `blog_category` table doesn't exist — categories page is empty

On a fresh standalone install with Redux 1.4.x or earlier, the `blog_category`
table was never created by the installer. Fix it by running this query in
phpMyAdmin (or any MySQL client):

```sql
CREATE TABLE `blog_category` (
  `fl_id` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `fl_subject` varchar(50) NOT NULL DEFAULT '',
  PRIMARY KEY (`fl_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;
```

Redux 1.5.0+ creates this table automatically during fresh installation.
If you are upgrading from 1.4.x and the table is missing, run either the SQL
above or re-run `install.php` (it will skip existing tables and only create
missing ones).

### The dashboard shows a warning about install.php still being present

`install.php` auto-deletes itself on success (Redux 1.5.0+), but deletion can
fail if the file is not writable by the web server process. Delete it manually
via FTP or your hosting file manager. The warning will disappear on the next
login once the file is gone.

**If you just updated *to* Redux 2.4.0 via the Admin Update Manager** and
`install.php` is still there despite normal file permissions, this is
expected and not a bug: `InstallManager` deleting `install.php` after an
Admin Update Manager install is itself new in 2.4.0 (see `CHANGELOG.md`).
The code that actually performs an update is whichever version was live
*before* the update runs — so updating 2.3.0 → 2.4.0 executes 2.3.0's
`InstallManager`, which doesn't have this step at all. The fix only takes
effect starting with your *next* update (2.4.0 → whatever follows), which
will delete `install.php` automatically. For this one transition, just
delete `install.php` manually as described above — no different from any
other version's occasional deletion failure.

### `div#fanupdate` and its content break out of their container

This was a bug in `show-cat.php` present in Redux 1.5.1 and earlier. The guard
that checks for a valid `$listingid` ran *after* the opening `<div id="fanupdate">`
had already been written to the page. If `$listingid` was absent or non-numeric,
the script exited with the `<div>` unclosed, and the browser treated the rest of
the host page as children of `#fanupdate`.

Fixed in Redux 1.5.2 — the guard now runs before any HTML is emitted. Upgrade
to 1.5.2 and replace `show-cat.php`.

### Some posts are not visible on the public blog

This was a timezone bug present in Redux 1.5.0 and earlier. The post visibility
query used `DATE_ADD(CURRENT_TIMESTAMP(), INTERVAL X HOUR)` with the
`_server_tz_offset` option to determine which posts had passed their scheduled
time. On servers where the MySQL timezone differed from UTC, this calculation
could make recently-added posts appear future-dated and hide them.

Fixed in Redux 1.5.1 — all visibility queries now use `UTC_TIMESTAMP()`
directly, which is always correct regardless of server timezone. Upgrade to
1.5.1 and run `install.php` to remove the obsolete `_server_tz_offset` option.

If you cannot upgrade immediately, go to **Admin → Options** and set
`_server_tz_offset` to `0`.

### Admin panel pages are blank white after upgrading to Redux 2.0.0

This is caused by `getHeader()` and `getFooter()` in `class/FanUpdate.php` using
`require_once` instead of `include` to load `admin/header.php` and
`admin/footer.php`. On PHP-FPM servers with persistent workers, `require_once`
tracks loaded file paths across the entire process lifetime — so `header.php`
loads correctly on the first request and is silently skipped on every subsequent
one, producing a blank page.

Fix: open `class/FanUpdate.php`, find `getHeader()` and `getFooter()`, and
change `require_once` to `include` in both methods. Redux 2.0.0 includes this
fix in the shipped `class/FanUpdate.php`.

### The entries list on admin/blog.php is blank after upgrading to Redux 2.0.0

`printCategoryDropdown()` called with the `$include_all` third parameter leaves
a result on the `SqlConnection` result stack, corrupting the state of the
subsequent `ExecutePaginate()` call so `total_results` is set to 0. Redux 2.0.0
replaces both `printCategoryDropdown()` calls in `admin/blog.php` with inline
`<select>` queries that call `FreeResult()` explicitly.

### The select-all checkbox in the entries table renders as raw HTML text

The standardista table sorter wraps every sortable `<th>` content in an `<a>`
tag. Browsers strip `<input>` elements inside `<a>` tags and render the tag as
escaped text. Fix: add `class="nosort"` to the checkbox `<th>` and the Actions
`<th>` in `admin/blog.php`. Redux 2.0.0 includes this fix.

### Smilies are not displaying after upgrading to Redux 1.7.0

The smiley image directory moved from `img/` to `assets/images/smilies/` in
Redux 1.7.0. Make sure you have moved your smiley image files to the new
location. The `gravatar_default` URL stored in the database also needs updating
— go to **Admin → Options** and change the Gravatar default image URL from
`.../img/gravatar.png` to `.../assets/images/gravatar.png`.
