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
		add_action( 'wpfv_rdcrm_refresh_token', array( $this, 'refresh_rdcrm_token' ) );
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
		if ( ( new WPFV_RDCRM_API() )->connected() && ! wp_next_scheduled( 'wpfv_rdcrm_refresh_token' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wpfv_rdcrm_refresh_token' );
		}

		$last_cleanup = absint( get_option( 'wpfv_last_log_cleanup', 0 ) );
		if ( $last_cleanup < time() - DAY_IN_SECONDS ) {
			$repository = new WPFV_Submission_Repository();
			$repository->purge_expired_logs();
			$repository->purge_expired_spam( 90 );
			$global = WPFV_Settings::get_global();
			( new WPFV_Attachments() )->purge_expired( $global['attachment_retention_days'] );
			update_option( 'wpfv_last_log_cleanup', time(), false );
		}
	}

	/** Keep the rolling RD CRM refresh token active even without form submissions. */
	public function refresh_rdcrm_token() {
		$api = new WPFV_RDCRM_API();
		if ( ! $api->connected() ) {
			return;
		}
		$result = $api->refresh_if_needed();
		if ( empty( $result['success'] ) ) {
			( new WPFV_Submission_Repository() )->log( 'error', 'rdcrm', 'Falha na renovação programada do token RD CRM.', array(
				'http_status' => isset( $result['code'] ) ? absint( $result['code'] ) : 0,
				'error' => isset( $result['error'] ) ? $result['error'] : 'Falha sem detalhes.',
			) );
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
