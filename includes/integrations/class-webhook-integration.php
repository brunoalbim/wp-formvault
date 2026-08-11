<?php
/**
 * Signed JSON webhook integration.
 *
 * @package WPFormVault
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPFV_Webhook_Integration implements WPFV_Integration {
	/** {@inheritdoc} */
	public function deliver( $submission, $form, $settings ) {
		$url = esc_url_raw( $settings['webhook_url'] );
		if ( ! wp_http_validate_url( $url ) ) {
			return array( 'success' => false, 'code' => 0, 'error' => 'URL de webhook inválida.' );
		}

		$mapped_fields = ( new WPFV_Field_Mapper() )->webhook_fields( json_decode( $submission->fields, true ), $settings );
		$payload = array(
			'event'         => 'form.submitted',
			'submission_id' => (int) $submission->id,
			'form'          => array( 'id' => $submission->form_id, 'name' => $submission->form_name ),
			'fields'        => $mapped_fields,
			'attribution'   => json_decode( $submission->attribution, true ),
			'page'          => array( 'url' => $submission->source_url, 'title' => $submission->source_title ),
			'submitted_at'  => mysql2date( DATE_ATOM, $submission->created_at, false ),
			'integrations'  => isset( $submission->integration_results ) ? $submission->integration_results : array(),
		);
		$body    = wp_json_encode( $payload );
		$headers = array(
			'Content-Type'                => 'application/json; charset=utf-8',
			'X-WP-FormVault-Event'        => 'form.submitted',
			'X-WP-FormVault-Submission'   => (string) $submission->id,
		);

		if ( ! empty( $settings['webhook_secret'] ) ) {
			$headers['X-WP-FormVault-Signature'] = 'sha256=' . hash_hmac( 'sha256', $body, $settings['webhook_secret'] );
		}

		$response = wp_safe_remote_post(
			$url,
			array( 'timeout' => 10, 'redirection' => 2, 'headers' => $headers, 'body' => $body, 'data_format' => 'body' )
		);

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'code' => 0, 'error' => $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		return array(
			'success' => $code >= 200 && $code < 300,
			'code'    => $code,
			'body'    => substr( wp_remote_retrieve_body( $response ), 0, 2000 ),
			'error'   => $code >= 200 && $code < 300 ? '' : 'O webhook respondeu com HTTP ' . $code . '.',
		);
	}
}
