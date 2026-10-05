<?php
/**
 * Minimal dependency-free test for the Elementor adapter.
 */

define( 'ABSPATH', __DIR__ );

function add_action() {}
function wp_unslash( $value ) { return stripslashes( $value ); }
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function esc_url_raw( $value ) { return filter_var( $value, FILTER_SANITIZE_URL ); }

require_once dirname( __DIR__ ) . '/includes/class-elementor-adapter.php';

class WPAT_Test_Record {
	public $fields;
	public function __construct() {
		$this->fields = array(
			'utm_source' => array( 'id' => 'utm_source', 'value' => '' ),
		);
	}
	public function get( $key ) { return 'fields' === $key ? $this->fields : null; }
	public function set( $key, $value ) { if ( 'fields' === $key ) { $this->fields = $value; } }
	public function update_field( $id, $key, $value ) { $this->fields[ $id ][ $key ] = $value; }
}

function wpat_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}

$_POST['wpat_attribution'] = wp_slash_for_test(
	json_encode(
		array(
			'first'            => array(
				'utm_source'         => 'ig',
				'normalized_source'  => 'instagram',
				'utm_medium'         => 'social',
				'utm_channel'        => 'Social',
				'attribution_method' => 'utm',
			),
			'last'             => array(
				'utm_source'         => 'google',
				'normalized_source'  => 'google',
				'utm_medium'         => 'organic',
				'utm_channel'        => 'Organic Search',
				'attribution_method' => 'referrer_search',
			),
			'landing_url'      => 'https://example.test/entrada',
			'submission_url'   => 'https://example.test/contato',
			'submission_title' => '<b>Contato</b>',
		)
	)
);

$record  = new WPAT_Test_Record();
$adapter = new WPAT_Elementor_Adapter();
$adapter->process( $record, null );

wpat_assert_same( 'google', $record->fields['utm_source']['value'], 'Configured fields must be updated.' );
wpat_assert_same( 'ig', $record->fields['first_utm_source']['value'], 'First attribution must be added.' );
wpat_assert_same( 'Organic Search', $record->fields['last_utm_channel']['value'], 'Last channel must be added.' );
wpat_assert_same( 'https://example.test/contato', $record->fields['submission_url']['value'], 'Submission URL must be preserved.' );
wpat_assert_same( 'Contato', $record->fields['submission_title']['value'], 'Text values must be sanitized.' );

echo "Elementor adapter test passed.\n";

function wp_slash_for_test( $value ) {
	return addslashes( $value );
}
