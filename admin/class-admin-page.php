<?php
/**
 * Administration interface for PlugSeal.
 *
 * Displays the list of active plugins and allows administrators
 * to allow or deny individual permissions per plugin.
 *
 * @package PlugSeal
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the admin settings page.
 */
final class PlugSeal_Admin_Page {

	/**
	 * Required capability to access the settings page.
	 */
	private const CAPABILITY = 'manage_options';

	/**
	 * Admin page slug.
	 */
	private const PAGE_SLUG = 'plugseal';

	/**
	 * Nonce action for AJAX requests.
	 */
	private const NONCE_ACTION = 'plugseal_admin_action';

	/**
	 * Nonce action for the data settings form.
	 */
	private const NONCE_DATA = 'plugseal_data';

	/**
	 * Option key for the delete-on-uninstall setting.
	 */
	public const OPTION_DELETE_ON_UNINSTALL = 'plugseal_delete_data_on_uninstall';

	public function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_filter( 'plugin_action_links_' . PLUGSEAL_BASENAME, [ $this, 'add_settings_link' ] );
		add_action( 'admin_init',            [ $this, 'handle_data_form' ] );
		add_action( 'wp_ajax_plugseal_set_override',    [ $this, 'ajax_set_override' ] );
		add_action( 'wp_ajax_plugseal_remove_override', [ $this, 'ajax_remove_override' ] );
		add_action( 'wp_ajax_plugseal_reset_plugin',    [ $this, 'ajax_reset_plugin' ] );
	}

	/**
	 * Adds a Settings link to the plugin action links.
	 *
	 * @param array<string> $links Existing action links.
	 * @return array<string>
	 */
	public function add_settings_link( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ),
			esc_html__( 'Settings', 'plugseal' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}

	public function register_menu(): void {
		add_options_page(
			esc_html__( 'PlugSeal', 'plugseal' ),
			esc_html__( 'PlugSeal', 'plugseal' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Enqueues admin assets only on our page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'plugseal-admin',
			PLUGSEAL_URL . 'assets/css/admin.css',
			[],
			PLUGSEAL_VERSION
		);

		wp_enqueue_script(
			'plugseal-admin',
			PLUGSEAL_URL . 'assets/js/admin.js',
			[ 'jquery' ],
			PLUGSEAL_VERSION,
			true
		);

		wp_localize_script(
			'plugseal-admin',
			'PlugSeal',
			[
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'i18n'    => [
					'allowed'          => esc_html__( 'Allowed', 'plugseal' ),
					'denied'           => esc_html__( 'Denied', 'plugseal' ),

					/* translators: %s: plugin name */
					'reset_confirm'    => esc_html__( 'Reset all permissions for %s to defaults?', 'plugseal' ),
				],
			]
		);
	}

	/**
	 * Handles the data settings form submission.
	 */
	public function handle_data_form(): void {
		if ( ! isset( $_POST['plugseal_data_form'] ) ) {
			return;
		}

		check_admin_referer( self::NONCE_DATA );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'plugseal' ) );
		}

		$delete = isset( $_POST['plugseal_delete_on_uninstall'] ) ? 1 : 0;
		update_option( self::OPTION_DELETE_ON_UNINSTALL, $delete );

		set_transient( 'plugseal_saved', '1', 30 );

		wp_safe_redirect(
			add_query_arg(
				[ 'page' => self::PAGE_SLUG ],
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Renders the full settings page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'plugseal' ) );
		}

		$active_plugins = $this->get_active_plugins_data();
		$overrides      = PlugSeal_Permission_Registry::get_overrides();
		?>
		<div class="wrap plugseal-wrap">
			<h1><?php esc_html_e( 'PlugSeal', 'plugseal' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Allow or deny specific permissions for each active plugin. All permissions are allowed by default.', 'plugseal' ); ?>
			</p>

			<?php if ( get_transient( 'plugseal_saved' ) ) :
				delete_transient( 'plugseal_saved' );
				?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Settings saved.', 'plugseal' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="plugseal-layout">

				<!-- Plugin list -->
				<nav class="plugseal-plugin-list" aria-label="<?php esc_attr_e( 'Plugin list', 'plugseal' ); ?>">
					<?php foreach ( $active_plugins as $plugin_data ) :
						$slug         = $plugin_data['slug'];
						$name         = $plugin_data['name'];
						$denied_count = $this->count_denied_permissions( $slug, $overrides );
					?>
						<button
							class="plugseal-plugin-item"
							data-slug="<?php echo esc_attr( $slug ); ?>"
							title="<?php echo esc_attr( $name ); ?>"
						>
							<span class="plugseal-plugin-name"><?php echo esc_html( $name ); ?></span>
							<?php if ( $denied_count > 0 ) : ?>
								<span class="plugseal-count-badge"><?php echo esc_html( $denied_count ); ?></span>
							<?php endif; ?>
						</button>
					<?php endforeach; ?>
				</nav>

				<!-- Permissions panel -->
				<div class="plugseal-permissions-panel">
					<p class="plugseal-select-hint" id="plugseal-select-hint">
						<?php esc_html_e( 'Select a plugin to manage its permissions.', 'plugseal' ); ?>
					</p>

					<?php foreach ( $active_plugins as $plugin_data ) :
						$slug = $plugin_data['slug'];
						$name = $plugin_data['name'];
					?>
						<div class="plugseal-plugin-detail" data-slug="<?php echo esc_attr( $slug ); ?>" hidden>
							<div class="plugseal-plugin-detail-header">
								<h2 tabindex="-1"><?php echo esc_html( $name ); ?></h2>
								<button
									class="button plugseal-reset-btn"
									data-slug="<?php echo esc_attr( $slug ); ?>"
									title="<?php esc_attr_e( 'Reset all permissions to defaults', 'plugseal' ); ?>"
								>
									<?php esc_html_e( 'Reset to defaults', 'plugseal' ); ?>
								</button>
							</div>
							<?php
							$hook_categories    = PlugSeal_Interceptor_Hooks::get_hook_categories();
							$group_labels       = PlugSeal_Permission_Registry::get_group_labels();
							$perm_descriptions  = PlugSeal_Permission_Registry::get_permission_descriptions();
							foreach ( PlugSeal_Permission_Registry::PERMISSION_GROUPS as $group_key => $group_perms ) :
								$group_label = $group_labels[ $group_key ] ?? $group_key;
							?>
							<h3 class="plugseal-group-label"><?php echo esc_html( $group_label ); ?></h3>
							<table class="plugseal-perms-table widefat">
								<thead>
									<tr>
										<th scope="col"><?php esc_html_e( 'Permission', 'plugseal' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Status', 'plugseal' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $group_perms as $perm ) :
										$allowed     = PlugSeal_Permission_Registry::can( $slug, $perm );
										$hooks_list  = $hook_categories[ $perm ] ?? [];
										$hooks_title = ! empty( $hooks_list )
											? implode( ', ', $hooks_list )
											: '';
										?>
										<tr>
											<td>
												<code><?php echo esc_html( $perm ); ?></code>
												<?php if ( $hooks_title ) : ?>
													<br><span class="plugseal-hooks-desc"><?php echo esc_html( $hooks_title ); ?></span>
												<?php elseif ( ! empty( $perm_descriptions[ $perm ] ) ) : ?>
													<br><span class="plugseal-hooks-desc"><?php echo esc_html( $perm_descriptions[ $perm ] ); ?></span>
												<?php endif; ?>
											</td>
											<td>
												<button
													class="plugseal-toggle <?php echo esc_attr( $allowed ? 'is-allowed' : 'is-denied' ); ?>"
													data-slug="<?php echo esc_attr( $slug ); ?>"
													data-perm="<?php echo esc_attr( $perm ); ?>"
													data-allowed="<?php echo esc_attr( $allowed ? '1' : '0' ); ?>"
													>
													<?php echo $allowed ? esc_html__( 'Allowed', 'plugseal' ) : esc_html__( 'Denied', 'plugseal' ); ?>
												</button>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
							<?php endforeach; ?>
						</div>
					<?php endforeach; ?>
				</div>

			</div>

			<?php $this->render_data_section(); ?>
		</div>
		<?php
	}

	/**
	 * Renders the data settings section.
	 */
	private function render_data_section(): void {
		$delete_on_uninstall = (bool) get_option( self::OPTION_DELETE_ON_UNINSTALL, false );
		?>
		<section class="plugseal-data-section">
			<h2><?php esc_html_e( 'Data', 'plugseal' ); ?></h2>
			<form method="post" action="">
				<?php wp_nonce_field( self::NONCE_DATA ); ?>
				<input type="hidden" name="plugseal_data_form" value="1">
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'Data settings', 'plugseal' ); ?></legend>
					<label>
						<input
							type="checkbox"
							name="plugseal_delete_on_uninstall"
							value="1"
							<?php checked( $delete_on_uninstall ); ?>
						>
						<?php esc_html_e( 'Delete all plugin data on uninstall', 'plugseal' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'If checked, the permission overrides and settings will be permanently deleted when the plugin is uninstalled.', 'plugseal' ); ?>
					</p>
				</fieldset>
				<?php submit_button( esc_html__( 'Save', 'plugseal' ), 'secondary', 'submit', false ); ?>
			</form>
		</section>
		<?php
	}

	/**
	 * Returns data for all active plugins except this one.
	 *
	 * @return array<string, array{slug: string, name: string, file: string}>
	 */
	private function get_active_plugins_data(): array {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$active  = (array) get_option( 'active_plugins', [] );
		$plugins = [];

		foreach ( $active as $plugin_file ) {
			if ( ! is_string( $plugin_file ) ) {
				continue;
			}
			if ( str_contains( $plugin_file, 'plugseal' ) ) {
				continue;
			}
			$slug = explode( '/', $plugin_file )[0];
			$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false, false );
			$name = ! empty( $data['Name'] ) ? $data['Name'] : $slug;
			$plugins[ $slug ] = [
				'slug' => $slug,
				'name' => $name,
				'file' => $plugin_file,
			];
		}

		uasort( $plugins, static fn( $a, $b ) => strcmp( $a['name'], $b['name'] ) );
		return $plugins;
	}

	/**
	 * Returns the number of denied permissions for a plugin.
	 *
	 * @param string                             $slug      Plugin slug.
	 * @param array<string, array<string, bool>> $overrides All overrides.
	 */
	private function count_denied_permissions( string $slug, array $overrides ): int {
		if ( ! isset( $overrides[ $slug ] ) ) {
			return 0;
		}
		$count = 0;
		foreach ( $overrides[ $slug ] as $value ) {
			if ( false === $value || '0' === (string) $value ) {
				++$count;
			}
		}
		return $count;
	}

	// -------------------------------------------------------------------------
	// AJAX handlers
	// -------------------------------------------------------------------------

	/**
	 * Handles the reset_plugin AJAX action.
	 */
	public function ajax_reset_plugin(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'plugseal' ) ], 403 );
		}

		$slug = sanitize_key( wp_unslash( $_POST['slug'] ?? '' ) );

		if ( '' === $slug ) {
			wp_send_json_error( [ 'message' => __( 'Invalid parameters.', 'plugseal' ) ], 400 );
		}

		PlugSeal_Permission_Registry::remove_all_overrides( $slug );

		wp_send_json_success();
	}

	/**
	 * Handles the set_override AJAX action.
	 */
	public function ajax_set_override(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'plugseal' ) ], 403 );
		}

		$slug  = sanitize_key( wp_unslash( $_POST['slug'] ?? '' ) );
		$perm  = sanitize_text_field( wp_unslash( $_POST['perm'] ?? '' ) );
		$value = sanitize_text_field( wp_unslash( $_POST['value'] ?? '' ) );

		if ( '' === $slug || '' === $perm ) {
			wp_send_json_error( [ 'message' => __( 'Invalid parameters.', 'plugseal' ) ], 400 );
		}

		if ( ! in_array( $perm, PlugSeal_Permission_Registry::KNOWN_PERMISSIONS, true ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown permission.', 'plugseal' ) ], 400 );
		}

		if ( '' === $value ) {
			PlugSeal_Permission_Registry::remove_override( $slug, $perm );
		} else {
			PlugSeal_Permission_Registry::set_override( $slug, $perm, '1' === $value );
		}

		wp_send_json_success( [
			'allowed' => PlugSeal_Permission_Registry::can( $slug, $perm ),
		] );
	}

	/**
	 * Handles the remove_override AJAX action.
	 */
	public function ajax_remove_override(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'plugseal' ) ], 403 );
		}

		$slug = sanitize_key( wp_unslash( $_POST['slug'] ?? '' ) );
		$perm = sanitize_text_field( wp_unslash( $_POST['perm'] ?? '' ) );

		if ( '' === $slug || '' === $perm ) {
			wp_send_json_error( [ 'message' => __( 'Invalid parameters.', 'plugseal' ) ], 400 );
		}

		PlugSeal_Permission_Registry::remove_override( $slug, $perm );
		wp_send_json_success();
	}
}
