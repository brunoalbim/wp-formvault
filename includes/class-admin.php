<?php
/**
 * Administrative screens.
 *
 * @package WPFormVault
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPFV_Admin {
	private $registry;
	private $repository;
	private $manager;

	public function __construct( $registry, $repository, $manager ) {
		$this->registry   = $registry;
		$this->repository = $repository;
		$this->manager    = $manager;
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_wpfv_save_form_settings', array( $this, 'save_form_settings' ) );
		add_action( 'admin_post_wpfv_save_global_settings', array( $this, 'save_global_settings' ) );
		add_action( 'admin_post_wpfv_clear_logs', array( $this, 'clear_logs' ) );
		add_action( 'wp_ajax_wpfv_test_pipedrive', array( $this, 'test_pipedrive' ) );
		add_action( 'wp_ajax_wpfv_test_brevo', array( $this, 'test_brevo' ) );
		add_action( 'wp_ajax_wpfv_pipedrive_catalog', array( $this, 'pipedrive_catalog' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_wpfv_retry_delivery', array( $this, 'retry_delivery' ) );
	}

	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, 'wpfv' ) ) {
			return;
		}
		wp_enqueue_script( 'wpfv-admin', WPFV_URL . 'admin/js/admin.js', array(), WPFV_VERSION, true );
		wp_enqueue_style( 'wpfv-admin', WPFV_URL . 'admin/css/admin.css', array(), WPFV_VERSION );
		wp_localize_script(
			'wpfv-admin',
			'wpfvAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wpfv_admin' ),
			)
		);
	}

	public function menu() {
		add_menu_page( 'Contatos', 'Contatos', 'manage_options', 'wpfv-submissions', array( $this, 'submissions_page' ), 'dashicons-email-alt', 58 );
		add_submenu_page( 'wpfv-submissions', 'Contatos', 'Envios', 'manage_options', 'wpfv-submissions', array( $this, 'submissions_page' ) );
		add_submenu_page( 'wpfv-submissions', 'Formulários', 'Formulários', 'manage_options', 'wpfv-forms', array( $this, 'forms_page' ) );
		add_submenu_page( 'wpfv-submissions', 'Configurações', 'Configurações', 'manage_options', 'wpfv-settings', array( $this, 'settings_page' ) );
		add_submenu_page( 'wpfv-submissions', 'Logs', 'Logs', 'manage_options', 'wpfv-logs', array( $this, 'logs_page' ) );
	}

	public function submissions_page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$view_id = isset( $_GET['view'] ) ? absint( $_GET['view'] ) : 0;
		if ( $view_id ) {
			$this->submission_detail( $view_id );
			return;
		}

		$form_id = isset( $_GET['form_id'] ) ? sanitize_key( wp_unslash( $_GET['form_id'] ) ) : '';
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$page    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$result  = $this->repository->query( array( 'form_id' => $form_id, 'search' => $search, 'page' => $page ) );
		$forms   = $this->registry->all();
		$delivery_map = $this->repository->get_latest_deliveries( wp_list_pluck( $result['items'], 'id' ) );
		?>
		<div class="wrap"><h1>Contatos</h1>
		<?php if ( isset( $_GET['retried'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Integração reenviada. O novo status já aparece na listagem.</p></div><?php endif; ?>
		<style>.wpfv-badge{display:inline-block;margin:2px 4px 2px 0;padding:4px 9px;border-radius:999px;font-weight:600;white-space:nowrap}.wpfv-badge--success{background:#d8f0df;color:#18743a}.wpfv-badge--error{background:#f8d7da;color:#9c2430}.wpfv-badge--pending{background:#fff0c2;color:#785b00}.wpfv-integration{margin-bottom:5px}.wpfv-retry{margin-left:4px}</style>
		<form method="get"><input type="hidden" name="page" value="wpfv-submissions">
		<select name="form_id"><option value="">Todos os formulários</option><?php foreach ( $forms as $form ) : ?><option value="<?php echo esc_attr( $form['id'] ); ?>" <?php selected( $form_id, $form['id'] ); ?>><?php echo esc_html( $form['name'] ); ?></option><?php endforeach; ?></select>
		<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Nome, e-mail ou conteúdo"> <?php submit_button( 'Filtrar', 'secondary', '', false ); ?></form>
		<table class="widefat striped" style="margin-top:16px"><thead><tr><th>ID</th><th>Data</th><th>Formulário</th><th>Nome</th><th>E-mail</th><th>Integrações</th><th>Ações</th></tr></thead><tbody>
		<?php if ( empty( $result['items'] ) ) : ?><tr><td colspan="7">Nenhum envio registrado.</td></tr><?php endif; ?>
		<?php foreach ( $result['items'] as $item ) : $item_deliveries = isset( $delivery_map[ (int) $item->id ] ) ? $delivery_map[ (int) $item->id ] : array(); ?><tr><td><?php echo (int) $item->id; ?></td><td><?php echo esc_html( get_date_from_gmt( $item->created_at, 'd/m/Y H:i' ) ); ?></td><td><?php echo esc_html( $item->form_name ); ?></td><td><?php echo esc_html( $item->primary_name ); ?></td><td><a href="mailto:<?php echo esc_attr( $item->primary_email ); ?>"><?php echo esc_html( $item->primary_email ); ?></a></td><td><?php $this->delivery_badges( $item, $item_deliveries ); ?></td><td><a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=wpfv-submissions&view=' . (int) $item->id ) ); ?>">Detalhes</a></td></tr><?php endforeach; ?>
		</tbody></table>
		<?php $pages = (int) ceil( $result['total'] / $result['per_page'] ); if ( $pages > 1 ) { echo wp_kses_post( paginate_links( array( 'total' => $pages, 'current' => $page ) ) ); } ?>
		</div><?php
	}

	private function delivery_badges( $submission, $deliveries ) {
		if ( empty( $deliveries ) ) {
			echo '<span aria-label="Nenhuma integração processada">—</span>';
			return;
		}

		foreach ( $deliveries as $integration => $delivery ) {
			$status = in_array( $delivery->status, array( 'success', 'error', 'pending' ), true ) ? $delivery->status : 'pending';
			$icon   = 'success' === $status ? '✓' : ( 'error' === $status ? '✕' : '…' );
			$label  = ucfirst( $integration );
			if ( $delivery->response_code ) {
				$label .= ' HTTP ' . (int) $delivery->response_code;
			}
			echo '<div class="wpfv-integration"><span class="wpfv-badge wpfv-badge--' . esc_attr( $status ) . '">' . esc_html( $icon . ' ' . $label ) . '</span>';
			if ( 'error' === $status ) {
				$retry_url = wp_nonce_url(
					admin_url( 'admin-post.php?action=wpfv_retry_delivery&submission_id=' . (int) $submission->id . '&integration=' . sanitize_key( $integration ) ),
					'wpfv_retry_' . (int) $submission->id . '_' . sanitize_key( $integration )
				);
				echo '<a class="button button-small wpfv-retry" href="' . esc_url( $retry_url ) . '">↻ Reenviar</a>';
			}
			echo '</div>';
		}
	}

	public function retry_delivery() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Acesso negado.', 'wp-formvault' ) );
		}

		$submission_id = isset( $_GET['submission_id'] ) ? absint( $_GET['submission_id'] ) : 0;
		$integration   = isset( $_GET['integration'] ) ? sanitize_key( wp_unslash( $_GET['integration'] ) ) : '';
		check_admin_referer( 'wpfv_retry_' . $submission_id . '_' . $integration );

		$submission = $this->repository->get( $submission_id );
		$form       = $submission ? $this->registry->get( $submission->form_id ) : null;
		if ( ! $submission || ! $form || ! $integration ) {
			wp_die( esc_html__( 'Não foi possível localizar o contato ou a integração.', 'wp-formvault' ) );
		}

		if ( ! $this->manager->process( $submission_id, $form, $integration ) ) {
			wp_die( esc_html__( 'A integração não está habilitada ou não está disponível.', 'wp-formvault' ) );
		}

		$return_to_detail = ! empty( $_GET['return_to_detail'] );
		$redirect_url     = $return_to_detail
			? admin_url( 'admin.php?page=wpfv-submissions&view=' . $submission_id . '&retried=' . rawurlencode( $integration ) )
			: admin_url( 'admin.php?page=wpfv-submissions&retried=1' );
		wp_safe_redirect( $redirect_url );
		exit;
	}

	private function submission_detail( $id ) {
		$item = $this->repository->get( $id );
		if ( ! $item ) { wp_die( esc_html__( 'Envio não encontrado.', 'wp-formvault' ) ); }
		$fields      = $this->filled_values( json_decode( $item->fields, true ) );
		$attribution = $this->filled_values( json_decode( $item->attribution, true ) );
		$deliveries  = $this->repository->get_deliveries( $id );
		$form         = $this->registry->get( $item->form_id );
		$submitted_at = get_date_from_gmt( $item->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
		$first_utm    = $this->extract_values( $attribution, array( 'first_utm_source', 'first_utm_medium', 'first_utm_campaign', 'first_utm_term', 'first_utm_content' ) );
		$last_utm     = $this->extract_values( $attribution, array( 'last_utm_source', 'last_utm_medium', 'last_utm_campaign', 'last_utm_term', 'last_utm_content' ) );
		$ads          = $this->extract_values( $attribution, array( 'gclid', 'fbclid', 'msclkid', 'ttclid' ) );
		$page_data    = $this->extract_values( $attribution, array( 'landing_url', 'landing_referrer', 'submission_url', 'submission_title' ) );
		$known_keys   = array_merge( array_keys( $first_utm ), array_keys( $last_utm ), array_keys( $ads ), array_keys( $page_data ) );
		$technical    = array_diff_key( $attribution, array_flip( $known_keys ) );
		$latest       = array();
		foreach ( $deliveries as $delivery ) {
			$latest[ sanitize_key( $delivery->integration ) ] = $delivery;
		}
		?>
		<div class="wrap wpfv-admin-wrap wpfv-submission-detail">
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=wpfv-submissions' ) ); ?>">&larr; Voltar aos contatos</a></p>
			<div class="wpfv-detail-heading">
				<div><h1>Contato #<?php echo (int) $item->id; ?></h1><p class="wpfv-page-description"><?php echo esc_html( $item->form_name ); ?></p></div>
				<div class="wpfv-detail-date"><span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span><span><strong>Recebido em</strong><?php echo esc_html( $submitted_at ); ?></span></div>
			</div>
			<?php if ( isset( $_GET['retried'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( ucfirst( sanitize_key( wp_unslash( $_GET['retried'] ) ) ) ); ?> reenviado. O resultado da nova tentativa aparece abaixo.</p></div><?php endif; ?>

			<div class="wpfv-detail-grid">
				<?php $this->detail_card( 'Campos preenchidos', 'dashicons-feedback', $fields, $form ); ?>
				<?php
				$source = array();
				if ( $this->has_value( $item->source_url ) ) {
					$source['source_page'] = array( 'label' => 'Página de origem', 'value' => $item->source_title ?: $item->source_url, 'url' => $item->source_url );
				}
				$this->detail_card( 'Página', 'dashicons-admin-page', array_merge( $source, $page_data ) );
				$this->detail_card( 'Primeira atribuição', 'dashicons-location-alt', $first_utm );
				$this->detail_card( 'Última atribuição', 'dashicons-update', $last_utm );
				$this->detail_card( 'Identificadores de anúncios', 'dashicons-megaphone', $ads );
				$this->detail_card( 'Dados técnicos', 'dashicons-info-outline', $technical );
				?>
			</div>

			<section class="card wpfv-admin-card wpfv-detail-integrations">
				<div class="wpfv-card-header"><h2>Integrações</h2><p>Status mais recente e histórico de entregas deste contato.</p></div>
				<div class="wpfv-card-body">
					<?php if ( ! $latest ) : ?><p>Nenhuma integração foi processada.</p><?php endif; ?>
					<div class="wpfv-delivery-summary">
						<?php foreach ( $latest as $integration => $delivery ) : $this->integration_summary( $item, $integration, $delivery ); endforeach; ?>
					</div>
					<?php if ( $deliveries ) : ?>
						<details class="wpfv-delivery-history"><summary>Ver histórico de tentativas (<?php echo count( $deliveries ); ?>)</summary>
						<table class="widefat striped"><thead><tr><th>Integração</th><th>Status</th><th>HTTP</th><th>Erro</th><th>Data e hora</th></tr></thead><tbody><?php foreach ( array_reverse( $deliveries ) as $delivery ) : ?><tr><td><?php echo esc_html( ucfirst( $delivery->integration ) ); ?></td><td><?php echo esc_html( $this->status_label( $delivery->status ) ); ?></td><td><?php echo $delivery->response_code ? (int) $delivery->response_code : '—'; ?></td><td><?php echo $this->has_value( $delivery->error_message ) ? esc_html( $delivery->error_message ) : '—'; ?></td><td><?php echo esc_html( get_date_from_gmt( $delivery->updated_at, 'd/m/Y H:i:s' ) ); ?></td></tr><?php endforeach; ?></tbody></table>
						</details>
					<?php endif; ?>
				</div>
			</section>
		</div>
		<?php
	}

	private function has_value( $value ) {
		if ( is_array( $value ) ) {
			return ! empty( array_filter( $value, array( $this, 'has_value' ) ) );
		}
		return null !== $value && '' !== trim( (string) $value );
	}

	private function filled_values( $values ) {
		return is_array( $values ) ? array_filter( $values, array( $this, 'has_value' ) ) : array();
	}

	private function extract_values( $values, $keys ) {
		return array_intersect_key( $values, array_flip( $keys ) );
	}

	private function detail_card( $title, $icon, $values, $form = null ) {
		$values = $this->filled_values( $values );
		if ( ! $values ) { return; }
		$labels = array(
			'landing_url' => 'Página de entrada', 'landing_referrer' => 'Referência de entrada', 'submission_url' => 'URL do envio', 'submission_title' => 'Título da página',
			'first_utm_source' => 'Origem', 'first_utm_medium' => 'Mídia', 'first_utm_campaign' => 'Campanha', 'first_utm_term' => 'Termo', 'first_utm_content' => 'Conteúdo',
			'last_utm_source' => 'Origem', 'last_utm_medium' => 'Mídia', 'last_utm_campaign' => 'Campanha', 'last_utm_term' => 'Termo', 'last_utm_content' => 'Conteúdo',
			'gclid' => 'Google Click ID', 'fbclid' => 'Meta Click ID', 'msclkid' => 'Microsoft Click ID', 'ttclid' => 'TikTok Click ID',
		);
		?>
		<section class="card wpfv-admin-card wpfv-detail-card">
			<div class="wpfv-card-header"><h2><span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span><?php echo esc_html( $title ); ?></h2></div>
			<div class="wpfv-card-body"><dl class="wpfv-detail-list">
			<?php foreach ( $values as $key => $raw_value ) :
				$is_structured = is_array( $raw_value ) && isset( $raw_value['value'] );
				$value         = $is_structured ? $raw_value['value'] : $raw_value;
				$label         = $is_structured && isset( $raw_value['label'] ) ? $raw_value['label'] : ( isset( $labels[ $key ] ) ? $labels[ $key ] : ucwords( str_replace( '_', ' ', $key ) ) );
				if ( $form && isset( $form['fields'][ $key ]['label'] ) ) { $label = $form['fields'][ $key ]['label']; }
				$url = $is_structured && isset( $raw_value['url'] ) ? $raw_value['url'] : ( false !== strpos( $key, 'url' ) ? $value : '' );
			?>
				<div><dt><?php echo esc_html( $label ); ?></dt><dd><?php if ( $url ) : ?><a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $value ); ?></a><?php else : ?><?php echo nl2br( esc_html( is_array( $value ) ? implode( ', ', $value ) : $value ) ); ?><?php endif; ?></dd></div>
			<?php endforeach; ?>
			</dl></div>
		</section>
		<?php
	}

	private function retry_url( $submission_id, $integration, $return_to_detail = false ) {
		$url = admin_url( 'admin-post.php?action=wpfv_retry_delivery&submission_id=' . absint( $submission_id ) . '&integration=' . sanitize_key( $integration ) );
		if ( $return_to_detail ) { $url = add_query_arg( 'return_to_detail', '1', $url ); }
		return wp_nonce_url( $url, 'wpfv_retry_' . absint( $submission_id ) . '_' . sanitize_key( $integration ) );
	}

	private function status_label( $status ) {
		return 'success' === $status ? 'Sucesso' : ( 'error' === $status ? 'Erro' : 'Pendente' );
	}

	private function integration_summary( $submission, $integration, $delivery ) {
		$status      = in_array( $delivery->status, array( 'success', 'error', 'pending' ), true ) ? $delivery->status : 'pending';
		$can_resend  = 'error' === $status || 'webhook' === $integration;
		$button_text = 'webhook' === $integration ? 'Reenviar webhook' : 'Tentar novamente';
		?>
		<div class="wpfv-delivery-item">
			<div><strong><?php echo esc_html( ucfirst( $integration ) ); ?></strong><span class="wpfv-badge wpfv-badge--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $this->status_label( $status ) ); ?><?php echo $delivery->response_code ? ' · HTTP ' . (int) $delivery->response_code : ''; ?></span></div>
			<p>Última tentativa: <?php echo esc_html( get_date_from_gmt( $delivery->updated_at, 'd/m/Y H:i:s' ) ); ?></p>
			<?php if ( $this->has_value( $delivery->error_message ) ) : ?><p class="wpfv-delivery-error"><?php echo esc_html( $delivery->error_message ); ?></p><?php endif; ?>
			<?php if ( $can_resend ) : ?><a class="button button-secondary" href="<?php echo esc_url( $this->retry_url( $submission->id, $integration, true ) ); ?>"><span class="dashicons dashicons-update" aria-hidden="true"></span><?php echo esc_html( $button_text ); ?></a><?php endif; ?>
		</div>
		<?php
	}

	public function forms_page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$forms   = $this->registry->all();
		$edit_id = isset( $_GET['form_id'] ) ? sanitize_key( wp_unslash( $_GET['form_id'] ) ) : '';
		if ( $edit_id && isset( $forms[ $edit_id ] ) ) { $this->form_settings( $forms[ $edit_id ] ); return; }
		?>
		<div class="wrap"><h1>Formulários</h1><p>Gerencie os formulários disponíveis e suas notificações.</p><table class="widefat striped"><thead><tr><th>Nome</th><th>ID</th><th>Campos</th><th></th></tr></thead><tbody><?php if ( ! $forms ) : ?><tr><td colspan="4">Nenhum formulário registrado.</td></tr><?php endif; ?><?php foreach ( $forms as $form ) : ?><tr><td><strong><?php echo esc_html( $form['name'] ); ?></strong><br><?php echo esc_html( $form['description'] ); ?></td><td><code><?php echo esc_html( $form['id'] ); ?></code></td><td><?php echo esc_html( implode( ', ', wp_list_pluck( $form['fields'], 'label' ) ) ); ?></td><td><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=wpfv-forms&form_id=' . $form['id'] ) ); ?>">Configurar</a></td></tr><?php endforeach; ?></tbody></table></div>
		<?php
	}

	private function form_settings( $form ) {
		$settings             = WPFV_Settings::get_for_form( $form );
		$validation           = $settings['validation'];
		$global_settings      = WPFV_Settings::get_global();
		$pipedrive_configured = ! empty( $global_settings['pipedrive_domain'] ) && ! empty( $global_settings['pipedrive_token'] );
		$brevo_configured     = ! empty( $global_settings['brevo_api_key'] );
		?>
		<div class="wrap wpfv-admin-wrap">
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=wpfv-forms' ) ); ?>">&larr; Voltar aos formulários</a></p>
			<h1><?php echo esc_html( $form['name'] ); ?></h1>
			<p class="wpfv-page-description">Configure a captação, os destinos e as regras de validação deste formulário.</p>
			<?php if ( isset( $_GET['updated'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Configurações salvas.</p></div><?php endif; ?>
			<nav class="nav-tab-wrapper" aria-label="Seções de configuração">
				<a href="#wpfv-general" class="nav-tab nav-tab-active wpfv-nav-tab">Geral</a>
				<a href="#wpfv-webhook" class="nav-tab wpfv-nav-tab">Webhook</a>
				<?php if ( $pipedrive_configured ) : ?>
					<a href="#wpfv-pipedrive" class="nav-tab wpfv-nav-tab">Pipedrive</a>
				<?php endif; ?>
				<?php if ( $brevo_configured ) : ?>
					<a href="#wpfv-brevo" class="nav-tab wpfv-nav-tab">Brevo</a>
				<?php endif; ?>
				<a href="#wpfv-validation" class="nav-tab wpfv-nav-tab">Validações</a>
			</nav>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wpfv_save_form_' . $form['id'] ); ?>
				<input type="hidden" name="action" value="wpfv_save_form_settings">
				<input type="hidden" name="form_id" value="<?php echo esc_attr( $form['id'] ); ?>">

				<section id="wpfv-general" class="wpfv-tab-panel">
					<div class="card wpfv-admin-card"><div class="wpfv-card-header"><h2>Captação e armazenamento</h2><p>Controle o registro dos contatos recebidos.</p></div><div class="wpfv-card-body"><label><input type="checkbox" name="active" value="1" <?php checked( $settings['active'] ); ?>> Armazenar novos envios deste formulário</label></div></div>
					<div class="card wpfv-admin-card"><div class="wpfv-card-header"><h2>Notificação por e-mail</h2><p>Envie uma cópia do contato para sua equipe.</p></div><div class="wpfv-card-body"><table class="form-table" role="presentation"><tr><th scope="row">Ativação</th><td><label><input type="checkbox" name="email_enabled" value="1" <?php checked( $settings['email_enabled'] ); ?>> Enviar notificação por e-mail</label></td></tr><tr><th scope="row"><label for="wpfv-email-recipients">Destinatários</label></th><td><input id="wpfv-email-recipients" class="large-text" type="text" name="email_recipients" value="<?php echo esc_attr( $settings['email_recipients'] ); ?>"><p class="description">Separe múltiplos endereços por vírgula.</p></td></tr><tr><th scope="row"><label for="wpfv-email-subject">Assunto</label></th><td><input id="wpfv-email-subject" class="large-text" type="text" name="email_subject" value="<?php echo esc_attr( $settings['email_subject'] ); ?>"></td></tr></table></div></div>
				</section>

				<section id="wpfv-webhook" class="wpfv-tab-panel" hidden>
					<div class="card wpfv-admin-card"><div class="wpfv-card-header"><h2>Entrega via webhook</h2><p>Envie o contato em JSON para uma aplicação externa.</p></div><div class="wpfv-card-body"><table class="form-table" role="presentation"><tr><th scope="row">Ativação</th><td><label><input type="checkbox" name="webhook_enabled" value="1" <?php checked( $settings['webhook_enabled'] ); ?>> Enviar webhook</label></td></tr><tr><th scope="row"><label for="wpfv-webhook-url">URL</label></th><td><input id="wpfv-webhook-url" class="large-text code" type="url" name="webhook_url" value="<?php echo esc_attr( $settings['webhook_url'] ); ?>"></td></tr><tr><th scope="row"><label for="wpfv-webhook-secret">Segredo HMAC</label></th><td><input id="wpfv-webhook-secret" class="large-text" type="password" autocomplete="new-password" name="webhook_secret" value="" placeholder="<?php echo $settings['webhook_secret'] ? esc_attr__( 'Segredo configurado — deixe vazio para manter', 'wp-formvault' ) : ''; ?>"><p class="description">Assina o corpo no header <code>X-WP-FormVault-Signature</code>.</p></td></tr></table></div></div>
					<div class="card wpfv-admin-card"><div class="wpfv-card-header"><h2>Mapeamento de campos</h2><p>Renomeie as chaves enviadas no objeto <code>form</code>. Cada origem e destino deve ser único; <code>utm</code> é reservado.</p></div><div class="wpfv-card-body"><?php $this->mapping_rows( 'webhook', $settings['webhook_field_map'], $form, false ); ?></div></div>
				</section>

				<?php if ( $pipedrive_configured ) : ?>
				<section id="wpfv-pipedrive" class="wpfv-tab-panel" hidden>
					<div class="card wpfv-admin-card"><div class="wpfv-card-header"><h2>Negociação no Pipedrive</h2><p>Crie organização, pessoa e negócio a partir do contato.</p></div><div class="wpfv-card-body"><p><label><input type="checkbox" name="pipedrive_enabled" value="1" <?php checked( $settings['pipedrive_enabled'] ); ?>> Habilitar integração com o Pipedrive</label></p><div class="wpfv-inline-fields"><label for="wpfv-pipeline">Pipeline</label><select id="wpfv-pipeline" class="regular-text" name="pipedrive_pipeline_id" data-selected="<?php echo absint( $settings['pipedrive_pipeline_id'] ); ?>"><option value="<?php echo absint( $settings['pipedrive_pipeline_id'] ); ?>">— Carregando pipelines —</option></select><label for="wpfv-stage">Etapa</label><select id="wpfv-stage" class="regular-text" name="pipedrive_stage_id" data-selected="<?php echo absint( $settings['pipedrive_stage_id'] ); ?>"><option value="<?php echo absint( $settings['pipedrive_stage_id'] ); ?>">— Selecione o pipeline —</option></select></div><p class="wpfv-connection-status" id="wpfv-pipedrive-catalog-status"></p><p><label for="wpfv-deal-title"><strong>Título da negociação</strong></label><br><input id="wpfv-deal-title" class="large-text" type="text" name="pipedrive_deal_title" value="<?php echo esc_attr( $settings['pipedrive_deal_title'] ); ?>"></p></div></div>
					<div class="card wpfv-admin-card"><div class="wpfv-card-header"><h2>Mapeamento do Pipedrive</h2><p>Associe os campos recebidos às propriedades de cada entidade.</p></div><div class="wpfv-card-body"><?php $this->pipedrive_rows( $settings['pipedrive_field_map'], $form ); ?><p class="description">Os campos são carregados automaticamente após configurar e testar a conexão.</p></div></div>
				</section>
				<?php endif; ?>

				<?php if ( $brevo_configured ) : ?>
				<section id="wpfv-brevo" class="wpfv-tab-panel" hidden>
					<div class="card wpfv-admin-card"><div class="wpfv-card-header"><h2>Contato no Brevo</h2><p>Crie ou atualize o contato e associe-o a uma lista.</p></div><div class="wpfv-card-body"><table class="form-table" role="presentation"><tr><th scope="row">Ativação</th><td><label><input type="checkbox" name="brevo_enabled" value="1" <?php checked( $settings['brevo_enabled'] ); ?>> Criar ou atualizar contato</label></td></tr><tr><th scope="row"><label for="wpfv-brevo-list">ID da lista</label></th><td><input id="wpfv-brevo-list" class="small-text" type="number" name="brevo_list_id" value="<?php echo absint( $settings['brevo_list_id'] ); ?>"></td></tr></table></div></div>
					<div class="card wpfv-admin-card"><div class="wpfv-card-header"><h2>Mapeamento do Brevo</h2><p>Mapeie obrigatoriamente um campo para <code>EMAIL</code>. Outros exemplos: <code>FNAME</code>, <code>LNAME</code> e <code>SMS</code>.</p></div><div class="wpfv-card-body"><?php $this->mapping_rows( 'brevo', $settings['brevo_field_map'], $form, true ); ?></div></div>
				</section>
				<?php endif; ?>

				<section id="wpfv-validation" class="wpfv-tab-panel" hidden>
					<div class="wpfv-validation-grid">
						<div class="card wpfv-admin-card"><div class="wpfv-card-header"><h2>Telefone</h2><p>Padronize e valide números brasileiros.</p></div><div class="wpfv-card-body"><p><label><input type="checkbox" name="phone_validation" value="1" <?php checked( $validation['phone_validation'] ); ?>> Aplicar máscara e exigir DDD + número</label></p><p><label for="wpfv-phone-message"><strong>Mensagem de erro</strong></label><br><input id="wpfv-phone-message" class="large-text" type="text" name="phone_message" value="<?php echo esc_attr( $validation['phone_message'] ); ?>"></p></div></div>
						<div class="card wpfv-admin-card"><div class="wpfv-card-header"><h2>E-mail corporativo</h2><p>Bloqueie provedores públicos e padrões indesejados.</p></div><div class="wpfv-card-body"><p><label><input type="checkbox" name="email_block_enabled" value="1" <?php checked( $validation['email_block_enabled'] ); ?>> Exigir e-mail corporativo</label></p><?php $this->validation_textarea( 'email_block_domains', 'Domínios bloqueados', $validation ); ?><?php $this->validation_textarea( 'email_block_suffixes', 'Sufixos bloqueados', $validation ); ?><?php $this->validation_textarea( 'email_block_words', 'Palavras bloqueadas no domínio', $validation ); ?><p><label for="wpfv-email-msg-domains">Mensagem para domínio</label><br><input id="wpfv-email-msg-domains" class="large-text" type="text" name="email_msg_domains" value="<?php echo esc_attr( $validation['email_msg_domains'] ); ?>"></p><p><label for="wpfv-email-msg-suffixes">Mensagem para sufixo</label><br><input id="wpfv-email-msg-suffixes" class="large-text" type="text" name="email_msg_suffixes" value="<?php echo esc_attr( $validation['email_msg_suffixes'] ); ?>"></p><p><label for="wpfv-email-msg-words">Mensagem para palavra</label><br><input id="wpfv-email-msg-words" class="large-text" type="text" name="email_msg_words" value="<?php echo esc_attr( $validation['email_msg_words'] ); ?>"></p></div></div>
					</div>
				</section>
				<div class="wpfv-actions"><?php submit_button( 'Salvar configurações', 'primary', 'submit', false ); ?></div>
			</form>
		</div>
		<?php
	}

	private function field_options( $form, $selected = '' ) { foreach ( $form['fields'] as $id => $field ) { echo '<option value="' . esc_attr( $id ) . '" ' . selected( $selected, $id, false ) . '>' . esc_html( $field['label'] . ' (' . $id . ')' ) . '</option>'; } }
	private function mapping_rows( $prefix, $rows, $form, $uppercase = false ) {
		$rows        = $rows ? $rows : array( array( 'source' => '', 'target' => '' ) );
		$placeholder = $uppercase ? 'EMAIL ou FNAME' : ( 'webhook' === $prefix ? 'Ex.: Área de atuação' : 'nome_da_chave' );
		echo '<div class="wpfv-field-mapping" data-mapping-prefix="' . esc_attr( $prefix ) . '">';
		echo '<table class="widefat striped wpfv-mapping-table"><thead><tr><th>Campo do formulário</th><th>Destino</th><th class="wpfv-mapping-actions">Ações</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$this->mapping_row( $prefix, $form, $placeholder, $row );
		}
		echo '</tbody></table>';
		echo '<p><button type="button" class="button wpfv-add-mapping">Adicionar campo</button></p>';
		echo '<template class="wpfv-mapping-template"><tr>';
		$this->mapping_cells( $prefix, $form, $placeholder, array() );
		echo '</tr></template></div>';
	}

	private function mapping_row( $prefix, $form, $placeholder, $row ) {
		echo '<tr>';
		$this->mapping_cells( $prefix, $form, $placeholder, $row );
		echo '</tr>';
	}

	private function mapping_cells( $prefix, $form, $placeholder, $row ) {
		$source     = isset( $row['source'] ) ? $row['source'] : '';
		$target     = isset( $row['target'] ) ? $row['target'] : '';
		$maxlength  = 'webhook' === $prefix ? ' maxlength="191"' : '';
		echo '<td><select class="regular-text wpfv-mapping-source" aria-label="Campo do formulário" name="' . esc_attr( $prefix ) . '_source[]"><option value="">— Selecione —</option>';
		$this->field_options( $form, $source );
		echo '</select></td><td><input class="regular-text wpfv-mapping-target" type="text"' . $maxlength . ' aria-label="Destino" name="' . esc_attr( $prefix ) . '_target[]" value="' . esc_attr( $target ) . '" placeholder="' . esc_attr( $placeholder ) . '"></td>';
		echo '<td class="wpfv-mapping-actions"><button type="button" class="button-link-delete wpfv-remove-mapping" aria-label="Remover este mapeamento">Remover</button></td>';
	}
	private function pipedrive_rows( $rows, $form ) { $rows = $rows ? $rows : array(); echo '<table class="widefat striped wpfv-mapping-table"><thead><tr><th>Campo do formulário</th><th>Entidade</th><th>Campo no Pipedrive</th></tr></thead><tbody>'; $total=max( 6, count( $rows )+3 ); for($i=0;$i<$total;$i++){ $r=isset($rows[$i])?$rows[$i]:array(); echo '<tr><td><select name="pd_source[]"><option value="">— Selecione —</option>'; $this->field_options($form,isset($r['source'])?$r['source']:''); echo '</select></td><td><select name="pd_entity[]" class="wpfv-pd-entity"><option value="person" '.selected(isset($r['entity'])?$r['entity']:'person','person',false).'>Pessoa</option><option value="organization" '.selected(isset($r['entity'])?$r['entity']:'','organization',false).'>Empresa</option><option value="deal" '.selected(isset($r['entity'])?$r['entity']:'','deal',false).'>Negociação</option></select></td><td><select class="regular-text wpfv-pd-target" name="pd_target[]" data-selected="'.esc_attr(isset($r['target'])?$r['target']:'').'"><option value="'.esc_attr(isset($r['target'])?$r['target']:'').'">— Carregando campos —</option></select></td></tr>'; } echo '</tbody></table>'; }
	private function validation_textarea( $name, $label, $validation ) { $id = 'wpfv-' . sanitize_html_class( str_replace( '_', '-', $name ) ); echo '<p><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><br><textarea id="' . esc_attr( $id ) . '" class="large-text code" rows="5" name="' . esc_attr( $name ) . '">' . esc_textarea( implode( "\n", (array) $validation[$name] ) ) . '</textarea></p>'; }

	public function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$s     = WPFV_Settings::get_global();
		$nonce = wp_create_nonce( 'wpfv_api_test' );
		?>
		<div class="wrap wpfv-admin-wrap">
			<h1>Configurações de Contatos</h1>
			<p class="wpfv-page-description">Conecte os serviços externos e defina como os eventos técnicos serão registrados.</p>
			<?php if ( isset( $_GET['updated'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Configurações salvas.</p></div><?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wpfv_save_global' ); ?>
				<input type="hidden" name="action" value="wpfv_save_global_settings">
				<div class="wpfv-settings-grid">
					<div class="card wpfv-admin-card">
						<div class="wpfv-card-header"><h2>Pipedrive</h2><p>Credenciais usadas para consultar campos e criar negociações.</p></div>
						<div class="wpfv-card-body"><table class="form-table" role="presentation"><tr><th scope="row"><label for="wpfv-pipedrive-domain">Company Domain</label></th><td><input id="wpfv-pipedrive-domain" class="large-text" type="text" name="pipedrive_domain" value="<?php echo esc_attr( $s['pipedrive_domain'] ); ?>" placeholder="empresa.pipedrive.com" autocomplete="url" inputmode="url"><p class="description">Informe somente o domínio da conta, sem protocolo.</p></td></tr><tr><th scope="row"><label for="wpfv-pipedrive-token">API Token</label></th><td><input id="wpfv-pipedrive-token" class="large-text" type="password" name="pipedrive_token" value="" placeholder="<?php echo $s['pipedrive_token'] ? 'Token configurado — deixe vazio para manter' : ''; ?>" autocomplete="new-password"><p><button type="button" class="button button-secondary wpfv-test" data-action="wpfv_test_pipedrive">Testar conexão</button> <span class="wpfv-test-result wpfv-connection-status" role="status"></span></p></td></tr></table></div>
					</div>
					<div class="card wpfv-admin-card">
						<div class="wpfv-card-header"><h2>Brevo</h2><p>Credencial usada para criar e atualizar contatos.</p></div>
						<div class="wpfv-card-body"><table class="form-table" role="presentation"><tr><th scope="row"><label for="wpfv-brevo-key">API Key</label></th><td><input id="wpfv-brevo-key" class="large-text" type="password" name="brevo_api_key" value="" placeholder="<?php echo $s['brevo_api_key'] ? 'Chave configurada — deixe vazio para manter' : ''; ?>" autocomplete="new-password"><p><button type="button" class="button button-secondary wpfv-test" data-action="wpfv_test_brevo">Testar conexão</button> <span class="wpfv-test-result wpfv-connection-status" role="status"></span></p></td></tr></table></div>
					</div>
				</div>
				<div class="card wpfv-admin-card">
					<div class="wpfv-card-header"><h2>Logs</h2><p>Defina por quanto tempo os eventos das integrações serão mantidos.</p></div>
					<div class="wpfv-card-body"><table class="form-table" role="presentation"><tr><th scope="row">Registro</th><td><label><input type="checkbox" name="logging_enabled" value="1" <?php checked( $s['logging_enabled'] ); ?>> Registrar eventos das integrações</label></td></tr><tr><th scope="row"><label for="wpfv-retention">Retenção</label></th><td><input id="wpfv-retention" class="small-text" type="number" min="1" max="3650" name="log_retention_days" value="<?php echo absint( $s['log_retention_days'] ); ?>"> dias</td></tr></table></div>
				</div>
				<div class="wpfv-actions"><?php submit_button( 'Salvar configurações', 'primary', 'submit', false ); ?></div>
			</form>
		</div>
		<script>document.querySelectorAll('.wpfv-test').forEach(function(b){b.addEventListener('click',function(){var o=b.nextElementSibling;o.textContent='Testando…';var d=new FormData();d.append('action',b.dataset.action);d.append('_ajax_nonce','<?php echo esc_js($nonce);?>');fetch(ajaxurl,{method:'POST',body:d}).then(r=>r.json()).then(r=>{o.textContent=r.data&&r.data.message?r.data.message:(r.success?'Conexão válida.':'Falha.');});});});</script><?php
	}

	public function save_global_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Acesso negado.', 'wp-formvault' ) );
		}
		check_admin_referer( 'wpfv_save_global' );
		$current = WPFV_Settings::get_global();
		$token   = isset( $_POST['pipedrive_token'] ) ? sanitize_text_field( wp_unslash( $_POST['pipedrive_token'] ) ) : '';
		$brevo   = isset( $_POST['brevo_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['brevo_api_key'] ) ) : '';

		WPFV_Settings::save_global(
			array(
				'pipedrive_domain'  => isset( $_POST['pipedrive_domain'] ) ? sanitize_text_field( wp_unslash( $_POST['pipedrive_domain'] ) ) : '',
				'pipedrive_token'   => '' !== $token ? $token : $current['pipedrive_token'],
				'brevo_api_key'      => '' !== $brevo ? $brevo : $current['brevo_api_key'],
				'logging_enabled'    => ! empty( $_POST['logging_enabled'] ),
				'log_retention_days' => isset( $_POST['log_retention_days'] ) ? absint( $_POST['log_retention_days'] ) : 90,
			)
		);
		wp_safe_redirect( admin_url( 'admin.php?page=wpfv-settings&updated=1' ) );
		exit;
	}
	public function test_pipedrive(){check_ajax_referer('wpfv_api_test');if(!current_user_can('manage_options')){wp_send_json_error();}$r=(new WPFV_Pipedrive_API())->test();$msg=$r['success']?'Conexão válida com o Pipedrive.':$r['error'];if($r['success']){wp_send_json_success(array('message'=>$msg));}wp_send_json_error(array('message'=>$msg));}
	public function test_brevo(){check_ajax_referer('wpfv_api_test');if(!current_user_can('manage_options')){wp_send_json_error();}$r=(new WPFV_Brevo_API())->test();$msg=$r['success']?'Conexão válida com o Brevo.':$r['error'];if($r['success']){wp_send_json_success(array('message'=>$msg));}wp_send_json_error(array('message'=>$msg));}

	/**
	 * Return Pipedrive pipelines, stages or fields to the form settings UI.
	 *
	 * @return void
	 */
	public function pipedrive_catalog() {
		check_ajax_referer( 'wpfv_admin' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Acesso negado.', 'wp-formvault' ) ), 403 );
		}

		$catalog = isset( $_POST['catalog'] ) ? sanitize_key( wp_unslash( $_POST['catalog'] ) ) : '';
		$api     = new WPFV_Pipedrive_API();

		if ( 'pipelines' === $catalog ) {
			$result = $api->pipelines();
		} elseif ( 'stages' === $catalog ) {
			$pipeline = isset( $_POST['pipeline_id'] ) ? absint( $_POST['pipeline_id'] ) : 0;
			$result   = $pipeline ? $api->stages( $pipeline ) : array( 'success' => false, 'error' => __( 'Selecione um pipeline.', 'wp-formvault' ) );
		} elseif ( 'fields' === $catalog ) {
			$entity = isset( $_POST['entity'] ) ? sanitize_key( wp_unslash( $_POST['entity'] ) ) : '';
			$result = $api->fields( $entity );
		} else {
			$result = array( 'success' => false, 'error' => __( 'Catálogo inválido.', 'wp-formvault' ) );
		}

		if ( ! empty( $result['success'] ) ) {
			wp_send_json_success( array( 'items' => isset( $result['data'] ) ? $result['data'] : array() ) );
		}

		wp_send_json_error( array( 'message' => isset( $result['error'] ) ? $result['error'] : __( 'Não foi possível consultar o Pipedrive.', 'wp-formvault' ) ) );
	}
	public function logs_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$logs = $this->repository->query_logs();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Logs', 'wp-formvault' ); ?></h1>
			<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wpfv_clear_logs' ), 'wpfv_clear_logs' ) ); ?>"><?php esc_html_e( 'Limpar logs', 'wp-formvault' ); ?></a></p>
			<table class="widefat striped">
				<thead><tr><th>Data</th><th>Nível</th><th>Origem</th><th>Contato</th><th>Mensagem</th><th>Contexto</th></tr></thead>
				<tbody>
				<?php if ( ! $logs ) : ?>
					<tr><td colspan="6">Nenhum log registrado.</td></tr>
				<?php endif; ?>
				<?php foreach ( $logs as $log ) : ?>
					<tr>
						<td><?php echo esc_html( get_date_from_gmt( $log->created_at, 'd/m/Y H:i:s' ) ); ?></td>
						<td><?php echo esc_html( $log->level ); ?></td>
						<td><?php echo esc_html( $log->source ); ?></td>
						<td><?php if ( $log->submission_id ) : ?><a href="<?php echo esc_url( admin_url( 'admin.php?page=wpfv-submissions&view=' . (int) $log->submission_id ) ); ?>">#<?php echo (int) $log->submission_id; ?></a><?php else : ?>—<?php endif; ?></td>
						<td><?php echo esc_html( $log->message ); ?></td>
						<td><details><summary>Ver</summary><pre><?php echo esc_html( $log->context ); ?></pre></details></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
	public function clear_logs(){if(!current_user_can('manage_options'))wp_die('Acesso negado.');check_admin_referer('wpfv_clear_logs');$this->repository->clear_logs();wp_safe_redirect(admin_url('admin.php?page=wpfv-logs'));exit;}

	public function save_form_settings() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Acesso negado.', 'wp-formvault' ) ); }
		$form_id = isset( $_POST['form_id'] ) ? sanitize_key( wp_unslash( $_POST['form_id'] ) ) : '';
		check_admin_referer( 'wpfv_save_form_' . $form_id );
		if ( ! $this->registry->get( $form_id ) ) { wp_die( esc_html__( 'Formulário inválido.', 'wp-formvault' ) ); }

		$form             = $this->registry->get( $form_id );
		$current_settings = WPFV_Settings::get_for_form( $form );
		$global_settings  = WPFV_Settings::get_global();
		$has_pipedrive    = ! empty( $global_settings['pipedrive_domain'] ) && ! empty( $global_settings['pipedrive_token'] );
		$has_brevo        = ! empty( $global_settings['brevo_api_key'] );
		$webhook_secret   = isset( $_POST['webhook_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['webhook_secret'] ) ) : '';
		if ( '' === $webhook_secret ) {
			$webhook_secret = $current_settings['webhook_secret'];
		}

		WPFV_Settings::save_for_form(
			$form_id,
			array(
				'active'           => ! empty( $_POST['active'] ),
				'email_enabled'    => ! empty( $_POST['email_enabled'] ),
				'email_recipients' => isset( $_POST['email_recipients'] ) ? sanitize_text_field( wp_unslash( $_POST['email_recipients'] ) ) : '',
				'email_subject'    => isset( $_POST['email_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['email_subject'] ) ) : '',
				'webhook_enabled'  => ! empty( $_POST['webhook_enabled'] ),
				'webhook_url'      => isset( $_POST['webhook_url'] ) ? esc_url_raw( wp_unslash( $_POST['webhook_url'] ) ) : '',
				'webhook_secret'   => $webhook_secret,
				'webhook_field_map'=> $this->sanitize_webhook_map( $form ),
				'pipedrive_enabled'=> $has_pipedrive ? ! empty( $_POST['pipedrive_enabled'] ) : $current_settings['pipedrive_enabled'],
				'pipedrive_pipeline_id'=> $has_pipedrive && isset($_POST['pipedrive_pipeline_id']) ? absint($_POST['pipedrive_pipeline_id']) : $current_settings['pipedrive_pipeline_id'],
				'pipedrive_stage_id'=> $has_pipedrive && isset($_POST['pipedrive_stage_id']) ? absint($_POST['pipedrive_stage_id']) : $current_settings['pipedrive_stage_id'],
				'pipedrive_deal_title'=> $has_pipedrive && isset($_POST['pipedrive_deal_title']) ? sanitize_text_field(wp_unslash($_POST['pipedrive_deal_title'])) : $current_settings['pipedrive_deal_title'],
				'pipedrive_field_map'=> $has_pipedrive ? $this->sanitize_pd_map() : $current_settings['pipedrive_field_map'],
				'brevo_enabled'=> $has_brevo ? ! empty($_POST['brevo_enabled']) : $current_settings['brevo_enabled'],
				'brevo_list_id'=> $has_brevo && isset($_POST['brevo_list_id']) ? absint($_POST['brevo_list_id']) : $current_settings['brevo_list_id'],
				'brevo_field_map'=> $has_brevo ? $this->sanitize_map('brevo_source','brevo_target') : $current_settings['brevo_field_map'],
				'validation'=> $this->sanitize_validation(),
			)
		);

		wp_safe_redirect( admin_url( 'admin.php?page=wpfv-forms&form_id=' . $form_id . '&updated=1' ) );
		exit;
	}

	private function sanitize_lines( $key ) { $raw=isset($_POST[$key])?sanitize_textarea_field(wp_unslash($_POST[$key])):''; return array_values(array_filter(array_map('trim',preg_split('/\R/',$raw)))); }
	private function sanitize_map( $source_key, $target_key ) { $sources=isset($_POST[$source_key])?(array)wp_unslash($_POST[$source_key]):array(); $targets=isset($_POST[$target_key])?(array)wp_unslash($_POST[$target_key]):array(); $out=array(); foreach($sources as $i=>$source){$source=sanitize_key($source);$target=isset($targets[$i])?sanitize_key($targets[$i]):'';if($source&&$target){$out[]=array('source'=>$source,'target'=>$target);}}return $out; }
	private function sanitize_webhook_map( $form ) {
		$raw_sources = isset( $_POST['webhook_source'] ) ? (array) wp_unslash( $_POST['webhook_source'] ) : array();
		$raw_targets = isset( $_POST['webhook_target'] ) ? (array) wp_unslash( $_POST['webhook_target'] ) : array();
		$rows        = array();
		foreach ( $raw_sources as $index => $raw_source ) {
			if ( ! is_scalar( $raw_source ) ) {
				continue;
			}
			$source = sanitize_key( $raw_source );
			$target = isset( $raw_targets[ $index ] ) && is_scalar( $raw_targets[ $index ] ) ? trim( sanitize_text_field( $raw_targets[ $index ] ) ) : '';
			if ( function_exists( 'mb_substr' ) ) {
				$target = mb_substr( $target, 0, 191 );
			}
			if ( $source && $target ) {
				$rows[] = array( 'source' => $source, 'target' => $target );
			}
		}

		$form_fields = array_fill_keys( array_keys( $form['fields'] ), true );
		$renames     = array();
		$targets     = array();
		foreach ( $rows as $row ) {
			$source = $row['source'];
			$target = $row['target'];
			if ( 'utm' === $target || ! isset( $form_fields[ $source ] ) || isset( $renames[ $source ] ) || isset( $targets[ $target ] ) ) {
				continue;
			}
			$renames[ $source ] = $target;
			$targets[ $target ] = true;
		}

		do {
			$changed = false;
			foreach ( $renames as $source => $target ) {
				if ( $target !== $source && isset( $form_fields[ $target ] ) && ! isset( $renames[ $target ] ) ) {
					unset( $renames[ $source ] );
					$changed = true;
				}
			}
		} while ( $changed );

		$out = array();
		foreach ( $renames as $source => $target ) {
			$out[] = array( 'source' => $source, 'target' => $target );
		}
		return $out;
	}
	private function sanitize_pd_map(){ $sources=isset($_POST['pd_source'])?(array)wp_unslash($_POST['pd_source']):array();$entities=isset($_POST['pd_entity'])?(array)wp_unslash($_POST['pd_entity']):array();$targets=isset($_POST['pd_target'])?(array)wp_unslash($_POST['pd_target']):array();$out=array();foreach($sources as $i=>$source){$source=sanitize_key($source);$entity=isset($entities[$i])?sanitize_key($entities[$i]):'';$target=isset($targets[$i])?sanitize_text_field($targets[$i]):'';if($source&&in_array($entity,array('person','organization','deal'),true)&&$target){$out[]=array('source'=>$source,'entity'=>$entity,'target'=>$target);}}return $out; }
	private function sanitize_validation(){return array('phone_validation'=>!empty($_POST['phone_validation']),'phone_message'=>isset($_POST['phone_message'])?sanitize_text_field(wp_unslash($_POST['phone_message'])):'','email_block_enabled'=>!empty($_POST['email_block_enabled']),'email_block_domains'=>$this->sanitize_lines('email_block_domains'),'email_block_suffixes'=>$this->sanitize_lines('email_block_suffixes'),'email_block_words'=>$this->sanitize_lines('email_block_words'),'email_msg_domains'=>isset($_POST['email_msg_domains'])?sanitize_text_field(wp_unslash($_POST['email_msg_domains'])):'','email_msg_suffixes'=>isset($_POST['email_msg_suffixes'])?sanitize_text_field(wp_unslash($_POST['email_msg_suffixes'])):'','email_msg_words'=>isset($_POST['email_msg_words'])?sanitize_text_field(wp_unslash($_POST['email_msg_words'])):'');}
}
