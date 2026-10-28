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
				'last_utm_source'         => 'Origem',
				'last_normalized_source'  => 'Origem normalizada',
				'last_utm_medium'         => 'Mídia',
				'last_utm_channel'        => 'Canal',
				'last_utm_campaign'       => 'Campanha',
				'last_utm_term'           => 'Termo',
				'last_utm_content'        => 'Conteúdo',
				'last_attribution_method' => 'Método de atribuição',
			),
			'Identificadores de anúncio:' => array(
				'gclid'    => 'Google Click ID',
				'gbraid'   => 'Google GBRAID',
				'wbraid'   => 'Google WBRAID',
				'msclkid'  => 'Microsoft Click ID',
				'ttclid'   => 'TikTok Click ID',
				'fbclid'   => 'Meta Click ID',
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
				if ( 'last_attribution_method' === $key ) {
					$value = $this->attribution_method_label( $value );
				}
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

		$files = array();
		$notes = array();
		$store = new WPFV_Attachments();
		$limit = (int) apply_filters( 'wpfv_email_attachments_max_bytes', 20 * MB_IN_BYTES, $submission, $form );
		$total = 0;
		foreach ( $store->for_submission( $submission->id ) as $attachment ) {
			$path = $store->path( $attachment );
			if ( ! $path ) {
				$notes[] = $this->labeled_line( $attachment->original_name, __( 'arquivo removido pela política de retenção', 'wp-formvault' ) );
			} elseif ( $total + (int) $attachment->size > $limit ) {
				$notes[] = $this->labeled_line( $attachment->original_name, __( 'não anexado por exceder o limite do e-mail; baixe no painel', 'wp-formvault' ) );
			} else {
				$name           = isset( $files[ $attachment->original_name ] ) ? $attachment->id . '-' . $attachment->original_name : $attachment->original_name;
				$files[ $name ] = $path;
				$total         += (int) $attachment->size;
			}
		}
		if ( $notes ) {
			$lines[] = '';
			$lines[] = '<strong>Anexos não incluídos:</strong>';
			$lines   = array_merge( $lines, $notes );
		}

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( is_email( $submission->primary_email ) ) {
			$headers[] = sprintf( 'Reply-To: %s <%s>', $submission->primary_name, $submission->primary_email );
		}

		$sent = wp_mail( $recipients, sanitize_text_field( $subject ), implode( "<br>\n", $lines ), $headers, $files );

		return array(
			'success' => (bool) $sent,
			'code'    => $sent ? 200 : 0,
			'body'    => array( 'recipients' => $recipients, 'attachments' => array_keys( $files ) ),
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

	private function attribution_method_label( $method ) {
		$labels = array(
			'utm'                => 'UTM',
			'click_id'           => 'Identificador de anúncio',
			'referrer_search'    => 'Referência de buscador',
			'referrer_social'    => 'Referência de rede social',
			'referral'           => 'Referência externa',
			'direct_no_referrer' => 'Direto — sem referência',
			'legacy_utm'         => 'UTM legada',
		);
		$method = sanitize_key( $method );
		return isset( $labels[ $method ] ) ? $labels[ $method ] : $method;
	}
}
