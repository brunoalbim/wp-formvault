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

		$source        = sanitize_key( $submission->form_id );
		$mapped_fields = ( new WPFV_Field_Mapper() )->webhook_fields( json_decode( $submission->fields, true ), $settings );
		$payload       = array(
			'source' => $source,
			'form'   => array(
				'source' => $source,
				'fields' => (object) $mapped_fields,
				'page'   => array(
					'title'          => sanitize_text_field( $submission->source_title ),
					'conversion_url' => esc_url_raw( $submission->source_url ),
				),
				'utm'    => (object) $this->last_utm( json_decode( $submission->attribution, true ) ),
			),
		);
		$attachments = $this->attachments( $submission );
		if ( $attachments ) {
			$payload['form']['attachments'] = $attachments;
		}
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
			array( 'timeout' => $attachments ? 30 : 10, 'redirection' => 2, 'headers' => $headers, 'body' => $body, 'data_format' => 'body' )
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

	/**
	 * Files of the submission, with Base64 content while they are still stored.
	 *
	 * @param object $submission Stored submission.
	 * @return array
	 */
	private function attachments( $submission ) {
		$store = new WPFV_Attachments();
		$items = array();
		foreach ( $store->for_submission( $submission->id ) as $attachment ) {
			$path    = $store->path( $attachment );
			$content = $path ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions
			$items[] = array(
				'field'          => sanitize_key( $attachment->field_id ),
				'name'           => $attachment->original_name,
				'mime_type'      => $attachment->mime_type,
				'size'           => (int) $attachment->size,
				'sha256'         => $attachment->sha256,
				'available'      => false !== $content,
				'content_base64' => false !== $content ? base64_encode( $content ) : null,
			);
		}
		return $items;
	}

	private function last_utm( $attribution ) {
		$attribution = is_array( $attribution ) ? $attribution : array();
		$utm         = array();
		foreach ( array( 'source', 'medium', 'channel', 'campaign', 'term', 'content' ) as $name ) {
			$stored_key = 'last_utm_' . $name;
			if ( ! isset( $attribution[ $stored_key ] ) || ! is_scalar( $attribution[ $stored_key ] ) ) {
				continue;
			}
			$value = sanitize_text_field( $attribution[ $stored_key ] );
			if ( '' !== $value ) {
				$utm[ 'utm_' . $name ] = $value;
			}
		}
		foreach ( array( 'last_normalized_source' => 'normalized_source', 'last_attribution_method' => 'attribution_method' ) as $stored_key => $payload_key ) {
			if ( isset( $attribution[ $stored_key ] ) && is_scalar( $attribution[ $stored_key ] ) ) {
				$value = sanitize_text_field( $attribution[ $stored_key ] );
				if ( '' !== $value ) {
					$utm[ $payload_key ] = $value;
				}
			}
		}
		return $utm;
	}
}
