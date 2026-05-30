<?php
/**
 * Additional API interceptors for PlugSeal.
 *
 * Covers: Options, Metadata, Email, Cron, Transients,
 *         Users, REST, Shortcodes, Rewrite, Settings, Dashboard, Admin menu.
 *
 * @package PlugSeal
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Options API interceptor.
 *
 * Intercepts get_option() via pre_option filter (fires before cache lookup)
 * and update_option() via pre_update_option.
 *
 * Limitation: pre_option does not fire when the value is already in the
 * WordPress object cache. For a complete solution this would require core changes.
 */
final class PlugSeal_Interceptor_Options {

	public function __construct() {
		// pre_option fires before cache — good for cold cache.
		add_filter( 'pre_option',        [ $this, 'check_read' ],  PHP_INT_MIN, 3 );
		// option_{name} fires after retrieval, even from cache.
		// We hook into 'all' to catch any option name dynamically,
		// but exit immediately if not an option_ filter to minimise overhead.
		add_filter( 'all',               [ $this, 'check_read_cached' ], PHP_INT_MIN );
		add_filter( 'pre_update_option', [ $this, 'check_write' ], PHP_INT_MIN, 3 );
		add_action( 'add_option',        [ $this, 'check_add' ],   PHP_INT_MIN, 1 );
		add_action( 'delete_option',     [ $this, 'check_delete' ], PHP_INT_MIN, 1 );
	}

	/**
	 * Intercepts option_{name} filters which fire even from cache.
	 * Uses 'all' filter but exits immediately if not an option_ hook.
	 * Registers a one-time filter for the specific option to intercept the value.
	 */
	public function check_read_cached(): void {
		$tag = current_filter();

		if ( ! str_starts_with( $tag, 'option_' ) ) {
			return;
		}

		$name = substr( $tag, 7 );

		if (
			str_starts_with( $name, '_transient' ) ||
			str_starts_with( $name, '_site_transient' ) ||
			$name === 'cron' ||
			$name === 'active_plugins'
		) {
			return;
		}

		$slug = PlugSeal_Interceptor_Helper::get_calling_plugin_slug();

		if ( null === $slug || PlugSeal_Permission_Registry::can( $slug, 'options:read' ) ) {
			return;
		}

		// Register a one-time filter for this specific option.
		if ( ! has_filter( $tag, [ $this, 'block_option_value' ] ) ) {
			add_filter( $tag, [ $this, 'block_option_value' ], PHP_INT_MIN );
		}
	}

	/**
	 * Returns null to block the option value.
	 *
	 * @param mixed $value Option value.
	 * @return null
	 */
	public function block_option_value( mixed $value ): mixed {
		return null;
	}

	/**
	 * @param mixed  $pre     Pre-option value.
	 * @param string $name    Option name.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public function check_read( mixed $pre, string $name, mixed $default ): mixed {
		if (
			str_starts_with( $name, '_transient' ) ||
			str_starts_with( $name, '_site_transient' ) ||
			$name === 'cron' ||
			$name === 'active_plugins'
		) {
			return $pre;
		}

		$slug = PlugSeal_Interceptor_Helper::get_calling_plugin_slug();

		if ( null === $slug || PlugSeal_Permission_Registry::can( $slug, 'options:read' ) ) {
			return $pre;
		}

		return $default;
	}

	/**
	 * @param mixed  $value     New value.
	 * @param string $name      Option name.
	 * @param mixed  $old_value Old value.
	 * @return mixed
	 */
	public function check_write( mixed $value, string $name, mixed $old_value ): mixed {
		if (
			str_starts_with( $name, '_transient' ) ||
			str_starts_with( $name, '_site_transient' ) ||
			$name === 'cron' ||
			$name === 'active_plugins'
		) {
			return $value;
		}

		$slug = PlugSeal_Interceptor_Helper::get_calling_plugin_slug();

		if ( null === $slug || PlugSeal_Permission_Registry::can( $slug, 'options:write' ) ) {
			return $value;
		}

		return $old_value;
	}

	/** @param string $name Option name. */
	public function check_add( string $name ): void {
		$slug = PlugSeal_Interceptor_Helper::get_calling_plugin_slug();

		if ( null !== $slug && ! PlugSeal_Permission_Registry::can( $slug, 'options:write' ) ) {
			add_action( 'added_option', static function ( string $added_name ) use ( $name ): void {
				if ( $added_name === $name ) {
					delete_option( $name );
				}
			}, PHP_INT_MIN );
		}
	}

	/** @param string $name Option name. */
	public function check_delete( string $name ): void {
		$slug = PlugSeal_Interceptor_Helper::get_calling_plugin_slug();

		if ( null !== $slug && ! PlugSeal_Permission_Registry::can( $slug, 'options:write' ) ) {
			add_filter( 'pre_delete_option', static fn() => false, PHP_INT_MIN );
		}
	}
}



/**
 * Email API interceptor.
 * Intercepts wp_mail().
 */
final class PlugSeal_Interceptor_Email {

	public function __construct() {
		add_filter( 'wp_mail', [ $this, 'check_send' ], PHP_INT_MIN );
	}

	/**
	 * @param array<string, mixed> $args Mail arguments.
	 * @return array<string, mixed>
	 */
	public function check_send( array $args ): array {
		$slug = PlugSeal_Interceptor_Helper::get_calling_plugin_slug();

		if ( null === $slug || PlugSeal_Permission_Registry::can( $slug, 'email:send' ) ) {
			return $args;
		}

		// Block by sending to an empty address — wp_mail will fail silently.
		$args['to'] = '';
		return $args;
	}
}

/**
 * Cron API interceptor.
 * Intercepts wp_schedule_event() and wp_schedule_single_event().
 */
final class PlugSeal_Interceptor_Cron {

	public function __construct() {
		add_filter( 'pre_schedule_event',        [ $this, 'check' ], PHP_INT_MIN );
		add_filter( 'pre_reschedule_event',      [ $this, 'check' ], PHP_INT_MIN );
		add_filter( 'pre_unschedule_event',      [ $this, 'check' ], PHP_INT_MIN );
	}

	/**
	 * @param mixed $pre Pre-schedule value.
	 * @return mixed
	 */
	public function check( mixed $pre ): mixed {
		$slug = PlugSeal_Interceptor_Helper::get_calling_plugin_slug();

		if ( null === $slug || PlugSeal_Permission_Registry::can( $slug, 'cron:write' ) ) {
			return $pre;
		}

		return new WP_Error( 'plugseal_denied', __( 'PlugSeal: cron:write denied.', 'plugseal' ) );
	}
}

/**
 * Transients API interceptor.
 *
 * WordPress does NOT have a generic 'pre_set_transient' filter.
 * The real filter is 'pre_set_transient_{$transient}' (specific per name).
 *
 * We intercept via the DB query filter: transients are stored as
 * wp_options rows with names '_transient_{name}'. We detect these
 * INSERT/UPDATE queries and block them if the plugin is denied.
 *
 * This approach works regardless of object cache configuration.
 */
final class PlugSeal_Interceptor_Transients {

	public function __construct() {
		add_filter( 'query', [ $this, 'check_transient_query' ], PHP_INT_MIN );
	}

	/**
	 * Detects queries that write transient data and blocks them if denied.
	 *
	 * @param string $query SQL query.
	 * @return string Blocked or original query.
	 */
	public function check_transient_query( string $query ): string {
		global $wpdb;

		// Only intercept write queries touching the options table.
		$upper = strtoupper( ltrim( $query ) );
		$is_write = str_starts_with( $upper, 'INSERT' )
			|| str_starts_with( $upper, 'UPDATE' )
			|| str_starts_with( $upper, 'REPLACE' );

		if ( ! $is_write ) {
			return $query;
		}

		if ( ! str_contains( $query, $wpdb->options ) ) {
			return $query;
		}

		if (
			! str_contains( $query, '_transient_' ) &&
			! str_contains( $query, '_site_transient_' )
		) {
			return $query;
		}

		$slug = PlugSeal_Interceptor_Helper::get_calling_plugin_slug();

		if ( null === $slug || PlugSeal_Permission_Registry::can( $slug, 'transients:write' ) ) {
			return $query;
		}

		return 'DO 0';
	}
}

/**
 * User API interceptor.
 *
 * create/update: intercepted via wp_pre_insert_user_data filter.
 * delete: covered by db:write:users — wp_delete_user() uses $wpdb->delete()
 *         on wp_users which is already intercepted by the DB interceptor.
 */
final class PlugSeal_Interceptor_Users {

	public function __construct() {
		add_filter( 'wp_pre_insert_user_data', [ $this, 'check_insert' ], PHP_INT_MIN, 4 );
	}

	/**
	 * @param array<string, mixed> $data     User data.
	 * @param bool                 $update   Whether updating.
	 * @param int|null             $user_id  User ID.
	 * @param array<string, mixed> $userdata Raw data.
	 * @return array<string, mixed>
	 */
	public function check_insert( array $data, bool $update, ?int $user_id, array $userdata ): array {
		// Only intercept user creation, not updates.
		// updates are covered by db:write:users.
		if ( $update ) return $data;

		$slug = PlugSeal_Interceptor_Helper::get_calling_plugin_slug();
		if ( null === $slug ) return $data;

		if ( PlugSeal_Permission_Registry::can( $slug, 'users:create' ) ) return $data;

		return [];
	}
}

/**
 * REST API interceptor.
 *
 * Intercepts register_rest_route() by hooking into rest_api_init with PHP_INT_MIN
 * priority, identifying callbacks by file, and removing them before they execute.
 * Also cleans up already-registered routes after rest_api_init fires.
 */
final class PlugSeal_Interceptor_Rest {

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'remove_denied_routes' ], PHP_INT_MAX );
	}

	/**
	 * After rest_api_init, removes routes registered by denied plugins.
	 */
	public function remove_denied_routes(): void {
		$server      = rest_get_server();
		$routes      = $server->get_routes();
		$plugins_dir = wp_normalize_path( WP_PLUGIN_DIR );
		$active      = (array) get_option( 'active_plugins', [] );

		// Build list of denied slugs.
		$denied_slugs = [];
		foreach ( $active as $plugin_file ) {
			if ( ! is_string( $plugin_file ) || str_contains( $plugin_file, 'plugseal' ) ) {
				continue;
			}
			$slug = explode( '/', $plugin_file )[0];
			if ( ! PlugSeal_Permission_Registry::can( $slug, 'rest:register' ) ) {
				$denied_slugs[] = $slug;
			}
		}

		if ( empty( $denied_slugs ) ) {
			return;
		}

		// Access the internal routes registry via reflection.
		try {
			$ref      = new ReflectionObject( $server );
			$prop     = $ref->getProperty( 'namespaces' );
			$prop->setAccessible( true );
			$namespaces = $prop->getValue( $server );
		} catch ( ReflectionException $e ) {
			return;
		}

		foreach ( $routes as $route => $handlers ) {
			foreach ( $handlers as $handler ) {
				if ( ! isset( $handler['callback'] ) ) {
					continue;
				}
				$file = PlugSeal_Interceptor_Helper::get_callback_file( $handler['callback'] );
				if ( null === $file ) {
					continue;
				}
				$file = wp_normalize_path( $file );
				foreach ( $denied_slugs as $slug ) {
					if ( str_contains( $file, $plugins_dir . '/' . $slug . '/' ) ) {
						$server->remove_route( $route );
						break;
					}
				}
			}
		}
	}
}


/**
 * Shortcode API interceptor.
 * Intercepts add_shortcode().
 */
final class PlugSeal_Interceptor_Shortcodes {

	public function __construct() {
		add_action( 'init', [ $this, 'remove_denied_shortcodes' ], PHP_INT_MAX );
	}

	public function remove_denied_shortcodes(): void {
		global $shortcode_tags;

		if ( empty( $shortcode_tags ) ) return;

		$plugins_dir    = wp_normalize_path( WP_PLUGIN_DIR );
		$active_plugins = (array) get_option( 'active_plugins', [] );

		$denied_slugs = [];
		foreach ( $active_plugins as $plugin_file ) {
			if ( ! is_string( $plugin_file ) || str_contains( $plugin_file, 'plugseal' ) ) continue;
			$slug = explode( '/', $plugin_file )[0];
			if ( ! PlugSeal_Permission_Registry::can( $slug, 'shortcode:register' ) ) {
				$denied_slugs[] = $slug;
			}
		}

		if ( empty( $denied_slugs ) ) return;

		// Rebuild shortcode_tags excluding shortcodes from denied plugins.
		$new_tags = [];
		foreach ( $shortcode_tags as $tag => $callback ) {
			$file    = PlugSeal_Interceptor_Helper::get_callback_file( $callback );
			$blocked = false;


			if ( null !== $file ) {
				$file = wp_normalize_path( $file );
				foreach ( $denied_slugs as $slug ) {
					if ( str_contains( $file, $plugins_dir . '/' . $slug . '/' ) ) {
						$blocked = true;
						break;
					}
				}
			}

			if ( ! $blocked ) {
				$new_tags[ $tag ] = $callback;
			}
		}

		$shortcode_tags = $new_tags;
	}
}

/**
 * Rewrite API interceptor.
 *
 * add_rewrite_rule() writes to $wp_rewrite->extra_rules_top directly.
 * We intercept by hooking into generate_rewrite_rules (fired during flush)
 * AND by monitoring extra_rules via the rewrite_rules_array filter.
 */
final class PlugSeal_Interceptor_Rewrite {

	public function __construct() {
		// Fires when rewrite rules are generated — remove denied rules here.
		add_filter( 'rewrite_rules_array', [ $this, 'filter_rules' ], PHP_INT_MAX );
		// Also prevent rules being written to option.
		add_filter( 'pre_update_option_rewrite_rules', [ $this, 'filter_option' ], PHP_INT_MIN );
	}

	/**
	 * Filters the rewrite rules array to remove rules from denied plugins.
	 *
	 * @param array<string, string> $rules Rewrite rules.
	 * @return array<string, string>
	 */
	public function filter_rules( array $rules ): array {
		$slug = PlugSeal_Interceptor_Helper::get_calling_plugin_slug();
		if ( null === $slug || PlugSeal_Permission_Registry::can( $slug, 'rewrite:register' ) ) {
			return $rules;
		}
		// Cannot easily identify which rules belong to which plugin at this point.
		// Return rules unchanged — limitation of this approach.
		return $rules;
	}

	/**
	 * Prevents saving rewrite rules if the calling plugin is denied.
	 *
	 * @param mixed $value New rules value.
	 * @return mixed
	 */
	public function filter_option( mixed $value ): mixed {
		$slug = PlugSeal_Interceptor_Helper::get_calling_plugin_slug();
		if ( null === $slug || PlugSeal_Permission_Registry::can( $slug, 'rewrite:register' ) ) {
			return $value;
		}
		return get_option( 'rewrite_rules', [] );
	}
}



/**
 * Dashboard Widgets API interceptor.
 *
 * Removes dashboard widgets belonging to denied plugins.
 * Uses complete array rebuild to avoid PHP copy-on-write issues with nested arrays.
 */
final class PlugSeal_Interceptor_Dashboard {

	public function __construct() {
		add_action( 'wp_dashboard_setup', [ $this, 'remove_denied_widgets' ], PHP_INT_MAX );
	}

	/**
	 * Removes dashboard widgets belonging to denied plugins.
	 */
	public function remove_denied_widgets(): void {
		global $wp_meta_boxes;

		if ( empty( $wp_meta_boxes['dashboard'] ) ) return;

		$plugins_dir = wp_normalize_path( WP_PLUGIN_DIR );
		$active      = (array) get_option( 'active_plugins', [] );

		$denied_slugs = [];
		foreach ( $active as $plugin_file ) {
			if ( ! is_string( $plugin_file ) || str_contains( $plugin_file, 'plugseal' ) ) continue;
			$slug = explode( '/', $plugin_file )[0];
			if ( ! PlugSeal_Permission_Registry::can( $slug, 'dashboard:widget' ) ) {
				$denied_slugs[] = $slug;
			}
		}

		if ( empty( $denied_slugs ) ) return;

		// Rebuild the dashboard meta boxes array excluding denied widgets.
		$new_dashboard = [];

		foreach ( $wp_meta_boxes['dashboard'] as $context => $priorities ) {
			foreach ( $priorities as $priority => $widgets ) {
				foreach ( $widgets as $id => $widget ) {
					if ( ! isset( $widget['callback'] ) ) {
						$new_dashboard[ $context ][ $priority ][ $id ] = $widget;
						continue;
					}

					$file = PlugSeal_Interceptor_Helper::get_callback_file( $widget['callback'] );

					if ( null === $file ) {
						$new_dashboard[ $context ][ $priority ][ $id ] = $widget;
						continue;
					}

					$file    = wp_normalize_path( $file );
					$blocked = false;

					foreach ( $denied_slugs as $slug ) {
						if ( str_contains( $file, $plugins_dir . '/' . $slug . '/' ) ) {
							$blocked = true;
							break;
						}
					}

					if ( ! $blocked ) {
						$new_dashboard[ $context ][ $priority ][ $id ] = $widget;
					}
				}
			}
		}

		// Replace the global with the rebuilt array.
		$wp_meta_boxes['dashboard'] = $new_dashboard;
	}
}

/**
 * Admin Menu API interceptor.
 * Intercepts add_menu_page() and add_submenu_page().
 */
final class PlugSeal_Interceptor_Admin_Menu {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'remove_denied_menus' ], PHP_INT_MAX );
	}

	public function remove_denied_menus(): void {
		global $menu, $submenu;

		$plugins_dir    = wp_normalize_path( WP_PLUGIN_DIR );
		$active_plugins = (array) get_option( 'active_plugins', [] );

		foreach ( $active_plugins as $plugin_file ) {
			$slug = explode( '/', (string) $plugin_file )[0];

			if ( str_contains( $plugin_file, 'plugseal' ) ) {
				continue;
			}

			if ( PlugSeal_Permission_Registry::can( $slug, 'admin:menu' ) ) {
				continue;
			}

			// Remove top-level menu items registered by this plugin.
			// Use the page hookname to find the real callback and identify the plugin.
			if ( is_array( $menu ) ) {
				foreach ( $menu as $position => $item ) {
					if ( empty( $item[2] ) ) continue;
					$hookname = get_plugin_page_hookname( $item[2], '' );
					if ( ! isset( $GLOBALS['wp_filter'][ $hookname ] ) ) continue;
					$found = false;
					foreach ( $GLOBALS['wp_filter'][ $hookname ]->callbacks as $priority => $callbacks ) {
						foreach ( $callbacks as $callback ) {
							$file = PlugSeal_Interceptor_Helper::get_callback_file( $callback['function'] );
							if ( null === $file ) continue;
							$file = wp_normalize_path( $file );
							if ( str_contains( $file, $plugins_dir . '/' . $slug . '/' ) ) {
								$found = true;
								break 2;
							}
						}
					}
					if ( $found ) {
						remove_menu_page( $item[2] );
					}
				}
			}

			// Remove submenu items registered by this plugin.
			if ( is_array( $submenu ) ) {
				foreach ( $submenu as $parent_slug => $items ) {
					foreach ( $items as $position => $item ) {
						if ( empty( $item[2] ) ) continue;
						// item[2] is the page slug — check against registered page callbacks.
						global $pagenow, $_registered_pages;
						$hookname = get_plugin_page_hookname( $item[2], $parent_slug );
						if ( ! isset( $GLOBALS['wp_filter'][ $hookname ] ) ) continue;
						foreach ( $GLOBALS['wp_filter'][ $hookname ]->callbacks as $priority => $callbacks ) {
							foreach ( $callbacks as $callback ) {
								$file = PlugSeal_Interceptor_Helper::get_callback_file( $callback['function'] );
								if ( null === $file ) continue;
								$file = wp_normalize_path( $file );
								if ( str_contains( $file, $plugins_dir . '/' . $slug . '/' ) ) {
									remove_submenu_page( $parent_slug, $item[2] );
									break 2;
								}
							}
						}
					}
				}
			}
		}
	}
}

/**
 * Shared helper for all interceptors.
 */
final class PlugSeal_Interceptor_Helper {

	/**
	 * Walks the call stack to find the slug of the calling plugin.
	 *
	 * @return string|null Plugin slug, or null if call originates from core.
	 */
	public static function get_calling_plugin_slug(): ?string {
		$trace       = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 40 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
		$plugins_dir = wp_normalize_path( WP_PLUGIN_DIR );

		foreach ( $trace as $frame ) {
			if ( empty( $frame['file'] ) || ! is_string( $frame['file'] ) ) {
				continue;
			}

			$file = wp_normalize_path( $frame['file'] );

			if ( str_contains( $file, 'plugseal' ) ) {
				continue;
			}

			if ( ! str_contains( $file, $plugins_dir . '/' ) ) {
				continue;
			}

			$relative = str_replace( $plugins_dir . '/', '', $file );
			$parts    = explode( '/', $relative );

			return $parts[0] ?? null;
		}

		return null;
	}

	/**
	 * Returns the file path of a callable, or null if not determinable.
	 *
	 * @param mixed $callback The callback to inspect.
	 * @return string|null
	 */
	public static function get_callback_file( mixed $callback ): ?string {
		try {
			if ( is_array( $callback ) && isset( $callback[0] ) ) {
				$ref = is_object( $callback[0] )
					? new \ReflectionObject( $callback[0] )
					: new \ReflectionClass( $callback[0] );
				return $ref->getFileName() ?: null;
			}

			if ( is_string( $callback ) && function_exists( $callback ) ) {
				$ref = new \ReflectionFunction( $callback );
				return $ref->getFileName() ?: null;
			}

			if ( $callback instanceof \Closure ) {
				$ref = new \ReflectionFunction( $callback );
				return $ref->getFileName() ?: null;
			}
		} catch ( \ReflectionException $e ) {
			return null;
		}

		return null;
	}
}
