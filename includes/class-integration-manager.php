<?php
/**
 * Integration orchestrator.
 *
 * @package WPFormVault
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPFV_Integration_Manager {
	/** @var WPFV_Submission_Repository */
	private $repository;

	public function __construct( $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Run enabled integrations only after a submission has been persisted.
	 *
	 * @param int   $submission_id Submission ID.
	 * @param array $form Registered form.
	 * @param string $only Optional integration slug to retry in isolation.
	 * @return bool Whether at least one integration was processed.
	 */
	public function process( $submission_id, $form, $only = '' ) {
		$submission = $this->repository->get( $submission_id );
		if ( ! $submission ) {
			return false;
		}

		$settings     = WPFV_Settings::get_for_form( $form );
		$integrations = array();

		if ( ! empty( $settings['email_enabled'] ) ) {
			$integrations['email'] = new WPFV_Email_Integration();
		}
		if ( ! empty( $settings['pipedrive_enabled'] ) ) { $integrations['pipedrive'] = new WPFV_Pipedrive_Integration(); }
		if ( ! empty( $settings['brevo_enabled'] ) ) { $integrations['brevo'] = new WPFV_Brevo_Integration(); }
		if ( ! empty( $settings['webhook_enabled'] ) && ! empty( $settings['webhook_url'] ) ) {
			$integrations['webhook'] = new WPFV_Webhook_Integration();
		}

		/**
		 * Add optional integrations such as Pipedrive or Brevo.
		 *
		 * @param array  $integrations Integration objects keyed by slug.
		 * @param object $submission Stored submission.
		 * @param array  $form Registered form.
		 */
		$integrations = apply_filters( 'wpfv_integrations', $integrations, $submission, $form );
		$processed    = false;
		$results      = array();

		foreach ( $integrations as $slug => $integration ) {
			if ( $only && sanitize_key( $slug ) !== sanitize_key( $only ) ) {
				continue;
			}
			if ( ! $integration instanceof WPFV_Integration ) {
				continue;
			}
			$submission->integration_results = $results;
			$delivery_id = $this->repository->create_delivery( $submission_id, sanitize_key( $slug ) );
			$result      = $integration->deliver( $submission, $form, $settings );
			$results[ sanitize_key( $slug ) ] = $result;
			$this->repository->complete_delivery( $delivery_id, $result );
			$this->repository->log( ! empty( $result['success'] ) ? 'success' : 'error', $slug, ! empty( $result['success'] ) ? 'Integração concluída.' : ( isset( $result['error'] ) ? $result['error'] : 'Falha na integração.' ), $result, $submission_id );
			$processed = true;
		}

		return $processed;
	}
}
