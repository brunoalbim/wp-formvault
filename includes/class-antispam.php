<?php
/**
 * Local antispam checks, rate limits, deduplication and aggregate metrics.
 *
 * @package WPFormVault
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPFV_Antispam {
	const METRICS_OPTION = 'wpfv_antispam_metrics';
	const MIN_SECONDS = 2;
	const MAX_FORM_AGE = 86400;
	const RATE_LIMIT = 5;
	const RATE_WINDOW = 600;
	const DUPLICATE_WINDOW = 600;

	/** Sign the form render timestamp without storing visitor data. */
	public static function timestamp_signature( $form_id, $timestamp ) {
		return hash_hmac( 'sha256', sanitize_key( $form_id ) . '|' . absint( $timestamp ), wp_salt( 'nonce' ) );
	}

	/** Return a validated client IP for transient use only. */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip = apply_filters( 'wpfv_client_ip', $ip );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/** Run checks that can safely reject before field processing or provider calls. */
	public function early_check( $form_id ) {
		$content_length = isset( $_SERVER['CONTENT_LENGTH'] ) ? absint( $_SERVER['CONTENT_LENGTH'] ) : 0;
		if ( $content_length > 131072 ) {
			return 'oversized_request';
		}

		$honeypots = array( 'wpfv_contact_url', 'wpfv_website', 'website' );
		foreach ( $honeypots as $key ) {
			if ( isset( $_POST[ $key ] ) && ( ! is_scalar( $_POST[ $key ] ) || '' !== trim( (string) wp_unslash( $_POST[ $key ] ) ) ) ) {
				return 'honeypot';
			}
		}

		$started   = isset( $_POST['wpfv_started_at'] ) && is_scalar( $_POST['wpfv_started_at'] ) ? absint( $_POST['wpfv_started_at'] ) : 0;
		$signature = isset( $_POST['wpfv_started_sig'] ) && is_scalar( $_POST['wpfv_started_sig'] ) ? sanitize_text_field( wp_unslash( $_POST['wpfv_started_sig'] ) ) : '';
		$expected  = self::timestamp_signature( $form_id, $started );
		if ( ! $started || ! $signature || ! hash_equals( $expected, $signature ) ) {
			return 'invalid_timestamp';
		}
		$age = time() - $started;
		if ( $age < self::MIN_SECONDS ) {
			return 'too_fast';
		}
		if ( $age > self::MAX_FORM_AGE ) {
			return 'expired_timestamp';
		}

		return '';
	}

	/** Increment the rate bucket and report whether it exceeded the limit. */
	public function rate_limited( $form_id ) {
		$ip = self::client_ip();
		if ( ! $ip ) {
			return false;
		}
		$key    = 'wpfv_rl_' . hash_hmac( 'sha256', sanitize_key( $form_id ) . '|' . $ip, wp_salt( 'nonce' ) );
		$now    = time();
		$bucket = get_transient( $key );
		if ( ! is_array( $bucket ) || empty( $bucket['started'] ) || absint( $bucket['started'] ) < $now - self::RATE_WINDOW ) {
			$bucket = array( 'started' => $now, 'count' => 0 );
		}
		$bucket['count'] = absint( $bucket['count'] ) + 1;
		$remaining       = max( 1, self::RATE_WINDOW - ( $now - absint( $bucket['started'] ) ) );
		set_transient( $key, $bucket, $remaining );
		return $bucket['count'] > self::RATE_LIMIT;
	}

	/** Build a privacy-safe exact-payload fingerprint. */
	public function fingerprint( $form_id, $fields ) {
		return hash_hmac( 'sha256', sanitize_key( $form_id ) . '|' . wp_json_encode( $fields ), wp_salt( 'nonce' ) );
	}

	public function is_duplicate( $fingerprint ) {
		return (bool) get_transient( 'wpfv_dup_' . sanitize_key( $fingerprint ) );
	}

	public function remember( $fingerprint, $submission_id ) {
		set_transient( 'wpfv_dup_' . sanitize_key( $fingerprint ), absint( $submission_id ), self::DUPLICATE_WINDOW );
	}

	/** Record a bounded, privacy-free daily counter. */
	public static function increment_metric( $form_id, $reason ) {
		$metrics = get_option( self::METRICS_OPTION, array() );
		$metrics = is_array( $metrics ) ? $metrics : array();
		$day     = gmdate( 'Y-m-d' );
		$form_id = sanitize_key( $form_id );
		$reason  = sanitize_key( $reason );
		if ( ! isset( $metrics[ $day ] ) || ! is_array( $metrics[ $day ] ) ) {
			$metrics[ $day ] = array();
		}
		if ( ! isset( $metrics[ $day ][ $form_id ] ) || ! is_array( $metrics[ $day ][ $form_id ] ) ) {
			$metrics[ $day ][ $form_id ] = array();
		}
		$metrics[ $day ][ $form_id ][ $reason ] = isset( $metrics[ $day ][ $form_id ][ $reason ] ) ? absint( $metrics[ $day ][ $form_id ][ $reason ] ) + 1 : 1;
		$cutoff = gmdate( 'Y-m-d', time() - ( 90 * DAY_IN_SECONDS ) );
		foreach ( array_keys( $metrics ) as $stored_day ) {
			if ( $stored_day < $cutoff ) {
				unset( $metrics[ $stored_day ] );
			}
		}
		update_option( self::METRICS_OPTION, $metrics, false );
	}

	/** Return totals for one form within the retention window. */
	public static function metrics_for_form( $form_id ) {
		$metrics = get_option( self::METRICS_OPTION, array() );
		$totals  = array();
		$form_id = sanitize_key( $form_id );
		foreach ( (array) $metrics as $forms ) {
			if ( empty( $forms[ $form_id ] ) || ! is_array( $forms[ $form_id ] ) ) {
				continue;
			}
			foreach ( $forms[ $form_id ] as $reason => $count ) {
				$totals[ $reason ] = isset( $totals[ $reason ] ) ? $totals[ $reason ] + absint( $count ) : absint( $count );
			}
		}
		return $totals;
	}
}
