<?php
/**
 * Plugin activation and schema management.
 *
 * @package WPFormVault
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPFV_Activator {
	/**
	 * Create or update plugin tables.
	 *
	 * @return void
	 */
	public static function activate() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$submissions     = $wpdb->prefix . 'wpfv_submissions';
		$deliveries      = $wpdb->prefix . 'wpfv_deliveries';
		$logs            = $wpdb->prefix . 'wpfv_logs';

		$sql_submissions = "CREATE TABLE {$submissions} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			form_id varchar(100) NOT NULL,
			form_name varchar(255) NOT NULL DEFAULT '',
			primary_name varchar(255) NOT NULL DEFAULT '',
			primary_email varchar(255) NOT NULL DEFAULT '',
			fields longtext NOT NULL,
			attribution longtext NOT NULL,
			source_url text NOT NULL,
			source_title text NOT NULL,
			status varchar(30) NOT NULL DEFAULT 'stored',
			risk_data longtext NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY form_id (form_id),
			KEY primary_email (primary_email),
			KEY status (status),
			KEY status_created (status,created_at),
			KEY created_at (created_at)
		) {$charset_collate};";

		$sql_deliveries = "CREATE TABLE {$deliveries} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			submission_id bigint(20) unsigned NOT NULL,
			integration varchar(50) NOT NULL,
			status varchar(30) NOT NULL DEFAULT 'pending',
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			response_code int(11) NOT NULL DEFAULT 0,
			response_body longtext NOT NULL,
			error_message text NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY submission_id (submission_id),
			KEY integration_status (integration,status)
		) {$charset_collate};";

		$sql_logs = "CREATE TABLE {$logs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			submission_id bigint(20) unsigned NOT NULL DEFAULT 0,
			level varchar(20) NOT NULL DEFAULT 'info',
			source varchar(50) NOT NULL DEFAULT 'plugin',
			message text NOT NULL,
			context longtext NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY submission_id (submission_id),
			KEY level_source (level,source),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_submissions );
		dbDelta( $sql_deliveries );
		dbDelta( $sql_logs );

		update_option( 'wpfv_version', WPFV_VERSION, false );
	}

	/**
	 * Data intentionally remains available after deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() { wp_clear_scheduled_hook( 'wpfv_rdcrm_refresh_token' ); }
}
