<?php
/**
 * Admin: settings page (connection, primary location, signature key, webhook URL)
 * + "Test connection" + the dry-run preview.
 * Security: capability check, nonce, sanitization; the token is never echoed in clear text.
 *
 * Add-ons can render extra sections via the `steadysync_admin_sections` action and persist
 * their own posted fields via the `steadysync_admin_save` action.
 */
namespace Steadysync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin {

	public function __construct(
		private Settings $settings,
		private Square_Client $client,
		private Preview $preview,
		private OAuth $oauth
	) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_steadysync_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_steadysync_test', array( $this, 'handle_test' ) );
		add_action( 'admin_post_steadysync_preview', array( $this, 'handle_preview' ) );
	}

	public function menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Steadysync for Square', 'steadysync-for-square' ),
			__( 'Steadysync', 'steadysync-for-square' ),
			'manage_woocommerce',
			'steadysync',
			array( $this, 'render' )
		);
	}

	private function field_row( string $label, string $html, string $desc = '' ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
		echo '</td></tr>';
	}

	public function render(): void {
		$s         = $this->settings;
		$env       = $s->get( 'environment', 'sandbox' );
		$loc       = (string) $s->get( 'location_id', '' );
		$sigkey    = (string) $s->get( 'signature_key', '' );
		$has_token = '' !== $s->access_token();
		$locations = get_transient( 'steadysync_locations' );
		$locations = is_array( $locations ) ? $locations : array();
		$conn      = get_transient( 'steadysync_conn_status' );
		$webhook   = esc_url( \Steadysync\Plugin::instance()->webhook->endpoint_url() );
		$post      = admin_url( 'admin-post.php' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Steadysync — Square ⇄ WooCommerce sync', 'steadysync-for-square' ); ?></h1>

			<?php $this->render_health(); ?>
			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'steadysync-for-square' ); ?></p></div>
			<?php endif; ?>
			<?php if ( is_string( $conn ) && str_starts_with( $conn, 'ok:' ) ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html( sprintf( /* translators: %d: number of Square locations found */ __( 'Connected — %d location(s) found.', 'steadysync-for-square' ), (int) substr( $conn, 3 ) ) ); ?></p></div>
			<?php elseif ( 'fail' === $conn ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'No connection — check the token/environment.', 'steadysync-for-square' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['connected'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Connected to Square (OAuth). Token saved.', 'steadysync-for-square' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['oauth_error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag ?>
				<div class="notice notice-error"><p><?php echo esc_html( sprintf( /* translators: %s: error message */ __( 'OAuth failed: %s', 'steadysync-for-square' ), sanitize_text_field( wp_unslash( $_GET['oauth_error'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag ?></p></div>
			<?php endif; ?>

			<?php $this->render_oauth( $post ); ?>

			<form method="post" action="<?php echo esc_url( $post ); ?>">
				<input type="hidden" name="action" value="steadysync_save" />
				<?php wp_nonce_field( 'steadysync_save' ); ?>
				<table class="form-table" role="presentation">
					<?php
					$this->field_row(
						__( 'Environment', 'steadysync-for-square' ),
						'<select name="environment"><option value="sandbox"' . selected( $env, 'sandbox', false ) . '>Sandbox</option><option value="production"' . selected( $env, 'production', false ) . '>Production</option></select>'
					);
					$this->field_row(
						__( 'Access Token', 'steadysync-for-square' ),
						'<input type="password" name="access_token" class="regular-text" autocomplete="off" placeholder="' . ( $has_token ? esc_attr__( 'stored', 'steadysync-for-square' ) : 'Square Access Token' ) . '" />',
						$has_token ? __( 'Leave empty to keep the stored token.', 'steadysync-for-square' ) : __( 'Sandbox: sandbox access token. Production: via OAuth (below).', 'steadysync-for-square' )
					);
					if ( ! empty( $locations ) ) {
						$opts = '';
						foreach ( $locations as $l ) {
							$id    = $l['id'] ?? '';
							$name  = ( $l['name'] ?? $id ) . ' (' . ( $l['currency'] ?? '' ) . ')';
							$opts .= '<option value="' . esc_attr( $id ) . '"' . selected( $loc, $id, false ) . '>' . esc_html( $name ) . '</option>';
						}
						$loc_field = '<select name="location_id">' . $opts . '</select>';
					} else {
						$loc_field = '<input type="text" name="location_id" class="regular-text" value="' . esc_attr( $loc ) . '" placeholder="L… (dropdown after connection test)" />';
					}
					$this->field_row( __( 'Location', 'steadysync-for-square' ), $loc_field, __( 'The Square location whose stock and catalog are synced into WooCommerce.', 'steadysync-for-square' ) );
					$this->field_row(
						__( 'Webhook Signature Key', 'steadysync-for-square' ),
						'<input type="text" name="signature_key" class="regular-text" value="' . esc_attr( $sigkey ) . '" />',
						__( 'From the Square dashboard (webhook subscription). Enforces HMAC verification.', 'steadysync-for-square' )
					);
					$this->field_row(
						__( 'Webhook URL (add in Square)', 'steadysync-for-square' ),
						'<code>' . $webhook . '</code>'
					);
					?>
				</table>
				<?php submit_button( __( 'Save settings', 'steadysync-for-square' ) ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( $post ); ?>" style="margin-top:-1em">
				<input type="hidden" name="action" value="steadysync_test" />
				<?php wp_nonce_field( 'steadysync_test' ); ?>
				<?php submit_button( __( 'Test connection', 'steadysync-for-square' ), 'secondary', 'submit', false ); ?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Preview (dry run) — what a sync would change', 'steadysync-for-square' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Computes the changes without writing anything. Shows exactly what happens before you sync.', 'steadysync-for-square' ); ?></p>
			<form method="post" action="<?php echo esc_url( $post ); ?>">
				<input type="hidden" name="action" value="steadysync_preview" />
				<?php wp_nonce_field( 'steadysync_preview' ); ?>
				<?php submit_button( __( 'Compute preview', 'steadysync-for-square' ), 'secondary' ); ?>
			</form>
			<?php $this->render_preview(); ?>

			<?php
			/**
			 * Extension point for add-ons to render their own settings sections
			 * (e.g. Woo→Square push, order sync, multi-location, 1-click migration).
			 *
			 * @param Settings $settings
			 */
			do_action( 'steadysync_admin_sections', $this->settings );
			?>
		</div>
		<?php
	}

	/** Renders the last computed dry-run preview (from a transient), if present. */
	private function render_preview(): void {
		$pv = get_transient( 'steadysync_preview' );
		if ( ! is_array( $pv ) ) {
			return;
		}

		$cat = $pv['catalog'] ?? array();
		echo '<h4>' . esc_html__( 'Catalog changes', 'steadysync-for-square' ) . '</h4>';
		if ( empty( $cat ) ) {
			echo '<p>' . esc_html__( 'No catalog changes.', 'steadysync-for-square' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Product', 'steadysync-for-square' ) . '</th><th>' . esc_html__( 'Action', 'steadysync-for-square' ) . '</th><th>' . esc_html__( 'Changes', 'steadysync-for-square' ) . '</th></tr></thead><tbody>';
			foreach ( array_slice( $cat, 0, 100 ) as $row ) {
				$parts = array();
				foreach ( (array) ( $row['changes'] ?? array() ) as $field => $pair ) {
					$parts[] = $field . ': ' . ( '' === $pair[0] ? '—' : $pair[0] ) . ' → ' . $pair[1];
				}
				echo '<tr><td>' . esc_html( $row['name'] ?? '' ) . '</td><td>' . esc_html( $row['action'] ?? '' ) . '</td><td>' . esc_html( implode( ' · ', $parts ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		$inv = $pv['inventory'] ?? array();
		echo '<h4>' . esc_html__( 'Stock changes', 'steadysync-for-square' ) . '</h4>';
		if ( empty( $inv ) ) {
			echo '<p>' . esc_html__( 'No stock differences (WC = Square).', 'steadysync-for-square' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Product', 'steadysync-for-square' ) . '</th><th>WC</th><th>Square</th><th>&Delta;</th></tr></thead><tbody>';
			foreach ( array_slice( $inv, 0, 200 ) as $row ) {
				echo '<tr><td>' . esc_html( $row['name'] ?? '' ) . '</td><td>' . (int) ( $row['wc_stock'] ?? 0 ) . '</td><td>' . (int) ( $row['sq_stock'] ?? 0 ) . '</td><td>' . esc_html( ( ( $row['delta'] ?? 0 ) > 0 ? '+' : '' ) . (int) ( $row['delta'] ?? 0 ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
	}

	/** Compact health panel at the top of the page (connection, last success, errors). */
	private function render_health(): void {
		$h     = \Steadysync\Plugin::instance()->health->state();
		$conn  = $h['connection'];
		$color = 'ok' === $conn ? '#46b450' : ( 'fail' === $conn ? '#dc3232' : '#999' );
		$label = 'ok' === $conn ? __( 'connected', 'steadysync-for-square' ) : ( 'fail' === $conn ? __( 'no connection', 'steadysync-for-square' ) : __( 'unknown', 'steadysync-for-square' ) );
		echo '<div class="notice notice-info" style="padding:8px 12px"><strong>' . esc_html__( 'Health', 'steadysync-for-square' ) . ':</strong> ';
		echo '<span style="color:' . esc_attr( $color ) . '">&#9679; ' . esc_html( $label ) . '</span>';
		if ( $h['last_success'] ) {
			echo ' · ' . esc_html__( 'last success', 'steadysync-for-square' ) . ': ' . esc_html( $h['last_success'] );
		}
		if ( (int) $h['failures'] > 0 ) {
			echo ' · <span style="color:#dc3232">' . esc_html( sprintf( /* translators: %d: failure count */ __( '%d errors', 'steadysync-for-square' ), (int) $h['failures'] ) ) . '</span>';
			if ( $h['last_error'] ) {
				echo ' (' . esc_html( $h['last_error'] ) . ')';
			}
		}
		echo '</div>';
	}

	/** Production OAuth section: credentials + "Connect to Square" button + redirect URI. */
	private function render_oauth( string $post ): void {
		$has_id     = '' !== $this->settings->oauth_client_id();
		$has_secret = '' !== $this->settings->oauth_client_secret();
		$connected  = '' !== (string) $this->settings->get( 'merchant_id', '' );
		$redirect   = esc_url( $this->oauth->redirect_uri() );
		echo '<hr /><h2>' . esc_html__( 'Production connection (OAuth)', 'steadysync-for-square' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'For live operation: enter your Square production app credentials, set the redirect URL in the Square app, then connect.', 'steadysync-for-square' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Redirect URL (add in the Square app):', 'steadysync-for-square' ) . '</strong><br><code>' . $redirect . '</code></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '<form method="post" action="' . esc_url( $post ) . '">';
		echo '<input type="hidden" name="action" value="steadysync_save" />';
		wp_nonce_field( 'steadysync_save' );
		echo '<table class="form-table" role="presentation">';
		$this->field_row(
			__( 'Production Application ID', 'steadysync-for-square' ),
			'<input type="text" name="oauth_client_id" class="regular-text" value="' . esc_attr( $this->settings->oauth_client_id() ) . '" placeholder="sq0idp-…" />'
		);
		$this->field_row(
			__( 'Production Application Secret', 'steadysync-for-square' ),
			'<input type="password" name="oauth_client_secret" class="regular-text" autocomplete="off" placeholder="' . ( $has_secret ? esc_attr__( 'stored', 'steadysync-for-square' ) : 'sq0csp-…' ) . '" />',
			$has_secret ? __( 'Leave empty to keep the stored secret.', 'steadysync-for-square' ) : ''
		);
		echo '</table>';
		submit_button( __( 'Save credentials', 'steadysync-for-square' ), 'secondary' );
		echo '</form>';

		if ( $connected ) {
			echo '<p>' . esc_html( sprintf( /* translators: %s: merchant id */ __( 'Connected (merchant %s).', 'steadysync-for-square' ), (string) $this->settings->get( 'merchant_id' ) ) ) . '</p>';
		}
		if ( $has_id && $has_secret ) {
			echo '<form method="post" action="' . esc_url( $post ) . '" style="margin-top:.5em">';
			echo '<input type="hidden" name="action" value="steadysync_oauth_connect" />';
			wp_nonce_field( 'steadysync_oauth_connect' );
			submit_button( $connected ? __( 'Reconnect to Square', 'steadysync-for-square' ) : __( 'Connect to Square', 'steadysync-for-square' ), 'primary', 'submit', false );
			echo '</form>';
		} else {
			echo '<p class="description">' . esc_html__( 'Save credentials, then the connect button appears.', 'steadysync-for-square' ) . '</p>';
		}
	}

	public function handle_preview(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'steadysync_preview' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'steadysync-for-square' ) );
		}
		$pv = array(
			'catalog'   => $this->preview->catalog_diff( null ),
			'inventory' => $this->preview->inventory_diff(),
		);
		set_transient( 'steadysync_preview', $pv, 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'steadysync',
					'previewed' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'steadysync_save' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'steadysync-for-square' ) );
		}
		$env = ( isset( $_POST['environment'] ) && 'production' === $_POST['environment'] ) ? 'production' : 'sandbox';
		$this->settings->set( 'environment', $env );
		$this->settings->set( 'location_id', sanitize_text_field( wp_unslash( $_POST['location_id'] ?? '' ) ) );
		$this->settings->set( 'signature_key', sanitize_text_field( wp_unslash( $_POST['signature_key'] ?? '' ) ) );

		// Only set the token when a new one was entered (ignore the placeholder/empty).
		// Secrets are stored verbatim (encrypted at rest); sanitize_text_field would corrupt them.
		$tok = trim( (string) wp_unslash( $_POST['access_token'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '' !== $tok && ! str_contains( $tok, '•' ) ) {
			$this->settings->set_token( $tok );
		}

		// OAuth credentials (production). Overwrite the secret only when newly entered.
		if ( isset( $_POST['oauth_client_id'] ) || isset( $_POST['oauth_client_secret'] ) ) {
			$oid = sanitize_text_field( wp_unslash( $_POST['oauth_client_id'] ?? $this->settings->oauth_client_id() ) );
			$sec = trim( (string) wp_unslash( $_POST['oauth_client_secret'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$sec = ( '' !== $sec && ! str_contains( $sec, '•' ) ) ? $sec : '';
			$this->settings->set_oauth_credentials( $oid, $sec );
		}

		/**
		 * Lets add-ons persist their own posted settings fields. The core nonce
		 * `steadysync_save` has already been verified at this point. Add-ons read and
		 * sanitize the specific fields they own from $_POST themselves.
		 */
		do_action( 'steadysync_admin_save' );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'  => 'steadysync',
					'saved' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_test(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'steadysync_test' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'steadysync-for-square' ) );
		}
		$locs = $this->client->get_locations();
		set_transient( 'steadysync_locations', $locs, HOUR_IN_SECONDS );
		set_transient( 'steadysync_conn_status', ! empty( $locs ) ? 'ok:' . count( $locs ) : 'fail', 300 );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => 'steadysync',
					'tested' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
