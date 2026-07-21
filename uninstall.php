<?php
/**
 * Clean uninstall — removes Steadysync's own options/transients.
 * Product-mapping meta (_square_item_id/_square_item_variation_id) is intentionally kept
 * (non-destructive: a later re-install or the official plugin keeps using it).
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$steadysync_options = array(
	'steadysync_settings',
	'steadysync_token_sandbox',
	'steadysync_token_production',
	'steadysync_oauth_secret',      // encrypted OAuth app secret — do not leave behind.
	'steadysync_seen_events',
	'steadysync_last_catalog_sync',
	'steadysync_health',            // Health::STATE_OPT
	'steadysync_sync_job',          // Batch_Queue::JOB_OPT
);
foreach ( $steadysync_options as $steadysync_option ) {
	delete_option( $steadysync_option );
}
foreach ( array( 'steadysync_locations', 'steadysync_conn_status', 'steadysync_preview', 'steadysync_oauth_state' ) as $steadysync_transient ) {
	delete_transient( $steadysync_transient );
}
// Remove all scheduled cron hooks (defensive — normally already cleared on deactivation).
foreach ( array( 'steadysync_token_refresh', 'steadysync_health_check', 'steadysync_process_batch' ) as $steadysync_hook ) {
	wp_clear_scheduled_hook( $steadysync_hook );
}
