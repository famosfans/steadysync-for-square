<?php
/**
 * Dünner Square-API-Client (wp_remote_*). Sandbox/Prod via Settings.
 * Bewusst schlank: keine Riesen-SDK-Abhängigkeit (der Bloat-Vorwurf gegen Konkurrenten).
 */
namespace Steadysync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Square_Client {

	private const SQUARE_VERSION = '2025-04-16';

	public function __construct( private Settings $settings ) {}

	private function request( string $method, string $path, ?array $body = null, bool $authless = false ) {
		$headers = array(
			'Square-Version' => self::SQUARE_VERSION,
			'Content-Type'   => 'application/json',
		);
		// OAuth-Token-Endpoints (/oauth2/token) authentifizieren über client_id/secret im Body,
		// NICHT über einen Bearer-Header. Ein (leerer/fremder) Bearer würde dort „Not Authorized" auslösen.
		if ( ! $authless ) {
			$headers['Authorization'] = 'Bearer ' . $this->settings->access_token();
		}
		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => $headers,
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$url = $this->settings->api_base() . $path;
		/** Max. Versuche pro Square-Call (1 = kein Retry). */
		$max        = max( 1, (int) apply_filters( 'steadysync_retry_max', 3 ) );
		/** Basis-Backoff in Sekunden (Tests setzen 0). */
		$base_delay = (float) apply_filters( 'steadysync_retry_base_delay', 1.0 );
		$attempt    = 0;

		do {
			$res  = wp_remote_request( $url, $args );
			$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );

			// Transient: Netzwerkfehler, Rate-Limit (429), Server (5xx). Retries sind dank
			// idempotency_key (POSTs) bzw. Read-Idempotenz (GET) sicher.
			$retryable = is_wp_error( $res ) || 429 === $code || $code >= 500;
			++$attempt;
			if ( ! $retryable || $attempt >= $max ) {
				break;
			}
			$this->backoff_sleep( $attempt, $base_delay, is_wp_error( $res ) ? null : $res );
		} while ( true );

		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return json_decode( wp_remote_retrieve_body( $res ), true );
	}

	/** Exponentieller Backoff; respektiert den Retry-After-Header (gedeckelt), wenn vorhanden. */
	private function backoff_sleep( int $attempt, float $base_delay, $res ): void {
		$delay = $base_delay * ( 2 ** ( $attempt - 1 ) ); // 1×, 2×, 4× …
		if ( $res ) {
			$retry_after = (int) wp_remote_retrieve_header( $res, 'retry-after' );
			if ( $retry_after > 0 ) {
				$delay = min( $retry_after, 10 );
			}
		}
		$delay = min( $delay, 10.0 ); // harte Obergrenze, um Worker nicht zu blockieren.
		if ( $delay > 0 ) {
			usleep( (int) round( $delay * 1000000 ) );
		}
	}

	/** Locations (auch für Setup-Auto-Detect). */
	public function get_locations(): array {
		$r = $this->request( 'GET', '/v2/locations' );
		return is_array( $r ) ? ( $r['locations'] ?? array() ) : array();
	}

	/**
	 * Katalog-Items (ITEM), für Import + Delta-Sync.
	 * $begin_time (ISO-8601) → nur seitdem geänderte Objekte (Delta). null → alle.
	 */
	public function search_catalog_items( ?string $cursor = null, ?string $begin_time = null ): array {
		$body = array(
			'object_types'            => array( 'ITEM' ),
			'include_related_objects' => true,
		);
		if ( $cursor ) {
			$body['cursor'] = $cursor;
		}
		if ( $begin_time ) {
			$body['begin_time'] = $begin_time;
		}
		/**
		 * Seitengröße pro Katalog-Abfrage (0 = Square-Default). Tuning-Punkt für die Batch-Queue.
		 *
		 * @param int $limit Anzahl Objekte pro Seite.
		 */
		$limit = (int) apply_filters( 'steadysync_catalog_page_limit', 0 );
		if ( $limit > 0 ) {
			$body['limit'] = $limit;
		}
		return (array) $this->request( 'POST', '/v2/catalog/search', $body );
	}

	/** Setzt den physischen Bestand einer Variation in Square (Push Woo → Square). */
	public function set_inventory_count( string $variation_id, string $location_id, int $qty ) {
		return $this->request(
			'POST',
			'/v2/inventory/changes/batch-create',
			array(
				'idempotency_key' => wp_generate_uuid4(),
				'changes'         => array(
					array(
						'type'           => 'PHYSICAL_COUNT',
						'physical_count' => array(
							'catalog_object_id' => $variation_id,
							'state'             => 'IN_STOCK',
							'location_id'       => $location_id,
							'quantity'          => (string) $qty,
							'occurred_at'       => gmdate( 'c' ),
						),
					),
				),
			)
		);
	}

	/** Aktuellen Square-Bestand einer Variation lesen (für Verifikation/Konfliktlösung). */
	public function get_inventory_count( string $variation_id, string $location_id ) {
		$r      = $this->request(
			'POST',
			'/v2/inventory/counts/batch-retrieve',
			array(
				'catalog_object_ids' => array( $variation_id ),
				'location_ids'       => array( $location_id ),
			)
		);
		$counts = is_array( $r ) ? ( $r['counts'] ?? array() ) : array();
		return $counts[0]['quantity'] ?? null;
	}

	/**
	 * Summiert den IN_STOCK-Bestand einer Variation über mehrere Locations (Multi-Location-Aggregat).
	 * Nur der IN_STOCK-State zählt (verkaufsfähig) — SOLD/WASTE etc. bleiben außen vor.
	 *
	 * @param string[] $location_ids
	 * @return int|null Summe, oder null wenn Square einen Fehler lieferte (dann NICHT anwenden → Anti-Zeroing).
	 */
	public function get_inventory_sum( string $variation_id, array $location_ids ) {
		if ( empty( $location_ids ) ) {
			return null;
		}
		$r = $this->request(
			'POST',
			'/v2/inventory/counts/batch-retrieve',
			array(
				'catalog_object_ids' => array( $variation_id ),
				'location_ids'       => array_values( $location_ids ),
			)
		);
		if ( is_wp_error( $r ) || ! is_array( $r ) || ! empty( $r['errors'] ) ) {
			return null;
		}
		// Anti-Zeroing: „keine IN_STOCK-Zeile" (untracked) ist NICHT dasselbe wie echte 0.
		// Nur wenn Square mindestens eine IN_STOCK-Zeile liefert, ist die Summe autoritativ.
		$sum   = 0;
		$found = false;
		foreach ( $r['counts'] ?? array() as $c ) {
			if ( 'IN_STOCK' === ( $c['state'] ?? '' ) && is_numeric( $c['quantity'] ?? null ) ) {
				$sum  += (int) $c['quantity'];
				$found = true;
			}
		}
		return $found ? $sum : null;
	}

	/**
	 * Gebatchter IN_STOCK-Read: viele Variationen in EINEM Call (für Diff-Vorschau ohne N Requests).
	 * Square erlaubt mehrere catalog_object_ids pro batch-retrieve; wir paginieren in 100er-Blöcken.
	 *
	 * @param string[] $variation_ids
	 * @param string[] $location_ids
	 * @return array<string,int> variation_id => Summe IN_STOCK über die Locations (fehlende = 0).
	 */
	public function get_inventory_counts_batch( array $variation_ids, array $location_ids ): array {
		$out = array();
		$ids = array_values( array_unique( array_filter( array_map( 'strval', $variation_ids ) ) ) );
		if ( empty( $ids ) || empty( $location_ids ) ) {
			return $out;
		}
		foreach ( array_chunk( $ids, 100 ) as $chunk ) {
			$cursor = null;
			$guard  = 0;
			do {
				$body = array(
					'catalog_object_ids' => $chunk,
					'location_ids'       => array_values( $location_ids ),
				);
				if ( $cursor ) {
					$body['cursor'] = $cursor;
				}
				$r = $this->request( 'POST', '/v2/inventory/counts/batch-retrieve', $body );
				if ( is_wp_error( $r ) || ! is_array( $r ) ) {
					break;
				}
				foreach ( $r['counts'] ?? array() as $c ) {
					if ( 'IN_STOCK' !== ( $c['state'] ?? '' ) ) {
						continue;
					}
					$vid = (string) ( $c['catalog_object_id'] ?? '' );
					if ( '' !== $vid && is_numeric( $c['quantity'] ?? null ) ) {
						$out[ $vid ] = ( $out[ $vid ] ?? 0 ) + (int) $c['quantity'];
					}
				}
				$cursor = $r['cursor'] ?? null;
			} while ( $cursor && $guard++ < 50 );
		}
		return $out;
	}

	/**
	 * Relativer Inventar-ADJUSTMENT (Zustandsübergang), z. B. IN_STOCK → SOLD bei einer
	 * WooCommerce-Bestellung. Anders als PHYSICAL_COUNT (absolut überschreiben) schreibt das
	 * einen echten Verkaufs-Eintrag in Squares Inventar-Ledger — korrekte Verkaufs-/COGS-Reports
	 * statt opaker Neuzählungen (der Weg, den das offizielle Plugin NICHT geht).
	 * $idempotency_key stabil pro Order+Variation → Square dedupliziert Retries automatisch.
	 */
	public function adjust_inventory( string $variation_id, string $location_id, int $qty, string $from_state, string $to_state, string $idempotency_key ) {
		return $this->request(
			'POST',
			'/v2/inventory/changes/batch-create',
			array(
				'idempotency_key' => $idempotency_key,
				'changes'         => array(
					array(
						'type'       => 'ADJUSTMENT',
						'adjustment' => array(
							'catalog_object_id' => $variation_id,
							'from_state'        => $from_state,
							'to_state'          => $to_state,
							'location_id'       => $location_id,
							'quantity'          => (string) $qty,
							'occurred_at'       => gmdate( 'c' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Einzelnes Katalog-Objekt inkl. verschachtelter Objekte (ITEM bringt seine VARIATIONs mit,
	 * jeweils mit `version` für optimistische Nebenläufigkeit). Basis für den Woo→Square-Katalog-Push.
	 */
	public function get_catalog_object( string $object_id, bool $include_related = false ) {
		$q = $include_related ? '?include_related_objects=true' : '';
		return $this->request( 'GET', '/v2/catalog/object/' . rawurlencode( $object_id ) . $q );
	}

	/**
	 * Upsert eines Katalog-Objekts (mit verschachtelten VARIATIONs möglich). Jedes Objekt muss
	 * seine aktuelle `version` mitführen (aus get_catalog_object) — sonst Versionskonflikt.
	 */
	public function upsert_catalog_object( array $catalog_object ) {
		return $this->request(
			'POST',
			'/v2/catalog/object',
			array(
				'idempotency_key' => wp_generate_uuid4(),
				'object'          => $catalog_object,
			)
		);
	}

	/**
	 * Lädt ein lokales Bild als Square-Katalog-Bild hoch und heftet es an ein ITEM (Bild-Push Woo→Square).
	 * Multipart-Upload (kein JSON) — bewusst über wp_remote_post statt request().
	 *
	 * @return array|\WP_Error Square-Antwort oder Fehler.
	 */
	public function create_catalog_image( string $object_id, string $file_path, string $caption = '' ) {
		if ( ! is_readable( $file_path ) ) {
			return new \WP_Error( 'steadysync_no_file', 'Bilddatei nicht lesbar: ' . $file_path );
		}
		$data = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- lokale Binärdatei.
		if ( false === $data ) {
			return new \WP_Error( 'steadysync_read_fail', 'Bilddatei nicht lesbar.' );
		}

		$boundary = 'steadysync' . wp_generate_password( 20, false );
		$detected = wp_check_filetype( $file_path )['type'];
		$mime     = $detected ? $detected : 'image/jpeg';
		$name     = basename( $file_path );
		$request  = wp_json_encode(
			array(
				'idempotency_key' => wp_generate_uuid4(),
				'object_id'       => $object_id,
				'image'           => array(
					'type'       => 'IMAGE',
					'id'         => '#steadysync_img',
					'image_data' => array( 'caption' => $caption ),
				),
			)
		);

		$eol   = "\r\n";
		$parts = '--' . $boundary . $eol;
		$parts .= 'Content-Disposition: form-data; name="request"' . $eol;
		$parts .= 'Content-Type: application/json' . $eol . $eol;
		$parts .= $request . $eol;
		$parts .= '--' . $boundary . $eol;
		$parts .= 'Content-Disposition: form-data; name="image_file"; filename="' . $name . '"' . $eol;
		$parts .= 'Content-Type: ' . $mime . $eol . $eol;
		$parts .= $data . $eol;
		$parts .= '--' . $boundary . '--' . $eol;

		$res = wp_remote_post(
			$this->settings->api_base() . '/v2/catalog/images',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization'  => 'Bearer ' . $this->settings->access_token(),
					'Square-Version' => self::SQUARE_VERSION,
					'Content-Type'   => 'multipart/form-data; boundary=' . $boundary,
				),
				'body'    => $parts,
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return json_decode( wp_remote_retrieve_body( $res ), true );
	}

	/** OAuth-Token-Refresh (production). Gibt [access_token, refresh_token, expires_at] oder WP_Error. */
	public function refresh_oauth_token( string $refresh_token, string $client_id, string $client_secret ) {
		return $this->request(
			'POST',
			'/oauth2/token',
			array(
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh_token,
			),
			true // authless: Token-Endpoint nutzt Body-Credentials, keinen Bearer.
		);
	}

	/** Tauscht den OAuth-Authorization-Code gegen Tokens (nach dem Merchant-Consent). */
	public function exchange_oauth_code( string $code, string $client_id, string $client_secret, string $redirect_uri ) {
		return $this->request(
			'POST',
			'/oauth2/token',
			array(
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'code'          => $code,
				'grant_type'    => 'authorization_code',
				'redirect_uri'  => $redirect_uri,
			),
			true // authless.
		);
	}

	public function connection_ok(): bool {
		return ! empty( $this->get_locations() );
	}
}
