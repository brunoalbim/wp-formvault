<?php
/**
 * Elementor Pro forms adapter.
 *
 * @package WPAttributionTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAT_Elementor_Adapter {
	/**
	 * Fields accepted from the browser envelope and their labels.
	 *
	 * @var array<string,string>
	 */
	private $labels = array(
		'first_utm_source'              => 'Primeira origem',
		'first_normalized_source'       => 'Primeira origem normalizada',
		'first_utm_medium'              => 'Primeira mídia',
		'first_utm_channel'             => 'Primeiro canal',
		'first_utm_campaign'            => 'Primeira campanha',
		'first_utm_term'                => 'Primeiro termo',
		'first_utm_content'             => 'Primeiro conteúdo',
		'first_attribution_method'      => 'Primeiro método de atribuição',
		'last_utm_source'               => 'Última origem não direta',
		'last_normalized_source'        => 'Última origem normalizada',
		'last_utm_medium'               => 'Última mídia',
		'last_utm_channel'              => 'Último canal',
		'last_utm_campaign'             => 'Última campanha',
		'last_utm_term'                 => 'Último termo',
		'last_utm_content'              => 'Último conteúdo',
		'last_attribution_method'       => 'Último método de atribuição',
		'utm_source'                    => 'UTM Source',
		'normalized_source'             => 'Origem normalizada',
		'utm_medium'                    => 'UTM Medium',
		'utm_channel'                   => 'Canal',
		'utm_campaign'                  => 'UTM Campaign',
		'utm_term'                      => 'UTM Term',
		'utm_content'                   => 'UTM Content',
		'attribution_method'            => 'Método de atribuição',
		'gclid'                         => 'Google Click ID',
		'gbraid'                        => 'Google GBRAID',
		'wbraid'                        => 'Google WBRAID',
		'msclkid'                       => 'Microsoft Click ID',
		'ttclid'                        => 'TikTok Click ID',
		'fbclid'                        => 'Meta Click ID',
		'landing_url'                   => 'URL da primeira entrada',
		'landing_referrer'              => 'Referenciador da primeira entrada',
		'submission_url'                => 'URL de envio',
		'submission_title'              => 'Título da página de envio',
	);

	public function __construct() {
		add_action( 'elementor_pro/forms/process', array( $this, 'process' ), 20, 2 );
	}

	/**
	 * Convert the validated browser envelope into Elementor record fields before
	 * email, webhook and submissions actions execute.
	 *
	 * @param object $record       Elementor form record.
	 * @param object $ajax_handler Elementor AJAX handler.
	 * @return void
	 */
	public function process( $record, $ajax_handler ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$values = $this->posted_values();
		if ( ! $values || ! is_object( $record ) || ! method_exists( $record, 'get' ) ) {
			return;
		}

		$fields = $record->get( 'fields' );
		$fields = is_array( $fields ) ? $fields : array();

		foreach ( $values as $field_id => $value ) {
			if ( isset( $fields[ $field_id ] ) && method_exists( $record, 'update_field' ) ) {
				$record->update_field( $field_id, 'value', $value );
				$record->update_field( $field_id, 'raw_value', $value );
				$fields[ $field_id ]['value']     = $value;
				$fields[ $field_id ]['raw_value'] = $value;
				continue;
			}

			$fields[ $field_id ] = array(
				'id'        => $field_id,
				'type'      => 'hidden',
				'title'     => $this->labels[ $field_id ],
				'value'     => $value,
				'raw_value' => $value,
				'required'  => false,
			);
		}

		if ( method_exists( $record, 'set' ) ) {
			$record->set( 'fields', $fields );
		}
	}

	/**
	 * Read, flatten and sanitize the posted attribution envelope.
	 *
	 * @return array<string,string>
	 */
	private function posted_values() {
		if ( empty( $_POST['wpat_attribution'] ) || ! is_scalar( $_POST['wpat_attribution'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return array();
		}

		$raw  = wp_unslash( $_POST['wpat_attribution'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$data = json_decode( (string) $raw, true );
		if ( ! is_array( $data ) ) {
			return array();
		}

		$flat       = array();
		$attributes = array( 'utm_source', 'normalized_source', 'utm_medium', 'utm_channel', 'utm_campaign', 'utm_term', 'utm_content', 'attribution_method' );
		$click_ids  = array( 'gclid', 'gbraid', 'wbraid', 'msclkid', 'ttclid', 'fbclid' );
		$first      = isset( $data['first'] ) && is_array( $data['first'] ) ? $data['first'] : array();
		$last       = isset( $data['last'] ) && is_array( $data['last'] ) ? $data['last'] : array();

		foreach ( $attributes as $key ) {
			if ( isset( $first[ $key ] ) && is_scalar( $first[ $key ] ) ) {
				$flat[ 'first_' . $key ] = $first[ $key ];
			}
			if ( isset( $last[ $key ] ) && is_scalar( $last[ $key ] ) ) {
				$flat[ 'last_' . $key ] = $last[ $key ];
				$flat[ $key ]           = $last[ $key ];
			}
		}

		foreach ( $click_ids as $key ) {
			if ( isset( $last[ $key ] ) && is_scalar( $last[ $key ] ) ) {
				$flat[ $key ] = $last[ $key ];
			} elseif ( isset( $first[ $key ] ) && is_scalar( $first[ $key ] ) ) {
				$flat[ $key ] = $first[ $key ];
			}
		}

		foreach ( array( 'landing_url', 'landing_referrer', 'submission_url', 'submission_title' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ) {
				$flat[ $key ] = $data[ $key ];
			}
		}

		$sanitized = array();
		foreach ( $flat as $key => $value ) {
			if ( ! isset( $this->labels[ $key ] ) ) {
				continue;
			}
			if ( in_array( $key, array( 'landing_url', 'landing_referrer', 'submission_url' ), true ) ) {
				$value = substr( esc_url_raw( (string) $value ), 0, 2048 );
			} else {
				$value = substr( sanitize_text_field( (string) $value ), 0, 1000 );
			}
			if ( '' !== $value ) {
				$sanitized[ $key ] = $value;
			}
		}

		return $sanitized;
	}
}
