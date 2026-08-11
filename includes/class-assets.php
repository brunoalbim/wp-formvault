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
		$registry = WPFV_Plugin::instance()->registry();
		foreach ( $registry->all() as $form ) { $validation_forms[ $form['id'] ] = WPFV_Settings::get_for_form( $form )['validation']; }
		wp_enqueue_script( 'wp-formvault-attribution', WPFV_URL . 'public/js/attribution.js', array(), WPFV_VERSION, true );
		wp_localize_script(
			'wp-formvault-attribution',
			'wpFormVault',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'sendingLabel' => __( 'Enviando…', 'wp-formvault' ),
				'networkError' => __( 'Não foi possível enviar agora. Verifique sua conexão e tente novamente.', 'wp-formvault' ),
				'validations'  => $validation_forms,
			)
		);
		wp_enqueue_style( 'wp-formvault-forms', WPFV_URL . 'public/css/forms.css', array(), WPFV_VERSION );
	}
}
