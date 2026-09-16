<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WPFV_RDCRM_API {
	const API_BASE = 'https://api.rd.services/crm/v2/';
	const TOKEN_URL = 'https://api.rd.services/oauth2/token';
	const AUTH_URL = 'https://accounts.rdstation.com/oauth/authorize';
	const REFRESH_LOCK = 'wpfv_rdcrm_refresh_lock';
	const HEALTH_OPTION = 'wpfv_rdcrm_token_health';
	private $settings;

	public function __construct() { $this->settings = WPFV_Settings::get_global(); }
	public static function health() { return get_option( self::HEALTH_OPTION, array() ); }
	public static function clear_health() { delete_option( self::HEALTH_OPTION ); }
	public static function callback_url() { return admin_url( 'admin-post.php?action=wpfv_rdcrm_oauth_callback' ); }
	public function configured() { return ! empty( $this->settings['rdcrm_client_id'] ) && ! empty( $this->settings['rdcrm_client_secret'] ); }
	public function connected() { return $this->configured() && ( ! empty( $this->settings['rdcrm_refresh_token'] ) || ! empty( $this->settings['rdcrm_access_token'] ) ); }
	public function authorization_url( $state ) { return add_query_arg( array( 'response_type' => 'code', 'client_id' => $this->settings['rdcrm_client_id'], 'redirect_uri' => self::callback_url(), 'state' => $state ), self::AUTH_URL ); }
	public function exchange_code( $code ) { return $this->token_request( array( 'client_id' => $this->settings['rdcrm_client_id'], 'client_secret' => $this->settings['rdcrm_client_secret'], 'code' => $code, 'redirect_uri' => self::callback_url(), 'grant_type' => 'authorization_code' ), 'autorização' ); }
	public function disconnect() { $s = WPFV_Settings::get_global(); $s['rdcrm_access_token'] = ''; $s['rdcrm_refresh_token'] = ''; $s['rdcrm_token_expires_at'] = 0; delete_option( self::HEALTH_OPTION ); return WPFV_Settings::save_global( $s ); }
	public function refresh_if_needed() { return $this->token(); }
	private function record_health( $status, $message = '' ) { update_option( self::HEALTH_OPTION, array( 'status' => $status, 'message' => sanitize_text_field( $message ), 'time' => time() ), false ); }

	private function refresh() {
		if ( empty( $this->settings['rdcrm_refresh_token'] ) ) return array( 'success' => false, 'code' => 0, 'error' => 'RD CRM: refresh token ausente. Reconecte a integração.' );
		return $this->token_request( array( 'client_id' => $this->settings['rdcrm_client_id'], 'client_secret' => $this->settings['rdcrm_client_secret'], 'refresh_token' => $this->settings['rdcrm_refresh_token'], 'grant_type' => 'refresh_token' ), 'renovação do token' );
	}

	private function token_request( $body, $operation ) {
		$response = wp_remote_post( self::TOKEN_URL, array( 'timeout' => 20, 'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ), 'body' => $body ) );
		if ( is_wp_error( $response ) ) {
			$error = 'RD CRM: falha de rede na ' . $operation . ': ' . sanitize_text_field( $response->get_error_message() );
			$this->record_health( 'error', $error );
			return array( 'success' => false, 'code' => 0, 'error' => $error );
		}
		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || empty( $data['access_token'] ) ) {
			$message = $this->error( $data, 'Resposta sem token de acesso.' );
			$hint = 'renovação do token' === $operation ? ' Se o refresh token expirou ou foi revogado, reconecte a integração em Configurações.' : ' Confira Client ID, Client Secret e URL de callback.';
			$error = sprintf( 'RD CRM: falha na %s (HTTP %d): %s%s', $operation, $code, $message, $hint );
			$this->record_health( 'error', $error );
			return array( 'success' => false, 'code' => $code, 'error' => $error );
		}
		$settings = WPFV_Settings::get_global();
		$settings['rdcrm_access_token'] = sanitize_text_field( $data['access_token'] );
		if ( ! empty( $data['refresh_token'] ) ) $settings['rdcrm_refresh_token'] = sanitize_text_field( $data['refresh_token'] );
		$settings['rdcrm_token_expires_at'] = time() + max( 60, absint( isset( $data['expires_in'] ) ? $data['expires_in'] : 7200 ) );
		WPFV_Settings::save_global( $settings );
		$this->settings = $settings;
		$this->record_health( 'success' );
		return array( 'success' => true, 'code' => $code );
	}

	/** Serialize token rotation across concurrent catalog, test and delivery requests. */
	private function token( $force = false, $previous_token = '' ) {
		for ( $attempt = 0; $attempt < 30; $attempt++ ) {
			$this->settings = WPFV_Settings::get_global();
			$access = $this->settings['rdcrm_access_token'];
			if ( $access && ( ( ! $force && absint( $this->settings['rdcrm_token_expires_at'] ) > time() + 300 ) || ( $force && $access !== $previous_token ) ) ) {
				return array( 'success' => true, 'token' => $access );
			}
			if ( add_option( self::REFRESH_LOCK, time(), '', false ) ) {
				try {
					$this->settings = WPFV_Settings::get_global();
					$access = $this->settings['rdcrm_access_token'];
					if ( $access && ( ( ! $force && absint( $this->settings['rdcrm_token_expires_at'] ) > time() + 300 ) || ( $force && $access !== $previous_token ) ) ) {
						return array( 'success' => true, 'token' => $access );
					}
					$refresh = $this->refresh();
					return ! empty( $refresh['success'] ) ? array( 'success' => true, 'token' => $this->settings['rdcrm_access_token'] ) : $refresh;
				} finally {
					delete_option( self::REFRESH_LOCK );
				}
			}
			$locked_at = absint( get_option( self::REFRESH_LOCK, 0 ) );
			if ( $locked_at && $locked_at < time() - 30 ) delete_option( self::REFRESH_LOCK );
			usleep( 200000 );
		}
		return array( 'success' => false, 'code' => 0, 'error' => 'RD CRM: a renovação do token está em andamento. Tente novamente em alguns segundos.' );
	}

	public function request( $method, $path, $data = array(), $query = array(), $retry = true ) {
		$token = $this->token();
		if ( empty( $token['success'] ) ) return $token;
		$url = add_query_arg( $query, self::API_BASE . ltrim( $path, '/' ) );
		$args = array( 'method' => $method, 'timeout' => 20, 'headers' => array( 'Authorization' => 'Bearer ' . $token['token'], 'Content-Type' => 'application/json', 'Accept' => 'application/json' ) );
		if ( ! empty( $data ) ) $args['body'] = wp_json_encode( array( 'data' => $data ) );
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) return array( 'success' => false, 'code' => 0, 'error' => 'RD CRM: falha de rede em ' . $method . ' ' . $path . ': ' . sanitize_text_field( $response->get_error_message() ) );
		$code = wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 401 === $code && $retry ) {
			$renewed = $this->token( true, $token['token'] );
			return ! empty( $renewed['success'] ) ? $this->request( $method, $path, $data, $query, false ) : $renewed;
		}
		return array( 'success' => $code >= 200 && $code < 300, 'code' => $code, 'data' => is_array( $decoded ) ? $decoded : array(), 'error' => $code >= 200 && $code < 300 ? '' : sprintf( 'RD CRM: %s %s falhou (HTTP %d): %s', $method, $path, $code, $this->error( $decoded, 'Resposta sem detalhes.' ) ) );
	}

	private function error( $data, $fallback ) {
		if ( ! is_array( $data ) ) return $fallback;
		$parts = array();
		foreach ( array( 'error', 'error_description', 'error_message' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ) $parts[] = sanitize_text_field( (string) $data[ $key ] );
		}
		if ( ! empty( $data['errors'][0] ) && is_array( $data['errors'][0] ) ) {
			foreach ( array( 'title', 'detail', 'code' ) as $key ) {
				if ( isset( $data['errors'][0][ $key ] ) && is_scalar( $data['errors'][0][ $key ] ) ) $parts[] = sanitize_text_field( (string) $data['errors'][0][ $key ] );
			}
		}
		$parts = array_unique( array_filter( $parts ) );
		return $parts ? implode( ' — ', $parts ) : $fallback;
	}

	public function test() { return $this->request( 'GET', 'pipelines' ); }
	public function pipelines() { return $this->request( 'GET', 'pipelines' ); }
	public function stages( $id ) { return $this->request( 'GET', 'pipelines/' . rawurlencode( (string) $id ) . '/stages' ); }
	public function users() { return $this->request( 'GET', 'users' ); }
	public function custom_fields( $entity ) { return $this->request( 'GET', 'custom_fields', array(), array( 'filter' => 'entity:' . $entity ) ); }
	public function find_contact( $email ) { return $this->request( 'GET', 'contacts', array(), array( 'filter' => 'email:' . $email ) ); }
	public function create_contact( $data ) { return $this->request( 'POST', 'contacts', $data ); }
	public function update_contact( $id, $data ) { return $this->request( 'PUT', 'contacts/' . rawurlencode( (string) $id ), $data ); }
	public function create_deal( $data ) { return $this->request( 'POST', 'deals', $data ); }
	public function get_deal( $id ) { return $this->request( 'GET', 'deals/' . rawurlencode( (string) $id ) ); }
	public function update_deal( $id, $data ) { return $this->request( 'PATCH', 'deals/' . rawurlencode( (string) $id ), $data ); }
}
