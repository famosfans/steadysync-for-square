<?php
/**
 * Square-Webhook-Empfänger: HMAC-Signaturprüfung + Idempotenz (fixt "duplicate events")
 * + Routing an Anti-Zeroing-Inventar-Sync UND Katalog-Delta-Sync. Spike-validiert.
 */
namespace Steadysync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Webhook {

	private const SEEN_OPTION = 'steadysync_seen_events';
	private const REST_NS     = 'steadysync/v1';
	private const REST_ROUTE  = '/square-webhook';

	public function __construct(
		private Settings $settings,
		private Inventory_Sync $inventory,
		private Catalog_Sync $catalog
	) {}

	public function register(): void {
		add_action(
			'rest_api_init',
			function () {
				register_rest_route(
					self::REST_NS,
					self::REST_ROUTE,
					array(
						'methods'             => 'POST',
						'permission_callback' => '__return_true', // Auth = Signatur
						'callback'            => array( $this, 'handle' ),
					)
				);
			}
		);
	}

	public function endpoint_url(): string {
		return rest_url( self::REST_NS . self::REST_ROUTE );
	}

	private function verify_signature( \WP_REST_Request $req ): bool {
		$key = (string) $this->settings->get( 'signature_key', '' );
		if ( '' === $key ) {
			// FAIL CLOSED: ohne Signaturschlüssel ist der Endpoint nicht authentifizierbar → ablehnen.
			// (Der Merchant muss den Key aus der Square-Webhook-Subscription eintragen — Pflicht-Setup.)
			return false;
		}
		$configured = (string) $this->settings->get( 'webhook_url' );
		$url        = '' !== $configured ? $configured : $this->endpoint_url();
		$sig        = $req->get_header( 'x_square_hmacsha256_signature' );
		if ( ! $sig ) {
			return false;
		}
		$expected = base64_encode( hash_hmac( 'sha256', $url . $req->get_body(), $key, true ) );
		return hash_equals( $expected, $sig );
	}

	public function handle( \WP_REST_Request $req ) {
		if ( ! $this->verify_signature( $req ) ) {
			return new \WP_REST_Response( array( 'error' => 'invalid-signature' ), 401 );
		}
		$data     = json_decode( $req->get_body(), true );
		$event_id = is_array( $data ) ? ( $data['event_id'] ?? '' ) : '';
		$type     = is_array( $data ) ? ( $data['type'] ?? '' ) : '';

		// Idempotenz — kein Doppel-Anwenden bei Webhook-Retries.
		$seen = get_option( self::SEEN_OPTION, array() );
		if ( $event_id && in_array( $event_id, $seen, true ) ) {
			return new \WP_REST_Response(
				array(
					'status'   => 'duplicate-ignored',
					'event_id' => $event_id,
				),
				200
			);
		}

		$result = array(
			'status' => 'ignored',
			'type'   => $type,
		);

		if ( 'inventory.count.updated' === $type ) {
			$counts = $data['data']['object']['inventory_counts'] ?? array();
			$result = array(
				'status'  => 'processed',
				'type'    => $type,
				'applied' => $this->inventory->apply_inventory_counts( $counts ),
			);
		} elseif ( 'catalog.version.updated' === $type ) {
			// Delta: geänderte Katalog-Items seit letztem Sync übernehmen.
			$last   = get_option( Catalog_Sync::LAST_SYNC_OPT, null );
			$result = array(
				'status'  => 'processed',
				'type'    => $type,
				'applied' => $this->catalog->sync_since( $last ),
			);
		}

		if ( $event_id ) {
			$seen[] = $event_id;
			update_option( self::SEEN_OPTION, array_slice( $seen, -1000 ), false );
		}
		return new \WP_REST_Response( $result, 200 );
	}
}
