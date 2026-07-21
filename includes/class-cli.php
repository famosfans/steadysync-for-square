<?php
/**
 * WP-CLI: wp steadysync <status|catalog_sync|preview|sync_start|sync_status|sync_cancel>
 */
namespace Steadysync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! \WP_CLI ) {
	return;
}

class CLI {

	/**
	 * Connection / sync status.
	 */
	public function status() {
		$p = Plugin::instance();
		\WP_CLI::log( 'Environment    : ' . $p->settings->get( 'environment' ) );
		\WP_CLI::log( 'Token          : ' . ( $p->settings->access_token() ? 'set' : 'not connected' ) );
		\WP_CLI::log( 'Webhook URL    : ' . $p->webhook->endpoint_url() );
		\WP_CLI::log( 'Location       : ' . $p->settings->primary_location_id() );
		\WP_CLI::log( 'Last cat. sync : ' . ( get_option( Catalog_Sync::LAST_SYNC_OPT, '-' ) ) );
		$h = $p->health->state();
		\WP_CLI::log( 'Connection     : ' . $h['connection'] . ( $h['checked_at'] ? ' (' . $h['checked_at'] . ')' : '' ) );
		\WP_CLI::log( 'Failure count  : ' . $h['failures'] . ( $h['last_error'] ? ' - ' . $h['last_error'] : '' ) );
	}

	/**
	 * Run the catalog delta sync manually (since the last sync; --full for a full sync).
	 *
	 * [--full]
	 * : Ignore the last timestamp and sync the whole catalog.
	 */
	public function catalog_sync( $args, $assoc ) {
		$last    = empty( $assoc['full'] ) ? get_option( Catalog_Sync::LAST_SYNC_OPT, null ) : null;
		$applied = Plugin::instance()->catalog->sync_since( $last );
		if ( empty( $applied ) ) {
			\WP_CLI::log( 'No changed items.' );
			return;
		}
		\WP_CLI\Utils\format_items( 'table', $applied, array( 'item', 'wc_product', 'created', 'name', 'price', 'image', 'ok' ) );
		\WP_CLI::success( sprintf( '%d items processed.', count( $applied ) ) );
	}

	/**
	 * Dry-run preview: what would a sync change? Writes NOTHING.
	 *
	 * [--full]
	 * : Check the whole catalog (instead of only the delta since the last sync).
	 */
	public function preview( $args, $assoc ) {
		$pv   = Plugin::instance()->preview;
		$last = empty( $assoc['full'] ) ? get_option( Catalog_Sync::LAST_SYNC_OPT, null ) : null;

		$cat = $pv->catalog_diff( $last );
		\WP_CLI::log( sprintf( 'Catalog changes: %d', count( $cat ) ) );
		if ( ! empty( $cat ) ) {
			$rows = array();
			foreach ( $cat as $c ) {
				$parts = array();
				foreach ( (array) $c['changes'] as $f => $p ) {
					$parts[] = $f . ':' . ( '' === $p[0] ? '-' : $p[0] ) . '->' . $p[1];
				}
				$rows[] = array(
					'wc_product' => $c['wc_product'],
					'action'     => $c['action'],
					'name'       => $c['name'],
					'changes'    => implode( ' | ', $parts ),
				);
			}
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'wc_product', 'action', 'name', 'changes' ) );
		}

		$inv = $pv->inventory_diff();
		\WP_CLI::log( '' );
		\WP_CLI::log( sprintf( 'Stock differences: %d', count( $inv ) ) );
		if ( ! empty( $inv ) ) {
			\WP_CLI\Utils\format_items( 'table', $inv, array( 'wc_product', 'name', 'wc_stock', 'sq_stock', 'delta' ) );
		}
		\WP_CLI::success( 'Preview computed - nothing written.' );
	}

	/**
	 * Start a paginated catalog sync as a background batch job (timeout-safe, resumable).
	 *
	 * [--full]
	 * : Sync the whole catalog (instead of only the delta since the last sync).
	 */
	public function sync_start( $args, $assoc ) {
		$job = Plugin::instance()->queue->start( ! empty( $assoc['full'] ) );
		\WP_CLI::success( sprintf( 'Batch sync started (%s). Progress: wp steadysync sync_status', $job['full'] ? 'full' : 'delta' ) );
	}

	/**
	 * Show the status of the batch sync job.
	 */
	public function sync_status() {
		$j = Plugin::instance()->queue->status();
		foreach ( array( 'status', 'processed', 'pages', 'retries', 'last_error', 'started_at', 'updated_at' ) as $k ) {
			if ( isset( $j[ $k ] ) && '' !== $j[ $k ] ) {
				\WP_CLI::log( str_pad( $k, 12 ) . ': ' . $j[ $k ] );
			}
		}
	}

	/**
	 * Cancel a running batch sync.
	 */
	public function sync_cancel() {
		Plugin::instance()->queue->cancel();
		\WP_CLI::success( 'Batch sync cancelled.' );
	}
}

\WP_CLI::add_command( 'steadysync', __NAMESPACE__ . '\\CLI' );
