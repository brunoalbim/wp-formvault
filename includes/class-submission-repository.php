<?php
/**
 * Persistence layer for submissions and deliveries.
 *
 * @package WPFormVault
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPFV_Submission_Repository {
	public function log( $level, $source, $message, $context = array(), $submission_id = 0 ) {
		$global = WPFV_Settings::get_global();
		if ( empty( $global['logging_enabled'] ) ) { return 0; }
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'wpfv_logs', array( 'submission_id' => absint( $submission_id ), 'level' => sanitize_key( $level ), 'source' => sanitize_key( $source ), 'message' => sanitize_text_field( $message ), 'context' => wp_json_encode( $context ), 'created_at' => current_time( 'mysql', true ) ), array( '%d', '%s', '%s', '%s', '%s', '%s' ) );
		return (int) $wpdb->insert_id;
	}

	public function query_logs( $args = array() ) {
		global $wpdb; $args = wp_parse_args( $args, array( 'level' => '', 'source' => '', 'limit' => 200 ) ); $where = array( '1=1' ); $vars = array();
		if ( $args['level'] ) { $where[] = 'level=%s'; $vars[] = sanitize_key( $args['level'] ); }
		if ( $args['source'] ) { $where[] = 'source=%s'; $vars[] = sanitize_key( $args['source'] ); }
		$sql = "SELECT * FROM {$wpdb->prefix}wpfv_logs WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d'; $vars[] = min( 500, max( 1, absint( $args['limit'] ) ) );
		return $wpdb->get_results( $wpdb->prepare( $sql, $vars ) );
	}

	public function clear_logs() { global $wpdb; return $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}wpfv_logs" ); }

	/** Delete integration logs older than the configured retention window. */
	public function purge_expired_logs() {
		global $wpdb;
		$settings = WPFV_Settings::get_global();
		$days     = max( 1, absint( $settings['log_retention_days'] ) );
		$cutoff   = gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * $days ) );
		return $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wpfv_logs WHERE created_at < %s", $cutoff ) );
	}
	/**
	 * Store a validated submission.
	 *
	 * @param array $data Submission data.
	 * @return int|WP_Error
	 */
	public function create( $data ) {
		global $wpdb;
		$now = current_time( 'mysql', true );

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'wpfv_submissions',
			array(
				'form_id'       => $data['form_id'],
				'form_name'     => $data['form_name'],
				'primary_name'  => $data['primary_name'],
				'primary_email' => $data['primary_email'],
				'fields'        => wp_json_encode( $data['fields'] ),
				'attribution'   => wp_json_encode( $data['attribution'] ),
				'source_url'    => $data['source_url'],
				'source_title'  => $data['source_title'],
				'status'        => 'stored',
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'wpfv_storage_error', __( 'Não foi possível armazenar o envio.', 'wp-formvault' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Get one submission.
	 *
	 * @param int $id Submission ID.
	 * @return object|null
	 */
	public function get( $id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpfv_submissions WHERE id = %d", $id )
		);
	}

	/** Delete one submission and its local delivery/log history. */
	public function delete( $id ) {
		global $wpdb;
		$id = absint( $id );
		if ( ! $id || ! $this->get( $id ) ) { return false; }
		$wpdb->query( 'START TRANSACTION' );
		$logs       = $wpdb->delete( $wpdb->prefix . 'wpfv_logs', array( 'submission_id' => $id ), array( '%d' ) );
		$deliveries = $wpdb->delete( $wpdb->prefix . 'wpfv_deliveries', array( 'submission_id' => $id ), array( '%d' ) );
		$submission = $wpdb->delete( $wpdb->prefix . 'wpfv_submissions', array( 'id' => $id ), array( '%d' ) );
		if ( false === $logs || false === $deliveries || 1 !== $submission ) { $wpdb->query( 'ROLLBACK' ); return false; }
		$wpdb->query( 'COMMIT' );
		return true;
	}

	/**
	 * Query submissions for the admin list.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public function query( $args = array() ) {
		global $wpdb;
		$args = wp_parse_args( $args, array( 'form_id' => '', 'search' => '', 'page' => 1, 'per_page' => 20 ) );
		$where = array( '1=1' );
		$vars  = array();

		if ( $args['form_id'] ) {
			$where[] = 'form_id = %s';
			$vars[]  = sanitize_key( $args['form_id'] );
		}

		if ( $args['search'] ) {
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = '(primary_name LIKE %s OR primary_email LIKE %s OR fields LIKE %s)';
			$vars[]  = $like;
			$vars[]  = $like;
			$vars[]  = $like;
		}

		$where_sql = implode( ' AND ', $where );
		$per_page = max( 1, min( 100, absint( $args['per_page'] ) ) );
		$offset   = ( max( 1, absint( $args['page'] ) ) - 1 ) * $per_page;
		$count_sql  = "SELECT COUNT(*) FROM {$wpdb->prefix}wpfv_submissions WHERE {$where_sql}";
		$count_vars = $vars;
		$total      = empty( $count_vars )
			? (int) $wpdb->get_var( $count_sql )
			: (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $count_vars ) );

		$sql      = "SELECT * FROM {$wpdb->prefix}wpfv_submissions WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$vars[]   = $per_page;
		$vars[]   = $offset;
		$prepared = $wpdb->prepare( $sql, $vars );
		$items    = $wpdb->get_results( $prepared );

		return array( 'items' => $items, 'total' => $total, 'per_page' => $per_page );
	}

	/**
	 * Create an integration delivery record.
	 *
	 * @param int    $submission_id Submission ID.
	 * @param string $integration Integration key.
	 * @return int
	 */
	public function create_delivery( $submission_id, $integration ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			$wpdb->prefix . 'wpfv_deliveries',
			array(
				'submission_id' => $submission_id,
				'integration'   => $integration,
				'status'        => 'pending',
				'attempts'      => 0,
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Complete a delivery attempt.
	 *
	 * @param int   $delivery_id Delivery ID.
	 * @param array $result Integration result.
	 * @return void
	 */
	public function complete_delivery( $delivery_id, $result ) {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'wpfv_deliveries',
			array(
				'status'        => ! empty( $result['success'] ) ? 'success' : 'error',
				'attempts'      => 1,
				'response_code' => isset( $result['code'] ) ? absint( $result['code'] ) : 0,
				'response_body' => isset( $result['body'] ) ? wp_json_encode( $result['body'] ) : '',
				'error_message' => isset( $result['error'] ) ? sanitize_text_field( $result['error'] ) : '',
				'updated_at'    => current_time( 'mysql', true ),
			),
			array( 'id' => $delivery_id ),
			array( '%s', '%d', '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Get integration deliveries for one submission.
	 *
	 * @param int $submission_id Submission ID.
	 * @return array
	 */
	public function get_deliveries( $submission_id ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpfv_deliveries WHERE submission_id = %d ORDER BY id ASC", $submission_id )
		);
	}

	/**
	 * Get the latest delivery of each integration for multiple submissions.
	 *
	 * @param array $submission_ids Submission IDs.
	 * @return array Deliveries keyed by submission ID and integration slug.
	 */
	public function get_latest_deliveries( $submission_ids ) {
		global $wpdb;

		$submission_ids = array_values( array_filter( array_map( 'absint', $submission_ids ) ) );
		if ( empty( $submission_ids ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $submission_ids ), '%d' ) );
		$sql          = "SELECT * FROM {$wpdb->prefix}wpfv_deliveries WHERE submission_id IN ({$placeholders}) ORDER BY id DESC";
		$rows         = $wpdb->get_results( $wpdb->prepare( $sql, $submission_ids ) );
		$latest       = array();

		foreach ( $rows as $row ) {
			$submission_id = (int) $row->submission_id;
			$integration   = sanitize_key( $row->integration );
			if ( ! isset( $latest[ $submission_id ][ $integration ] ) ) {
				$latest[ $submission_id ][ $integration ] = $row;
			}
		}

		return $latest;
	}
}
