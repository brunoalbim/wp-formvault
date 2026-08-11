<?php
/**
 * Main plugin bootstrap.
 *
 * @package WPFormVault
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WPFV_Plugin {
	/** @var WPFV_Plugin|null */
	private static $instance = null;

	/** @var WPFV_Form_Registry */
	private $registry;

	/**
	 * Bootstrap the singleton.
	 *
	 * @return WPFV_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->registry = new WPFV_Form_Registry();
		$repository     = new WPFV_Submission_Repository();
		$manager        = new WPFV_Integration_Manager( $repository );

		new WPFV_Submission_Handler( $this->registry, $repository, $manager );
		new WPFV_Assets();

		if ( is_admin() ) {
			new WPFV_Admin( $this->registry, $repository, $manager );
		}

		add_action( 'init', array( $this, 'maybe_upgrade' ), 1 );
	}

	/**
	 * Run idempotent schema upgrades after plugin updates.
	 *
	 * @return void
	 */
	public function maybe_upgrade() {
		if ( WPFV_VERSION !== get_option( 'wpfv_version' ) ) {
			WPFV_Activator::activate();
		}

		$last_cleanup = absint( get_option( 'wpfv_last_log_cleanup', 0 ) );
		if ( $last_cleanup < time() - DAY_IN_SECONDS ) {
			$repository = new WPFV_Submission_Repository();
			$repository->purge_expired_logs();
			update_option( 'wpfv_last_log_cleanup', time(), false );
		}
	}

	/**
	 * Expose the registry to integrations and themes.
	 *
	 * @return WPFV_Form_Registry
	 */
	public function registry() {
		return $this->registry;
	}
}
