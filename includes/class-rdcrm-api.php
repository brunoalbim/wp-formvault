<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WPFV_RDCRM_API {
	const API_BASE = 'https://api.rd.services/crm/v2/';
	const TOKEN_URL = 'https://api.rd.services/oauth2/token';
	const AUTH_URL = 'https://accounts.rdstation.com/oauth/authorize';
	private $settings;

	public function __construct() { $this->settings = WPFV_Settings::get_global(); }
	public static function callback_url() { return admin_url( 'admin-post.php?action=wpfv_rdcrm_oauth_callback' ); }
	public function configured() { return ! empty( $this->settings['rdcrm_client_id'] ) && ! empty( $this->settings['rdcrm_client_secret'] ); }
	public function connected() { return $this->configured() && ( ! empty( $this->settings['rdcrm_refresh_token'] ) || ! empty( $this->settings['rdcrm_access_token'] ) ); }
	public function authorization_url( $state ) { return add_query_arg( array( 'response_type' => 'code', 'client_id' => $this->settings['rdcrm_client_id'], 'redirect_uri' => self::callback_url(), 'state' => $state ), self::AUTH_URL ); }
	public function exchange_code( $code ) { return $this->token_request( array( 'client_id'=>$this->settings['rdcrm_client_id'], 'client_secret'=>$this->settings['rdcrm_client_secret'], 'code'=>$code, 'redirect_uri'=>self::callback_url(), 'grant_type'=>'authorization_code' ) ); }
	public function disconnect() { $s = WPFV_Settings::get_global(); $s['rdcrm_access_token']=''; $s['rdcrm_refresh_token']=''; $s['rdcrm_token_expires_at']=0; return WPFV_Settings::save_global($s); }
	private function refresh() { if ( empty( $this->settings['rdcrm_refresh_token'] ) ) return array('success'=>false,'error'=>'Refresh token do RD CRM ausente.'); return $this->token_request(array('client_id'=>$this->settings['rdcrm_client_id'],'client_secret'=>$this->settings['rdcrm_client_secret'],'refresh_token'=>$this->settings['rdcrm_refresh_token'],'grant_type'=>'refresh_token')); }
	private function token_request( $body ) {
		$r=wp_remote_post(self::TOKEN_URL,array('timeout'=>20,'headers'=>array('Content-Type'=>'application/x-www-form-urlencoded'),'body'=>$body));
		if(is_wp_error($r)) return array('success'=>false,'code'=>0,'error'=>$r->get_error_message());
		$code=wp_remote_retrieve_response_code($r); $data=json_decode(wp_remote_retrieve_body($r),true);
		if($code<200||$code>=300||empty($data['access_token'])) return array('success'=>false,'code'=>$code,'error'=>$this->error($data,'Falha na autenticação do RD CRM.'));
		$s=WPFV_Settings::get_global(); $s['rdcrm_access_token']=sanitize_text_field($data['access_token']); if(!empty($data['refresh_token']))$s['rdcrm_refresh_token']=sanitize_text_field($data['refresh_token']); $s['rdcrm_token_expires_at']=time()+max(60,absint(isset($data['expires_in'])?$data['expires_in']:7200)); WPFV_Settings::save_global($s); $this->settings=$s;
		return array('success'=>true,'code'=>$code,'data'=>$data);
	}
	private function token() { if(empty($this->settings['rdcrm_access_token']) || absint($this->settings['rdcrm_token_expires_at']) < time()+300){$r=$this->refresh();if(empty($r['success']))return $r;} return array('success'=>true,'token'=>$this->settings['rdcrm_access_token']); }
	public function request($method,$path,$data=array(),$query=array(),$retry=true){
		$t=$this->token(); if(empty($t['success']))return $t; $url=add_query_arg($query,self::API_BASE.ltrim($path,'/')); $args=array('method'=>$method,'timeout'=>20,'headers'=>array('Authorization'=>'Bearer '.$t['token'],'Content-Type'=>'application/json','Accept'=>'application/json'));
		if(!empty($data))$args['body']=wp_json_encode(array('data'=>$data)); $r=wp_remote_request($url,$args); if(is_wp_error($r))return array('success'=>false,'code'=>0,'error'=>$r->get_error_message()); $code=wp_remote_retrieve_response_code($r); $decoded=json_decode(wp_remote_retrieve_body($r),true);
		if(401===$code&&$retry){$rr=$this->refresh();if(!empty($rr['success']))return $this->request($method,$path,$data,$query,false);}
		return array('success'=>$code>=200&&$code<300,'code'=>$code,'data'=>is_array($decoded)?$decoded:array(),'error'=>$code>=200&&$code<300?'':$this->error($decoded,'Falha na API do RD CRM.'));
	}
	private function error($data,$fallback){ if(isset($data['errors'][0]['detail']))return sanitize_text_field($data['errors'][0]['detail']); if(isset($data['error']))return sanitize_text_field(is_array($data['error'])?wp_json_encode($data['error']):$data['error']); return $fallback; }
	public function test(){return $this->request('GET','pipelines');}
	public function pipelines(){return $this->request('GET','pipelines');}
	public function stages($id){return $this->request('GET','pipelines/'.rawurlencode((string)$id).'/stages');}
	public function users(){return $this->request('GET','users');}
	public function custom_fields($entity){return $this->request('GET','custom_fields',array(),array('filter'=>'entity:'.$entity));}
	public function find_contact($email){return $this->request('GET','contacts',array(),array('filter'=>'email:'.$email));}
	public function create_contact($data){return $this->request('POST','contacts',$data);}
	public function update_contact($id,$data){return $this->request('PUT','contacts/'.rawurlencode((string)$id),$data);}
	public function create_deal($data){return $this->request('POST','deals',$data);}
	public function get_deal($id){return $this->request('GET','deals/'.rawurlencode((string)$id));}
	public function update_deal($id,$data){return $this->request('PATCH','deals/'.rawurlencode((string)$id),$data);}
}
