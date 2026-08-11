<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WPFV_Brevo_Integration implements WPFV_Integration {
	public function deliver( $submission, $form, $settings ) {
		$api = new WPFV_Brevo_API(); $mapped = ( new WPFV_Field_Mapper() )->brevo( json_decode( $submission->fields, true ), $settings );
		if ( ! is_email( $mapped['email'] ) ) { return array( 'success' => false, 'code' => 0, 'error' => 'O mapeamento do Brevo precisa conter EMAIL.' ); }
		$r = $api->contact( $mapped['email'], $mapped['attributes'], $settings['brevo_list_id'] ? array( $settings['brevo_list_id'] ) : array() );
		return array( 'success' => $r['success'], 'code' => $r['code'], 'body' => $r['data'], 'error' => $r['error'] );
	}
}
