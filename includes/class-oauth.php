<?php
/**
 * Square-Production-OAuth. Der Merchant klickt „Mit Square verbinden", wird zu Squares Consent-Seite
 * geleitet, kommt mit einem Authorization-Code zurück (Callback-REST-Endpoint), den wir gegen
 * Access-/Refresh-Token tauschen und verschlüsselt ablegen. CSRF-geschützt über einen State-Token.
 *
 * Redirect-URL (in der Square-App eintragen): rest_url( 'steadysync/v1/oauth-callback' )
 * = https://<host>/wp-json/steadysync/v1/oauth-callback
 */
namespace Steadysync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OAuth {

	private const REST_NS         = 'steadysync/v1';
	private const CALLBACK_ROUTE  = '/oauth-callback';
	private const STATE_TRANSIENT = 'steadysync_oauth_state';
	private const SCOPES          = 'ITEMS_READ ITEMS_WRITE INVENTORY_READ INVENTORY_WRITE MERCHANT_PROFILE_READ ORDERS_READ';

	public function __construct( private Settings $settings, private Square_Client $client ) {}

	public function register(): void {
		add_action(
			'rest_api_init',
			function () {
				register_rest_route(
					self::REST_NS,
					self::CALLBACK_ROUTE,
					array(
						'methods'             => 'GET',
						'permission_callback' => '__return_true', // Schutz = State-Token.
						'callback'            => array( $this, 'handle_callback' ),
					)
				);
			}
		);
		add_action( 'admin_post_steadysync_oauth_connect', array( $this, 'start' ) );
	}

	/** Callback-/Redirect-URL — genau diese in die Square-App eintragen. */
	public function redirect_uri(): string {
		return rest_url( self::REST_NS . self::CALLBACK_ROUTE );
	}

	/**
	 * OAuth läuft immer gegen Production. Sandbox nutzt in diesem Plugin die Direkt-Token-Eingabe,
	 * nicht OAuth — so kann keine Production-App-ID versehentlich an den Sandbox-Host gehen.
	 */
	private function oauth_base(): string {
		return 'https://connect.squareup.com';
	}

	/** Baut die Square-Authorize-URL inkl. frischem State-Token. */
	public function authorize_url(): string {
		$state = wp_generate_password( 32, false );
		set_transient( self::STATE_TRANSIENT, $state, 15 * MINUTE_IN_SECONDS );
		return add_query_arg(
			array(
				'client_id' => rawurlencode( $this->settings->oauth_client_id() ),
				'scope'     => rawurlencode( self::SCOPES ),
				'session'   => 'false',
				'state'     => $state,
			),
			$this->oauth_base() . '/oauth2/authorize'
		);
	}

	/** Admin-Aktion: startet den Flow → Weiterleitung zu Square. */
	public function start(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'steadysync_oauth_connect' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'steadysync-for-square' ) );
		}
		if ( ! $this->settings->has_oauth_credentials() ) {
			wp_safe_redirect( $this->admin_url( 'oauth_error', 'missing-credentials' ) );
			exit;
		}
		wp_redirect( $this->authorize_url() ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- externe Square-URL, bewusst kein wp_safe_redirect.
		exit;
	}

	/** REST-Callback: verifiziert State, tauscht Code gegen Token, leitet in den Admin zurück. */
	public function handle_callback( \WP_REST_Request $req ) {
		$state = (string) $req->get_param( 'state' );
		$saved = get_transient( self::STATE_TRANSIENT );
		delete_transient( self::STATE_TRANSIENT );

		if ( '' === $state || ! is_string( $saved ) || ! hash_equals( $saved, $state ) ) {
			return $this->redirect_admin( 'oauth_error', 'invalid-state' );
		}
		if ( $req->get_param( 'error' ) ) {
			return $this->redirect_admin( 'oauth_error', (string) $req->get_param( 'error' ) );
		}
		$code = (string) $req->get_param( 'code' );
		if ( '' === $code ) {
			return $this->redirect_admin( 'oauth_error', 'no-code' );
		}

		// OAuth = Production: Environment umstellen, damit der Token-Exchange gegen den Prod-Host läuft
		// und das Token unter dem Production-Slot landet.
		$this->settings->set( 'environment', 'production' );

		$res = $this->client->exchange_oauth_code(
			$code,
			$this->settings->oauth_client_id(),
			$this->settings->oauth_client_secret(),
			$this->redirect_uri()
		);

		if ( is_array( $res ) && ! empty( $res['access_token'] ) ) {
			$expires = isset( $res['expires_at'] ) ? strtotime( $res['expires_at'] ) : ( time() + 30 * DAY_IN_SECONDS );
			$this->settings->set_token( $res['access_token'], $res['refresh_token'] ?? '', (int) $expires );
			if ( ! empty( $res['merchant_id'] ) ) {
				$this->settings->set( 'merchant_id', sanitize_text_field( $res['merchant_id'] ) );
			}
			do_action( 'steadysync_oauth_connected', $res['merchant_id'] ?? '' );
			return $this->redirect_admin( 'connected', '1' );
		}

		$detail = is_array( $res ) && ! empty( $res['errors'][0]['detail'] ) ? $res['errors'][0]['detail'] : 'exchange-failed';
		do_action( 'steadysync_oauth_failed', $res );
		return $this->redirect_admin( 'oauth_error', $detail );
	}

	private function admin_url( string $key, string $value ): string {
		return add_query_arg(
			array(
				'page' => 'steadysync',
				$key   => rawurlencode( $value ),
			),
			admin_url( 'admin.php' )
		);
	}

	/** Browser-Redirect aus dem REST-Callback in den Admin (beendet den Request). */
	private function redirect_admin( string $key, string $value ) {
		wp_safe_redirect( $this->admin_url( $key, $value ) );
		exit;
	}
}
