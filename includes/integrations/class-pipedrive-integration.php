<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WPFV_Pipedrive_Integration implements WPFV_Integration {
	public function deliver( $submission, $form, $settings ) {
		$api = new WPFV_Pipedrive_API(); $mapper = new WPFV_Field_Mapper();
		if ( ! $api->configured() ) { return array( 'success' => false, 'code' => 0, 'error' => 'Credenciais do Pipedrive ausentes.' ); }
		$data = $mapper->pipedrive( json_decode( $submission->fields, true ), $settings );
		$ids = array( 'organization_id' => 0, 'person_id' => 0, 'deal_id' => 0 );
		if ( ! empty( $data['organization'] ) ) { $r = $api->create( 'organization', $data['organization'] ); if ( ! $r['success'] ) { return $this->result( $r, $ids ); } $ids['organization_id'] = $this->id( $r ); }
		if ( $ids['organization_id'] ) { $data['person']['org_id'] = $ids['organization_id']; }
		$r = $api->create( 'person', $data['person'] ); if ( ! $r['success'] ) { return $this->result( $r, $ids ); } $ids['person_id'] = $this->id( $r );
		if ( $ids['organization_id'] ) { $data['deal']['org_id'] = $ids['organization_id']; } if ( $ids['person_id'] ) { $data['deal']['person_id'] = $ids['person_id']; }
		$r = $api->create( 'deal', $data['deal'] ); if ( $r['success'] ) { $ids['deal_id'] = $this->id( $r ); }
		return $this->result( $r, $ids );
	}
	private function id( $r ) { return isset( $r['data']['data']['id'] ) ? absint( $r['data']['data']['id'] ) : 0; }
	private function result( $r, $ids ) { return array( 'success' => ! empty( $r['success'] ), 'code' => isset( $r['code'] ) ? $r['code'] : 0, 'body' => $ids, 'error' => isset( $r['error'] ) ? $r['error'] : '' ); }
}
