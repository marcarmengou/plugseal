<?php
/**
 * Plugin Name: PlugSeal
 * Plugin URI: https://wordpress.org/plugins/plugseal/
 * Description: Control what each active plugin is allowed to do. Allow or deny specific permissions per plugin, with immediate effect.
 * Version: 0.3.0
 * Requires at least: 6.6
 * Tested up to: 7.1
 * Requires PHP: 8.2
 * Author: Marc Armengou
 * Author URI: https://www.marcarmengou.com/
 * Text Domain: plugseal
 * License: GPLv2 or later
 *
 * @package PlugSeal
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'PLUGSEAL_VERSION',  '0.3.0' );
define( 'PLUGSEAL_DIR',      plugin_dir_path( __FILE__ ) );
define( 'PLUGSEAL_URL',      plugin_dir_url( __FILE__ ) );
define( 'PLUGSEAL_BASENAME', plugin_basename( __FILE__ ) );

// Core classes — extended interceptors must load before DB and HTTP
// because they define PlugSeal_Interceptor_Helper used by all interceptors.
require_once PLUGSEAL_DIR . 'includes/class-plugseal-registry.php';
require_once PLUGSEAL_DIR . 'includes/class-interceptors-extended.php';
require_once PLUGSEAL_DIR . 'includes/class-interceptor-hooks.php';
require_once PLUGSEAL_DIR . 'includes/class-interceptor-db.php';
require_once PLUGSEAL_DIR . 'includes/class-interceptor-http.php';
require_once PLUGSEAL_DIR . 'admin/class-admin-page.php';

/**
 * Main plugin class.
 */
final class PlugSeal {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'plugins_loaded', [ $this, 'init' ], 1 );
	}

	/**
	 * Initialise all interceptors and admin UI.
	 */
	public function init(): void {
		// Database & HTTP.
		new PlugSeal_Interceptor_DB();
		new PlugSeal_Interceptor_HTTP();

		// Extended API interceptors.
		new PlugSeal_Interceptor_Options();
		new PlugSeal_Interceptor_Email();
		new PlugSeal_Interceptor_Cron();
		new PlugSeal_Interceptor_Transients();
		new PlugSeal_Interceptor_Users();
		new PlugSeal_Interceptor_Rest();
		new PlugSeal_Interceptor_Shortcodes();
		new PlugSeal_Interceptor_Rewrite();
		new PlugSeal_Interceptor_Dashboard();
		new PlugSeal_Interceptor_Admin_Menu();
		new PlugSeal_Interceptor_Hooks();

		if ( is_admin() ) {
			new PlugSeal_Admin_Page();
		}

		// Remove overrides when a plugin is deleted.
		add_action( 'delete_plugin', static function ( string $plugin_file ): void {
			$slug = explode( '/', $plugin_file )[0];
			PlugSeal_Permission_Registry::remove_all_overrides( $slug );
		} );
	}
}

PlugSeal::instance();
