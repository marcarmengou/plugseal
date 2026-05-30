<?php
/**
 * Uninstall routine for PlugSeal.
 *
 * Only runs when the plugin is deleted via the WordPress admin.
 * Data is preserved by default; deletion requires administrator opt-in
 * via Settings → PlugSeal → Data.
 *
 * @package PlugSeal
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/admin/class-admin-page.php';

$plugseal_delete_data = (bool) get_option(
	PlugSeal_Admin_Page::OPTION_DELETE_ON_UNINSTALL,
	false
);

if ( $plugseal_delete_data ) {
	delete_option( 'plugseal_overrides' );
	delete_option( PlugSeal_Admin_Page::OPTION_DELETE_ON_UNINSTALL );
}
