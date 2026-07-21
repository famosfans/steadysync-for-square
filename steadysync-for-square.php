<?php
/**
 * Plugin Name:       Steadysync – Inventory Sync for Square
 * Plugin URI:        https://steadysync.net
 * Description:        Keeps WooCommerce stock and catalog in sync with Square. An anti-zeroing guard rejects empty or partial updates so a sync never overwrites your stock with 0. Webhook-driven, with a dry-run preview.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            famosMedia
 * Author URI:        https://famos-media.de
 * Text Domain:       steadysync-for-square
 * WC requires at least: 8.0
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Square is a trademark of Block, Inc. WooCommerce is a trademark of Automattic Inc.
 * Steadysync is an independent, unofficial plugin and is not affiliated with, endorsed
 * by or sponsored by Block, Inc. or Automattic Inc.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'STEADYSYNC_VERSION', '1.0.0' );
define( 'STEADYSYNC_DIR', plugin_dir_path( __FILE__ ) );
define( 'STEADYSYNC_FILE', __FILE__ );

require_once STEADYSYNC_DIR . 'includes/class-crypto.php';
require_once STEADYSYNC_DIR . 'includes/class-settings.php';
require_once STEADYSYNC_DIR . 'includes/class-square-client.php';
require_once STEADYSYNC_DIR . 'includes/class-token-manager.php';
require_once STEADYSYNC_DIR . 'includes/class-inventory-sync.php';
require_once STEADYSYNC_DIR . 'includes/class-image-sync.php';
require_once STEADYSYNC_DIR . 'includes/class-catalog-sync.php';
require_once STEADYSYNC_DIR . 'includes/class-webhook.php';
require_once STEADYSYNC_DIR . 'includes/class-preview.php';
require_once STEADYSYNC_DIR . 'includes/class-batch-queue.php';
require_once STEADYSYNC_DIR . 'includes/class-health.php';
require_once STEADYSYNC_DIR . 'includes/class-oauth.php';
require_once STEADYSYNC_DIR . 'includes/class-admin.php';
require_once STEADYSYNC_DIR . 'includes/class-plugin.php';

register_activation_hook(
	__FILE__,
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die( esc_html__( 'Steadysync requires WooCommerce.', 'steadysync-for-square' ) );
		}
		if ( ! wp_next_scheduled( \Steadysync\Token_Manager::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', \Steadysync\Token_Manager::CRON_HOOK );
		}
		if ( ! wp_next_scheduled( \Steadysync\Health::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', \Steadysync\Health::CRON_HOOK );
		}
		add_option( 'steadysync_settings', \Steadysync\Settings::defaults(), '', false );
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( \Steadysync\Token_Manager::CRON_HOOK );
		wp_clear_scheduled_hook( \Steadysync\Batch_Queue::CRON_HOOK );
		wp_clear_scheduled_hook( \Steadysync\Health::CRON_HOOK );
	}
);

add_action(
	'plugins_loaded',
	function () {
		\Steadysync\Plugin::instance()->init();
	}
);

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once STEADYSYNC_DIR . 'includes/class-cli.php';
}
