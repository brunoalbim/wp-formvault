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
		add_action( 'admin_post_wpfv_dismiss_rdcrm_error', array( $this, 'dismiss_rdcrm_error' ) );
		add_action( 'wp_ajax_wpfv_test_pipedrive', array( $this, 'test_pipedrive' ) );
		add_action( 'wp_ajax_wpfv_test_brevo', array( $this, 'test_brevo' ) );
		add_action( 'wp_ajax_wpfv_test_recaptcha', array( $this, 'test_recaptcha' ) );
		add_action( 'wp_ajax_wpfv_test_rdcrm', array( $this, 'test_rdcrm' ) );
		add_action( 'wp_ajax_wpfv_rdcrm_catalog', array( $this, 'rdcrm_catalog' ) );
		add_action( 'wp_ajax_wpfv_rdcrm_refresh_catalogs', array( $this, 'rdcrm_refresh_catalogs' ) );
		add_action( 'admin_post_wpfv_rdcrm_connect', array( $this, 'rdcrm_connect' ) );
		add_action( 'admin_post_wpfv_rdcrm_oauth_callback', array( $this, 'rdcrm_oauth_callback' ) );
		add_action( 'admin_post_wpfv_rdcrm_disconnect', array( $this, 'rdcrm_disconnect' ) );
		add_action( 'wp_ajax_wpfv_pipedrive_catalog', array( $this, 'pipedrive_catalog' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_wpfv_retry_delivery', array( $this, 'retry_delivery' ) );
		add_action( 'admin_post_wpfv_retry_rdcrm_note', array( $this, 'retry_rdcrm_note' ) );
		add_action( 'admin_post_wpfv_delete_submission', array( $this, 'delete_submission' ) );
		add_action( 'admin_post_wpfv_approve_spam', array( $this, 'approve_spam' ) );
		add_action( 'admin_notices', array( $this, 'recaptcha_health_notice' ) );
	}

	/** Surface provider failures without exposing credentials or request data. */
	public function recaptcha_health_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$health = WPFV_Recaptcha::health();
		$status = isset( $health['status'] ) ? sanitize_key( $health['status'] ) : '';
		if ( ! in_array( $status, array( 'error', 'warning' ), true ) || empty( $health['message'] ) ) {
			return;
		}
		$class = 'error' === $status ? 'notice-error' : 'notice-warning';
		$url   = admin_url( 'admin.php?page=wpfv-settings#wpfv-settings-antispam' );
		echo '<div class="notice ' . esc_attr( $class ) . '"><p><strong>WP FormVault:</strong> ' . esc_html( $health['message'] ) . ' <a href="' . esc_url( $url ) . '">Revisar configuração antispam</a></p></div>';
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
		$spam_count = $this->repository->count_by_status( 'spam' );
		$bubble     = $spam_count ? ' <span class="awaiting-mod count-' . absint( $spam_count ) . '"><span class="pending-count">' . absint( $spam_count ) . '</span></span>' : '';
		add_menu_page( 'Contatos', 'Contatos' . $bubble, 'manage_options', 'wpfv-submissions', array( $this, 'submissions_page' ), 'dashicons-email-alt', 58 );
		add_submenu_page( 'wpfv-submissions', 'Contatos', 'Envios' . $bubble, 'manage_options', 'wpfv-submissions', array( $this, 'submissions_page' ) );
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
		$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$status  = in_array( $status, array( 'spam', 'legitimate' ), true ) ? $status : '';
		$page    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$result  = $this->repository->query( array( 'form_id' => $form_id, 'search' => $search, 'status' => $status, 'page' => $page ) );
		$forms   = $this->registry->all();
		$counts  = array( 'all' => $this->repository->count_by_status(), 'legitimate' => $this->repository->count_by_status( 'legitimate' ), 'spam' => $this->repository->count_by_status( 'spam' ) );
		$delivery_map = $this->repository->get_latest_deliveries( wp_list_pluck( $result['items'], 'id' ) );
		?>
		<div class="wrap"><h1>Contatos</h1>
		<?php if ( isset( $_GET['retried'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Integração reenviada. O novo status já aparece na listagem.</p></div><?php endif; ?>
		<?php if ( isset( $_GET['deleted'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Envio excluído com seu histórico local.</p></div><?php endif; ?>
		<?php if ( isset( $_GET['approved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Contato aprovado e liberado para as integrações configuradas.</p></div><?php endif; ?>
		<ul class="subsubsub"><li><a href="<?php echo esc_url( admin_url( 'admin.php?page=wpfv-submissions' ) ); ?>" <?php echo '' === $status ? 'class="current" aria-current="page"' : ''; ?>>Todos <span class="count">(<?php echo absint( $counts['all'] ); ?>)</span></a> | </li><li><a href="<?php echo esc_url( admin_url( 'admin.php?page=wpfv-submissions&status=legitimate' ) ); ?>" <?php echo 'legitimate' === $status ? 'class="current" aria-current="page"' : ''; ?>>Legítimos <span class="count">(<?php echo absint( $counts['legitimate'] ); ?>)</span></a> | </li><li><a href="<?php echo esc_url( admin_url( 'admin.php?page=wpfv-submissions&status=spam' ) ); ?>" <?php echo 'spam' === $status ? 'class="current" aria-current="page"' : ''; ?>>Spam <span class="count">(<?php echo absint( $counts['spam'] ); ?>)</span></a></li></ul>
		<form method="get" class="wpfv-submission-filters"><input type="hidden" name="page" value="wpfv-submissions"><input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
		<select name="form_id"><option value="">Todos os formulários</option><?php foreach ( $forms as $form ) : ?><option value="<?php echo esc_attr( $form['id'] ); ?>" <?php selected( $form_id, $form['id'] ); ?>><?php echo esc_html( $form['name'] ); ?></option><?php endforeach; ?></select>
		<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Nome, e-mail ou conteúdo"> <?php submit_button( 'Filtrar', 'secondary', '', false ); ?></form>
		<table class="widefat striped wpfv-submissions-table"><thead><tr><th>ID</th><th>Data</th><th>Status</th><th>Formulário</th><th>Nome</th><th>E-mail</th><th>Integrações</th><th>Ações</th></tr></thead><tbody>
		<?php if ( empty( $result['items'] ) ) : ?><tr><td colspan="8">Nenhum envio registrado.</td></tr><?php endif; ?>
		<?php foreach ( $result['items'] as $item ) : $item_deliveries = isset( $delivery_map[ (int) $item->id ] ) ? $delivery_map[ (int) $item->id ] : array(); ?><tr><td><?php echo (int) $item->id; ?></td><td><?php echo esc_html( get_date_from_gmt( $item->created_at, 'd/m/Y H:i' ) ); ?></td><td><?php $this->submission_status_badge( $item->status ); ?></td><td><?php echo esc_html( $item->form_name ); ?></td><td><?php echo esc_html( $item->primary_name ); ?></td><td><a href="mailto:<?php echo esc_attr( $item->primary_email ); ?>"><?php echo esc_html( $item->primary_email ); ?></a></td><td><?php $this->delivery_badges( $item, $item_deliveries ); ?></td><td><div class="wpfv-row-actions"><a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=wpfv-submissions&view=' . (int) $item->id ) ); ?>">Detalhes</a><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Excluir este envio e todo o histórico local associado?');"><input type="hidden" name="action" value="wpfv_delete_submission"><input type="hidden" name="submission_id" value="<?php echo (int) $item->id; ?>"><?php wp_nonce_field( 'wpfv_delete_submission_' . (int) $item->id ); ?><button type="submit" class="button button-small button-link-delete">Excluir</button></form></div></td></tr><?php endforeach; ?>
		</tbody></table>
		<?php $pages = (int) ceil( $result['total'] / $result['per_page'] ); if ( $pages > 1 ) { echo wp_kses_post( paginate_links( array( 'total' => $pages, 'current' => $page ) ) ); } ?>
		</div><?php
	}

	private function delivery_badges( $submission, $deliveries ) {
		if ( 'spam' === $submission->status ) {
			echo '<span class="wpfv-badge wpfv-badge--spam">Em quarentena</span>';
			return;
		}
		if ( empty( $deliveries ) ) {
			echo '<span aria-label="Nenhuma integração processada">—</span>';
			return;
		}

		foreach ( $deliveries as $integration => $delivery ) {
			$status = in_array( $delivery->status, array( 'success', 'error', 'pending' ), true ) ? $delivery->status : 'pending';
			$icon   = 'success' === $status ? '✓' : ( 'error' === $status ? '✕' : '…' );
			$label  = $this->integration_label( $integration );
			if ( $delivery->response_code ) {
				$label .= ' HTTP ' . (int) $delivery->response_code;
			}
			echo '<div class="wpfv-integration"><span class="wpfv-badge wpfv-badge--' . esc_attr( $status ) . '">' . esc_html( $icon . ' ' . $label ) . '</span>';
			if ( 'error' === $status ) {
				$note_failure = 'rdcrm' === $integration && $this->rdcrm_note_failure( $delivery );
				$retry_url = $note_failure ? $this->rdcrm_note_retry_url( $submission->id ) : wp_nonce_url(
					admin_url( 'admin-post.php?action=wpfv_retry_delivery&submission_id=' . (int) $submission->id . '&integration=' . sanitize_key( $integration ) ),
					'wpfv_retry_' . (int) $submission->id . '_' . sanitize_key( $integration )
				);
				echo '<a class="button button-small wpfv-retry" href="' . esc_url( $retry_url ) . '">↻ ' . esc_html( $note_failure ? 'Reenviar anotação' : 'Reenviar' ) . '</a>';
			}
			echo '</div>';
		}
	}

	private function submission_status_badge( $status ) {
		$labels = array( 'stored' => 'Legítimo', 'approved' => 'Aprovado', 'spam' => 'Spam' );
		$status = isset( $labels[ $status ] ) ? $status : 'stored';
		echo '<span class="wpfv-badge wpfv-badge--' . esc_attr( $status ) . '">' . esc_html( $labels[ $status ] ) . '</span>';
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
		if ( ! $submission || ! $form || ! $integration || ! in_array( $submission->status, array( 'stored', 'approved' ), true ) ) {
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

	/** Retry only a failed RD CRM note, reusing the deal created previously. */
	public function retry_rdcrm_note() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Acesso negado.', 'wp-formvault' ) );
		}

		$submission_id = isset( $_GET['submission_id'] ) ? absint( $_GET['submission_id'] ) : 0;
		check_admin_referer( 'wpfv_retry_rdcrm_note_' . $submission_id );
		$submission = $this->repository->get( $submission_id );
		$form       = $submission ? $this->registry->get( $submission->form_id ) : null;
		if ( ! $submission || ! $form || ! in_array( $submission->status, array( 'stored', 'approved' ), true ) ) {
			wp_die( esc_html__( 'Não foi possível localizar o contato.', 'wp-formvault' ) );
		}

		$deliveries = array_reverse( $this->repository->get_deliveries( $submission_id ) );
		$failure    = null;
		foreach ( $deliveries as $delivery ) {
			if ( 'rdcrm' === sanitize_key( $delivery->integration ) ) {
				$failure = $this->rdcrm_note_failure( $delivery );
				break;
			}
		}
		if ( ! $failure ) {
			wp_die( esc_html__( 'A tentativa mais recente do RD CRM não possui uma anotação pendente.', 'wp-formvault' ) );
		}

		$settings    = WPFV_Settings::get_for_form( $form );
		$delivery_id = $this->repository->create_delivery( $submission_id, 'rdcrm' );
		$result      = ( new WPFV_RDCRM_Integration() )->deliver_note( $submission, $settings, $failure['deal_id'] );
		$this->repository->complete_delivery( $delivery_id, $result );
		$this->repository->log( ! empty( $result['success'] ) ? 'success' : 'error', 'rdcrm', ! empty( $result['success'] ) ? 'Anotação do RD CRM reenviada.' : ( isset( $result['error'] ) ? $result['error'] : 'Falha ao reenviar a anotação do RD CRM.' ), $result, $submission_id );

		$return_to_detail = ! empty( $_GET['return_to_detail'] );
		$redirect_url     = $return_to_detail
			? admin_url( 'admin.php?page=wpfv-submissions&view=' . $submission_id . '&retried=rdcrm_note' )
			: admin_url( 'admin.php?page=wpfv-submissions&retried=rdcrm_note' );
		wp_safe_redirect( $redirect_url );
		exit;
	}

	public function delete_submission() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Acesso negado.', 'wp-formvault' ) ); }
		$id = isset( $_POST['submission_id'] ) ? absint( $_POST['submission_id'] ) : 0;
		check_admin_referer( 'wpfv_delete_submission_' . $id );
		if ( ! $this->repository->delete( $id ) ) { wp_die( esc_html__( 'Não foi possível excluir o envio.', 'wp-formvault' ) ); }
		wp_safe_redirect( admin_url( 'admin.php?page=wpfv-submissions&deleted=1' ) );
		exit;
	}

	public function approve_spam() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Acesso negado.', 'wp-formvault' ) );
		}
		$id = isset( $_POST['submission_id'] ) ? absint( $_POST['submission_id'] ) : 0;
		check_admin_referer( 'wpfv_approve_spam_' . $id );
		$submission = $this->repository->get( $id );
		$form       = $submission ? $this->registry->get( $submission->form_id ) : null;
		if ( ! $submission || ! $form || 'spam' !== $submission->status ) {
			wp_die( esc_html__( 'Este envio não está disponível para aprovação.', 'wp-formvault' ) );
		}
		if ( ! $this->repository->approve_spam( $id ) ) {
			wp_die( esc_html__( 'O envio já foi revisado por outro administrador.', 'wp-formvault' ) );
		}

		$fields = json_decode( $submission->fields, true );
		$fields = is_array( $fields ) ? $fields : array();
		do_action( 'wpfv_submission_stored', $id, $fields, $form );
		$this->manager->process( $id, $form );
		$this->repository->log( 'success', 'antispam', 'Contato aprovado manualmente.', array( 'reviewed_by' => get_current_user_id() ), $id );
		wp_safe_redirect( admin_url( 'admin.php?page=wpfv-submissions&view=' . $id . '&approved=1' ) );
		exit;
	}

	private function submission_detail( $id ) {
		$item = $this->repository->get( $id );
		if ( ! $item ) { wp_die( esc_html__( 'Envio não encontrado.', 'wp-formvault' ) ); }
		$fields      = $this->filled_values( json_decode( $item->fields, true ) );
		$attribution = $this->filled_values( json_decode( $item->attribution, true ) );
		$risk_data   = $this->filled_values( json_decode( $item->risk_data, true ) );
		$deliveries  = $this->repository->get_deliveries( $id );
		$form         = $this->registry->get( $item->form_id );
		$submitted_at = get_date_from_gmt( $item->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
		$first_utm    = $this->extract_values( $attribution, array( 'first_utm_source', 'first_normalized_source', 'first_utm_medium', 'first_utm_channel', 'first_utm_campaign', 'first_utm_term', 'first_utm_content', 'first_attribution_method' ) );
		$last_utm     = $this->extract_values( $attribution, array( 'last_utm_source', 'last_normalized_source', 'last_utm_medium', 'last_utm_channel', 'last_utm_campaign', 'last_utm_term', 'last_utm_content', 'last_attribution_method' ) );
		$ads          = $this->extract_values( $attribution, array( 'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', 'ttclid' ) );
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
			<?php if ( isset( $_GET['retried'] ) ) : $retried = sanitize_key( wp_unslash( $_GET['retried'] ) ); ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( 'rdcrm_note' === $retried ? 'Anotação do RD CRM reenviada. O resultado da nova tentativa aparece abaixo.' : ucfirst( $retried ) . ' reenviado. O resultado da nova tentativa aparece abaixo.' ); ?></p></div><?php endif; ?>
			<?php if ( isset( $_GET['approved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Contato aprovado e enviado às integrações configuradas.</p></div><?php endif; ?>
			<?php if ( 'spam' === $item->status ) : ?>
				<div class="notice notice-warning inline wpfv-spam-review"><p><strong>Este contato está em quarentena.</strong> Nenhuma integração foi processada.</p><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Aprovar este contato e enviá-lo às integrações configuradas?');"><input type="hidden" name="action" value="wpfv_approve_spam"><input type="hidden" name="submission_id" value="<?php echo (int) $item->id; ?>"><?php wp_nonce_field( 'wpfv_approve_spam_' . (int) $item->id ); ?><button type="submit" class="button button-primary">Aprovar como legítimo</button></form></div>
			<?php endif; ?>

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
				$this->detail_card( 'Análise antispam', 'dashicons-shield', $this->risk_display_values( $risk_data ) );
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

	private function risk_display_values( $risk ) {
		if ( ! is_array( $risk ) || empty( $risk ) ) {
			return array();
		}
		$labels = array(
			'accepted'               => 'Aceito',
			'low_score'              => 'Pontuação abaixo do limite',
			'missing_token'          => 'Token ausente',
			'invalid_token'          => 'Token inválido ou vencido',
			'action_mismatch'        => 'Ação inesperada',
			'hostname_mismatch'      => 'Domínio inesperado',
			'missing_credentials'    => 'Credenciais incompletas',
			'provider_configuration' => 'Erro de configuração do Google',
			'provider_unavailable'   => 'Google temporariamente indisponível',
			'network_error'          => 'Falha de rede com o Google',
			'not_enabled'            => 'reCAPTCHA não habilitado',
		);
		$reason = isset( $risk['reason'] ) ? sanitize_key( $risk['reason'] ) : '';
		$values = array(
			'recaptcha_result' => array( 'label' => 'Resultado', 'value' => isset( $labels[ $reason ] ) ? $labels[ $reason ] : $reason ),
		);
		if ( isset( $risk['score'] ) && is_numeric( $risk['score'] ) ) {
			$values['recaptcha_score'] = array( 'label' => 'Score', 'value' => number_format_i18n( (float) $risk['score'], 2 ) );
		}
		if ( ! empty( $risk['reasons'] ) && is_array( $risk['reasons'] ) ) {
			$values['recaptcha_reasons'] = array( 'label' => 'Sinais do Google', 'value' => implode( ', ', array_map( 'sanitize_text_field', $risk['reasons'] ) ) );
		}
		if ( ! empty( $risk['provider_reason'] ) && is_scalar( $risk['provider_reason'] ) ) {
			$values['recaptcha_provider_reason'] = array( 'label' => 'Motivo do token', 'value' => sanitize_text_field( $risk['provider_reason'] ) );
		}
		if ( ! empty( $risk['provider_detail'] ) && is_scalar( $risk['provider_detail'] ) ) {
			$values['recaptcha_provider_detail'] = array( 'label' => 'Diagnóstico do Google', 'value' => sanitize_text_field( $risk['provider_detail'] ) );
		}
		if ( ! empty( $risk['assessment_name'] ) && is_scalar( $risk['assessment_name'] ) ) {
			$values['recaptcha_assessment'] = array( 'label' => 'Identificador da avaliação', 'value' => sanitize_text_field( $risk['assessment_name'] ) );
		}
		return $values;
	}

	private function detail_card( $title, $icon, $values, $form = null ) {
		$values = $this->filled_values( $values );
		if ( ! $values ) { return; }
		$labels = array(
			'landing_url' => 'Página de entrada', 'landing_referrer' => 'Referência de entrada', 'submission_url' => 'URL do envio', 'submission_title' => 'Título da página',
			'first_utm_source' => 'Origem', 'first_normalized_source' => 'Origem normalizada', 'first_utm_medium' => 'Mídia', 'first_utm_channel' => 'Canal', 'first_utm_campaign' => 'Campanha', 'first_utm_term' => 'Termo', 'first_utm_content' => 'Conteúdo', 'first_attribution_method' => 'Método de atribuição',
			'last_utm_source' => 'Origem', 'last_normalized_source' => 'Origem normalizada', 'last_utm_medium' => 'Mídia', 'last_utm_channel' => 'Canal', 'last_utm_campaign' => 'Campanha', 'last_utm_term' => 'Termo', 'last_utm_content' => 'Conteúdo', 'last_attribution_method' => 'Método de atribuição',
			'gclid' => 'Google Click ID', 'gbraid' => 'Google GBRAID', 'wbraid' => 'Google WBRAID', 'fbclid' => 'Meta Click ID', 'msclkid' => 'Microsoft Click ID', 'ttclid' => 'TikTok Click ID',
		);
		?>
		<section class="card wpfv-admin-card wpfv-detail-card">
			<div class="wpfv-card-header"><h2><span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span><?php echo esc_html( $title ); ?></h2></div>
			<div class="wpfv-card-body"><dl class="wpfv-detail-list">
			<?php foreach ( $values as $key => $raw_value ) :
				$is_structured = is_array( $raw_value ) && isset( $raw_value['value'] );
				$value         = $is_structured ? $raw_value['value'] : $raw_value;
				if ( false !== strpos( $key, 'attribution_method' ) ) { $value = $this->attribution_method_label( $value ); }
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

	private function retry_url( $submission_id, $integration, $return_to_detail = false ) {
		$url = admin_url( 'admin-post.php?action=wpfv_retry_delivery&submission_id=' . absint( $submission_id ) . '&integration=' . sanitize_key( $integration ) );
		if ( $return_to_detail ) { $url = add_query_arg( 'return_to_detail', '1', $url ); }
		return wp_nonce_url( $url, 'wpfv_retry_' . absint( $submission_id ) . '_' . sanitize_key( $integration ) );
	}

	private function rdcrm_note_retry_url( $submission_id, $return_to_detail = false ) {
		$url = admin_url( 'admin-post.php?action=wpfv_retry_rdcrm_note&submission_id=' . absint( $submission_id ) );
		if ( $return_to_detail ) { $url = add_query_arg( 'return_to_detail', '1', $url ); }
		return wp_nonce_url( $url, 'wpfv_retry_rdcrm_note_' . absint( $submission_id ) );
	}

	private function rdcrm_note_failure( $delivery ) {
		if ( ! $delivery || 'error' !== $delivery->status || 'rdcrm' !== sanitize_key( $delivery->integration ) ) { return false; }
		$body = json_decode( (string) $delivery->response_body, true );
		if ( ! is_array( $body ) || 'note' !== ( isset( $body['operation'] ) ? $body['operation'] : '' ) ) { return false; }
		$deal_id = isset( $body['deal_id'] ) ? (string) $body['deal_id'] : '';
		return preg_match( '/^[0-9a-f]{24}$/i', $deal_id ) ? array( 'deal_id' => $deal_id ) : false;
	}

	private function integration_label( $integration ) {
		$integration = sanitize_key( $integration );
		return 'rdcrm' === $integration ? 'RD CRM' : ucfirst( $integration );
	}

	private function status_label( $status ) {
		return 'success' === $status ? 'Sucesso' : ( 'error' === $status ? 'Erro' : 'Pendente' );
	}

	private function integration_summary( $submission, $integration, $delivery ) {
		$status      = in_array( $delivery->status, array( 'success', 'error', 'pending' ), true ) ? $delivery->status : 'pending';
		$can_resend  = 'error' === $status || 'webhook' === $integration;
		$note_failure = 'rdcrm' === $integration && $this->rdcrm_note_failure( $delivery );
		$button_text = $note_failure ? 'Tentar novamente a anotação' : ( 'webhook' === $integration ? 'Reenviar webhook' : 'Tentar novamente' );
		$retry_url = $note_failure ? $this->rdcrm_note_retry_url( $submission->id, true ) : $this->retry_url( $submission->id, $integration, true );
		?>
		<div class="wpfv-delivery-item">
			<div><strong><?php echo esc_html( $this->integration_label( $integration ) ); ?></strong><span class="wpfv-badge wpfv-badge--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $this->status_label( $status ) ); ?><?php echo $delivery->response_code ? ' · HTTP ' . (int) $delivery->response_code : ''; ?></span></div>
			<p>Última tentativa: <?php echo esc_html( get_date_from_gmt( $delivery->updated_at, 'd/m/Y H:i:s' ) ); ?></p>
			<?php if ( $this->has_value( $delivery->error_message ) ) : ?><p class="wpfv-delivery-error"><?php echo esc_html( $delivery->error_message ); ?></p><?php endif; ?>
			<?php if ( $can_resend ) : ?><a class="button button-secondary" href="<?php echo esc_url( $retry_url ); ?>"><span class="dashicons dashicons-update" aria-hidden="true"></span><?php echo esc_html( $button_text ); ?></a><?php endif; ?>
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
		$antispam             = $settings['antispam'];
		$global_settings      = WPFV_Settings::get_global();
		$pipedrive_configured = ! empty( $global_settings['pipedrive_domain'] ) && ! empty( $global_settings['pipedrive_token'] );
		$brevo_configured     = ! empty( $global_settings['brevo_api_key'] );
		$rdcrm_configured     = ( new WPFV_RDCRM_API() )->connected();
		$recaptcha_configured = WPFV_Recaptcha::configured();
		$antispam_metrics     = WPFV_Antispam::metrics_for_form( $form['id'] );
		?>
		<div class="wrap wpfv-admin-wrap">
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=wpfv-forms' ) ); ?>">&larr; Voltar aos formulários</a></p>
			<h1><?php echo esc_html( $form['name'] ); ?></h1>
			<p class="wpfv-page-description">Configure a captação, os destinos e as regras de validação deste formulário.</p>
			<?php if ( isset( $_GET['updated'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Configurações salvas.</p></div><?php endif; ?>
			<?php if ( isset( $_GET['rdcrm_invalid'] ) ) : ?><div class="notice notice-error"><p>Revise o RD CRM: nome, e-mail, funil, etapa e responsável são obrigatórios.</p></div><?php endif; ?>
			<nav class="nav-tab-wrapper" aria-label="Seções de configuração">
				<a href="#wpfv-general" class="nav-tab nav-tab-active wpfv-nav-tab">Geral</a>
				<a href="#wpfv-webhook" class="nav-tab wpfv-nav-tab">Webhook</a>
				<?php if ( $pipedrive_configured ) : ?>
					<a href="#wpfv-pipedrive" class="nav-tab wpfv-nav-tab">Pipedrive</a>
				<?php endif; ?>
				<?php if ( $brevo_configured ) : ?>
					<a href="#wpfv-brevo" class="nav-tab wpfv-nav-tab">Brevo</a>
				<?php endif; ?>
				<?php if ( $rdcrm_configured ) : ?><a href="#wpfv-rdcrm" class="nav-tab wpfv-nav-tab">RD CRM</a><?php endif; ?>
				<a href="#wpfv-validation" class="nav-tab wpfv-nav-tab">Validações</a>
				<a href="#wpfv-antispam" class="nav-tab wpfv-nav-tab">Antispam</a>
			</nav>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wpfv_save_form_' . $form['id'] ); ?>
				<input type="hidden" name="action" value="wpfv_save_form_settings">
				<input type="hidden" name="form_id" value="<?php echo esc_attr( $form['id'] ); ?>">

				<section id="wpfv-general" class="wpfv-tab-panel">
					<div class="card wpfv-admin-card"><div class="wpfv-card-header"><h2>Captação e armazenamento</h2><p>Controle o registro dos contatos recebidos.</p></div><div class="wpfv-card-body"><label><input type="checkbox" name="active" value="1" <?php checked( $settings['active'] ); ?>> Armazenar novos envios deste formulário</label></div></div>
					<div class="card wpfv-admin-card"><div class="wpfv-card-header"><h2>Notificação por e-mail</h2><p>Envie uma cópia do contato para sua equipe.</p></div><div class="wpfv-card-body"><table class="form-table" role="presentation"><tr><th scope="row">Ativação</th><td><label><input type="checkbox" name="email_enabled" value="1" <?php checked( $settings['email_enabled'] ); ?>> Enviar notificação por e-mail</label></td></tr><tr><th scope="row"><label for="wpfv-email-recipients">Destinatários</label></th><td><input id="wpfv-email-recipients" class="large-text" type="text" name="email_recipients" value="<?php echo esc_attr( $settings['email_recipients'] ); ?>"><p class="description">Separe múltiplos endereços por vírgula.</p></td></tr><tr><th scope="row"><label for="wpfv-email-subject">Assunto</label></th><td><input id="wpfv-email-subject" class="large-text" type="text" name="email_subject" value="<?php echo esc_attr( $settings['email_subject'] ); ?>"><?php $this->template_variable_picker( 'wpfv-email-subject', array( '{site_name}' => 'Nome do site', '{form_name}' => 'Nome do formulário', '{form_id}' => 'ID do formulário' ) ); ?></td></tr></table></div></div>
				</section>

				<section id="wpfv-webhook" class="wpfv-tab-panel" hidden>
					<div class="card wpfv-admin-card"><div class="wpfv-card-header"><h2>Entrega via webhook</h2><p>Envie o contato em JSON para uma aplicação externa.</p></div><div class="wpfv-card-body"><table class="form-table" role="presentation"><tr><th scope="row">Ativação</th><td><label><input type="checkbox" name="webhook_enabled" value="1" <?php checked( $settings['webhook_enabled'] ); ?>> Enviar webhook</label></td></tr><tr><th scope="row"><label for="wpfv-webhook-url">URL</label></th><td><input id="wpfv-webhook-url" class="large-text code" type="url" name="webhook_url" value="<?php echo esc_attr( $settings['webhook_url'] ); ?>"></td></tr><tr><th scope="row"><label for="wpfv-webhook-secret">Segredo HMAC</label></th><td><input id="wpfv-webhook-secret" class="large-text" type="password" autocomplete="new-password" name="webhook_secret" value="" placeholder="<?php echo $settings['webhook_secret'] ? esc_attr__( 'Segredo configurado — deixe vazio para manter', 'wp-formvault' ) : ''; ?>"><p class="description">Assina o corpo no header <code>X-WP-FormVault-Signature</code>.</p></td></tr></table></div></div>
					<div class="card wpfv-admin-card"><div class="wpfv-card-header"><h2>Mapeamento de campos</h2><p>Renomeie as chaves enviadas no objeto <code>form.fields</code>. Cada origem e destino deve ser único.</p></div><div class="wpfv-card-body"><?php $this->mapping_rows( 'webhook', $settings['webhook_field_map'], $form, false ); ?></div></div>
				</section>

				<?php if ( $pipedrive_configured ) : ?>
				<section id="wpfv-pipedrive" class="wpfv-tab-panel" hidden>
					<div class="card wpfv-admin-card"><div class="wpfv-card-header"><h2>Negociação no Pipedrive</h2><p>Crie organização, pessoa e negócio a partir do contato.</p></div><div class="wpfv-card-body"><p><label><input type="checkbox" name="pipedrive_enabled" value="1" <?php checked( $settings['pipedrive_enabled'] ); ?>> Habilitar integração com o Pipedrive</label></p><div class="wpfv-inline-fields"><label for="wpfv-pipeline">Pipeline</label><select id="wpfv-pipeline" class="regular-text" name="pipedrive_pipeline_id" data-selected="<?php echo absint( $settings['pipedrive_pipeline_id'] ); ?>"><option value="<?php echo absint( $settings['pipedrive_pipeline_id'] ); ?>">— Carregando pipelines —</option></select><label for="wpfv-stage">Etapa</label><select id="wpfv-stage" class="regular-text" name="pipedrive_stage_id" data-selected="<?php echo absint( $settings['pipedrive_stage_id'] ); ?>"><option value="<?php echo absint( $settings['pipedrive_stage_id'] ); ?>">— Selecione o pipeline —</option></select></div><p class="wpfv-connection-status" id="wpfv-pipedrive-catalog-status"></p><p><label for="wpfv-deal-title"><strong>Título da negociação</strong></label><br><input id="wpfv-deal-title" class="large-text" type="text" name="pipedrive_deal_title" value="<?php echo esc_attr( $settings['pipedrive_deal_title'] ); ?>"></p></div></div>
					<div class="card wpfv-admin-card"><div class="wpfv-card-header"><h2>Mapeamento do Pipedrive</h2><p>Associe os campos recebidos às propriedades de cada entidade.</p></div><div class="wpfv-card-body"><?php $this->pipedrive_rows( $settings['pipedrive_field_map'], $form ); ?><p class="description">Os campos são carregados automaticamente após configurar e testar a conexão.</p></div></div>
				</section>
				<?php endif; ?>

				<?php if ( $rdcrm_configured ) : ?>
				<section id="wpfv-rdcrm" class="wpfv-tab-panel" hidden>
					<div class="card wpfv-admin-card"><div class="wpfv-card-header"><h2>Negociação no RD CRM</h2><p>Crie ou atualize o contato e abra uma negociação associada.</p></div><div class="wpfv-card-body">
						<p><label><input type="checkbox" name="rdcrm_enabled" value="1" <?php checked($settings['rdcrm_enabled']); ?>> Habilitar integração com o RD CRM</label></p><input type="hidden" name="rdcrm_catalog_ready" id="wpfv-rdcrm-catalog-ready" value="0">
						<div class="wpfv-inline-fields"><label>Funil <select id="wpfv-rdcrm-pipeline" name="rdcrm_pipeline_id" data-selected="<?php echo esc_attr($settings['rdcrm_pipeline_id']); ?>"><option value="<?php echo esc_attr($settings['rdcrm_pipeline_id']); ?>">— <?php echo $settings['rdcrm_pipeline_id'] ? 'Funil salvo; carregando' : 'Carregando'; ?> —</option></select></label><label>Etapa <select id="wpfv-rdcrm-stage" name="rdcrm_stage_id" data-selected="<?php echo esc_attr($settings['rdcrm_stage_id']); ?>"><option value="<?php echo esc_attr($settings['rdcrm_stage_id']); ?>">— <?php echo $settings['rdcrm_stage_id'] ? 'Etapa salva; carregando' : 'Selecione o funil'; ?> —</option></select></label><label>Responsável <select id="wpfv-rdcrm-owner" name="rdcrm_owner_id" data-selected="<?php echo esc_attr($settings['rdcrm_owner_id']); ?>"><option value="<?php echo esc_attr($settings['rdcrm_owner_id']); ?>">— <?php echo $settings['rdcrm_owner_id'] ? 'Responsável salvo; carregando' : 'Carregando'; ?> —</option></select></label></div>
						<p id="wpfv-rdcrm-catalog-status" class="wpfv-connection-status" role="status"></p><p><button type="button" class="button button-secondary" id="wpfv-rdcrm-refresh-catalogs">Atualizar dados do RD CRM</button> <span class="description">As listas são guardadas por 10 minutos.</span></p><p><label for="wpfv-rdcrm-deal-title"><strong>Título da negociação</strong></label><br><input id="wpfv-rdcrm-deal-title" class="large-text" type="text" name="rdcrm_deal_title" value="<?php echo esc_attr($settings['rdcrm_deal_title']); ?>"></p><?php $this->template_variable_picker( 'wpfv-rdcrm-deal-title', $this->form_template_variables( $form ) ); ?>
						</div></div>
						<div class="card wpfv-admin-card"><div class="wpfv-card-header"><h2>Mapeamento do RD CRM</h2><p>Nome e e-mail do contato são obrigatórios. A empresa só é consultada ou cadastrada quando o nome da empresa está mapeado e preenchido. Campos obrigatórios no RD CRM também precisam ser mapeados e preenchidos.</p></div><div class="wpfv-card-body"><?php $this->rdcrm_rows($settings['rdcrm_field_map'],$form); ?><p class="description">Se o formulário não tiver um campo correspondente, escolha “Valor fixo”. Para campos de seleção, use uma opção aceita pelo RD CRM.</p></div></div>
						<div class="card wpfv-admin-card"><div class="wpfv-card-header"><h2>Anotação no histórico</h2><p>Monte uma mensagem com dados do formulário para aparecer no histórico da negociação.</p></div><div class="wpfv-card-body">
							<p><label for="wpfv-rdcrm-note-template"><strong>Modelo da anotação</strong></label><br><textarea id="wpfv-rdcrm-note-template" class="large-text code" rows="8" maxlength="524288" name="rdcrm_note_template" placeholder="Necessidade informada:&#10;{campo_id}"><?php echo esc_textarea($settings['rdcrm_note_template']); ?></textarea></p>
							<p class="description">Deixe vazio para não criar uma anotação.</p>
							<?php $this->template_variable_picker( 'wpfv-rdcrm-note-template', $this->form_template_variables( $form ) ); ?>
						</div></div>
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
			<section id="wpfv-antispam" class="wpfv-tab-panel" hidden>
				<?php if ( ! $recaptcha_configured ) : ?><div class="notice notice-warning inline"><p>Configure o projeto e as chaves do Google reCAPTCHA em <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpfv-settings#wpfv-settings-antispam' ) ); ?>">Configurações</a> antes de ativar esta proteção.</p></div><?php endif; ?>
				<div class="wpfv-validation-grid">
					<div class="card wpfv-admin-card"><div class="wpfv-card-header"><h2>Google reCAPTCHA</h2><p>Avalie cada envio sem apresentar um desafio visual.</p></div><div class="wpfv-card-body"><p><label><input type="checkbox" name="recaptcha_enabled" value="1" <?php checked( $antispam['recaptcha_enabled'] ); ?> <?php disabled( ! $recaptcha_configured ); ?>> Habilitar reCAPTCHA neste formulário</label></p><p><label for="wpfv-score-threshold"><strong>Score mínimo</strong></label><br><input id="wpfv-score-threshold" class="small-text" type="number" name="recaptcha_score_threshold" min="0" max="1" step="0.1" value="<?php echo esc_attr( $antispam['score_threshold'] ); ?>"></p><p class="description">Comece com 0,5. Valores maiores são mais rigorosos; scores inferiores ficam em quarentena.</p></div></div>
					<div class="card wpfv-admin-card"><div class="wpfv-card-header"><h2>Proteções locais</h2><p>Ativas automaticamente, mesmo quando o Google estiver indisponível.</p></div><div class="wpfv-card-body"><ul><li>Honeypot e tempo mínimo de <?php echo absint( WPFV_Antispam::MIN_SECONDS ); ?> segundos</li><li>Máximo de <?php echo absint( WPFV_Antispam::RATE_LIMIT ); ?> envios por IP a cada 10 minutos</li><li>Supressão de payloads repetidos por 10 minutos</li><li>Spam em quarentena por 90 dias</li></ul><?php if ( $antispam_metrics ) : ?><details><summary>Ver bloqueios e alertas acumulados</summary><dl class="wpfv-antispam-metrics"><?php foreach ( $antispam_metrics as $reason => $count ) : ?><div><dt><?php echo esc_html( str_replace( '_', ' ', $reason ) ); ?></dt><dd><?php echo absint( $count ); ?></dd></div><?php endforeach; ?></dl></details><?php else : ?><p class="description">Nenhum evento antispam contabilizado ainda.</p><?php endif; ?></div></div>
				</div>
			</section>
				<div class="wpfv-actions"><?php submit_button( 'Salvar configurações', 'primary', 'submit', false ); ?></div>
			</form>
		</div>
		<?php
	}

	private function field_options( $form, $selected = '' ) { foreach ( $form['fields'] as $id => $field ) { echo '<option value="' . esc_attr( $id ) . '" ' . selected( $selected, $id, false ) . '>' . esc_html( $field['label'] . ' (' . $id . ')' ) . '</option>'; } }
	private function form_template_variables( $form ) {
		$variables = array();
		foreach ( $form['fields'] as $field_id => $field ) {
			$variables[ '{' . $field_id . '}' ] = $field['label'];
		}
		return $variables;
	}
	private function template_variable_picker( $target_id, $variables ) {
		echo '<details class="wpfv-variable-picker"><summary>Usar variáveis</summary><div class="wpfv-variable-picker-buttons">';
		foreach ( $variables as $token => $label ) {
			echo '<button type="button" class="button button-secondary wpfv-insert-template-token" data-target="' . esc_attr( $target_id ) . '" data-token="' . esc_attr( $token ) . '">' . esc_html( $label ) . ' <code>' . esc_html( $token ) . '</code></button>';
		}
		echo '</div><p class="description wpfv-variable-picker-status" role="status" aria-live="polite"></p></details>';
	}
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
	private function rdcrm_rows($rows,$form){$rows=$rows?$rows:array(array());echo '<div class="wpfv-rd-mapping"><table class="widefat striped wpfv-mapping-table"><thead><tr><th>Campo do formulário ou valor fixo</th><th>Entidade</th><th>Destino no RD CRM</th><th>Ações</th></tr></thead><tbody>';foreach($rows as $r){echo '<tr>';$this->rdcrm_cells($r,$form);echo '</tr>';}echo '</tbody></table><p><button type="button" class="button wpfv-add-rd-mapping">Adicionar campo</button></p><template class="wpfv-rd-template"><tr>';$this->rdcrm_cells(array(),$form);echo '</tr></template></div>';}
	private function rdcrm_cells($r,$form){
		$source=isset($r['source'])?$r['source']:'';
		$target=isset($r['target'])?$r['target']:'';
		$fixed='__fixed__'===$source;
		echo '<td><select name="rd_source[]" class="wpfv-rd-source" aria-label="Origem do mapeamento"><option value="">— Selecione —</option><option value="__fixed__" '.selected($source,'__fixed__',false).'>Valor fixo</option>';
		$this->field_options($form,$source);
		echo '</select><div class="wpfv-rd-fixed-controls"'.($fixed?'':' hidden').'><input class="regular-text wpfv-rd-fixed-value" type="text" name="rd_fixed_value[]" value="'.esc_attr(isset($r['fixed_value'])?$r['fixed_value']:'').'" placeholder="Valor enviado ao RD CRM" aria-label="Valor fixo"><label class="wpfv-rd-fixed-format"'.($fixed&&0===strpos($target,'custom:')?'':' hidden').'>Formato <select name="rd_fixed_list[]" class="wpfv-rd-fixed-list"><option value="0" '.selected(empty($r['fixed_list']),true,false).'>Valor simples</option><option value="1" '.selected(!empty($r['fixed_list']),true,false).'>Lista com um valor</option></select></label></div></td>';
		echo '<td><select name="rd_entity[]" class="wpfv-rd-entity"><option value="contact" '.selected(isset($r['entity'])?$r['entity']:'contact','contact',false).'>Contato</option><option value="organization" '.selected(isset($r['entity'])?$r['entity']:'','organization',false).'>Empresa</option><option value="deal" '.selected(isset($r['entity'])?$r['entity']:'','deal',false).'>Negociação</option></select></td>';
		echo '<td><select class="regular-text wpfv-rd-target" name="rd_target[]" data-selected="'.esc_attr($target).'"><option value="'.esc_attr($target).'">— Carregando campos —</option></select></td><td><button type="button" class="button-link-delete wpfv-remove-rd-mapping">Remover</button></td>';
	}
	private function validation_textarea( $name, $label, $validation ) { $id = 'wpfv-' . sanitize_html_class( str_replace( '_', '-', $name ) ); echo '<p><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><br><textarea id="' . esc_attr( $id ) . '" class="large-text code" rows="5" name="' . esc_attr( $name ) . '">' . esc_textarea( implode( "\n", (array) $validation[$name] ) ) . '</textarea></p>'; }

	public function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$s     = WPFV_Settings::get_global();
		$nonce = wp_create_nonce( 'wpfv_api_test' );
		$recaptcha_health = WPFV_Recaptcha::health();
		$rdcrm_health = WPFV_RDCRM_API::health();
		?>
		<div class="wrap wpfv-admin-wrap">
			<h1>Configurações de Contatos</h1>
			<p class="wpfv-page-description">Conecte os serviços externos e defina como os eventos técnicos serão registrados.</p>
			<?php if ( isset( $_GET['updated'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Configurações salvas.</p></div><?php endif; ?>
			<?php if ( isset( $_GET['rdcrm'] ) ) : ?><div class="notice notice-<?php echo 'connected'===sanitize_key(wp_unslash($_GET['rdcrm']))?'success':'warning'; ?> is-dismissible"><p><?php echo 'connected'===sanitize_key(wp_unslash($_GET['rdcrm']))?'RD CRM conectado com sucesso.':'Estado da conexão do RD CRM atualizado.'; ?></p></div><?php endif; ?>
			<?php $rdcrm_error = get_transient( 'wpfv_rdcrm_error_' . get_current_user_id() ); if ( $rdcrm_error && false !== strpos( $rdcrm_error, '(HTTP 429)' ) ) { delete_transient( 'wpfv_rdcrm_error_' . get_current_user_id() ); $rdcrm_error = false; } if ( $rdcrm_error ) : ?><div class="notice notice-error"><p><?php echo esc_html( $rdcrm_error ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpfv-logs' ) ); ?>">Ver logs</a></p><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="wpfv_dismiss_rdcrm_error"><?php wp_nonce_field( 'wpfv_dismiss_rdcrm_error' ); ?><p><button type="submit" class="button">Dispensar aviso</button></p></form></div><?php endif; ?>
			<?php if ( ! $rdcrm_error && ( new WPFV_RDCRM_API() )->connected() && isset( $rdcrm_health['status'] ) && 'error' === $rdcrm_health['status'] ) : ?><div class="notice notice-error"><p><?php echo esc_html( $rdcrm_health['message'] ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpfv-logs' ) ); ?>">Ver logs</a></p></div><?php endif; ?>
			<nav class="nav-tab-wrapper" aria-label="Seções de configuração"><a href="#wpfv-settings-logs" class="nav-tab nav-tab-active wpfv-nav-tab">Logs</a><a href="#wpfv-settings-antispam" class="nav-tab wpfv-nav-tab">Antispam</a><a href="#wpfv-settings-integrations" class="nav-tab wpfv-nav-tab">Integrações</a></nav>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wpfv_save_global' ); ?>
				<input type="hidden" name="action" value="wpfv_save_global_settings">
				<section id="wpfv-settings-logs" class="wpfv-tab-panel"><div class="card wpfv-admin-card"><div class="wpfv-card-header"><h2>Logs</h2><p>Defina por quanto tempo os eventos das integrações serão mantidos.</p></div><div class="wpfv-card-body"><table class="form-table" role="presentation"><tr><th scope="row">Registro</th><td><label><input type="checkbox" name="logging_enabled" value="1" <?php checked( $s['logging_enabled'] ); ?>> Registrar eventos das integrações</label></td></tr><tr><th scope="row"><label for="wpfv-retention">Retenção</label></th><td><input id="wpfv-retention" class="small-text" type="number" min="1" max="3650" name="log_retention_days" value="<?php echo absint( $s['log_retention_days'] ); ?>"> dias</td></tr></table></div></div></section>
				<section id="wpfv-settings-antispam" class="wpfv-tab-panel" hidden><div class="wpfv-settings-stack">
					<?php if ( ! empty( $recaptcha_health['message'] ) ) : ?><div class="notice notice-<?php echo esc_attr( 'error' === $recaptcha_health['status'] ? 'error' : ( 'warning' === $recaptcha_health['status'] ? 'warning' : 'success' ) ); ?> inline"><p><?php echo esc_html( $recaptcha_health['message'] ); ?><?php if ( ! empty( $recaptcha_health['detail'] ) ) : ?> <strong>Diagnóstico:</strong> <?php echo esc_html( $recaptcha_health['detail'] ); ?>.<?php endif; ?><?php if ( ! empty( $recaptcha_health['time'] ) ) : ?> <small>Último evento: <?php echo esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', absint( $recaptcha_health['time'] ) ), 'd/m/Y H:i' ) ); ?>.</small><?php endif; ?></p></div><?php endif; ?>
					<div class="card wpfv-admin-card"><div class="wpfv-card-header"><h2>Google Cloud reCAPTCHA</h2><p>Use um projeto pertencente ao cliente e uma chave baseada em pontuação.</p></div><div class="wpfv-card-body"><table class="form-table" role="presentation"><tr><th scope="row"><label for="wpfv-recaptcha-project">Project ID</label></th><td><input id="wpfv-recaptcha-project" class="large-text code" type="text" name="recaptcha_project_id" value="<?php echo esc_attr( $s['recaptcha_project_id'] ); ?>" autocomplete="off"></td></tr><tr><th scope="row"><label for="wpfv-recaptcha-site-key">Site key</label></th><td><input id="wpfv-recaptcha-site-key" class="large-text code" type="text" name="recaptcha_site_key" value="<?php echo esc_attr( $s['recaptcha_site_key'] ); ?>" autocomplete="off"></td></tr><tr><th scope="row"><label for="wpfv-recaptcha-api-key">API key</label></th><td><input id="wpfv-recaptcha-api-key" class="large-text" type="password" name="recaptcha_api_key" value="" placeholder="<?php echo $s['recaptcha_api_key'] ? esc_attr__( 'Chave configurada — deixe vazio para manter', 'wp-formvault' ) : ''; ?>" autocomplete="new-password"><p class="description">Use uma chave de API do Google Cloud vinculada ao Project ID; a chave secreta legada do reCAPTCHA não funciona neste campo. Ela nunca é enviada ao navegador nem registrada nos logs.</p><p><button type="button" class="button button-secondary wpfv-test" data-action="wpfv_test_recaptcha">Testar conexão</button> <span class="wpfv-test-result wpfv-connection-status" role="status"></span></p><p class="description">O teste usa as credenciais já salvas e faz uma avaliação com token inválido de propósito. Ele consome uma chamada da API e não valida o formulário no navegador.</p></td></tr></table><p><a href="https://cloud.google.com/recaptcha/docs/create-key-website" target="_blank" rel="noopener noreferrer">Abrir instruções oficiais do Google <span class="screen-reader-text">(abre em nova aba)</span></a> &nbsp;|&nbsp; <a href="https://www.google.com/recaptcha/admin/create?hl=pt-br" target="_blank" rel="noopener noreferrer">Criar Site key do reCAPTCHA <span class="screen-reader-text">(abre em nova aba)</span></a> &nbsp;|&nbsp; <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer">Criar API key no Google Cloud <span class="screen-reader-text">(abre em nova aba)</span></a></p></div></div>
				</div></section>
				<section id="wpfv-settings-integrations" class="wpfv-tab-panel" hidden><div class="wpfv-settings-stack">
					<div class="card wpfv-admin-card">
						<div class="wpfv-card-header"><h2>Pipedrive</h2><p>Credenciais usadas para consultar campos e criar negociações.</p></div>
						<div class="wpfv-card-body"><table class="form-table" role="presentation"><tr><th scope="row"><label for="wpfv-pipedrive-domain">Company Domain</label></th><td><input id="wpfv-pipedrive-domain" class="large-text" type="text" name="pipedrive_domain" value="<?php echo esc_attr( $s['pipedrive_domain'] ); ?>" placeholder="empresa.pipedrive.com" autocomplete="url" inputmode="url"><p class="description">Informe somente o domínio da conta, sem protocolo.</p></td></tr><tr><th scope="row"><label for="wpfv-pipedrive-token">API Token</label></th><td><input id="wpfv-pipedrive-token" class="large-text" type="password" name="pipedrive_token" value="" placeholder="<?php echo $s['pipedrive_token'] ? 'Token configurado — deixe vazio para manter' : ''; ?>" autocomplete="new-password"><p><button type="button" class="button button-secondary wpfv-test" data-action="wpfv_test_pipedrive">Testar conexão</button> <span class="wpfv-test-result wpfv-connection-status" role="status"></span></p></td></tr></table></div>
					</div>
					<div class="card wpfv-admin-card">
						<div class="wpfv-card-header"><h2>Brevo</h2><p>Credencial usada para criar e atualizar contatos.</p></div>
						<div class="wpfv-card-body"><table class="form-table" role="presentation"><tr><th scope="row"><label for="wpfv-brevo-key">API Key</label></th><td><input id="wpfv-brevo-key" class="large-text" type="password" name="brevo_api_key" value="" placeholder="<?php echo $s['brevo_api_key'] ? 'Chave configurada — deixe vazio para manter' : ''; ?>" autocomplete="new-password"><p><button type="button" class="button button-secondary wpfv-test" data-action="wpfv_test_brevo">Testar conexão</button> <span class="wpfv-test-result wpfv-connection-status" role="status"></span></p></td></tr></table></div>
					</div>
					<div class="notice notice-info inline wpfv-rdcrm-help"><p><strong>Como obter as credenciais do RD CRM</strong></p><ol><li>Acesse o App Publisher da RD Station e clique em <strong>Quero criar um aplicativo</strong>.</li><li>Crie um aplicativo privado e selecione o produto <strong>RD Station CRM</strong>.</li><li>Cadastre exatamente a callback mostrada no card abaixo. Em produção, ela precisa usar HTTPS.</li><li>Salve e copie o <strong>client_id</strong> e o <strong>client_secret</strong> gerados.</li></ol><p><a href="https://developers.rdstation.com/reference/crm-v2-authentication-step-1" target="_blank" rel="noopener noreferrer">Abrir instruções oficiais da RD Station <span class="screen-reader-text">(abre em nova aba)</span></a></p></div>
					<div class="card wpfv-admin-card"><div class="wpfv-card-header"><h2>RD CRM</h2><p>OAuth2 para criar contatos e negociações pela API v2.</p></div><div class="wpfv-card-body"><table class="form-table" role="presentation"><tr><th scope="row"><label for="wpfv-rd-client">Client ID</label></th><td><input id="wpfv-rd-client" class="large-text" type="text" name="rdcrm_client_id" value="<?php echo esc_attr($s['rdcrm_client_id']); ?>" autocomplete="off"></td></tr><tr><th scope="row"><label for="wpfv-rd-secret">Client Secret</label></th><td><input id="wpfv-rd-secret" class="large-text" type="password" name="rdcrm_client_secret" autocomplete="new-password" placeholder="<?php echo $s['rdcrm_client_secret']?'Secret configurado — deixe vazio para manter':''; ?>"></td></tr><tr><th scope="row"><label for="wpfv-rd-callback">Callback</label></th><td><input id="wpfv-rd-callback" class="large-text" type="text" readonly="readonly" aria-readonly="true" value="<?php echo esc_attr(WPFV_RDCRM_API::callback_url()); ?>"><p class="description">Copie exatamente esta URL para o aplicativo no App Publisher da RD Station.</p></td></tr><tr><th scope="row">Estado</th><td><strong><?php echo ! ( new WPFV_RDCRM_API() )->connected() ? 'Desconectado' : ( isset( $rdcrm_health['status'] ) && 'error' === $rdcrm_health['status'] ? 'Falha na renovação' : 'Conectado' ); ?></strong><p><?php if((new WPFV_RDCRM_API())->configured()): ?><a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wpfv_rdcrm_connect'),'wpfv_rdcrm_connect')); ?>">Conectar</a> <?php endif; ?><button type="button" class="button wpfv-test" data-action="wpfv_test_rdcrm">Testar conexão</button> <span class="wpfv-test-result wpfv-connection-status" role="status"></span><?php if((new WPFV_RDCRM_API())->connected()): ?> <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wpfv_rdcrm_disconnect'),'wpfv_rdcrm_disconnect')); ?>">Desconectar</a><?php endif; ?></p><p class="description">Salve Client ID e Client Secret antes de conectar. Secrets e tokens salvos nunca são exibidos.</p></td></tr></table></div></div>
				</div>
				</section>
				<div class="wpfv-actions"><?php submit_button( 'Salvar configurações', 'primary', 'submit', false ); ?></div>
			</form>
		</div>
		<script>document.querySelectorAll('.wpfv-test').forEach(function(b){b.addEventListener('click',function(){var o=b.nextElementSibling;o.textContent='Testando…';var d=new FormData();d.append('action',b.dataset.action);d.append('_ajax_nonce','<?php echo esc_js($nonce);?>');fetch(ajaxurl,{method:'POST',body:d}).then(r=>r.json()).then(r=>{o.textContent=r.data&&r.data.message?r.data.message:(r.success?'Conexão válida.':'Falha sem detalhes.');}).catch(()=>{o.textContent='Não foi possível consultar o servidor. Verifique a conexão e tente novamente.';});});});</script><?php
	}

	public function save_global_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Acesso negado.', 'wp-formvault' ) );
		}
		check_admin_referer( 'wpfv_save_global' );
		$current = WPFV_Settings::get_global();
		$token   = isset( $_POST['pipedrive_token'] ) ? sanitize_text_field( wp_unslash( $_POST['pipedrive_token'] ) ) : '';
		$brevo   = isset( $_POST['brevo_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['brevo_api_key'] ) ) : '';
		$recaptcha_api_key = isset( $_POST['recaptcha_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['recaptcha_api_key'] ) ) : '';
		$rd_secret = isset($_POST['rdcrm_client_secret']) ? sanitize_text_field(wp_unslash($_POST['rdcrm_client_secret'])) : '';
		$rd_client = isset($_POST['rdcrm_client_id']) ? sanitize_text_field(wp_unslash($_POST['rdcrm_client_id'])) : '';
		$credentials_changed = $rd_client !== $current['rdcrm_client_id'] || (''!==$rd_secret && $rd_secret!==$current['rdcrm_client_secret']);

		WPFV_Settings::save_global(
			array(
				'pipedrive_domain'  => isset( $_POST['pipedrive_domain'] ) ? sanitize_text_field( wp_unslash( $_POST['pipedrive_domain'] ) ) : '',
				'pipedrive_token'   => '' !== $token ? $token : $current['pipedrive_token'],
				'brevo_api_key'      => '' !== $brevo ? $brevo : $current['brevo_api_key'],
				'rdcrm_client_id' => $rd_client,
				'rdcrm_client_secret' => ''!==$rd_secret?$rd_secret:$current['rdcrm_client_secret'],
				'rdcrm_access_token' => $credentials_changed?'':$current['rdcrm_access_token'],
				'rdcrm_refresh_token' => $credentials_changed?'':$current['rdcrm_refresh_token'],
				'rdcrm_token_expires_at' => $credentials_changed?0:$current['rdcrm_token_expires_at'],
				'recaptcha_project_id' => isset( $_POST['recaptcha_project_id'] ) ? sanitize_text_field( wp_unslash( $_POST['recaptcha_project_id'] ) ) : '',
				'recaptcha_site_key' => isset( $_POST['recaptcha_site_key'] ) ? sanitize_text_field( wp_unslash( $_POST['recaptcha_site_key'] ) ) : '',
				'recaptcha_api_key' => '' !== $recaptcha_api_key ? $recaptcha_api_key : $current['recaptcha_api_key'],
				'logging_enabled'    => ! empty( $_POST['logging_enabled'] ),
				'log_retention_days' => isset( $_POST['log_retention_days'] ) ? absint( $_POST['log_retention_days'] ) : 90,
			)
		);
		if ( $credentials_changed ) {
			$this->invalidate_rdcrm_catalog_cache();
		}
		wp_safe_redirect( admin_url( 'admin.php?page=wpfv-settings&updated=1#wpfv-settings-integrations' ) );
		exit;
	}
	public function test_pipedrive(){check_ajax_referer('wpfv_api_test');if(!current_user_can('manage_options')){wp_send_json_error();}$r=(new WPFV_Pipedrive_API())->test();$msg=$r['success']?'Conexão válida com o Pipedrive.':$r['error'];if($r['success']){wp_send_json_success(array('message'=>$msg));}wp_send_json_error(array('message'=>$msg));}
	public function test_brevo(){check_ajax_referer('wpfv_api_test');if(!current_user_can('manage_options')){wp_send_json_error();}$r=(new WPFV_Brevo_API())->test();$msg=$r['success']?'Conexão válida com o Brevo.':$r['error'];if($r['success']){wp_send_json_success(array('message'=>$msg));}wp_send_json_error(array('message'=>$msg));}
	public function test_recaptcha() {
		check_ajax_referer( 'wpfv_api_test' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Acesso negado.', 'wp-formvault' ) ), 403 );
		}
		$result = ( new WPFV_Recaptcha() )->test_connection();
		if ( $result['success'] ) {
			wp_send_json_success( array( 'message' => $result['message'] ) );
		}
		wp_send_json_error( array( 'message' => $result['message'] ) );
	}
	public function test_rdcrm(){check_ajax_referer('wpfv_api_test');if(!current_user_can('manage_options'))wp_send_json_error();$r=(new WPFV_RDCRM_API())->test();$m=!empty($r['success'])?'Conexão válida com o RD CRM.':$r['error'];if(empty($r['success']))$this->rdcrm_admin_error('teste de conexão',$r);else {delete_transient('wpfv_rdcrm_error_'.get_current_user_id());WPFV_RDCRM_API::clear_health();}!empty($r['success'])?wp_send_json_success(array('message'=>$m)):wp_send_json_error(array('message'=>$m));}
	public function rdcrm_connect(){if(!current_user_can('manage_options'))wp_die('Acesso negado.');check_admin_referer('wpfv_rdcrm_connect');$api=new WPFV_RDCRM_API();if(!$api->configured())wp_die('Salve as credenciais antes de conectar.');$state=wp_generate_password(48,false,false);set_transient('wpfv_rdcrm_state_'.get_current_user_id(),$state,10*MINUTE_IN_SECONDS);wp_redirect($api->authorization_url($state));exit;}
	public function rdcrm_oauth_callback(){if(!current_user_can('manage_options'))wp_die('Acesso negado.');$state=isset($_GET['state'])?sanitize_text_field(wp_unslash($_GET['state'])):'';$saved=get_transient('wpfv_rdcrm_state_'.get_current_user_id());delete_transient('wpfv_rdcrm_state_'.get_current_user_id());if(!$saved||!hash_equals($saved,$state))wp_die('Estado OAuth inválido.');$code=isset($_GET['code'])?sanitize_text_field(wp_unslash($_GET['code'])):'';$provider_error=isset($_GET['error'])?sanitize_text_field(wp_unslash($_GET['error'])):'';$provider_detail=isset($_GET['error_description'])?sanitize_text_field(wp_unslash($_GET['error_description'])):'';$r=$provider_error?array('success'=>false,'code'=>0,'error'=>'RD CRM: autorização recusada: '.$provider_error.($provider_detail?' — '.$provider_detail:'')):(new WPFV_RDCRM_API())->exchange_code($code);if(empty($r['success']))$this->rdcrm_admin_error('autorização OAuth',$r);else {delete_transient('wpfv_rdcrm_error_'.get_current_user_id());$this->invalidate_rdcrm_catalog_cache();}wp_safe_redirect(admin_url('admin.php?page=wpfv-settings&rdcrm='.(!empty($r['success'])?'connected':'error').'#wpfv-settings-integrations'));exit;}
	public function rdcrm_disconnect(){if(!current_user_can('manage_options'))wp_die('Acesso negado.');check_admin_referer('wpfv_rdcrm_disconnect');(new WPFV_RDCRM_API())->disconnect();$this->invalidate_rdcrm_catalog_cache();wp_safe_redirect(admin_url('admin.php?page=wpfv-settings&rdcrm=disconnected#wpfv-settings-integrations'));exit;}

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
	private function rdcrm_admin_error( $operation, $result ) {
		$message = isset( $result['error'] ) ? sanitize_text_field( $result['error'] ) : 'Falha sem detalhes retornados pelo RD CRM.';
		if ( 429 !== (int) ( isset( $result['code'] ) ? $result['code'] : 0 ) ) {
			set_transient( 'wpfv_rdcrm_error_' . get_current_user_id(), $message, DAY_IN_SECONDS );
		}
		$this->repository->log( 'error', 'rdcrm', 'Falha na configuração do RD CRM.', array(
			'operation' => sanitize_text_field( $operation ),
			'http_status' => isset( $result['code'] ) ? absint( $result['code'] ) : 0,
			'error' => $message,
		) );
	}

	public function dismiss_rdcrm_error() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Acesso negado.', 'wp-formvault' ) );
		}
		check_admin_referer( 'wpfv_dismiss_rdcrm_error' );
		delete_transient( 'wpfv_rdcrm_error_' . get_current_user_id() );
		wp_safe_redirect( admin_url( 'admin.php?page=wpfv-settings' ) );
		exit;
	}

	private function rdcrm_catalog_cache_key( $catalog, $pipeline_id = '', $entity = '' ) {
		$settings = WPFV_Settings::get_global();
		$client_id = isset( $settings['rdcrm_client_id'] ) ? (string) $settings['rdcrm_client_id'] : '';
		$generation = (string) get_option( 'wpfv_rdcrm_catalog_generation', '0' );
		return 'wpfv_rdcat_' . md5( implode( '|', array( $client_id, $generation, $catalog, $pipeline_id, $entity ) ) );
	}

	private function invalidate_rdcrm_catalog_cache() {
		update_option( 'wpfv_rdcrm_catalog_generation', wp_generate_uuid4(), false );
	}

	public function rdcrm_refresh_catalogs() {
		check_ajax_referer( 'wpfv_admin' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Acesso negado.' ), 403 );
		}
		$this->invalidate_rdcrm_catalog_cache();
		wp_send_json_success( array( 'message' => 'As listas do RD CRM serão consultadas novamente.' ) );
	}

	public function rdcrm_catalog() {
		check_ajax_referer( 'wpfv_admin' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Acesso negado.' ), 403 );
		$catalog = isset( $_POST['catalog'] ) ? sanitize_key( wp_unslash( $_POST['catalog'] ) ) : '';
		$api = new WPFV_RDCRM_API();
		$entity = '';
		$id = '';
		if ( 'stages' === $catalog ) $id = isset( $_POST['pipeline_id'] ) ? sanitize_text_field( wp_unslash( $_POST['pipeline_id'] ) ) : '';
		if ( 'fields' === $catalog ) $entity = isset( $_POST['entity'] ) ? sanitize_key( wp_unslash( $_POST['entity'] ) ) : '';
		$cacheable = in_array( $catalog, array( 'pipelines', 'stages', 'users', 'fields' ), true ) && ( 'stages' !== $catalog || '' !== $id ) && ( 'fields' !== $catalog || in_array( $entity, array( 'contact', 'organization', 'deal' ), true ) );
		$cache_key = $cacheable ? $this->rdcrm_catalog_cache_key( $catalog, $id, $entity ) : '';
		$cached = $cache_key ? get_transient( $cache_key ) : false;
		if ( false !== $cached && is_array( $cached ) && isset( $cached['items'] ) ) wp_send_json_success( $cached );
		if ( 'pipelines' === $catalog ) $result = $api->pipelines();
		elseif ( 'stages' === $catalog ) {
			$result = $id ? $api->stages( $id ) : array( 'success' => false, 'code' => 0, 'error' => 'Selecione um funil para carregar as etapas.' );
		} elseif ( 'users' === $catalog ) $result = $api->users();
		elseif ( 'fields' === $catalog ) {
			$result = in_array( $entity, array( 'contact', 'organization', 'deal' ), true ) ? $api->custom_fields( $entity ) : array( 'success' => false, 'code' => 0, 'error' => 'Entidade inválida.' );
		} else $result = array( 'success' => false, 'code' => 0, 'error' => 'Catálogo inválido.' );
		if ( empty( $result['success'] ) ) {
			$this->rdcrm_admin_error( 'catálogo ' . $catalog, $result );
			wp_send_json_error( array( 'message' => $result['error'] ) );
		}
		$raw = isset( $result['data']['data'] ) && is_array( $result['data']['data'] ) ? $result['data']['data'] : array();
		$required_ids = array();
		$required_slugs = array();
		$required_warning = '';
		$required_ok = true;
		if ( 'fields' === $catalog && in_array( $entity, array( 'contact', 'organization', 'deal' ), true ) ) {
			$required_result = $api->required_custom_fields( $entity );
			if ( empty( $required_result['success'] ) ) { $required_warning = 'Não foi possível verificar quais campos personalizados são obrigatórios no RD CRM. Confira as exigências no painel do CRM antes de salvar o mapeamento.'; $required_ok = false; }
			$required_raw = ! empty( $required_result['success'] ) && isset( $required_result['data']['data'] ) && is_array( $required_result['data']['data'] ) ? $required_result['data']['data'] : array();
			foreach ( $required_raw as $required_item ) {
				if ( is_array( $required_item ) && ! empty( $required_item['id'] ) ) $required_ids[ (string) $required_item['id'] ] = true;
				if ( is_array( $required_item ) && ! empty( $required_item['slug'] ) ) $required_slugs[ (string) $required_item['slug'] ] = true;
			}
		}
		$items = array();
		if ( 'fields' === $catalog && 'contact' === $entity ) $items = array( array( 'value' => 'name', 'label' => 'Nome' ), array( 'value' => 'email', 'label' => 'E-mail' ), array( 'value' => 'phone', 'label' => 'Telefone' ), array( 'value' => 'job_title', 'label' => 'Cargo' ) );
		if ( 'fields' === $catalog && 'organization' === $entity ) $items = array( array( 'value' => 'name', 'label' => 'Nome da empresa' ), array( 'value' => 'description', 'label' => 'Descrição' ), array( 'value' => 'url', 'label' => 'Site (URL)' ), array( 'value' => 'address.line', 'label' => 'Endereço' ) );
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) continue;
			$id = isset( $item['id'] ) ? (string) $item['id'] : '';
			$slug = isset( $item['slug'] ) ? (string) $item['slug'] : $id;
			$label = isset( $item['name'] ) ? $item['name'] : ( isset( $item['label'] ) ? $item['label'] : $slug );
			$required = isset( $required_ids[ $id ] ) || isset( $required_slugs[ $slug ] ) || ( isset( $item['required'] ) && in_array( $item['required'], array( true, 1, '1' ), true ) ) || ( isset( $item['is_required'] ) && in_array( $item['is_required'], array( true, 1, '1' ), true ) );
			if ( $required && 'fields' === $catalog ) $label .= ' (obrigatório)';
			$type = isset( $item['type'] ) && is_scalar( $item['type'] ) ? (string) $item['type'] : ( isset( $item['field_type'] ) && is_scalar( $item['field_type'] ) ? (string) $item['field_type'] : '' );
			if ( $id ) $items[] = array( 'value' => 'fields' === $catalog ? 'custom:' . $slug : $id, 'legacy_value' => 'fields' === $catalog ? 'custom:' . $id : $id, 'label' => $label, 'multiple' => false !== stripos( $type, 'multi' ) );
		}
		$payload = array( 'items' => $items, 'warning' => $required_warning );
		if ( $cache_key && $required_ok ) set_transient( $cache_key, $payload, 10 * MINUTE_IN_SECONDS );
		wp_send_json_success( $payload );
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
			<table class="widefat striped wpfv-logs-table">
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
						<td class="wpfv-log-context"><details><summary>Ver JSON</summary><pre><?php echo esc_html( $this->pretty_json( $log->context ) ); ?></pre></details></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
	private function pretty_json( $context ) { $decoded=json_decode((string)$context,true); return JSON_ERROR_NONE===json_last_error()?wp_json_encode($decoded,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):(string)$context; }
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
		$has_rdcrm        = ( new WPFV_RDCRM_API() )->connected();
		$webhook_secret   = isset( $_POST['webhook_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['webhook_secret'] ) ) : '';
		$rd_map           = $has_rdcrm && ! empty( $_POST['rdcrm_catalog_ready'] ) ? $this->sanitize_rd_map() : $current_settings['rdcrm_field_map'];
		$rd_pipeline_id   = $has_rdcrm && ! empty( $_POST['rdcrm_pipeline_id'] ) ? sanitize_text_field( wp_unslash( $_POST['rdcrm_pipeline_id'] ) ) : $current_settings['rdcrm_pipeline_id'];
		$rd_stage_id      = $has_rdcrm && ! empty( $_POST['rdcrm_stage_id'] ) ? sanitize_text_field( wp_unslash( $_POST['rdcrm_stage_id'] ) ) : $current_settings['rdcrm_stage_id'];
		$rd_owner_id      = $has_rdcrm && ! empty( $_POST['rdcrm_owner_id'] ) ? sanitize_text_field( wp_unslash( $_POST['rdcrm_owner_id'] ) ) : $current_settings['rdcrm_owner_id'];
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
				'rdcrm_enabled'=> $has_rdcrm ? !empty($_POST['rdcrm_enabled']) : $current_settings['rdcrm_enabled'],
				'rdcrm_pipeline_id'=> $rd_pipeline_id,
				'rdcrm_stage_id'=> $rd_stage_id,
				'rdcrm_owner_id'=> $rd_owner_id,
				'rdcrm_deal_title'=> $has_rdcrm&&isset($_POST['rdcrm_deal_title'])?sanitize_text_field(wp_unslash($_POST['rdcrm_deal_title'])):$current_settings['rdcrm_deal_title'],
				'rdcrm_note_template'=> $has_rdcrm&&isset($_POST['rdcrm_note_template'])?sanitize_textarea_field(wp_unslash($_POST['rdcrm_note_template'])):$current_settings['rdcrm_note_template'],
				'rdcrm_field_map'=> $rd_map,
				'validation'=> $this->sanitize_validation(),
				'antispam'=> array(
					'recaptcha_enabled' => WPFV_Recaptcha::configured() && ! empty( $_POST['recaptcha_enabled'] ),
					'score_threshold'   => isset( $_POST['recaptcha_score_threshold'] ) ? max( 0, min( 1, (float) wp_unslash( $_POST['recaptcha_score_threshold'] ) ) ) : 0.5,
				),
			)
		);

		$rd_targets=array();foreach($rd_map as $row){if('contact'===$row['entity'])$rd_targets[]=$row['target'];}$rd_invalid=$has_rdcrm&&!empty($_POST['rdcrm_enabled'])&&(!in_array('name',$rd_targets,true)||!in_array('email',$rd_targets,true)||!$rd_pipeline_id||!$rd_stage_id||!$rd_owner_id);
		wp_safe_redirect( admin_url( 'admin.php?page=wpfv-forms&form_id=' . $form_id . '&updated=1'.($rd_invalid?'&rdcrm_invalid=1#wpfv-rdcrm':'')) );
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
			if ( ! isset( $form_fields[ $source ] ) || isset( $renames[ $source ] ) || isset( $targets[ $target ] ) ) {
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
	private function sanitize_rd_map(){
		$s=isset($_POST['rd_source'])?(array)wp_unslash($_POST['rd_source']):array();
		$e=isset($_POST['rd_entity'])?(array)wp_unslash($_POST['rd_entity']):array();
		$t=isset($_POST['rd_target'])?(array)wp_unslash($_POST['rd_target']):array();
		$f=isset($_POST['rd_fixed_value'])?(array)wp_unslash($_POST['rd_fixed_value']):array();
		$l=isset($_POST['rd_fixed_list'])?(array)wp_unslash($_POST['rd_fixed_list']):array();
		$out=array();$seen=array();
		foreach($s as $i=>$source){
			$source=sanitize_key($source);
			$entity=isset($e[$i])?sanitize_key($e[$i]):'';
			$target=isset($t[$i])?sanitize_text_field($t[$i]):'';
			$key=$entity.'|'.$target;
			if(!$source||!in_array($entity,array('contact','organization','deal'),true)||!$target||isset($seen[$key]))continue;
			$row=array('source'=>$source,'entity'=>$entity,'target'=>$target);
			if('__fixed__'===$source){
				$row['fixed_value']=isset($f[$i])?sanitize_text_field($f[$i]):'';
				$row['fixed_list']=0===strpos($target,'custom:')&&!empty($l[$i]);
			}
			$out[]=$row;$seen[$key]=true;
		}
		return $out;
	}
	private function sanitize_validation(){return array('phone_validation'=>!empty($_POST['phone_validation']),'phone_message'=>isset($_POST['phone_message'])?sanitize_text_field(wp_unslash($_POST['phone_message'])):'','email_block_enabled'=>!empty($_POST['email_block_enabled']),'email_block_domains'=>$this->sanitize_lines('email_block_domains'),'email_block_suffixes'=>$this->sanitize_lines('email_block_suffixes'),'email_block_words'=>$this->sanitize_lines('email_block_words'),'email_msg_domains'=>isset($_POST['email_msg_domains'])?sanitize_text_field(wp_unslash($_POST['email_msg_domains'])):'','email_msg_suffixes'=>isset($_POST['email_msg_suffixes'])?sanitize_text_field(wp_unslash($_POST['email_msg_suffixes'])):'','email_msg_words'=>isset($_POST['email_msg_words'])?sanitize_text_field(wp_unslash($_POST['email_msg_words'])):'');}
}
