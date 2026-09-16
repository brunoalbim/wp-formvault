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
		$original = is_array( $fields ) ? $fields : array();
		$renames  = array();
		$targets  = array();
		foreach ( (array) $settings['webhook_field_map'] as $row ) {
			$source = isset( $row['source'] ) && is_scalar( $row['source'] ) ? sanitize_key( $row['source'] ) : '';
			$target = isset( $row['target'] ) && is_scalar( $row['target'] ) ? trim( sanitize_text_field( $row['target'] ) ) : '';
			if ( ! $source || ! $target || ! array_key_exists( $source, $original ) || isset( $renames[ $source ] ) || isset( $targets[ $target ] ) ) {
				continue;
			}
			$renames[ $source ] = $target;
			$targets[ $target ] = true;
		}

		$mapped = $original;
		foreach ( $renames as $source => $target ) {
			if ( $target !== $source && array_key_exists( $target, $original ) && ! isset( $renames[ $target ] ) ) {
				unset( $renames[ $source ] );
				continue;
			}
			if ( $target !== $source ) {
				unset( $mapped[ $source ] );
			}
		}
		foreach ( $renames as $source => $target ) {
			$mapped[ $target ] = $original[ $source ];
		}
		return $mapped;
	}

	public function rdcrm( $fields, $settings ) {
		$result = array( 'contact' => array( 'custom_fields' => array() ), 'organization' => array( 'custom_fields' => array() ), 'deal' => array( 'custom_fields' => array() ) );
		foreach ( (array) $settings['rdcrm_field_map'] as $row ) {
			$source = isset( $row['source'] ) ? sanitize_key( $row['source'] ) : '';
			$entity = isset( $row['entity'] ) ? sanitize_key( $row['entity'] ) : '';
			$target = isset( $row['target'] ) ? sanitize_text_field( $row['target'] ) : '';
			if ( ! $source || ! isset( $fields[ $source ] ) || ! isset( $result[ $entity ] ) || ! $target || '' === $fields[ $source ] ) { continue; }
			$value = is_array( $fields[ $source ] ) ? implode( ', ', $fields[ $source ] ) : sanitize_text_field( $fields[ $source ] );
			if ( 'contact' === $entity && 'email' === $target ) { $result['contact']['emails'] = array( array( 'email' => sanitize_email( $value ) ) ); }
			elseif ( 'contact' === $entity && 'phone' === $target ) { $result['contact']['phones'] = array( array( 'phone' => $value, 'type' => 'work' ) ); }
			elseif ( 'organization' === $entity && 'address.line' === $target ) { $result['organization']['address']['line'] = $value; }
			elseif ( 0 === strpos( $target, 'custom:' ) ) { $result[ $entity ]['custom_fields'][ substr( $target, 7 ) ] = $value; }
			else { $result[ $entity ][ $target ] = $value; }
		}
		$result['deal']['name'] = $this->template( $settings['rdcrm_deal_title'], $fields );
		$result['deal']['status'] = 'ongoing';
		$result['deal']['pipeline_id'] = (string) $settings['rdcrm_pipeline_id'];
		$result['deal']['stage_id'] = (string) $settings['rdcrm_stage_id'];
		$result['deal']['owner_id'] = (string) $settings['rdcrm_owner_id'];
		if ( empty( $result['contact']['custom_fields'] ) ) { unset( $result['contact']['custom_fields'] ); }
		if ( empty( $result['organization']['custom_fields'] ) ) { unset( $result['organization']['custom_fields'] ); }
		if ( empty( $result['deal']['custom_fields'] ) ) { unset( $result['deal']['custom_fields'] ); }
		return $result;
	}

	private function template( $template, $fields ) {
		return preg_replace_callback( '/\{([^}]+)\}/', function( $m ) use ( $fields ) { return isset( $fields[ $m[1] ] ) ? sanitize_text_field( $fields[ $m[1] ] ) : $m[0]; }, sanitize_text_field( $template ) );
	}

	private function international_phone( $value ) {
		$digits = preg_replace( '/\D+/', '', $value );
		return ( 10 === strlen( $digits ) || 11 === strlen( $digits ) ) ? '+55' . $digits : ( $digits ? '+' . $digits : '' );
	}
}
