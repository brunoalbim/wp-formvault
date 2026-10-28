<?php
/**
 * Registry for forms declared by themes and plugins.
 *
 * @package WPFormVault
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPFV_Form_Registry {
	/** @var array|null */
	private $forms = null;

	/**
	 * Return every registered and normalized form.
	 *
	 * @return array
	 */
	public function all() {
		if ( null !== $this->forms ) {
			return $this->forms;
		}

		/**
		 * Register code-based forms with WP FormVault.
		 *
		 * @param array $forms Forms keyed by a stable form identifier.
		 */
		$forms       = apply_filters( 'wpfv_registered_forms', array() );
		$this->forms = array();

		if ( ! is_array( $forms ) ) {
			return $this->forms;
		}

		foreach ( $forms as $form_id => $form ) {
			$form_id = sanitize_key( $form_id );
			if ( '' === $form_id || ! is_array( $form ) ) {
				continue;
			}

			$fields = isset( $form['fields'] ) && is_array( $form['fields'] ) ? $form['fields'] : array();
			$this->forms[ $form_id ] = array(
				'id'                => $form_id,
				'name'              => isset( $form['name'] ) ? sanitize_text_field( $form['name'] ) : $form_id,
				'description'       => isset( $form['description'] ) ? sanitize_text_field( $form['description'] ) : '',
				'fields'            => $this->normalize_fields( $fields ),
				'defaults'          => isset( $form['defaults'] ) && is_array( $form['defaults'] ) ? $form['defaults'] : array(),
				'redirect_fragment' => isset( $form['redirect_fragment'] ) ? sanitize_title( $form['redirect_fragment'] ) : '',
			);
		}

		return $this->forms;
	}

	/**
	 * Get one registered form.
	 *
	 * @param string $form_id Form identifier.
	 * @return array|null
	 */
	public function get( $form_id ) {
		$forms   = $this->all();
		$form_id = sanitize_key( $form_id );

		return isset( $forms[ $form_id ] ) ? $forms[ $form_id ] : null;
	}

	/**
	 * Normalize field definitions.
	 *
	 * @param array $fields Field definitions.
	 * @return array
	 */
	private function normalize_fields( $fields ) {
		$normalized = array();
		$types      = array( 'text', 'textarea', 'email', 'tel', 'url', 'integer', 'checkbox', 'select', 'hidden', 'file' );

		foreach ( $fields as $field_id => $field ) {
			$field_id = sanitize_key( $field_id );
			if ( '' === $field_id || ! is_array( $field ) ) {
				continue;
			}

			$type = isset( $field['type'] ) ? sanitize_key( $field['type'] ) : 'text';
			if ( ! in_array( $type, $types, true ) ) {
				$type = 'text';
			}

			$normalized[ $field_id ] = array(
				'label'    => isset( $field['label'] ) ? sanitize_text_field( $field['label'] ) : $field_id,
				'type'     => $type,
				'required' => ! empty( $field['required'] ),
				'options'  => isset( $field['options'] ) && is_array( $field['options'] ) ? array_map( 'sanitize_text_field', $field['options'] ) : array(),
			);
			if ( 'file' === $type ) {
				$normalized[ $field_id ]['accept']   = WPFV_Attachments::normalize_extensions( isset( $field['accept'] ) ? $field['accept'] : array() );
				$normalized[ $field_id ]['max_size'] = isset( $field['max_size'] ) && is_numeric( $field['max_size'] ) && $field['max_size'] > 0 ? (float) $field['max_size'] : WPFV_Attachments::DEFAULT_MAX_SIZE_MB;
			}
		}

		return $normalized;
	}
}
