<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WPFV_Field_Mapper {
	public function pipedrive( $fields, $settings ) {
		$result = array( 'person' => array(), 'organization' => array(), 'deal' => array() );
		foreach ( (array) $settings['pipedrive_field_map'] as $row ) {
			$source = isset( $row['source'] ) ? sanitize_key( $row['source'] ) : '';
			$entity = isset( $row['entity'] ) ? sanitize_key( $row['entity'] ) : '';
			$target = isset( $row['target'] ) ? sanitize_text_field( $row['target'] ) : '';
			if ( ! $source || ! isset( $fields[ $source ] ) || ! isset( $result[ $entity ] ) || ! $target || '' === $fields[ $source ] ) { continue; }
			$value = is_array( $fields[ $source ] ) ? implode( ', ', $fields[ $source ] ) : $fields[ $source ];
			if ( 'email' === $target ) { $result[ $entity ]['emails'] = array( array( 'value' => sanitize_email( $value ), 'primary' => true, 'label' => 'work' ) ); }
			elseif ( 'phone' === $target ) { $result[ $entity ]['phones'] = array( array( 'value' => sanitize_text_field( $value ), 'primary' => true, 'label' => 'work' ) ); }
			elseif ( preg_match( '/^[0-9a-f]{40}$/', $target ) ) { $result[ $entity ]['custom_fields'][ $target ] = sanitize_text_field( $value ); }
			else { $result[ $entity ][ $target ] = sanitize_text_field( $value ); }
		}
		$result['deal']['title'] = $this->template( $settings['pipedrive_deal_title'], $fields );
		$result['deal']['pipeline_id'] = absint( $settings['pipedrive_pipeline_id'] );
		$result['deal']['stage_id'] = absint( $settings['pipedrive_stage_id'] );
		return $result;
	}

	public function brevo( $fields, $settings ) {
		$data = array( 'email' => '', 'attributes' => array() );
		foreach ( (array) $settings['brevo_field_map'] as $row ) {
			$source = isset( $row['source'] ) ? sanitize_key( $row['source'] ) : '';
			$target = isset( $row['target'] ) ? strtoupper( sanitize_key( $row['target'] ) ) : '';
			if ( ! $source || ! $target || ! isset( $fields[ $source ] ) || '' === $fields[ $source ] ) { continue; }
			$value = is_array( $fields[ $source ] ) ? implode( ', ', $fields[ $source ] ) : sanitize_text_field( $fields[ $source ] );
			if ( 'EMAIL' === $target ) { $data['email'] = sanitize_email( $value ); }
			else { $data['attributes'][ $target ] = in_array( $target, array( 'SMS', 'LANDLINE_NUMBER' ), true ) ? $this->international_phone( $value ) : $value; }
		}
		return $data;
	}

	public function webhook_fields( $fields, $settings ) {
		$mapped = $fields;
		foreach ( (array) $settings['webhook_field_map'] as $row ) {
			$source = isset( $row['source'] ) ? sanitize_key( $row['source'] ) : '';
			$target = isset( $row['target'] ) ? sanitize_key( $row['target'] ) : '';
			if ( $source && $target && array_key_exists( $source, $mapped ) ) { $mapped[ $target ] = $mapped[ $source ]; if ( $target !== $source ) { unset( $mapped[ $source ] ); } }
		}
		return $mapped;
	}

	private function template( $template, $fields ) {
		return preg_replace_callback( '/\{([^}]+)\}/', function( $m ) use ( $fields ) { return isset( $fields[ $m[1] ] ) ? sanitize_text_field( $fields[ $m[1] ] ) : $m[0]; }, sanitize_text_field( $template ) );
	}

	private function international_phone( $value ) {
		$digits = preg_replace( '/\D+/', '', $value );
		return ( 10 === strlen( $digits ) || 11 === strlen( $digits ) ) ? '+55' . $digits : ( $digits ? '+' . $digits : '' );
	}
}
