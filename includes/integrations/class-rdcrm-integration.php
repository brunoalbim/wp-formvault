<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class WPFV_RDCRM_Integration implements WPFV_Integration {
	public function deliver($submission,$form,$settings){
		$api=new WPFV_RDCRM_API(); if(!$api->connected())return $this->result(false,0,array(),'RD CRM não conectado.');
		$data=(new WPFV_Field_Mapper())->rdcrm(json_decode($submission->fields,true),$settings); $email=isset($data['contact']['emails'][0]['email'])?$data['contact']['emails'][0]['email']:'';
		if(empty($data['contact']['name'])||!is_email($email))return $this->result(false,0,array(),'Mapeie nome e e-mail válidos para o contato do RD CRM.');
		if(empty($data['deal']['pipeline_id'])||empty($data['deal']['stage_id'])||empty($data['deal']['owner_id']))return $this->result(false,0,array(),'Configure funil, etapa e responsável do RD CRM.');
		$organization_id='';$organization_name=isset($data['organization']['name'])?trim($data['organization']['name']):'';
		if(''!==$organization_name){
			$data['organization']['name']=$organization_name;
			$organization=$this->sync_organization($api,$data['organization']);
			if(empty($organization['success']))return $this->from_api($organization['response'],array('operation'=>'organization'));
			$organization_id=$organization['id'];
			$data['contact']['organization_id']=$organization_id;
			$data['deal']['organization_id']=$organization_id;
		}
		$context=$organization_id?array('organization_id'=>$organization_id):array();
		$find=$api->find_contact($email); if(empty($find['success']))return $this->from_api($find,$context); $contact_id=$this->first_id($find);
		$contact=$contact_id?$api->update_contact($contact_id,$data['contact']):$api->create_contact($data['contact']); if(empty($contact['success']))return $this->from_api($contact,array_merge($context,array('contact_id'=>$contact_id))); if(!$contact_id)$contact_id=$this->id($contact);
		if(!$contact_id){$created=$api->find_contact($email);if(!empty($created['success']))$contact_id=$this->first_id($created);}
		if(!$contact_id)return $this->result(false,0,$context,'O RD CRM criou ou atualizou o contato, mas não retornou seu identificador. A negociação não foi criada para evitar um registro sem vínculo.');
		$data['deal']['contact_ids']=array((string)$contact_id);$deal=$api->create_deal($data['deal']);$deal_id=$this->id($deal);$ids=array('contact_id'=>(string)$contact_id,'deal_id'=>$deal_id,'contact_ids_sent'=>$data['deal']['contact_ids']);if($organization_id)$ids['organization_id']=$organization_id;
		if(empty($deal['success'])||!$deal_id)return $this->from_api($deal,$ids);
		$check=$api->get_deal($deal_id);$linked=$this->contact_ids($check);$ids['contact_ids_confirmed']=$linked;
		if(!in_array((string)$contact_id,$linked,true)){$patch=$api->update_deal($deal_id,array('contact_ids'=>array((string)$contact_id)));$ids['link_patch_code']=isset($patch['code'])?$patch['code']:0;if(empty($patch['success']))return $this->from_api($patch,$ids);$check=$api->get_deal($deal_id);$linked=$this->contact_ids($check);$ids['contact_ids_confirmed']=$linked;}
		if(!in_array((string)$contact_id,$linked,true))return $this->result(false,isset($check['code'])?$check['code']:0,$ids,'A negociação foi criada, mas o RD CRM não confirmou o vínculo com o contato. Consulte os dados de diagnóstico desta entrega.');
		return $this->from_api($deal,$ids);
	}
	private function sync_organization($api,$data){
		$name=$data['name'];$find=$api->find_organization($name);
		if(empty($find['success']))return array('success'=>false,'response'=>$find);
		$id=$this->exact_organization_id($find,$name);
		if($id){
			if(count($data)>1){$update=$api->update_organization($id,$data);if(empty($update['success']))return array('success'=>false,'response'=>$update);}
			return array('success'=>true,'id'=>$id);
		}
		$create=$api->create_organization($data);
		if(empty($create['success'])&&422!==(int)(isset($create['code'])?$create['code']:0))return array('success'=>false,'response'=>$create);
		$id=!empty($create['success'])?$this->id($create):'';
		if(!$id){
			$refind=$api->find_organization($name);
			if(empty($refind['success']))return array('success'=>false,'response'=>$refind);
			$id=$this->exact_organization_id($refind,$name);
			if($id&&empty($create['success'])&&count($data)>1){$update=$api->update_organization($id,$data);if(empty($update['success']))return array('success'=>false,'response'=>$update);}
		}
		if(!$id&&empty($create['success']))return array('success'=>false,'response'=>$create);
		if(!$id)return array('success'=>false,'response'=>$this->result(false,isset($create['code'])?$create['code']:0,array(),'O RD CRM não retornou o identificador da empresa criada ou existente. O contato e a negociação não foram enviados.'));
		return array('success'=>true,'id'=>$id);
	}
	private function exact_organization_id($response,$name){return $this->organization_id_in($this->payload($response),$name);}
	private function organization_id_in($data,$name){if(!is_array($data))return '';if(isset($data['name'],$data['id'])&&is_scalar($data['name'])&&is_scalar($data['id'])&&trim((string)$data['name'])===$name)return (string)$data['id'];foreach($data as $value){$id=$this->organization_id_in($value,$name);if($id)return $id;}return '';}
	private function payload($r){return isset($r['data']['data'])?$r['data']['data']:array();}
	private function id($r){return $this->nested_id($this->payload($r));}
	private function first_id($r){return $this->nested_id($this->payload($r));}
	private function nested_id($data){if(!is_array($data))return '';foreach(array('id','_id','contact_id','deal_id') as $key){if(isset($data[$key])&&is_scalar($data[$key])&&''!==(string)$data[$key])return (string)$data[$key];}foreach($data as $value){$id=$this->nested_id($value);if($id)return $id;}return '';}
	private function contact_ids($r){$data=$this->payload($r);if(isset($data['contact_ids'])&&is_array($data['contact_ids']))return array_values(array_map('strval',$data['contact_ids']));if(isset($data['contacts'])&&is_array($data['contacts'])){$ids=array();foreach($data['contacts'] as $contact){if(is_array($contact)&&isset($contact['id']))$ids[]=(string)$contact['id'];elseif(is_scalar($contact))$ids[]=(string)$contact;}return $ids;}return array();}
	private function from_api($r,$ids){return $this->result(!empty($r['success']),isset($r['code'])?$r['code']:0,$ids,isset($r['error'])?$r['error']:'');}
	private function result($success,$code,$body,$error){return array('success'=>$success,'code'=>$code,'body'=>$body,'error'=>$error);}
}
