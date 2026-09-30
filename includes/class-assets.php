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

		wp_enqueue_script( 'wp-formvault-attribution-classifier', WPFV_URL . 'public/js/attribution-classifier.js', array(), WPFV_VERSION, true );

		$dependencies = array( 'wp-formvault-attribution-classifier' );
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
				'attribution'  => array(
					'sources' => $this->attribution_source_aliases(),
				),
				'recaptcha'    => array(
					'siteKey' => ! empty( $recaptcha_forms ) ? $global['recaptcha_site_key'] : '',
					'forms'   => $recaptcha_forms,
				),
			)
		);
		wp_enqueue_style( 'wp-formvault-forms', WPFV_URL . 'public/css/forms.css', array(), WPFV_VERSION );
	}

	/**
	 * Return the sanitized source aliases exposed to the browser classifier.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function attribution_source_aliases() {
		$path     = WPFV_DIR . 'public/data/attribution-sources.json';
		$contents = is_readable( $path ) ? file_get_contents( $path ) : '';
		$defaults = $contents ? json_decode( $contents, true ) : array();
		$defaults = is_array( $defaults ) ? $defaults : array();

		/**
		 * Filters source aliases used by the browser attribution classifier.
		 *
		 * Each canonical source contains a supported `family` and an `aliases`
		 * array. Aliases match either exactly or as a delimited prefix.
		 *
		 * @param array<string,array<string,mixed>> $defaults Source definitions.
		 */
		$definitions = apply_filters( 'wpfv_attribution_source_aliases', $defaults );
		$definitions = is_array( $definitions ) ? $definitions : $defaults;
		$allowed      = array( 'search', 'social', 'email', 'referral' );
		$sanitized    = array();

		foreach ( $definitions as $canonical => $definition ) {
			$canonical = sanitize_key( $canonical );
			if ( ! $canonical || ! is_array( $definition ) ) {
				continue;
			}
			$family = isset( $definition['family'] ) ? sanitize_key( $definition['family'] ) : '';
			if ( ! in_array( $family, $allowed, true ) ) {
				continue;
			}
			$raw_aliases = isset( $definition['aliases'] ) && is_array( $definition['aliases'] ) ? $definition['aliases'] : array();
			$aliases     = array();
			foreach ( $raw_aliases as $alias ) {
				if ( ! is_scalar( $alias ) ) {
					continue;
				}
				$alias = substr( sanitize_text_field( $alias ), 0, 100 );
				if ( '' !== $alias ) {
					$aliases[] = $alias;
				}
			}
			$aliases = array_slice( array_values( array_unique( $aliases ) ), 0, 50 );
			if ( $aliases ) {
				$sanitized[ $canonical ] = array( 'family' => $family, 'aliases' => $aliases );
			}
		}

		return $sanitized;
	}
}
