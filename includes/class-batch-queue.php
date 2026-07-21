<?php
/**
 * Batch-Queue für große Kataloge. Ein voller Katalog-Sync läuft NICHT in einem Request (Timeout-Risiko
 * bei 10k+ Produkten — der Crash-Modus, an dem die Konkurrenz scheitert), sondern seitenweise über
 * mehrere WP-Cron-Ticks. Der Square-Cursor wird zwischen den Ticks persistiert → **resume-fähig**:
 * stirbt ein Tick, macht der nächste an derselben Stelle weiter. Fehler → Backoff-Retry am selben Cursor.
 */
namespace Steadysync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Batch_Queue {

	public const JOB_OPT   = 'steadysync_sync_job';
	public const CRON_HOOK = 'steadysync_process_batch';

	private const PAGES_PER_TICK = 3;   // Seiten pro Cron-Lauf (Durchsatz vs. Laufzeit).
	private const MAX_RETRIES     = 5;
	private const BACKOFF_SECONDS = 60;
	private const NEXT_DELAY      = 5;

	public function __construct( private Catalog_Sync $catalog ) {}

	public function register(): void {
		add_action( self::CRON_HOOK, array( $this, 'process' ) );
	}

	/** Startet (bzw. resettet) einen Full- oder Delta-Sync-Job und plant den ersten Batch. */
	public function start( bool $full = true ): array {
		$job = array(
			'status'     => 'running',
			'cursor'     => null,
			'begin_time' => $full ? null : get_option( Catalog_Sync::LAST_SYNC_OPT, null ),
			'full'       => $full,
			'processed'  => 0,
			'pages'      => 0,
			'retries'    => 0,
			'started_at' => gmdate( 'c' ),
			'updated_at' => gmdate( 'c' ),
			'last_error' => '',
		);
		update_option( self::JOB_OPT, $job, false );
		$this->schedule_next();
		return $job;
	}

	public function status(): array {
		$job = get_option( self::JOB_OPT, array() );
		return is_array( $job ) && $job ? $job : array( 'status' => 'idle' );
	}

	/** Bricht einen laufenden Job ab (geplante Events werden entfernt). */
	public function cancel(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		$job = $this->status();
		if ( 'running' === ( $job['status'] ?? '' ) ) {
			$job['status']     = 'cancelled';
			$job['updated_at'] = gmdate( 'c' );
			update_option( self::JOB_OPT, $job, false );
		}
	}

	/** Cron-Handler: verarbeitet einige Seiten, persistiert den Cursor, plant den nächsten Batch. */
	public function process(): void {
		$job = $this->status();
		if ( 'running' !== ( $job['status'] ?? '' ) ) {
			return;
		}

		$pages_this_tick = 0;
		do {
			$page               = $this->catalog->sync_page( $job['cursor'], $job['begin_time'] );
			$job['updated_at']  = gmdate( 'c' );

			if ( $page['error'] ) {
				// Am selben Cursor bleiben, Backoff-Retry.
				++$job['retries'];
				$job['last_error'] = 'Square-API-Fehler bei Seite ' . ( $job['pages'] + 1 );
				if ( $job['retries'] > self::MAX_RETRIES ) {
					$job['status'] = 'error';
					update_option( self::JOB_OPT, $job, false );
					wp_clear_scheduled_hook( self::CRON_HOOK );
					return;
				}
				update_option( self::JOB_OPT, $job, false );
				$this->schedule_next( self::BACKOFF_SECONDS );
				return;
			}

			$job['retries']    = 0;
			$job['processed'] += count( $page['applied'] );
			++$job['pages'];
			$job['cursor'] = $page['cursor'];
			++$pages_this_tick;
		} while ( $job['cursor'] && $pages_this_tick < self::PAGES_PER_TICK );

		if ( $job['cursor'] ) {
			// Noch mehr zu tun → nächsten Batch planen.
			update_option( self::JOB_OPT, $job, false );
			$this->schedule_next();
		} else {
			$job['status'] = 'done';
			update_option( Catalog_Sync::LAST_SYNC_OPT, gmdate( 'c' ), false );
			update_option( self::JOB_OPT, $job, false );
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	private function schedule_next( int $delay = self::NEXT_DELAY ): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + $delay, self::CRON_HOOK );
		}
	}
}
