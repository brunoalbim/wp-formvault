<?php
/**
 * Integration contract.
 *
 * @package WPFormVault
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface WPFV_Integration {
	/**
	 * Deliver a stored submission.
	 *
	 * @param object $submission Stored submission.
	 * @param array  $form Registered form definition.
	 * @param array  $settings Effective form settings.
	 * @return array
	 */
	public function deliver( $submission, $form, $settings );
}

