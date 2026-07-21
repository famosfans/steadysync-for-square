<?php
/**
 * Atomarer Inventar-Sync mit ANTI-ZEROING-GUARD — der Kern-Differenzierer gegen
 * den schlimmsten Incumbent-Bug ("inventory zeroed out"). Validiert im Reliability-Spike.
 *
 * Führt zusätzlich ein INBOUND-Register (Request-scoped): markiert Produkte, deren
 * Bestand gerade aus Square kam, damit Push_Sync sie nicht zurück-echoet (Loop-Guard).
 */
namespace Steadysync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Inventory_Sync {

	/** @var array<int,bool> Request-scoped: Produkte, deren Änderung von Square kam. */
	private static array $inbound = array();

	private ?Settings $settings;
	private ?Square_Client $client;

	/**
	 * Settings + Client sind für Multi-Location optional injizierbar. Ohne sie verhält sich der
	 * Sync wie bisher (Single-Location, wendet jeden gemeldeten Count an) — abwärtskompatibel.
	 */
	public function __construct( ?Settings $settings = null, ?Square_Client $client = null ) {
		$this->settings = $settings;
		$this->client   = $client;
	}

	public static function mark_inbound( int $pid ): void {
		self::$inbound[ $pid ] = true;
	}

	public static function is_inbound( int $pid ): bool {
		return ! empty( self::$inbound[ $pid ] );
	}

	/** Square-Variation-ID → WC-Produkt (nutzt den im Sharp-Test bestätigten Meta-Key). */
	public function product_id_by_square_variation( string $sq_variation_id ): int {
		if ( '' === $sq_variation_id ) {
			return 0;
		}
		$q = new \WP_Query(
			array(
				'post_type'      => array( 'product', 'product_variation' ),
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => '_square_item_variation_id',
						'value' => $sq_variation_id,
					),
				),
			)
		);
		return (int) ( $q->posts[0] ?? 0 );
	}

	/**
	 * Sichere Bestands-Anwendung. Gibt [ok=>bool, reason=>string].
	 * GUARD: leere/nicht-numerische/negative Menge setzt NIE auf 0 (Anti-Zeroing).
	 */
	public function safe_apply_stock( int $product_id, $new_qty ): array {
		$product = $product_id ? wc_get_product( $product_id ) : null;
		if ( ! $product ) {
			return array(
				'ok'     => false,
				'reason' => 'no-product-mapped',
			);
		}
		if ( null === $new_qty || '' === $new_qty || ! is_numeric( $new_qty ) ) {
			return array(
				'ok'     => false,
				'reason' => 'REJECTED empty/non-numeric qty (anti-zeroing)',
			);
		}
		$new_qty = (int) $new_qty;
		if ( $new_qty < 0 ) {
			return array(
				'ok'     => false,
				'reason' => 'REJECTED negative qty',
			);
		}
		$old = (int) $product->get_stock_quantity();

		// Loop-Guard: diese Änderung kommt von Square → Push_Sync soll sie NICHT zurückpushen.
		self::mark_inbound( $product_id );
		// Der product->save() unten feuert woocommerce_update_product → Catalog_Push. Dieselbe Änderung
		// darf NICHT als Katalog-Push zurück-echoen, also auch den Catalog-Inbound-Guard setzen.
		Catalog_Sync::mark_inbound( $product_id );

		$product->set_manage_stock( true );
		$product->set_stock_quantity( $new_qty );
		$product->save();
		return array(
			'ok'     => true,
			'reason' => "OK stock {$old} -> {$new_qty}",
		);
	}

	/**
	 * Processes a Square inventory.count.updated payload for the primary location.
	 *
	 * Only IN_STOCK counts from the configured primary location are applied; counts for
	 * other locations or other states (e.g. SOLD) are ignored so they can never land as
	 * stock. Empty/non-numeric quantities are rejected by the anti-zeroing guard.
	 *
	 * Extension point: an add-on may return an array from the
	 * `steadysync_apply_inventory_counts` filter to take over the whole application
	 * (e.g. to aggregate stock across several Square locations). Returning null (default)
	 * lets the core apply its single-location logic.
	 *
	 * @param array $counts Square inventory_counts payload.
	 * @return array Per-count diagnostics.
	 */
	public function apply_inventory_counts( array $counts ): array {
		/**
		 * Allow an add-on to fully handle the inventory counts (e.g. multi-location aggregate).
		 *
		 * @param array|null    $handled Return an array to override the core; null to keep core logic.
		 * @param array         $counts  Square inventory_counts payload.
		 * @param Inventory_Sync $inv     This instance.
		 */
		$handled = apply_filters( 'steadysync_apply_inventory_counts', null, $counts, $this );
		if ( is_array( $handled ) ) {
			return $handled;
		}

		$primary = $this->settings ? $this->settings->primary_location_id() : '';
		$allowed = '' !== $primary ? array( $primary ) : array();
		$applied = array();
		$seen    = array();

		foreach ( $counts as $c ) {
			$sq_var = (string) ( $c['catalog_object_id'] ?? '' );
			$loc    = (string) ( $c['location_id'] ?? '' );
			$state  = (string) ( $c['state'] ?? 'IN_STOCK' );

			// Only sellable stock is relevant.
			if ( 'IN_STOCK' !== $state ) {
				continue;
			}
			// Location filter (when a primary location is configured): only it counts.
			if ( ! empty( $allowed ) && '' !== $loc && ! in_array( $loc, $allowed, true ) ) {
				$applied[] = array(
					'sq_variation' => $sq_var,
					'wc_product'   => 0,
					'ok'           => false,
					'reason'       => 'location-not-selected',
				);
				continue;
			}
			// Process each variation only once per payload.
			if ( isset( $seen[ $sq_var ] ) ) {
				continue;
			}
			$seen[ $sq_var ] = true;

			$pid       = $this->product_id_by_square_variation( $sq_var );
			$res       = $this->safe_apply_stock( $pid, $c['quantity'] ?? null );
			$applied[] = array(
				'sq_variation' => $sq_var,
				'wc_product'   => $pid,
				'mode'         => 'single',
			) + $res;
		}
		return $applied;
	}
}
