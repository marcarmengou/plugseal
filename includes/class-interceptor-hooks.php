<?php
/**
 * Hooks interceptor for PlugSeal.
 *
 * Removes callbacks registered by denied plugins from specific hook categories.
 * Runs after all plugins have registered their hooks but before most hooks fire.
 *
 * Honest limitations:
 * - Cannot intercept hooks that fire before `init` (e.g. plugins_loaded, muplugins_loaded)
 * - Anonymous closures may not always be attributable to a specific plugin
 *
 * @package PlugSeal
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Hooks interceptor.
 */
final class PlugSeal_Interceptor_Hooks {

	/**
	 * Hooks grouped by permission category.
	 * These are the hooks we monitor and can remove callbacks from.
	 *
	 * @var array<string, string[]>
	 */
	private const HOOK_CATEGORIES = [
		'hooks:frontend' => [
			'wp_head',
			'wp_footer',
			'wp_body_open',
			'wp_enqueue_scripts',
			'wp_print_styles',
			'wp_print_scripts',
			'the_content',
			'the_excerpt',
			'the_title',
			'comment_text',
			'widget_text',
			'template_redirect',
			'template_include',
			'get_header',
			'get_footer',
			'get_sidebar',
		],
		'hooks:admin' => [
			'admin_head',
			'admin_footer',
			'admin_notices',
			'admin_enqueue_scripts',
			'admin_print_styles',
			'admin_print_scripts',
			'admin_bar_menu',
			'add_meta_boxes',
			'post_submitbox_misc_actions',
			'bulk_actions',
			'manage_posts_columns',
			'manage_pages_columns',
		],
		'hooks:auth' => [
			'authenticate',
			'wp_authenticate',
			'wp_login',
			'wp_logout',
			'wp_login_failed',
			'user_register',
			'profile_update',
			'password_reset',
			'retrieve_password',
			'lostpassword_post',
			'registration_errors',
			'login_form',
			'login_redirect',
		],
		'hooks:content' => [
			'save_post',
			'pre_post_update',
			'wp_insert_post',
			'delete_post',
			'trash_post',
			'untrash_post',
			'pre_get_posts',
			'the_posts',
			'post_updated',
			'attachment_updated',
			'add_attachment',
			'edit_attachment',
			'delete_attachment',
			'wp_handle_upload',
			'wp_handle_sideload',
		],
		'hooks:lifecycle' => [
			'activated_plugin',
			'deactivated_plugin',
			'upgrader_process_complete',
			'switch_theme',
			'after_switch_theme',
			'wp_update_nav_menu',
			'update_option_active_plugins',
			'pre_update_option_active_plugins',
			'wp_ajax_nopriv_',
			'wp_ajax_',
		],
	];

	public function __construct() {
		// Run at init PHP_INT_MAX — after all plugins have registered their hooks.
		add_action( 'init',       [ $this, 'remove_denied_hooks' ], PHP_INT_MAX );
		add_action( 'admin_init', [ $this, 'remove_denied_hooks' ], PHP_INT_MAX );
	}

	/**
	 * Iterates over all hook categories and removes callbacks
	 * from plugins that have the category permission denied.
	 */
	public function remove_denied_hooks(): void {
		$active_plugins = (array) get_option( 'active_plugins', [] );

		// Build a map of slug → denied hook categories for efficiency.
		$denied = [];
		foreach ( $active_plugins as $plugin_file ) {
			if ( ! is_string( $plugin_file ) || str_contains( $plugin_file, 'plugseal' ) ) {
				continue;
			}

			$slug = explode( '/', $plugin_file )[0];

			foreach ( array_keys( self::HOOK_CATEGORIES ) as $perm ) {
				if ( ! PlugSeal_Permission_Registry::can( $slug, $perm ) ) {
					$denied[ $slug ][] = $perm;
				}
			}
		}

		if ( empty( $denied ) ) {
			return;
		}

		// For each denied plugin, remove its callbacks from the relevant hooks.
		foreach ( $denied as $slug => $denied_perms ) {
			foreach ( $denied_perms as $perm ) {
				foreach ( self::HOOK_CATEGORIES[ $perm ] as $hook_name ) {
					$this->remove_plugin_callbacks_from_hook( $hook_name, $slug );
				}
			}
		}
	}

	/**
	 * Removes all callbacks registered by a specific plugin from a hook.
	 *
	 * @param string $hook_name Hook name.
	 * @param string $slug      Plugin slug.
	 */
	private function remove_plugin_callbacks_from_hook( string $hook_name, string $slug ): void {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook_name ] ) ) {
			return;
		}

		$plugins_dir = wp_normalize_path( WP_PLUGIN_DIR );

		foreach ( $wp_filter[ $hook_name ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $key => $callback ) {
				$file = PlugSeal_Interceptor_Helper::get_callback_file( $callback['function'] );

				if ( null === $file ) {
					continue;
				}

				$file = wp_normalize_path( $file );

				// Only remove callbacks that belong to this plugin's directory.
				if ( str_contains( $file, $plugins_dir . '/' . $slug . '/' ) ) {
					unset( $wp_filter[ $hook_name ]->callbacks[ $priority ][ $key ] );
				}
			}
		}
	}

	/**
	 * Returns the full hook categories map.
	 * Used by the admin UI to show which hooks are covered per category.
	 *
	 * @return array<string, string[]>
	 */
	public static function get_hook_categories(): array {
		return self::HOOK_CATEGORIES;
	}
}
