<?php
/**
 * Dry-Run / Diff-Vorschau — „zeig was passiert, BEVOR es passiert". Read-only: berechnet, was ein
 * Katalog-Sync bzw. eine Migration ändern würde, ohne irgendetwas zu schreiben. Der Vertrauens-Hebel
 * für die Migration (Merchant sieht exakt, was mit seinem Shop geschieht, bevor er den Knopf drückt).
 */
namespace Steadysync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Preview {

	public function __construct(
		private Square_Client $client,
		private Catalog_Sync $catalog,
		private Settings $settings
	) {}

	/**
	 * Katalog-Diff: was würde ein Sync (Square→Woo) an Namen/Preis/SKU/Bild ändern? Ohne Writes.
	 *
	 * @param string|null $begin_time Delta-Startzeit; null = ganzer Katalog.
	 * @return array<int,array> Geplante Änderungen (nur Items MIT Änderung; unveränderte fallen raus).
	 */
	public function catalog_diff( ?string $begin_time = null, int $max_pages = 50 ): array {
		$planned = array();
		$cursor  = null;
		$guard   = 0;
		do {
			$resp    = $this->client->search_catalog_items( $cursor, $begin_time );
			$objects = is_array( $resp ) ? ( $resp['objects'] ?? array() ) : array();
			foreach ( $objects as $obj ) {
				if ( 'ITEM' !== ( $obj['type'] ?? '' ) ) {
					continue;
				}
				$row = $this->diff_item( $obj );
				if ( ! empty( $row['changes'] ) || 'create' === $row['action'] ) {
					$planned[] = $row;
				}
			}
			$cursor = is_array( $resp ) ? ( $resp['cursor'] ?? null ) : null;
		} while ( $cursor && $guard++ < $max_pages );
		return $planned;
	}

	/** Berechnet den Feld-Diff für ein einzelnes Square-ITEM gegen den aktuellen WC-Stand. */
	private function diff_item( array $obj ): array {
		$item_id    = (string) ( $obj['id'] ?? '' );
		$data       = $obj['item_data'] ?? array();
		$variations = $data['variations'] ?? array();
		$is_var     = count( $variations ) > 1;

		$pid      = $this->catalog->product_id_by_square_item( $item_id );
		$existing = $pid ? wc_get_product( $pid ) : null;
		$action   = $existing ? 'update' : 'create';
		$changes  = array();

		$new_name = (string) ( $data['name'] ?? '' );
		$cur_name = $existing ? $existing->get_name() : '';
		if ( '' !== $new_name && $new_name !== $cur_name ) {
			$changes['name'] = array( $cur_name, $new_name );
		}

		if ( ! $is_var ) {
			$first = $variations[0] ?? array();
			$vdata = $first['item_variation_data'] ?? array();
			$sku   = (string) ( $vdata['sku'] ?? '' );
			$cents = $vdata['price_money']['amount'] ?? null;
			if ( null !== $cents && is_numeric( $cents ) ) {
				$new_price = (string) ( (int) $cents / 100 );
				$cur_price = $existing ? (string) $existing->get_regular_price() : '';
				if ( $new_price !== $cur_price ) {
					$changes['price'] = array( $cur_price, $new_price );
				}
			}
			$cur_sku = $existing ? (string) $existing->get_sku() : '';
			if ( '' !== $sku && $sku !== $cur_sku ) {
				$changes['sku'] = array( $cur_sku, $sku );
			}
		} else {
			$cur_count = $existing ? count( $existing->get_children() ) : 0;
			$new_count = count( $variations );
			if ( $new_count !== $cur_count ) {
				$changes['variations'] = array( $cur_count, $new_count );
			}
		}

		// Bild: würde ein (neues) Featured-Image gesetzt?
		$img_ids = $data['image_ids'] ?? array();
		if ( ! empty( $img_ids ) ) {
			$cur_img = $pid ? (string) get_post_meta( $pid, Image_Sync::IMAGE_META, true ) : '';
			if ( (string) $img_ids[0] !== $cur_img ) {
				$changes['image'] = array( '' === $cur_img ? '—' : 'alt', 'neu' );
			}
		}

		return array(
			'item'       => $item_id,
			'wc_product' => $pid,
			'type'       => $is_var ? 'variable' : 'simple',
			'action'     => $action,
			'name'       => $new_name,
			'changes'    => $changes,
		);
	}

	/**
	 * Inventar-Diff: WC-Bestand vs Square-Bestand pro gemapptem Produkt (gebatcht, 1–wenige Calls).
	 * Zeigt genau, welche Bestände ein Sync ändern würde — der Anti-Zeroing-Vertrauensbeweis.
	 *
	 * @return array<int,array> Nur Produkte MIT Abweichung.
	 */
	public function inventory_diff( int $limit = 500 ): array {
		$q = new \WP_Query(
			array(
				'post_type'      => array( 'product', 'product_variation' ),
				'post_status'    => 'any',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_square_item_variation_id',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$by_var = array();
		foreach ( $q->posts as $pid ) {
			$vid = (string) get_post_meta( $pid, '_square_item_variation_id', true );
			if ( '' !== $vid ) {
				$by_var[ $vid ] = (int) $pid;
			}
		}
		if ( empty( $by_var ) ) {
			return array();
		}

		/**
		 * Locations whose Square stock the preview compares against. Core previews the
		 * primary location; an add-on may extend this (e.g. multi-location aggregate).
		 *
		 * @param string[] $locations
		 * @param Settings $settings
		 */
		$locations = apply_filters( 'steadysync_preview_locations', array( $this->settings->primary_location_id() ), $this->settings );
		$locations = array_values( array_filter( $locations ) );

		$square = $this->client->get_inventory_counts_batch( array_keys( $by_var ), $locations );

		$diff = array();
		foreach ( $by_var as $vid => $pid ) {
			$product = wc_get_product( $pid );
			if ( ! $product ) {
				continue;
			}
			$wc_stock = (int) $product->get_stock_quantity();
			$sq_stock = $square[ $vid ] ?? null;
			if ( null === $sq_stock || $sq_stock === $wc_stock ) {
				continue;
			}
			$diff[] = array(
				'wc_product' => $pid,
				'name'       => $product->get_name(),
				'sq_variation' => $vid,
				'wc_stock'   => $wc_stock,
				'sq_stock'   => (int) $sq_stock,
				'delta'      => (int) $sq_stock - $wc_stock,
			);
		}
		return $diff;
	}
}
