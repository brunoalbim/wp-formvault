<?php
/**
 * Public submission endpoint.
 *
 * @package WPFormVault
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPFV_Submission_Handler {
	private $registry;
	private $repository;
	private $manager;

	public function __construct( $registry, $repository, $manager ) {
		$this->registry   = $registry;
		$this->repository = $repository;
		$this->manager    = $manager;

		add_action( 'admin_post_nopriv_wpfv_submit_form', array( $this, 'handle' ) );
		add_action( 'admin_post_wpfv_submit_form', array( $this, 'handle' ) );
		add_action( 'wp_ajax_nopriv_wpfv_submit_form', array( $this, 'handle' ) );
		add_action( 'wp_ajax_wpfv_submit_form', array( $this, 'handle' ) );
	}

	/**
	 * Validate, store, deliver and redirect a public submission.
	 *
	 * @return void
	 */
	public function handle() {
		$form_id = isset( $_POST['wpfv_form_id'] ) && is_scalar( $_POST['wpfv_form_id'] ) ? sanitize_key( wp_unslash( $_POST['wpfv_form_id'] ) ) : '';
		$form    = $this->registry->get( $form_id );
		$guard   = new WPFV_Antispam();

		if ( ! $form ) {
			$this->respond( 'unknown_form', $form_id );
		}

		$nonce = isset( $_POST['wpfv_nonce'] ) && is_scalar( $_POST['wpfv_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['wpfv_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'wpfv_submit_' . $form_id ) ) {
			WPFV_Antispam::increment_metric( $form_id, 'invalid_nonce' );
			$this->respond( 'invalid', $form_id, $form );
		}

		$early_reason = $guard->early_check( $form_id );
		if ( $early_reason ) {
			WPFV_Antispam::increment_metric( $form_id, $early_reason );
			$this->respond( 'success', $form_id, $form );
		}

		$settings = WPFV_Settings::get_for_form( $form );
		if ( empty( $settings['active'] ) ) {
			$this->respond( 'disabled', $form_id, $form );
		}

		$fields = array();
		$errors = array();
		$messages = array();
		$malformed = false;
		$validation = isset( $settings['validation'] ) ? $settings['validation'] : WPFV_Settings::validation_defaults();
		foreach ( $form['fields'] as $field_id => $definition ) {
			$raw_value = isset( $_POST[ $field_id ] ) ? wp_unslash( $_POST[ $field_id ] ) : '';
			if ( is_array( $raw_value ) || is_object( $raw_value ) ) {
				$raw_value = '';
				$errors[]  = $field_id;
				$malformed = true;
			}
			$value     = $this->sanitize_value( $raw_value, $definition );

			if ( $definition['required'] && ( '' === $value || array() === $value ) ) {
				$errors[] = $field_id;
			}
			if ( 'email' === $definition['type'] && '' !== $value && ! is_email( $value ) ) {
				$errors[] = $field_id;
			}
			if ( 'tel' === $definition['type'] && ! empty( $validation['phone_validation'] ) && '' !== $value && strlen( preg_replace( '/\D+/', '', $value ) ) < 10 ) {
				$errors[] = $field_id; $messages[ $field_id ] = $validation['phone_message'];
			}
			if ( 'email' === $definition['type'] && ! empty( $validation['email_block_enabled'] ) && is_email( $value ) ) {
				$blocked = $this->blocked_email_message( $value, $validation );
				if ( $blocked ) { $errors[] = $field_id; $messages[ $field_id ] = $blocked; }
			}
			if ( 'select' === $definition['type'] && ! empty( $definition['options'] ) && '' !== $value && ! in_array( $value, $definition['options'], true ) ) {
				$errors[] = $field_id;
			}
			if ( is_string( $value ) && strlen( $value ) > $this->maximum_length( $definition['type'] ) ) {
				$errors[]  = $field_id;
				$malformed = true;
			}

			$fields[ $field_id ] = $value;
		}

		if ( ! empty( $errors ) ) {
			if ( $malformed ) {
				WPFV_Antispam::increment_metric( $form_id, 'malformed_fields' );
			}
			$this->respond( 'invalid', $form_id, $form, array_unique( $errors ), 0, $messages );
		}

		if ( $guard->rate_limited( $form_id ) ) {
			WPFV_Antispam::increment_metric( $form_id, 'rate_limit' );
			$this->respond( 'success', $form_id, $form );
		}

		$fingerprint = $guard->fingerprint( $form_id, $fields );
		if ( $guard->is_duplicate( $fingerprint ) ) {
			WPFV_Antispam::increment_metric( $form_id, 'duplicate' );
			$this->respond( 'success', $form_id, $form );
		}

		$risk_data = array( 'provider' => 'local', 'reason' => 'not_enabled', 'score' => null, 'reasons' => array() );
		$status    = 'stored';
		$antispam  = isset( $settings['antispam'] ) && is_array( $settings['antispam'] ) ? $settings['antispam'] : WPFV_Settings::antispam_defaults();
		if ( ! empty( $antispam['recaptcha_enabled'] ) ) {
			$threshold = isset( $antispam['score_threshold'] ) ? max( 0, min( 1, (float) $antispam['score_threshold'] ) ) : 0.5;
			$token     = isset( $_POST['wpfv_recaptcha_token'] ) && is_scalar( $_POST['wpfv_recaptcha_token'] ) ? wp_unslash( $_POST['wpfv_recaptcha_token'] ) : '';
			$assessment = ( new WPFV_Recaptcha() )->assess( $token, $form_id, $threshold );
			$risk_data = isset( $assessment['risk_data'] ) && is_array( $assessment['risk_data'] ) ? $assessment['risk_data'] : array();
			if ( 'spam' === $assessment['decision'] ) {
				$status = 'spam';
				WPFV_Antispam::increment_metric( $form_id, isset( $risk_data['reason'] ) ? $risk_data['reason'] : 'recaptcha_spam' );
			} elseif ( 'degraded' === $assessment['decision'] ) {
				WPFV_Antispam::increment_metric( $form_id, 'provider_degraded' );
			}
		}

		$attribution = $this->sanitize_attribution();
		$primary     = $this->find_primary_fields( $fields, $form['fields'] );
		$stored_id   = $this->repository->create(
			array(
				'form_id'       => $form_id,
				'form_name'     => $form['name'],
				'primary_name'  => $primary['name'],
				'primary_email' => $primary['email'],
				'fields'        => $fields,
				'attribution'   => $attribution,
				'source_url'    => isset( $attribution['submission_url'] ) ? $attribution['submission_url'] : wp_get_referer(),
				'source_title'  => isset( $attribution['submission_title'] ) ? $attribution['submission_title'] : '',
				'status'        => $status,
				'risk_data'     => $risk_data,
			)
		);

		if ( is_wp_error( $stored_id ) ) {
			$this->respond( 'error', $form_id, $form );
		}
		$guard->remember( $fingerprint, $stored_id );

		if ( 'spam' === $status ) {
			$this->repository->log( 'warning', 'antispam', 'Envio colocado em quarentena.', array( 'form_id' => $form_id, 'reason' => isset( $risk_data['reason'] ) ? $risk_data['reason'] : 'unknown', 'score' => isset( $risk_data['score'] ) ? $risk_data['score'] : null, 'provider_detail' => isset( $risk_data['provider_detail'] ) ? $risk_data['provider_detail'] : '' ), $stored_id );
			$this->respond( 'success', $form_id, $form, array(), $stored_id );
		}

		/** Fires after durable storage and antispam acceptance. */
		do_action( 'wpfv_submission_stored', $stored_id, $fields, $form );
		$this->manager->process( $stored_id, $form );
		$this->repository->log( 'success', 'submission', 'Contato armazenado.', array( 'form_id' => $form_id, 'score' => isset( $risk_data['score'] ) ? $risk_data['score'] : null ), $stored_id );
		$this->respond( 'success', $form_id, $form, array(), $stored_id );
	}

	private function maximum_length( $type ) {
		$limits = array(
			'email'    => 254,
			'tel'      => 50,
			'url'      => 2048,
			'textarea' => 10000,
			'text'     => 2000,
			'select'   => 2000,
			'hidden'   => 2000,
		);
		return isset( $limits[ $type ] ) ? $limits[ $type ] : 2000;
	}

	private function blocked_email_message( $email, $config ) {
		$parts = explode( '@', strtolower( $email ) ); $domain = end( $parts );
		foreach ( (array) $config['email_block_domains'] as $item ) { if ( strtolower( trim( $item ) ) === $domain ) { return $config['email_msg_domains']; } }
		foreach ( (array) $config['email_block_suffixes'] as $item ) { $item = strtolower( trim( $item ) ); if ( $item && substr( $domain, -strlen( $item ) ) === $item ) { return $config['email_msg_suffixes']; } }
		foreach ( (array) $config['email_block_words'] as $item ) { $item = strtolower( trim( $item ) ); if ( $item && false !== strpos( $domain, $item ) ) { return $config['email_msg_words']; } }
		return '';
	}

	private function sanitize_value( $value, $definition ) {
		switch ( $definition['type'] ) {
			case 'email': return sanitize_email( $value );
			case 'url': return esc_url_raw( $value );
			case 'textarea': return sanitize_textarea_field( $value );
			case 'integer': return '' === $value ? '' : absint( $value );
			case 'checkbox': return empty( $value ) ? '' : '1';
			default: return sanitize_text_field( $value );
		}
	}

	private function sanitize_attribution() {
		$allowed = array(
			'first_utm_source', 'first_utm_medium', 'first_utm_campaign', 'first_utm_term', 'first_utm_content',
			'last_utm_source', 'last_utm_medium', 'last_utm_campaign', 'last_utm_term', 'last_utm_content',
			'gclid', 'fbclid', 'landing_url', 'landing_referrer', 'submission_url', 'submission_title',
		);
		$raw     = isset( $_POST['wpfv_attribution'] ) && is_array( $_POST['wpfv_attribution'] ) ? wp_unslash( $_POST['wpfv_attribution'] ) : array();
		$data    = array();

		foreach ( $allowed as $key ) {
			if ( ! isset( $raw[ $key ] ) || ! is_scalar( $raw[ $key ] ) ) {
				continue;
			}
			$data[ $key ] = false !== strpos( $key, 'url' ) || 'landing_referrer' === $key ? esc_url_raw( $raw[ $key ] ) : sanitize_text_field( $raw[ $key ] );
		}

		return $data;
	}

	private function find_primary_fields( $fields, $definitions ) {
		$name  = '';
		$email = '';
		foreach ( $definitions as $field_id => $definition ) {
			if ( 'email' === $definition['type'] && ! $email && ! empty( $fields[ $field_id ] ) ) {
				$email = $fields[ $field_id ];
			}
			if ( ! $name && preg_match( '/(^|_)(name|nome)($|_)/i', $field_id ) && ! empty( $fields[ $field_id ] ) ) {
				$name = $fields[ $field_id ];
			}
		}
		return array( 'name' => $name, 'email' => $email );
	}

	private function respond( $status, $form_id, $form = null, $errors = array(), $submission_id = 0, $field_messages = array() ) {
		$messages = array(
			'success'      => __( 'Mensagem enviada com sucesso. Em breve entraremos em contato.', 'wp-formvault' ),
			'invalid'      => __( 'Revise os campos obrigatórios e tente novamente.', 'wp-formvault' ),
			'error'        => __( 'Não foi possível registrar sua mensagem agora. Tente novamente em instantes.', 'wp-formvault' ),
			'disabled'     => __( 'Este formulário não está disponível no momento.', 'wp-formvault' ),
			'unknown_form' => __( 'Formulário não reconhecido.', 'wp-formvault' ),
		);
		$message  = isset( $messages[ $status ] ) ? $messages[ $status ] : $messages['error'];

		if ( wp_doing_ajax() ) {
			$data = array(
				'status'        => sanitize_key( $status ),
				'message'       => $message,
				'form_id'       => sanitize_key( $form_id ),
				'fields'        => array_values( array_map( 'sanitize_key', $errors ) ),
				'field_messages'=> array_map( 'sanitize_text_field', $field_messages ),
				'submission_id' => absint( $submission_id ),
			);

			if ( 'success' === $status ) {
				wp_send_json_success( $data );
			}

			$http_status = 'invalid' === $status ? 422 : ( 'unknown_form' === $status || 'disabled' === $status ? 400 : 500 );
			wp_send_json_error( $data, $http_status );
		}

		$referer = wp_get_referer();
		$target  = $referer ? $referer : home_url( '/' );
		$target  = preg_replace( '/#.*$/', '', $target );
		$target  = remove_query_arg( array( 'wpfv_status', 'wpfv_form' ), $target );
		$target  = add_query_arg( array( 'wpfv_status' => sanitize_key( $status ), 'wpfv_form' => sanitize_key( $form_id ) ), $target );
		if ( is_array( $form ) && ! empty( $form['redirect_fragment'] ) ) {
			$target .= '#' . $form['redirect_fragment'];
		}
		wp_safe_redirect( $target );
		exit;
	}
}
