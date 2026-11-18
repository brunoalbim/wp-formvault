<?php
/**
 * Fresh security tokens for forms rendered from a page cache.
 *
 * `wpfv_form_hidden_fields()` prints a nonce and a signed render timestamp in
 * the HTML. When a page cache serves that HTML, both can be wrong for the
 * visitor:
 *
 * - nonces expire after 12–24h and are bound to the user session, so a stale
 *   copy, or the anonymous copy served to a logged-in user, fails
 *   `wp_verify_nonce()` and the submission is rejected as "invalid";
 * - a timestamp older than WPFV_Antispam::MAX_FORM_AGE makes the antispam
 *   check silently discard the submission while the visitor sees success.
 *
 * public/js/attribution.js calls this uncached admin-ajax endpoint on the
 * first interaction with a form and again right before each AJAX submission,
 * replacing the hidden values.
 *
 * @package WPFormVault
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPFV_Form_Tokens {
	const ACTION = 'wpfv_form_tokens';

	/** @var WPFV_Form_Registry */
	private $registry;

	public function __construct( WPFV_Form_Registry $registry ) {
		$this->registry = $registry;
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Build the token set for a form.
	 *
	 * A validly signed render timestamp is preserved while it is comfortably
	 * inside MAX_FORM_AGE, so the minimum fill time keeps measuring from the
	 * original render. A validly signed but (nearly) expired one proves the
	 * HTML came from this site long ago, so it is replaced by a timestamp that
	 * already satisfies MIN_SECONDS instead of letting the submission be
	 * discarded. A missing or forged one is replaced by the current time.
	 *
	 * @param string $form_id   Registered form identifier.
	 * @param int    $started   Render timestamp currently in the form.
	 * @param string $signature Signature currently in the form.
	 * @return array<string,string>
	 */
	public static function fresh( $form_id, $started = 0, $signature = '' ) {
		$form_id = sanitize_key( $form_id );
		$now     = time();
		$started = absint( $started );
		$valid   = $started && $signature && hash_equals( WPFV_Antispam::timestamp_signature( $form_id, $started ), (string) $signature );
		$age     = $now - $started;

		if ( $valid && $age >= 0 && $age <= WPFV_Antispam::MAX_FORM_AGE - HOUR_IN_SECONDS ) {
			$timestamp = $started;
		} elseif ( $valid ) {
			$timestamp = $now - WPFV_Antispam::MIN_SECONDS;
		} else {
			$timestamp = $now;
		}

		return array(
			'wpfv_nonce'       => wp_create_nonce( 'wpfv_submit_' . $form_id ),
			'wpfv_started_at'  => (string) $timestamp,
			'wpfv_started_sig' => WPFV_Antispam::timestamp_signature( $form_id, $timestamp ),
		);
	}

	/** Respond to the AJAX request without letting any cache store it. */
	public function handle() {
		nocache_headers();

		$form_id = isset( $_REQUEST['wpfv_form_id'] ) && is_scalar( $_REQUEST['wpfv_form_id'] ) ? sanitize_key( wp_unslash( $_REQUEST['wpfv_form_id'] ) ) : '';
		if ( '' === $form_id || ! $this->registry->get( $form_id ) ) {
			wp_send_json_error( array( 'status' => 'unknown_form' ), 400 );
		}

		$started   = isset( $_REQUEST['wpfv_started_at'] ) && is_scalar( $_REQUEST['wpfv_started_at'] ) ? absint( $_REQUEST['wpfv_started_at'] ) : 0;
		$signature = isset( $_REQUEST['wpfv_started_sig'] ) && is_scalar( $_REQUEST['wpfv_started_sig'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['wpfv_started_sig'] ) ) : '';

		wp_send_json_success( self::fresh( $form_id, $started, $signature ) );
	}
}
