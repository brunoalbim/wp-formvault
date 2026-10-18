<?php
/**
 * Plugin Name: WP FormVault
 * Plugin URI:  https://github.com/brunoalbim/wp-formvault
 * Description: Captura, valida e armazena formulários criados em código, com notificações por e-mail e webhooks configuráveis.
 * Version:     1.8.9
 * Author:      Bruno Albim
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-formvault
 * Requires at least: 6.5
 * Requires PHP: 7.4
 *
 * @package WPFormVault
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPFV_VERSION', '1.8.9' );
define( 'WPFV_FILE', __FILE__ );
define( 'WPFV_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPFV_URL', plugin_dir_url( __FILE__ ) );

require_once WPFV_DIR . 'includes/class-activator.php';
require_once WPFV_DIR . 'includes/class-form-registry.php';
require_once WPFV_DIR . 'includes/class-settings.php';
require_once WPFV_DIR . 'includes/class-antispam.php';
require_once WPFV_DIR . 'includes/class-recaptcha.php';
require_once WPFV_DIR . 'includes/class-field-mapper.php';
require_once WPFV_DIR . 'includes/class-pipedrive-api.php';
require_once WPFV_DIR . 'includes/class-brevo-api.php';
require_once WPFV_DIR . 'includes/class-rdcrm-api.php';
require_once WPFV_DIR . 'includes/class-submission-repository.php';
require_once WPFV_DIR . 'includes/integrations/interface-integration.php';
require_once WPFV_DIR . 'includes/integrations/class-email-integration.php';
require_once WPFV_DIR . 'includes/integrations/class-webhook-integration.php';
require_once WPFV_DIR . 'includes/integrations/class-pipedrive-integration.php';
require_once WPFV_DIR . 'includes/integrations/class-brevo-integration.php';
require_once WPFV_DIR . 'includes/integrations/class-rdcrm-integration.php';
require_once WPFV_DIR . 'includes/class-integration-manager.php';
require_once WPFV_DIR . 'includes/class-submission-handler.php';
require_once WPFV_DIR . 'includes/class-assets.php';
require_once WPFV_DIR . 'includes/class-admin.php';
require_once WPFV_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'WPFV_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WPFV_Activator', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'WPFV_Plugin', 'instance' ) );

/**
 * Render the hidden fields required by WP FormVault.
 *
 * @param string $form_id Registered form identifier.
 * @return void
 */
function wpfv_form_hidden_fields( $form_id ) {
	$form_id = sanitize_key( $form_id );
	$started = time();
	?>
	<input type="hidden" name="action" value="wpfv_submit_form">
	<input type="hidden" name="wpfv_form_id" value="<?php echo esc_attr( $form_id ); ?>">
	<input type="hidden" name="wpfv_nonce" value="<?php echo esc_attr( wp_create_nonce( 'wpfv_submit_' . $form_id ) ); ?>">
	<input type="hidden" name="wpfv_started_at" value="<?php echo esc_attr( $started ); ?>">
	<input type="hidden" name="wpfv_started_sig" value="<?php echo esc_attr( WPFV_Antispam::timestamp_signature( $form_id, $started ) ); ?>">
	<div class="wpfv-honeypot" aria-hidden="true">
		<label>Não preencha este campo <input type="text" name="wpfv_contact_url" value="" tabindex="-1" autocomplete="off"></label>
	</div>
	<?php
}
