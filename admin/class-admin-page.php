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
		add_action( 'admin_init',            [ $this, 'handle_data_form' ] );
		add_action( 'wp_ajax_plugseal_set_override',    [ $this, 'ajax_set_override' ] );
		add_action( 'wp_ajax_plugseal_remove_override', [ $this, 'ajax_remove_override' ] );
	}

	/**
	 * Registers the settings page under the Settings menu.
	 */
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
					'allowed' => esc_html__( 'Allowed', 'plugseal' ),
					'denied'  => esc_html__( 'Denied',  'plugseal' ),
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

		$active_plugins = $this->get_active_plugin_slugs();
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
					<?php foreach ( $active_plugins as $slug ) : ?>
						<button
							class="plugseal-plugin-item"
							data-slug="<?php echo esc_attr( $slug ); ?>"
						>
							<span class="plugseal-plugin-name"><?php echo esc_html( $slug ); ?></span>
							<?php if ( $this->has_denied_permissions( $slug, $overrides ) ) : ?>
								<span class="plugseal-badge badge-restricted"><?php esc_html_e( 'restricted', 'plugseal' ); ?></span>
							<?php endif; ?>
						</button>
					<?php endforeach; ?>
				</nav>

				<!-- Permissions panel -->
				<div class="plugseal-permissions-panel">
					<p class="plugseal-select-hint" id="plugseal-select-hint">
						<?php esc_html_e( 'Select a plugin to manage its permissions.', 'plugseal' ); ?>
					</p>

					<?php foreach ( $active_plugins as $slug ) : ?>
						<div class="plugseal-plugin-detail" data-slug="<?php echo esc_attr( $slug ); ?>" hidden>
							<h2><?php echo esc_html( $slug ); ?></h2>
							<?php
							$hook_categories = PlugSeal_Interceptor_Hooks::get_hook_categories();
							foreach ( PlugSeal_Permission_Registry::PERMISSION_GROUPS as $group_label => $group_perms ) : ?>
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
													<span
														class="plugseal-hooks-hint"
														title="<?php echo esc_attr( $hooks_title ); ?>"
													>?</span>
												<?php endif; ?>
											</td>
											<td>
												<button
													class="plugseal-toggle <?php echo esc_attr( $allowed ? 'is-allowed' : 'is-denied' ); ?>"
													data-slug="<?php echo esc_attr( $slug ); ?>"
													data-perm="<?php echo esc_attr( $perm ); ?>"
													data-allowed="<?php echo esc_attr( $allowed ? '1' : '0' ); ?>"
													aria-label="<?php echo esc_attr( sprintf( /* translators: 1: permission, 2: plugin */ __( 'Toggle %1$s for %2$s', 'plugseal' ), $perm, $slug ) ); ?>"
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
				<?php submit_button( esc_html__( 'Save', 'plugseal' ), 'secondary', 'submit', false ); ?>
			</form>
		</section>
		<?php
	}

	/**
	 * Returns the slugs of all active plugins except this one.
	 *
	 * @return string[]
	 */
	private function get_active_plugin_slugs(): array {
		$active  = (array) get_option( 'active_plugins', [] );
		$slugs   = [];

		foreach ( $active as $plugin_file ) {
			if ( ! is_string( $plugin_file ) ) {
				continue;
			}
			if ( str_contains( $plugin_file, 'plugseal' ) ) {
				continue;
			}
			$slugs[] = explode( '/', $plugin_file )[0];
		}

		sort( $slugs );
		return $slugs;
	}

	/**
	 * Returns true if a plugin has at least one denied permission.
	 *
	 * @param string                          $slug      Plugin slug.
	 * @param array<string, array<string, bool>> $overrides All overrides.
	 */
	private function has_denied_permissions( string $slug, array $overrides ): bool {
		if ( ! isset( $overrides[ $slug ] ) ) {
			return false;
		}
		foreach ( $overrides[ $slug ] as $value ) {
			if ( false === $value || '0' === (string) $value ) {
				return true;
			}
		}
		return false;
	}

	// -------------------------------------------------------------------------
	// AJAX handlers
	// -------------------------------------------------------------------------

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
