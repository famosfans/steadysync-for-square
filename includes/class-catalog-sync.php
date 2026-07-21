<?php
/**
 * Katalog-Delta-Sync — Gegenstück zum Inventar-Webhook. Auf `catalog.version.updated`
 * die seit dem letzten Sync geänderten Square-Items holen und in WooCommerce übernehmen
 * (Name, Beschreibung, Preis, SKU). SAFE-UPDATES: leere Square-Felder überschreiben NIE
 * bestehende WC-Daten. Unterstützt Simple- UND variable Produkte (mehrere Variationen).
 */
namespace Steadysync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Catalog_Sync {

	public const LAST_SYNC_OPT = 'steadysync_last_catalog_sync';
	private const ATTR_NAME    = 'Variante';

	private Image_Sync $images;

	/** @var array<int,bool> Request-scoped: Produkte, die gerade INBOUND (Square→Woo) geschrieben werden. */
	private static array $inbound = array();

	public function __construct( private Square_Client $client ) {
		$this->images = new Image_Sync();
	}

	/** Loop-Guard für den Katalog-Push: markiert ein Produkt als „kam gerade aus Square". */
	public static function mark_inbound( int $pid ): void {
		if ( $pid ) {
			self::$inbound[ $pid ] = true;
		}
	}

	public static function is_inbound( int $pid ): bool {
		return ! empty( self::$inbound[ $pid ] );
	}

	/** WC-Produkt anhand der Square-ITEM-ID (Item-Level, nicht Variation). */
	public function product_id_by_square_item( string $item_id ): int {
		if ( '' === $item_id ) {
			return 0;
		}
		$q = new \WP_Query(
			array(
				'post_type'      => array( 'product' ),
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => '_square_item_id',
						'value' => $item_id,
					),
				),
			)
		);
		return (int) ( $q->posts[0] ?? 0 );
	}

	/** WC-(Variation/Simple) anhand der Square-VARIATION-ID. */
	public function product_id_by_square_variation( string $variation_id ): int {
		if ( '' === $variation_id ) {
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
						'value' => $variation_id,
					),
				),
			)
		);
		return (int) ( $q->posts[0] ?? 0 );
	}

	/**
	 * Verarbeitet GENAU EINE Katalog-Seite und liefert das Ergebnis + den nächsten Cursor.
	 * Basis für die Batch-Queue (eine Seite pro Cron-Tick → keine Timeouts bei großen Katalogen)
	 * und für sync_since (Schleife). Erkennt Square-API-Fehler, damit die Queue sauber retryen kann.
	 *
	 * @return array{applied: array, cursor: ?string, error: bool}
	 */
	public function sync_page( ?string $cursor, ?string $begin_time ): array {
		$resp = $this->client->search_catalog_items( $cursor, $begin_time );

		// Fehler (WP_Error-Cast oder Square-Fehler-Payload haben beide einen 'errors'-Key).
		if ( ! is_array( $resp ) || isset( $resp['errors'] ) ) {
			return array(
				'applied' => array(),
				'cursor'  => $cursor,
				'error'   => true,
			);
		}

		$img_map = Image_Sync::build_image_map( $resp['related_objects'] ?? array() );
		$applied = array();
		foreach ( $resp['objects'] ?? array() as $obj ) {
			if ( 'ITEM' !== ( $obj['type'] ?? '' ) ) {
				continue;
			}
			$applied[] = $this->apply_item( $obj, $img_map );
		}
		return array(
			'applied' => $applied,
			'cursor'  => $resp['cursor'] ?? null,
			'error'   => false,
		);
	}

	public function sync_since( ?string $begin_time ): array {
		$applied = array();
		$cursor  = null;
		$guard   = 0;
		do {
			$page    = $this->sync_page( $cursor, $begin_time );
			$applied = array_merge( $applied, $page['applied'] );
			if ( $page['error'] ) {
				break;
			}
			$cursor = $page['cursor'];
		} while ( $cursor && $guard++ < 50 );

		update_option( self::LAST_SYNC_OPT, gmdate( 'c' ), false );
		return $applied;
	}

	/** Routet auf Simple- oder Variable-Produkt je nach Anzahl Variationen. */
	private function apply_item( array $obj, array $img_map = array() ): array {
		$item_id    = (string) ( $obj['id'] ?? '' );
		$data       = $obj['item_data'] ?? array();
		$variations = $data['variations'] ?? array();

		if ( count( $variations ) > 1 ) {
			return $this->apply_variable_item( $item_id, $data, $variations, $img_map );
		}
		return $this->apply_simple_item( $item_id, $data, $variations, $img_map );
	}

	/** Ein-Variations-Item → WC_Product_Simple. */
	private function apply_simple_item( string $item_id, array $data, array $variations, array $img_map = array() ): array {
		$name        = (string) ( $data['name'] ?? '' );
		$desc        = (string) ( $data['description'] ?? ( $data['description_plaintext'] ?? '' ) );
		$first_var   = $variations[0] ?? array();
		$var_id      = (string) ( $first_var['id'] ?? '' );
		$vdata       = $first_var['item_variation_data'] ?? array();
		$sku         = (string) ( $vdata['sku'] ?? '' );
		$price_cents = $vdata['price_money']['amount'] ?? null;

		$pid      = $this->product_id_by_square_item( $item_id );
		$existing = $pid ? wc_get_product( $pid ) : null;
		// Falls das existierende Produkt variabel ist, NICHT zerstören — nur updaten wo möglich.
		$product = ( $existing instanceof \WC_Product_Simple ) ? $existing
			: ( $existing ? $existing : new \WC_Product_Simple() );
		$created = ! $existing;

		if ( '' !== $name ) {
			$product->set_name( $name );
		} elseif ( $created ) {
			$product->set_name( 'Square Item ' . $item_id );
		}
		if ( '' !== $desc ) {
			$product->set_description( $desc );
		}
		if ( '' !== $sku && method_exists( $product, 'set_sku' ) ) {
			try {
				$product->set_sku( $sku ); } catch ( \Exception $e ) {
				/* Kollision: behalten */ }
		}
		if ( null !== $price_cents && is_numeric( $price_cents ) && method_exists( $product, 'set_regular_price' ) ) {
			$product->set_regular_price( (string) ( (int) $price_cents / 100 ) );
		}
		if ( $created ) {
			$product->set_status( 'publish' );
		}
		self::mark_inbound( $product->get_id() ); // Loop-Guard: Katalog-Push soll das NICHT zurück-echoen.
		$product->save();
		$pid = $product->get_id();
		self::mark_inbound( $pid );
		update_post_meta( $pid, '_square_item_id', $item_id );
		if ( '' !== $var_id ) {
			update_post_meta( $pid, '_square_item_variation_id', $var_id );
		}

		$image = $this->images->sync_featured_image( $pid, $data['image_ids'] ?? array(), $img_map );

		return array(
			'item'       => $item_id,
			'wc_product' => $pid,
			'type'       => 'simple',
			'created'    => $created,
			'name'       => $name,
			'price'      => null !== $price_cents ? (int) $price_cents / 100 : null,
			'variations' => 1,
			'image'      => $image['reason'] ?? '',
			'ok'         => true,
		);
	}

	/** Mehr-Variations-Item → WC_Product_Variable mit Kind-Variationen. */
	private function apply_variable_item( string $item_id, array $data, array $variations, array $img_map = array() ): array {
		$name = (string) ( $data['name'] ?? '' );
		$desc = (string) ( $data['description'] ?? ( $data['description_plaintext'] ?? '' ) );

		$pid = $this->product_id_by_square_item( $item_id );
		self::mark_inbound( $pid ); // Loop-Guard VOR dem Save (der Push-Hook feuert währenddessen).
		$existing = $pid ? wc_get_product( $pid ) : null;
		$product  = ( $existing instanceof \WC_Product_Variable ) ? $existing : new \WC_Product_Variable();
		$created  = ! ( $product->get_id() );

		if ( '' !== $name ) {
			$product->set_name( $name );
		} elseif ( $created ) {
			$product->set_name( 'Square Item ' . $item_id );
		}
		if ( '' !== $desc ) {
			$product->set_description( $desc );
		}

		// Attribut „Variante" mit den Variationsnamen als Optionen.
		$options = array();
		foreach ( $variations as $i => $v ) {
			$options[] = (string) ( $v['item_variation_data']['name'] ?? ( 'Option ' . ( $i + 1 ) ) );
		}
		$attr = new \WC_Product_Attribute();
		$attr->set_name( self::ATTR_NAME );
		$attr->set_options( $options );
		$attr->set_visible( true );
		$attr->set_variation( true );
		$product->set_attributes( array( $attr ) );
		$product->set_status( 'publish' );
		$parent_id = $product->save();
		self::mark_inbound( $parent_id ); // Loop-Guard: Katalog-Push soll das NICHT zurück-echoen.
		update_post_meta( $parent_id, '_square_item_id', $item_id );

		$attr_key  = sanitize_title( self::ATTR_NAME ); // 'variante'
		$child_ids = array();
		foreach ( $variations as $i => $v ) {
			$vid   = (string) ( $v['id'] ?? '' );
			$vdata = $v['item_variation_data'] ?? array();
			$vname = (string) ( $vdata['name'] ?? ( 'Option ' . ( $i + 1 ) ) );
			$sku   = (string) ( $vdata['sku'] ?? '' );
			$price = $vdata['price_money']['amount'] ?? null;

			$wc_var_id = $this->product_id_by_square_variation( $vid );
			self::mark_inbound( $wc_var_id ); // vor dem Save markieren
			$variation = $wc_var_id ? wc_get_product( $wc_var_id ) : new \WC_Product_Variation();
			if ( ! $variation instanceof \WC_Product_Variation ) {
				$variation = new \WC_Product_Variation();
			}
			$variation->set_parent_id( $parent_id );
			$variation->set_attributes( array( $attr_key => $vname ) );
			if ( '' !== $sku ) {
				try {
					$variation->set_sku( $sku ); } catch ( \Exception $e ) {
					/* Kollision */ }
			}
			if ( null !== $price && is_numeric( $price ) ) {
				$variation->set_regular_price( (string) ( (int) $price / 100 ) );
			}
			$variation->set_status( 'publish' );
			$child_id = $variation->save();
			self::mark_inbound( $child_id );
			update_post_meta( $child_id, '_square_item_id', $item_id );
			update_post_meta( $child_id, '_square_item_variation_id', $vid );
			$child_ids[] = $child_id;
		}

		// Datastore-Sync fürs Parent (Variationen-Transienten neu aufbauen).
		\WC_Product_Variable::sync( $parent_id );
		wc_delete_product_transients( $parent_id );

		// Featured-Image aufs Parent (variable Produkte zeigen das Parent-Bild als Default).
		$image = $this->images->sync_featured_image( $parent_id, $data['image_ids'] ?? array(), $img_map );

		return array(
			'item'       => $item_id,
			'wc_product' => $parent_id,
			'type'       => 'variable',
			'created'    => $created,
			'name'       => $name,
			'price'      => null, // Konsistenz mit Simple-Rows (CLI-Tabellenspalte).
			'variations' => count( $child_ids ),
			'child_ids'  => $child_ids,
			'image'      => $image['reason'] ?? '',
			'ok'         => true,
		);
	}
}
