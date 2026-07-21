<?php
/**
 * Proaktiver Token-Refresh — fixt "wöchentliche Disconnects" des offiziellen Plugins
 * (dort existiert Refresh-Infra, greift aber unzuverlässig). Prinzip: VOR Ablauf erneuern.
 */
namespace Steadysync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Token_Manager {

	public const CRON_HOOK = 'steadysync_token_refresh';

	/** Refresh, sobald weniger als so viele Tage bis Ablauf bleiben. */
	private const MARGIN_DAYS = 7;

	public function __construct( private Settings $settings, private Square_Client $client ) {}

	public function register(): void {
		add_action( self::CRON_HOOK, array( $this, 'maybe_refresh' ) );
	}

	/** Reine, testbare Entscheidungslogik. */
	public static function should_refresh( int $expires_at, int $now, int $margin_days = self::MARGIN_DAYS ): bool {
		if ( $expires_at <= 0 ) {
			return false; // kein Ablauf bekannt (z.B. Sandbox-Token) → nichts zu tun
		}
		return ( $expires_at - $now ) <= ( $margin_days * DAY_IN_SECONDS );
	}

	public function maybe_refresh(): void {
		$token = $this->settings->get_token();
		if ( ! self::should_refresh( $token['expires_at'], time() ) || empty( $token['refresh'] ) ) {
			return;
		}
		$client_id     = $this->settings->oauth_client_id();
		$client_secret = $this->settings->oauth_client_secret();
		if ( '' === $client_id || '' === $client_secret ) {
			return; // Sandbox-Direkt-Token oder Credentials fehlen → kein OAuth-Refresh nötig.
		}
		$res = $this->client->refresh_oauth_token( $token['refresh'], $client_id, $client_secret );

		if ( is_array( $res ) && ! empty( $res['access_token'] ) ) {
			$expires = isset( $res['expires_at'] ) ? strtotime( $res['expires_at'] ) : ( time() + 30 * DAY_IN_SECONDS );
			$this->settings->set_token( $res['access_token'], $res['refresh_token'] ?? $token['refresh'], (int) $expires );
		} else {
			// Fail-Alert statt stillem Disconnect (der Incumbent-Schmerz).
			do_action( 'steadysync_token_refresh_failed', $res );
		}
	}
}
