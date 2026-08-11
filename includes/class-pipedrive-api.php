<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WPFV_Pipedrive_API {
	private $token; private $domain;
	public function __construct() { $s = WPFV_Settings::get_global(); $this->token = $s['pipedrive_token']; $this->domain = preg_replace( '#^https?://#', '', trim( $s['pipedrive_domain'], '/' ) ); }
	public function configured() { return (bool) ( $this->token && $this->domain ); }
	private function request( $method, $endpoint, $body = array(), $version = 'v2', $query = array() ) {
		if ( ! $this->configured() ) { return array( 'success' => false, 'code' => 0, 'error' => 'Pipedrive não configurado.' ); }
		$url = 'https://' . $this->domain . '/api/' . $version . $endpoint;
		if ( $query ) { $url = add_query_arg( $query, $url ); }
		$args = array( 'method' => $method, 'headers' => array( 'x-api-token' => $this->token, 'Content-Type' => 'application/json' ), 'timeout' => 30 );
		if ( $body ) { $args['body'] = wp_json_encode( $body ); }
		$r = wp_safe_remote_request( $url, $args );
		if ( is_wp_error( $r ) ) { return array( 'success' => false, 'code' => 0, 'error' => $r->get_error_message() ); }
		$code = (int) wp_remote_retrieve_response_code( $r ); $data = json_decode( wp_remote_retrieve_body( $r ), true );
		return array( 'success' => $code >= 200 && $code < 300, 'code' => $code, 'data' => $data, 'error' => $code >= 200 && $code < 300 ? '' : ( isset( $data['error'] ) ? $data['error'] : 'HTTP ' . $code ) );
	}
	public function test() { return $this->request( 'GET', '/users/me', array(), 'v1' ); }
	public function pipelines() {
		$result = $this->request( 'GET', '/pipelines', array(), 'v2', array( 'limit' => 500 ) );
		return $this->normalize_catalog( $result, 'id', 'name' );
	}
	public function stages( $pipeline ) {
		$result = $this->request( 'GET', '/stages', array(), 'v2', array( 'pipeline_id' => absint( $pipeline ), 'limit' => 500 ) );
		return $this->normalize_catalog( $result, 'id', 'name' );
	}
	public function fields( $entity ) {
		$allowed = array( 'person' => '/personFields', 'organization' => '/organizationFields', 'deal' => '/dealFields' );
		if ( ! isset( $allowed[ $entity ] ) ) {
			return array( 'success' => false, 'error' => 'Entidade inválida.' );
		}
		$result = $this->request( 'GET', $allowed[ $entity ], array(), 'v1', array( 'limit' => 500 ) );
		return $this->normalize_catalog( $result, 'key', 'name' );
	}
	public function create( $entity, $data ) { $allowed = array( 'person' => '/persons', 'organization' => '/organizations', 'deal' => '/deals' ); return isset( $allowed[ $entity ] ) ? $this->request( 'POST', $allowed[ $entity ], $data ) : array( 'success' => false, 'code' => 0, 'error' => 'Entidade inválida.' ); }

	private function normalize_catalog( $result, $value_key, $label_key ) {
		if ( empty( $result['success'] ) ) {
			return $result;
		}
		$items = isset( $result['data']['data'] ) && is_array( $result['data']['data'] ) ? $result['data']['data'] : array();
		$data  = array();
		foreach ( $items as $item ) {
			if ( ! isset( $item[ $value_key ], $item[ $label_key ] ) ) {
				continue;
			}
			$data[] = array( 'value' => (string) $item[ $value_key ], 'label' => sanitize_text_field( $item[ $label_key ] ) );
		}
		$result['data'] = $data;
		return $result;
	}
}
