<?php
/**
 * Central orchestrator — holds the modules and wires the hooks.
 *
 * Extension points for add-ons (e.g. the Steadysync Pro add-on, distributed separately):
 *   do_action( 'steadysync_loaded', \Steadysync\Plugin $plugin )
 *     Fired once after the core has wired its hooks and WooCommerce is confirmed active.
 *     Add-ons register their own modules here, using the public properties below
 *     ($plugin->settings, $plugin->client, $plugin->inventory, $plugin->catalog, …).
 */
namespace Steadysync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	private static ?Plugin $instance = null;

	public Settings $settings;
	public Square_Client $client;
	public Token_Manager $tokens;
	public Inventory_Sync $inventory;
	public Catalog_Sync $catalog;
	public Webhook $webhook;
	public Preview $preview;
	public Batch_Queue $queue;
	public Health $health;
	public OAuth $oauth;
	public Admin $admin;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings  = new Settings();
		$this->client    = new Square_Client( $this->settings );
		$this->tokens    = new Token_Manager( $this->settings, $this->client );
		$this->inventory = new Inventory_Sync( $this->settings, $this->client );
		$this->catalog   = new Catalog_Sync( $this->client );
		$this->webhook   = new Webhook( $this->settings, $this->inventory, $this->catalog );
		$this->preview   = new Preview( $this->client, $this->catalog, $this->settings );
		$this->queue     = new Batch_Queue( $this->catalog );
		$this->health    = new Health( $this->settings, $this->client );
		$this->oauth     = new OAuth( $this->settings, $this->client );
		$this->admin     = new Admin( $this->settings, $this->client, $this->preview, $this->oauth );
	}

	public function init(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// Reliable Square → WooCommerce sync (inventory + catalog) with the anti-zeroing
		// guard, the Square connection/OAuth, proactive token refresh, the health monitor,
		// the timeout-safe batch queue and the dry-run preview.
		$this->webhook->register();
		$this->tokens->register();
		$this->oauth->register();
		$this->health->register();
		$this->queue->register();

		if ( is_admin() ) {
			$this->admin->register();
		}

		/**
		 * Fires after the core is initialised. Add-ons hook this to register their own
		 * modules against the public module properties on $plugin.
		 *
		 * @param Plugin $plugin The plugin instance.
		 */
		do_action( 'steadysync_loaded', $this );
	}
}
