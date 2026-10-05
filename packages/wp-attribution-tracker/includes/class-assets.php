<?php
/**
 * Public tracker assets.
 *
 * @package WPAttributionTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAT_Assets {
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue the global attribution tracker.
	 *
	 * @return void
	 */
	public function enqueue() {
		wp_enqueue_script( 'wpat-attribution-classifier', WPAT_URL . 'public/js/attribution-classifier.js', array(), WPAT_VERSION, true );
		wp_enqueue_script( 'wpat-tracker', WPAT_URL . 'public/js/tracker.js', array( 'wpat-attribution-classifier' ), WPAT_VERSION, true );
		wp_localize_script(
			'wpat-tracker',
			'wpAttributionTracker',
			array(
				'cookieDays' => 90,
				'sources'    => $this->source_aliases(),
			)
		);
	}

	/**
	 * Return sanitized source aliases exposed to the browser classifier.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function source_aliases() {
		$path     = WPAT_DIR . 'public/data/attribution-sources.json';
		$contents = is_readable( $path ) ? file_get_contents( $path ) : '';
		$defaults = $contents ? json_decode( $contents, true ) : array();
		$defaults = is_array( $defaults ) ? $defaults : array();

		/**
		 * Filters source aliases used by the attribution classifier.
		 *
		 * Each canonical source contains a supported `family` and an `aliases`
		 * array. Aliases match either exactly or as a delimited prefix.
		 *
		 * @param array<string,array<string,mixed>> $defaults Source definitions.
		 */
		$definitions = apply_filters( 'wpat_attribution_source_aliases', $defaults );
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
