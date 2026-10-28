<?php
/**
 * Form integration settings.
 *
 * @package WPFormVault
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPFV_Settings {
	const OPTION = 'wpfv_form_settings';
	const GLOBAL_OPTION = 'wpfv_global_settings';

	public static function global_defaults() {
		return array(
			'pipedrive_domain' => '', 'pipedrive_token' => '', 'brevo_api_key' => '',
			'rdcrm_client_id' => '', 'rdcrm_client_secret' => '', 'rdcrm_access_token' => '',
			'rdcrm_refresh_token' => '', 'rdcrm_token_expires_at' => 0,
			'recaptcha_project_id' => '', 'recaptcha_site_key' => '', 'recaptcha_api_key' => '',
			'logging_enabled' => true, 'log_retention_days' => 90,
			'attachment_retention_days' => 0,
		);
	}

	public static function get_global() {
		$saved = get_option( self::GLOBAL_OPTION, array() );
		return array_merge( self::global_defaults(), is_array( $saved ) ? $saved : array() );
	}

	public static function save_global( $settings ) {
		return update_option( self::GLOBAL_OPTION, array_merge( self::global_defaults(), $settings ), false );
	}

	public static function validation_defaults() {
		return array(
			'phone_validation' => false,
			'phone_message' => 'Telefone incompleto. Informe DDD + número.',
			'email_block_enabled' => false,
			'email_block_domains' => array( 'gmail.com', 'hotmail.com', 'yahoo.com', 'outlook.com', 'live.com', 'icloud.com', 'uol.com.br', 'terra.com.br', 'bol.com.br', 'ig.com.br' ),
			'email_block_suffixes' => array( '.ru', '.online', '.xyz' ),
			'email_block_words' => array( 'teste' ),
			'email_msg_domains' => 'E-mails de domínio público não são permitidos. Por favor, use um e-mail corporativo.',
			'email_msg_suffixes' => 'O domínio do seu e-mail não é permitido. Por favor, use um e-mail corporativo.',
			'email_msg_words' => 'O endereço de e-mail informado não é válido. Por favor, use um e-mail corporativo.',
		);
	}

	public static function antispam_defaults() {
		return array(
			'recaptcha_enabled' => false,
			'score_threshold'   => 0.5,
		);
	}

	/**
	 * Get effective settings for a form.
	 *
	 * @param array $form Registered form definition.
	 * @return array
	 */
	public static function get_for_form( $form ) {
		$all      = get_option( self::OPTION, array() );
		$form_id  = $form['id'];
		$defaults = array(
			'active'            => true,
			'email_enabled'     => true,
			'email_recipients'  => get_option( 'admin_email' ),
			'email_subject'     => '[{site_name}] Novo envio — {form_name}',
			'webhook_enabled'   => false,
			'webhook_url'       => '',
			'webhook_secret'    => '',
			'webhook_field_map' => array(),
			'pipedrive_enabled' => false,
			'pipedrive_pipeline_id' => 0,
			'pipedrive_stage_id' => 0,
			'pipedrive_deal_title' => 'Lead: {name}',
			'pipedrive_field_map' => array(),
			'brevo_enabled' => false,
			'brevo_list_id' => 0,
			'brevo_field_map' => array(),
			'rdcrm_enabled' => false,
			'rdcrm_pipeline_id' => '',
			'rdcrm_stage_id' => '',
			'rdcrm_owner_id' => '',
			'rdcrm_deal_title' => 'Lead: {name}',
			'rdcrm_note_template' => '',
			'rdcrm_field_map' => array(),
			'validation' => self::validation_defaults(),
			'antispam' => self::antispam_defaults(),
		);

		if ( ! empty( $form['defaults'] ) ) {
			$defaults = array_merge( $defaults, $form['defaults'] );
		}

		$saved = isset( $all[ $form_id ] ) && is_array( $all[ $form_id ] ) ? $all[ $form_id ] : array();
		if ( isset( $saved['validation']['email_block_words'] ) && array( 'teste', 'malware', 'phish' ) === $saved['validation']['email_block_words'] ) {
			$saved['validation']['email_block_words'] = self::validation_defaults()['email_block_words'];
		}

		$settings = array_merge( $defaults, $saved );
		$settings['validation'] = array_merge( self::validation_defaults(), isset( $saved['validation'] ) && is_array( $saved['validation'] ) ? $saved['validation'] : array() );
		$settings['antispam'] = array_merge( self::antispam_defaults(), isset( $defaults['antispam'] ) && is_array( $defaults['antispam'] ) ? $defaults['antispam'] : array(), isset( $saved['antispam'] ) && is_array( $saved['antispam'] ) ? $saved['antispam'] : array() );
		return $settings;
	}

	/**
	 * Save one form's integration settings.
	 *
	 * @param string $form_id Form identifier.
	 * @param array  $settings Sanitized settings.
	 * @return bool
	 */
	public static function save_for_form( $form_id, $settings ) {
		$all             = get_option( self::OPTION, array() );
		$all[ $form_id ] = $settings;

		return update_option( self::OPTION, $all, false );
	}
}
