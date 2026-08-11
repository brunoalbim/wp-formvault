<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WPFV_Brevo_API {
	private $key;
	public function __construct() { $s = WPFV_Settings::get_global(); $this->key = $s['brevo_api_key']; }
	public function configured() { return (bool) $this->key; }
	private function request( $method, $endpoint, $body = array() ) {
		if ( ! $this->configured() ) { return array( 'success' => false, 'code' => 0, 'error' => 'Brevo não configurado.' ); }
		$args = array( 'method' => $method, 'headers' => array( 'api-key' => $this->key, 'Content-Type' => 'application/json', 'Accept' => 'application/json' ), 'timeout' => 30 );
		if ( $body ) { $args['body'] = wp_json_encode( $body ); }
		$r = wp_safe_remote_request( 'https://api.brevo.com/v3' . $endpoint, $args );
		if ( is_wp_error( $r ) ) { return array( 'success' => false, 'code' => 0, 'error' => $r->get_error_message() ); }
		$code = (int) wp_remote_retrieve_response_code( $r ); $raw = wp_remote_retrieve_body( $r ); $data = $raw ? json_decode( $raw, true ) : null;
		return array( 'success' => $code >= 200 && $code < 300, 'code' => $code, 'data' => $data, 'error' => $code >= 200 && $code < 300 ? '' : ( isset( $data['message'] ) ? $data['message'] : 'HTTP ' . $code ) );
	}
	public function test() { return $this->request( 'GET', '/account' ); }
	public function contact( $email, $attributes, $list_ids ) { return $this->request( 'POST', '/contacts', array_filter( array( 'email' => $email, 'attributes' => $attributes, 'listIds' => array_map( 'absint', $list_ids ), 'updateEnabled' => true ) ) ); }
}
