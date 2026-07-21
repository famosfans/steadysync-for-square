<?php
/**
 * Health-Monitor + Fail-Alerts. Beobachtet die Sync-Gesundheit (Verbindung + Fehlerzähler auf den
 * steadysync_*_failed-Hooks) und mailt dem Shop-Admin bei anhaltenden Problemen — der proaktive
 * Gegenentwurf zum offiziellen Plugin, das wöchentlich still die Verbindung verliert.
 *
 * Alerts sind gedrosselt (ein Alert je Problemtyp pro Fenster), damit kein Postfach geflutet wird.
 * Die Mail geht via wp_mail an die WordPress-Admin-Adresse (der Shop mailt sich selbst — keine
 * externe Infrastruktur).
 */
namespace Steadysync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Health {

	public const STATE_OPT = 'steadysync_health';
	public const CRON_HOOK = 'steadysync_health_check';

	private const FAIL_THRESHOLD  = 3;                 // aufeinanderfolgende Fehler bis Alert.
	private const ALERT_THROTTLE  = 6 * HOUR_IN_SECONDS; // kein Re-Alert desselben Typs im Fenster.

	public function __construct( private Settings $settings, private Square_Client $client ) {}

	public function register(): void {
		add_action( self::CRON_HOOK, array( $this, 'check' ) );

		// Fehler zählen …
		add_action( 'steadysync_push_failed', array( $this, 'record_failure' ), 10, 2 );
		add_action( 'steadysync_catalog_push_failed', array( $this, 'record_failure' ), 10, 2 );
		add_action( 'steadysync_order_sync_failed', array( $this, 'record_failure' ), 10, 2 );

		// … Erfolg setzt den Zähler zurück.
		add_action( 'steadysync_pushed_stock', array( $this, 'record_success' ) );
		add_action( 'steadysync_catalog_pushed', array( $this, 'record_success' ) );
		add_action( 'steadysync_order_synced', array( $this, 'record_success' ) );
	}

	public function state(): array {
		$st = get_option( self::STATE_OPT, array() );
		return wp_parse_args(
			is_array( $st ) ? $st : array(),
			array(
				'connection'   => 'unknown',
				'checked_at'   => '',
				'failures'     => 0,
				'last_failure' => '',
				'last_success' => '',
				'last_error'   => '',
				'alerts'       => array(),
			)
		);
	}

	private function save( array $st ): void {
		update_option( self::STATE_OPT, $st, false );
	}

	/** Fehler-Hook: Zähler hoch, Fehlertext merken, ggf. Alert. */
	public function record_failure( $pid = 0, $res = null ): void {
		$st                 = $this->state();
		$st['failures']     = (int) $st['failures'] + 1;
		$st['last_failure'] = gmdate( 'c' );
		$st['last_error']   = $this->error_text( $res );
		$this->save( $st );

		if ( $st['failures'] >= self::FAIL_THRESHOLD ) {
			$this->maybe_alert(
				'sync_failures',
				__( 'Steadysync: repeated sync failures', 'steadysync-for-square' ),
				sprintf(
					/* translators: 1: failure count, 2: last error */
					__( "Steadysync reports %1\$d consecutive sync failures.\nLast error: %2\$s\n\nPlease check the connection/token in the Steadysync setup.", 'steadysync-for-square' ),
					(int) $st['failures'],
					$st['last_error']
				)
			);
		}
	}

	/** Erfolg-Hook: Fehlerzähler zurücksetzen. */
	public function record_success(): void {
		$st                 = $this->state();
		$st['failures']     = 0;
		$st['last_success'] = gmdate( 'c' );
		$this->save( $st );
	}

	/** Cron: Verbindung prüfen, bei Verlust alarmieren. */
	public function check(): void {
		$ok               = $this->client->connection_ok();
		$st               = $this->state();
		$st['connection'] = $ok ? 'ok' : 'fail';
		$st['checked_at'] = gmdate( 'c' );
		$this->save( $st );

		if ( ! $ok ) {
			$this->maybe_alert(
				'disconnected',
				__( 'Steadysync: no connection to Square', 'steadysync-for-square' ),
				__( "Steadysync cannot reach Square right now (health check failed).\nPossible causes: expired/invalid token, network issue, Square outage.\n\nPlease use \"Test connection\" in the Steadysync setup.", 'steadysync-for-square' )
			);
		}
	}

	/** Sendet einen Alert, aber höchstens einmal je Typ pro Drossel-Fenster. */
	private function maybe_alert( string $type, string $subject, string $body ): void {
		$st   = $this->state();
		$last = (int) ( $st['alerts'][ $type ] ?? 0 );
		if ( ( time() - $last ) < self::ALERT_THROTTLE ) {
			return;
		}
		$to = get_option( 'admin_email' );
		if ( $to ) {
			wp_mail( $to, $subject, $body );
		}
		$st['alerts'][ $type ] = time();
		$this->save( $st );
		do_action( 'steadysync_alert_sent', $type, $subject );
	}

	/** Extrahiert einen kurzen Fehlertext aus WP_Error oder Square-Fehler-Payload. */
	private function error_text( $res ): string {
		if ( is_wp_error( $res ) ) {
			return $res->get_error_message();
		}
		if ( is_array( $res ) && ! empty( $res['errors'][0]['detail'] ) ) {
			return (string) $res['errors'][0]['detail'];
		}
		return 'unbekannt';
	}
}
