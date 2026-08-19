=== PlugSeal ===
Contributors: marc4
Tags: security, permissions, hardening, access-control
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Control what each active plugin is allowed to do. Allow or deny specific permissions per plugin, with immediate effect.

== Description ==

PlugSeal gives administrators granular control over what each active plugin is allowed to do, inspired by Android app permissions and Flatseal for Flatpak. Each active plugin is listed in the settings page. For each plugin, administrators can allow or deny individual permissions with immediate effect. All permissions are allowed by default, so no existing functionality is broken until an administrator explicitly restricts it.

**Permissions covered:**

* `db:read` / `db:write` — database queries via $wpdb
* `db:read:users` / `db:write:users` — read and write access to user data (also covers wp_delete_user and wp_update_user)
* `http:outbound` — outbound HTTP requests via the WordPress HTTP API
* `options:read` / `options:write` — WordPress options via get_option / update_option (see limitations)
* `email:send` — sending email via wp_mail()
* `cron:write` — scheduling events via wp_schedule_event()
* `transients:write` — writing transients via set_transient()
* `users:create` — creating users via wp_create_user() (deletes are covered by db:write:users)
* `rest:register` — registering REST API endpoints via register_rest_route()
* `shortcode:register` — registering shortcodes via add_shortcode()
* `rewrite:register` — registering rewrite rules via add_rewrite_rule()
* `admin:menu` — adding entries to the admin menu and submenus
* `dashboard:widget` — adding dashboard widgets via wp_add_dashboard_widget()
* `hooks:frontend` — hooking into frontend hooks (wp_head, wp_footer, the_content, wp_enqueue_scripts...)
* `hooks:admin` — hooking into admin hooks (admin_head, admin_notices, admin_enqueue_scripts...)
* `hooks:auth` — hooking into authentication hooks (wp_login, wp_logout, user_register, authenticate...)
* `hooks:content` — hooking into content hooks (save_post, delete_post, pre_get_posts, wp_handle_upload...)
* `hooks:lifecycle` — hooking into plugin and theme lifecycle hooks (activated_plugin, deactivated_plugin, switch_theme...)

**Honest limitations:**

This plugin intercepts official WordPress APIs by identifying the calling plugin via the PHP call stack. It cannot intercept calls made by WordPress core on behalf of a plugin — for example, when WordPress processes a settings form via `options.php`, the call stack contains core files rather than the plugin files.

Specific limitations:

* `options:read` / `options:write` — work when a plugin calls these APIs directly from its own code (hooks, AJAX, cron). Do not block standard WordPress settings forms processed by `options.php`.
* Filesystem access (`file_get_contents`, `fopen`, etc.) is not intercepted.
* Direct `mysqli` connections, `eval()`, and raw PHP file functions bypass all interceptors.
* `wp_update_user()` and `wp_delete_user()` are covered by `db:write:users` since they write directly to the users table.
* `admin_init` is intentionally excluded from `hooks:admin` as it is too critical to block safely.

== Installation ==

1. Upload the `plugseal` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu.
3. Go to **Settings → PlugSeal**.
4. Select a plugin and toggle individual permissions on or off.

== Frequently Asked Questions ==

= Does this work with Multisite? =
No. Multisite is not supported in this version.

= What happens to my data if I uninstall the plugin? =
Data is preserved by default. To delete all data on uninstall, enable the option in the settings page before deleting the plugin.

= Can a plugin bypass this system? =
Yes, if a plugin makes direct database connections or filesystem calls without using WordPress APIs, or if WordPress core processes actions on its behalf. These are known limitations documented above.

== Changelog ==

= 0.3.0 - 2025-06-13 =
* Plugin names are now shown in the sidebar.
* Long plugin names are truncated with ellipsis; full name visible on hover.
* Plugins in the sidebar are now sorted alphabetically by name.
* Sidebar width increased to 280px and now stays fixed while scrolling through permissions.
* Replaced text badge with a compact round count badge that adapts to the admin colour scheme.
* Reset confirmation now shows the plugin name.
* Fixed duplicate JavaScript block in reset handler.
* Fixed CSS inconsistencies and removed unused rules.
* Improved accessibility: busy states during AJAX requests and keyboard focus management.

= 0.2.1 - 2025-06-13 =
* Performance: cache WP_PLUGIN_DIR normalisation across calls.
* Fixed: orphaned permission overrides are now removed when a plugin is deleted.
* Code: removed unused global variables.
* Code: fixed duplicate docblock.
* Code: fixed inconsistent alignment in permission groups.
* Code: updated outdated file header comment.

= 0.2.0 - 2025-05-30 =
* Added "Reset to defaults" button per plugin.
* Added Settings link to the plugin list page.
* Added descriptions for all permissions.
* Renamed hook categories.
* Improved translation support.
* Fixed untranslated strings in JavaScript.

= 0.1.0 - 2025-04-25 =
* Initial release.
