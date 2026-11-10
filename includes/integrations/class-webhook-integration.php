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

		return $this->send( $settings, $payload, array( 'X-WP-FormVault-Submission' => (string) $submission->id ), $attachments ? 30 : 10 );
	}

	/**
	 * Send a test delivery with fictitious data, using the same format as a real submission.
	 *
	 * @param array $form Registered form definition.
	 * @param array $settings Effective webhook settings (URL, secret and field map).
	 * @return array Delivery result plus the payload that was sent.
	 */
	public function test( $form, $settings ) {
		$source      = sanitize_key( $form['id'] );
		$fields      = array();
		$attachments = array();
		foreach ( $form['fields'] as $field_id => $definition ) {
			$fields[ $field_id ] = $this->sample_value( $field_id, $definition );
			if ( 'file' === $definition['type'] ) {
				$content       = "Arquivo de teste enviado pelo WP FormVault.\nFormulário: " . $form['name'] . "\nData: " . gmdate( 'c' ) . "\n";
				$attachments[] = array(
					'field'          => sanitize_key( $field_id ),
					'name'           => $fields[ $field_id ],
					'mime_type'      => 'text/plain',
					'size'           => strlen( $content ),
					'sha256'         => hash( 'sha256', $content ),
					'available'      => true,
					'content_base64' => base64_encode( $content ),
				);
			}
		}

		$payload = array(
			'source' => $source,
			'test'   => true,
			'form'   => array(
				'source' => $source,
				'fields' => (object) ( new WPFV_Field_Mapper() )->webhook_fields( $fields, $settings ),
				'page'   => array(
					'title'          => sanitize_text_field( 'Teste de webhook — ' . $form['name'] ),
					'conversion_url' => esc_url_raw( home_url( '/' ) ),
				),
				'utm'    => (object) array(
					'utm_source'         => 'wp-formvault',
					'utm_medium'         => 'teste',
					'utm_campaign'       => 'teste-webhook',
					'normalized_source'  => 'wp-formvault',
					'attribution_method' => 'teste',
				),
			),
		);
		if ( $attachments ) {
			$payload['form']['attachments'] = $attachments;
		}

		$result            = $this->send( $settings, $payload, array( 'X-WP-FormVault-Submission' => '0', 'X-WP-FormVault-Test' => '1' ), 15 );
		$result['payload'] = $payload;
		return $result;
	}

	/**
	 * Fictitious value for a registered field, following its type.
	 *
	 * @param string $field_id Field identifier.
	 * @param array  $definition Normalized field definition.
	 * @return string|int
	 */
	private function sample_value( $field_id, $definition ) {
		$token = strtolower( wp_generate_password( 6, false, false ) );
		switch ( $definition['type'] ) {
			case 'email':
				return 'teste+' . $token . '@example.com';
			case 'tel':
				return sprintf( '(11) 9%04d-%04d', wp_rand( 0, 9999 ), wp_rand( 0, 9999 ) );
			case 'url':
				return 'https://example.com/teste-' . $token;
			case 'integer':
				return wp_rand( 1, 100 );
			case 'checkbox':
				return '1';
			case 'select':
				return ! empty( $definition['options'] ) ? (string) reset( $definition['options'] ) : 'Opção de teste';
			case 'textarea':
				return 'Mensagem de teste (' . $token . ') enviada pelo WP FormVault para validar o webhook.';
			case 'file':
				return 'arquivo-teste-' . $token . '.txt';
			case 'hidden':
				return 'teste-' . $token;
			default:
				return 'Teste ' . ucfirst( $token ) . ( preg_match( '/nome|name/', $field_id ) ? ' da Silva' : '' );
		}
	}

	/**
	 * POST a JSON payload, signed when a secret is configured.
	 *
	 * @param array $settings Webhook settings.
	 * @param array $payload Body to encode.
	 * @param array $extra_headers Additional headers.
	 * @param int   $timeout Request timeout in seconds.
	 * @return array
	 */
	private function send( $settings, $payload, $extra_headers, $timeout ) {
		$url = esc_url_raw( $settings['webhook_url'] );
		if ( ! wp_http_validate_url( $url ) ) {
			return array( 'success' => false, 'code' => 0, 'error' => 'URL de webhook inválida.' );
		}

		$body    = wp_json_encode( $payload );
		$headers = array_merge(
			array(
				'Content-Type'         => 'application/json; charset=utf-8',
				'X-WP-FormVault-Event' => 'form.submitted',
			),
			$extra_headers
		);

		if ( ! empty( $settings['webhook_secret'] ) ) {
			$headers['X-WP-FormVault-Signature'] = 'sha256=' . hash_hmac( 'sha256', $body, $settings['webhook_secret'] );
		}

		$response = wp_safe_remote_post(
			$url,
			array( 'timeout' => $timeout, 'redirection' => 2, 'headers' => $headers, 'body' => $body, 'data_format' => 'body' )
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
