<?php
/**
 * Public attribution script.
 *
 * @package WPFormVault
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPFV_Assets {
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue() {
		$validation_forms = array();
		$recaptcha_forms  = array();
		$registry = WPFV_Plugin::instance()->registry();
		foreach ( $registry->all() as $form ) {
			$settings = WPFV_Settings::get_for_form( $form );
			$validation_forms[ $form['id'] ] = $settings['validation'];
			if ( ! empty( $settings['antispam']['recaptcha_enabled'] ) && WPFV_Recaptcha::configured() ) {
				$recaptcha_forms[ $form['id'] ] = array( 'action' => WPFV_Recaptcha::action_name( $form['id'] ) );
			}
		}

		$dependencies = array();
		$global       = WPFV_Settings::get_global();
		if ( ! empty( $recaptcha_forms ) ) {
			$recaptcha_url = add_query_arg( 'render', $global['recaptcha_site_key'], 'https://www.google.com/recaptcha/enterprise.js' );
			wp_enqueue_script( 'wpfv-google-recaptcha', $recaptcha_url, array(), null, true );
			$dependencies[] = 'wpfv-google-recaptcha';
		}

		wp_enqueue_script( 'wp-formvault-attribution', WPFV_URL . 'public/js/attribution.js', $dependencies, WPFV_VERSION, true );
		wp_localize_script(
			'wp-formvault-attribution',
			'wpFormVault',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'sendingLabel' => __( 'Enviando…', 'wp-formvault' ),
				'networkError' => __( 'Não foi possível enviar agora. Verifique sua conexão e tente novamente.', 'wp-formvault' ),
				'validations'  => $validation_forms,
				'recaptcha'    => array(
					'siteKey' => ! empty( $recaptcha_forms ) ? $global['recaptcha_site_key'] : '',
					'forms'   => $recaptcha_forms,
				),
			)
		);
		wp_enqueue_style( 'wp-formvault-forms', WPFV_URL . 'public/css/forms.css', array(), WPFV_VERSION );
	}
}
