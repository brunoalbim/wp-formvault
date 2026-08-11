<?php
/**
 * WordPress email integration.
 *
 * @package WPFormVault
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPFV_Email_Integration implements WPFV_Integration {
	/** {@inheritdoc} */
	public function deliver( $submission, $form, $settings ) {
		$recipients = array_filter( array_map( 'sanitize_email', preg_split( '/[,;\s]+/', $settings['email_recipients'] ) ) );
		if ( empty( $recipients ) ) {
			return array( 'success' => false, 'code' => 0, 'error' => 'Nenhum destinatário válido configurado.' );
		}

		$fields      = json_decode( $submission->fields, true );
		$attribution = json_decode( $submission->attribution, true );
		$attribution = is_array( $attribution ) ? $attribution : array();
		$subject = strtr(
			$settings['email_subject'],
			array(
				'{site_name}' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'{form_name}' => $submission->form_name,
				'{form_id}'   => $submission->form_id,
			)
		);
		$lines = array(
			$this->labeled_line( 'Formulário', $submission->form_name ),
			$this->labeled_line( 'Data', get_date_from_gmt( $submission->created_at, 'd/m/Y H:i:s' ) ),
			$this->labeled_line( 'Página', $submission->source_title ),
			$this->labeled_line( 'URL', $submission->source_url, true ),
			'',
			'<strong>Campos enviados:</strong>',
		);

		foreach ( $form['fields'] as $field_id => $definition ) {
			$value   = isset( $fields[ $field_id ] ) ? $fields[ $field_id ] : '';
			$value   = is_array( $value ) ? implode( ', ', $value ) : $value;
			$lines[] = $this->labeled_line( $definition['label'], $value );
		}

		$attribution_groups = array(
			'UTM:' => array(
				'last_utm_source'   => 'Origem',
				'last_utm_medium'   => 'Mídia',
				'last_utm_campaign' => 'Campanha',
				'last_utm_term'     => 'Termo',
				'last_utm_content'  => 'Conteúdo',
			),
			'Identificadores de anúncio:' => array(
				'gclid'  => 'Google Click ID',
				'fbclid' => 'Meta Click ID',
			),
			'Página de entrada:' => array(
				'landing_url'      => 'URL',
				'landing_referrer' => 'Referência',
			),
		);

		foreach ( $attribution_groups as $heading => $attributes ) {
			$group_lines = array();
			foreach ( $attributes as $key => $label ) {
				$value = isset( $attribution[ $key ] ) ? trim( (string) $attribution[ $key ] ) : '';
				if ( '' !== $value ) {
					$group_lines[] = $this->labeled_line( $label, $value, false !== strpos( $key, 'url' ) || 'landing_referrer' === $key );
				}
			}

			if ( $group_lines ) {
				$lines[] = '';
				$lines[] = '<strong>' . esc_html( $heading ) . '</strong>';
				$lines   = array_merge( $lines, $group_lines );
			}
		}

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( is_email( $submission->primary_email ) ) {
			$headers[] = sprintf( 'Reply-To: %s <%s>', $submission->primary_name, $submission->primary_email );
		}

		$sent = wp_mail( $recipients, sanitize_text_field( $subject ), implode( "<br>\n", $lines ), $headers );

		return array(
			'success' => (bool) $sent,
			'code'    => $sent ? 200 : 0,
			'body'    => array( 'recipients' => $recipients ),
			'error'   => $sent ? '' : 'wp_mail() não confirmou o envio.',
		);
	}

	/**
	 * Format one safe HTML label/value pair for the notification body.
	 *
	 * @param string $label Label shown in bold.
	 * @param mixed  $value Submitted value.
	 * @param bool   $is_url Whether the value should be rendered as a link.
	 * @return string
	 */
	private function labeled_line( $label, $value, $is_url = false ) {
		$label = esc_html( $label );
		$value = (string) $value;

		if ( $is_url && '' !== $value ) {
			$safe_url = esc_url( $value );
			$value    = $safe_url ? '<a href="' . esc_attr( $safe_url ) . '">' . esc_html( $value ) . '</a>' : esc_html( $value );
		} else {
			$value = nl2br( esc_html( $value ) );
		}

		return '<strong>' . $label . ':</strong> ' . $value;
	}
}
