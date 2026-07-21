<?php
/**
 * Bilder-Sync Square → WooCommerce. Square-Katalog-Items referenzieren ihre Bilder über
 * `item_data.image_ids`; die konkreten IMAGE-Objekte (mit URL) kommen als `related_objects`
 * der Catalog-Search zurück (include_related_objects=true). Wir setzen das erste Bild als
 * WC-Featured-Image. Idempotent: neu geladen wird nur, wenn sich die Square-image_id ändert —
 * so bläht wiederholtes Syncen die Mediathek NICHT auf (der Doppel-Import-Bug der Konkurrenz).
 */
namespace Steadysync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Image_Sync {

	public const IMAGE_META = '_square_image_id';

	/** Baut aus related_objects einer Catalog-Search eine Map image_id => url. */
	public static function build_image_map( array $related_objects ): array {
		$map = array();
		foreach ( $related_objects as $obj ) {
			if ( 'IMAGE' !== ( $obj['type'] ?? '' ) ) {
				continue;
			}
			$id  = (string) ( $obj['id'] ?? '' );
			$url = (string) ( $obj['image_data']['url'] ?? '' );
			if ( '' !== $id && '' !== $url ) {
				$map[ $id ] = $url;
			}
		}
		return $map;
	}

	/**
	 * Setzt das WC-Featured-Image aus dem ersten Square-Item-Bild.
	 *
	 * @param int      $product_id WC-Produkt/Variation.
	 * @param string[] $image_ids  Square item_data.image_ids.
	 * @param array    $image_map  id => url (aus build_image_map).
	 * @return array Diagnose [ok, reason, ...].
	 */
	public function sync_featured_image( int $product_id, array $image_ids, array $image_map ): array {
		if ( ! $product_id || empty( $image_ids ) ) {
			return array(
				'ok'     => false,
				'reason' => 'no-image',
			);
		}
		$image_id = (string) $image_ids[0];
		$url      = $image_map[ $image_id ] ?? '';
		if ( '' === $url ) {
			return array(
				'ok'       => false,
				'reason'   => 'url-not-in-related',
				'image_id' => $image_id,
			);
		}

		// Idempotenz: dasselbe Bild bereits gesetzt → nichts tun (kein Re-Download, kein Media-Bloat).
		$current = (string) get_post_meta( $product_id, self::IMAGE_META, true );
		if ( $current === $image_id && has_post_thumbnail( $product_id ) ) {
			return array(
				'ok'       => true,
				'reason'   => 'unchanged',
				'image_id' => $image_id,
			);
		}

		$attach_id = $this->sideload( $url, $product_id, $image_id );
		if ( is_wp_error( $attach_id ) || ! $attach_id ) {
			return array(
				'ok'     => false,
				'reason' => 'sideload-failed',
				'error'  => is_wp_error( $attach_id ) ? $attach_id->get_error_message() : 'unknown',
			);
		}

		set_post_thumbnail( $product_id, $attach_id );
		update_post_meta( $product_id, self::IMAGE_META, $image_id );
		return array(
			'ok'         => true,
			'reason'     => 'set',
			'image_id'   => $image_id,
			'attachment' => (int) $attach_id,
		);
	}

	/** Lädt eine Bild-URL herunter und hängt sie als Attachment an das Produkt. */
	private function sideload( string $url, int $product_id, string $image_id ) {
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$tmp = download_url( $url );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		// Dateiname mit gültiger Endung ableiten; Square-URLs sind nicht garantiert benannt.
		$name = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		if ( '' === $name || ! preg_match( '/\.(jpe?g|png|gif|webp)$/i', $name ) ) {
			$name = 'square-' . $image_id . '.jpg';
		}

		$file      = array(
			'name'     => $name,
			'tmp_name' => $tmp,
		);
		$attach_id = media_handle_sideload( $file, $product_id, 'Square ' . $image_id );

		if ( is_wp_error( $attach_id ) ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			return $attach_id;
		}
		// Herkunft markieren → der Bild-Push (Woo→Square) echoet dieses Bild NICHT zurück.
		update_post_meta( (int) $attach_id, '_steadysync_from_square', 1 );
		return (int) $attach_id;
	}
}
