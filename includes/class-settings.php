<?php
/**
 * Settings-Speicher (Sandbox/Prod, Token, Location, System-of-Record, Signaturschlüssel).
 * Token wird verschlüsselt abgelegt (Lehre aus dem offiziellen Plugin: Klartext-Token ist tabu).
 */
namespace Steadysync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings {

	private const OPTION = 'steadysync_settings';

	public static function defaults(): array {
		return array(
			'environment'      => 'sandbox',      // sandbox | production
			'location_id'      => '',             // Location, deren Square-Bestand in den WooCommerce-Stock gespiegelt wird.
			'system_of_record' => 'square',       // square | woocommerce (Konflikt-Master)
			'realtime'         => 'yes',           // webhook-getriebenes Real-Time (bei uns INKLUSIVE)
			'webhook_url'      => '',
			'signature_key'    => '',
			'oauth_client_id'  => '',              // Square-Production-App-ID (Secret separat verschlüsselt).
			'merchant_id'      => '',              // nach OAuth-Connect gesetzt.
			// Token/refresh liegen separat & verschlüsselt (siehe get/set_token).
		);
	}

	public function all(): array {
		return wp_parse_args( get_option( self::OPTION, array() ), self::defaults() );
	}

	public function get( string $key, $default = null ) {
		$all = $this->all();
		return $all[ $key ] ?? $default;
	}

	public function set( string $key, $value ): void {
		$all         = $this->all();
		$all[ $key ] = $value;
		update_option( self::OPTION, $all, false );
	}

	public function is_sandbox(): bool {
		return 'production' !== $this->get( 'environment' );
	}

	/* --- Multi-Location --- */

	/** Primäre Location: Ziel für Woo→Square-Push und Order-Adjustments (Online-Fulfillment). */
	public function primary_location_id(): string {
		return (string) $this->get( 'location_id', '' );
	}

	/**
	 * Alle Locations, deren Bestand in den WC-Stock zählt. Fallback: nur die Primary-Location.
	 * Primary ist immer enthalten (auch wenn nicht explizit in `locations`), damit Push/Order konsistent bleiben.
	 *
	 * @return string[]
	 */
	public function sync_location_ids(): array {
		$locations = (array) $this->get( 'locations', array() );
		$locations = array_values( array_filter( array_map( 'strval', $locations ) ) );
		$primary   = $this->primary_location_id();
		if ( '' !== $primary && ! in_array( $primary, $locations, true ) ) {
			$locations[] = $primary;
		}
		if ( empty( $locations ) ) {
			return '' !== $primary ? array( $primary ) : array();
		}
		return $locations;
	}

	public function api_base(): string {
		return $this->is_sandbox()
			? 'https://connect.squareupsandbox.com'
			: 'https://connect.squareup.com';
	}

	/* --- Token (verschlüsselt) --- */
	public function set_token( string $access_token, string $refresh_token = '', int $expires_at = 0 ): void {
		update_option(
			'steadysync_token_' . $this->get( 'environment' ),
			array(
				'access'     => Crypto::encrypt( $access_token ),
				'refresh'    => $refresh_token ? Crypto::encrypt( $refresh_token ) : '',
				'expires_at' => $expires_at,
			),
			false
		);
	}

	public function get_token(): array {
		$raw = get_option( 'steadysync_token_' . $this->get( 'environment' ), array() );
		return array(
			'access'     => isset( $raw['access'] ) ? Crypto::decrypt( $raw['access'] ) : '',
			'refresh'    => isset( $raw['refresh'] ) && $raw['refresh'] ? Crypto::decrypt( $raw['refresh'] ) : '',
			'expires_at' => (int) ( $raw['expires_at'] ?? 0 ),
		);
	}

	public function access_token(): string {
		return $this->get_token()['access'];
	}

	/* --- OAuth-Credentials (Production) --- */

	/** Production-App Client-ID. Konstante hat Vorrang (Config-as-Code), sonst gespeicherter Wert. */
	public function oauth_client_id(): string {
		if ( defined( 'STEADYSYNC_CLIENT_ID' ) && STEADYSYNC_CLIENT_ID ) {
			return (string) STEADYSYNC_CLIENT_ID;
		}
		return (string) $this->get( 'oauth_client_id', '' );
	}

	/**
	 * Production-App Secret (verschlüsselt at-rest). Konstante hat Vorrang.
	 * NICHT env-spezifisch: OAuth-Credentials sind Production; ein Environment-Wechsel darf sie nicht verlieren.
	 */
	public function oauth_client_secret(): string {
		if ( defined( 'STEADYSYNC_CLIENT_SECRET' ) && STEADYSYNC_CLIENT_SECRET ) {
			return (string) STEADYSYNC_CLIENT_SECRET;
		}
		$raw = get_option( 'steadysync_oauth_secret', '' );
		return $raw ? Crypto::decrypt( $raw ) : '';
	}

	public function set_oauth_credentials( string $client_id, string $client_secret ): void {
		$this->set( 'oauth_client_id', $client_id );
		if ( '' !== $client_secret ) {
			update_option( 'steadysync_oauth_secret', Crypto::encrypt( $client_secret ), false );
		}
	}

	public function has_oauth_credentials(): bool {
		return '' !== $this->oauth_client_id() && '' !== $this->oauth_client_secret();
	}
}
