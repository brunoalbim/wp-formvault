<?php
/**
 * Plugin updates from GitHub releases.
 *
 * The plugin header declares `Update URI: https://github.com/brunoalbim/wp-formvault`,
 * so WordPress never asks WordPress.org about this plugin and instead runs the
 * `update_plugins_github.com` filter during its regular update check
 * (wp-cron, Painel > Atualizações). This class answers that filter with the
 * newest published release of the repository and the ZIP that
 * .github/workflows/release.yml attaches to it (`wp-formvault-X.Y.Z.zip`).
 *
 * The result is cached in a site transient (12 h, 1 h after a failure) to stay
 * well below GitHub's anonymous API limit. "Verificar novamente" in
 * Painel > Atualizações clears the cache.
 *
 * @package WPFormVault
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPFV_Updater {
	const REPOSITORY = 'brunoalbim/wp-formvault';
	const CACHE_KEY  = 'wpfv_github_release';
	const CACHE_TTL  = 12 * HOUR_IN_SECONDS;
	const ERROR_TTL  = HOUR_IN_SECONDS;

	/** @var string Plugin basename, e.g. wp-formvault/wp-formvault.php. */
	private $basename;

	/** @var string Plugin slug (installation folder). */
	private $slug;

	public function __construct() {
		$this->basename = plugin_basename( WPFV_FILE );
		$this->slug     = dirname( $this->basename );

		add_filter( 'update_plugins_github.com', array( $this, 'check_update' ), 10, 3 );
		add_filter( 'plugins_api', array( $this, 'plugin_information' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_folder' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache_after_upgrade' ), 10, 2 );
		add_action( 'load-update-core.php', array( $this, 'clear_cache_on_force_check' ) );
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );
	}

	/**
	 * Answer WordPress' update check for this plugin.
	 *
	 * @param array|false $update      Update data from earlier callbacks.
	 * @param array       $plugin_data Plugin headers.
	 * @param string      $plugin_file Plugin basename.
	 * @return array|false
	 */
	public function check_update( $update, $plugin_data, $plugin_file ) {
		if ( $plugin_file !== $this->basename ) {
			return $update;
		}

		$release = $this->latest_release();
		if ( empty( $release ) ) {
			return $update;
		}

		return array(
			'id'           => 'github.com/' . self::REPOSITORY,
			'slug'         => $this->slug,
			'plugin'       => $this->basename,
			'version'      => $release['version'],
			'new_version'  => $release['version'],
			'url'          => 'https://github.com/' . self::REPOSITORY,
			'package'      => $release['package'],
			'requires'     => $release['requires'],
			'requires_php' => $release['requires_php'],
			'tested'       => '',
			'icons'        => array(),
			'banners'      => array(),
			'translations' => array(),
		);
	}

	/**
	 * Fill the "Ver detalhes" modal of the plugins screen.
	 *
	 * @param false|object|array $result Earlier result.
	 * @param string             $action API action.
	 * @param object             $args   Request arguments.
	 * @return false|object|array
	 */
	public function plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$release = $this->latest_release();
		if ( empty( $release ) ) {
			return $result;
		}

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$data = get_plugin_data( WPFV_FILE, false, false );

		return (object) array(
			'name'          => $data['Name'],
			'slug'          => $this->slug,
			'version'       => $release['version'],
			'author'        => $data['Author'],
			'homepage'      => 'https://github.com/' . self::REPOSITORY,
			'requires'      => $release['requires'],
			'requires_php'  => $release['requires_php'],
			'last_updated'  => $release['published_at'],
			'download_link' => $release['package'],
			'sections'      => array(
				'description' => '<p>' . esc_html( $data['Description'] ) . '</p>',
				'changelog'   => $release['changelog'],
			),
			'external'      => true,
		);
	}

	/**
	 * Keep the installation folder when the ZIP has a different root folder.
	 *
	 * @param string      $source        Extracted source folder.
	 * @param string      $remote_source Temporary extraction folder.
	 * @param WP_Upgrader $upgrader      Upgrader instance.
	 * @param array       $hook_extra    Upgrade context.
	 * @return string|WP_Error
	 */
	public function fix_source_folder( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;

		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename || ! $wp_filesystem ) {
			return $source;
		}

		$expected = trailingslashit( $remote_source ) . $this->slug . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $expected ) ) {
			return $source;
		}

		if ( ! $wp_filesystem->move( $source, $expected, true ) ) {
			return new WP_Error( 'wpfv_update_folder', 'Não foi possível preparar a pasta da atualização do WP FormVault.' );
		}

		return $expected;
	}

	/**
	 * Drop the cached release after this plugin is updated.
	 *
	 * @param WP_Upgrader $upgrader Upgrader instance.
	 * @param array       $options  Upgrade context.
	 * @return void
	 */
	public function clear_cache_after_upgrade( $upgrader, $options ) {
		if ( isset( $options['type'], $options['plugins'] ) && 'plugin' === $options['type'] && in_array( $this->basename, (array) $options['plugins'], true ) ) {
			delete_site_transient( self::CACHE_KEY );
		}
	}

	/** Make "Verificar novamente" in Painel > Atualizações query GitHub again. */
	public function clear_cache_on_force_check() {
		if ( isset( $_GET['force-check'] ) && current_user_can( 'update_plugins' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			delete_site_transient( self::CACHE_KEY );
		}
	}

	/**
	 * Add a "Verificar atualizações" link to the plugin row.
	 *
	 * @param array  $links Row links.
	 * @param string $file  Plugin basename.
	 * @return array
	 */
	public function row_meta( $links, $file ) {
		if ( $file === $this->basename && current_user_can( 'update_plugins' ) ) {
			$links[] = '<a href="' . esc_url( self_admin_url( 'update-core.php?force-check=1' ) ) . '">Verificar atualizações</a>';
		}

		return $links;
	}

	/**
	 * Newest published release with an installable ZIP, cached.
	 *
	 * @return array Empty when unavailable.
	 */
	public function latest_release() {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return isset( $cached['version'] ) ? $cached : array();
		}

		$release = $this->fetch_release();
		set_site_transient( self::CACHE_KEY, $release ? $release : array( 'error' => true ), $release ? self::CACHE_TTL : self::ERROR_TTL );

		return $release;
	}

	/**
	 * Query the GitHub API.
	 *
	 * @return array Empty on failure.
	 */
	private function fetch_release() {
		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPOSITORY . '/releases?per_page=10',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'WP-FormVault/' . WPFV_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$releases = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $releases ) ) {
			return array();
		}

		$best      = null;
		$changelog = '';
		foreach ( $releases as $item ) {
			if ( ! is_array( $item ) || ! empty( $item['draft'] ) || ! empty( $item['prerelease'] ) || empty( $item['tag_name'] ) ) {
				continue;
			}

			$version = ltrim( (string) $item['tag_name'], 'vV' );
			if ( ! preg_match( '/^\d+(\.\d+)*$/', $version ) ) {
				continue;
			}

			$changelog .= '<h4>' . esc_html( $version ) . '</h4>' . $this->markdown_to_html( isset( $item['body'] ) ? (string) $item['body'] : '' );

			$package = $this->package_url( $item, $version );
			if ( '' === $package ) {
				continue;
			}

			if ( null === $best || version_compare( $version, $best['version'], '>' ) ) {
				$best = array(
					'version'      => $version,
					'tag'          => (string) $item['tag_name'],
					'package'      => $package,
					'published_at' => isset( $item['published_at'] ) ? (string) $item['published_at'] : '',
				);
			}
		}

		if ( null === $best ) {
			return array();
		}

		$requirements = $this->fetch_requirements( $best['tag'] );

		return array(
			'version'      => $best['version'],
			'package'      => $best['package'],
			'published_at' => $best['published_at'],
			'requires'     => $requirements['requires'],
			'requires_php' => $requirements['requires_php'],
			'changelog'    => '' !== $changelog ? $changelog : '<p>Sem notas de versão.</p>',
		);
	}

	/**
	 * ZIP published by the release workflow.
	 *
	 * @param array  $release GitHub release.
	 * @param string $version Normalized version.
	 * @return string Empty when the release has no installable ZIP.
	 */
	private function package_url( array $release, $version ) {
		if ( empty( $release['assets'] ) || ! is_array( $release['assets'] ) ) {
			return '';
		}

		foreach ( $release['assets'] as $asset ) {
			if ( isset( $asset['name'], $asset['browser_download_url'] ) && 'wp-formvault-' . $version . '.zip' === $asset['name'] ) {
				return esc_url_raw( $asset['browser_download_url'] );
			}
		}

		return '';
	}

	/**
	 * Read "Requires at least" and "Requires PHP" from the released main file.
	 *
	 * @param string $tag Release tag.
	 * @return array
	 */
	private function fetch_requirements( $tag ) {
		$requirements = array(
			'requires'     => '',
			'requires_php' => '',
		);

		$response = wp_remote_get(
			'https://raw.githubusercontent.com/' . self::REPOSITORY . '/' . rawurlencode( $tag ) . '/wp-formvault.php',
			array(
				'timeout' => 10,
				'headers' => array( 'User-Agent' => 'WP-FormVault/' . WPFV_VERSION ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return $requirements;
		}

		$header = substr( wp_remote_retrieve_body( $response ), 0, 8192 );
		if ( preg_match( '/^[ \t\/*#@]*Requires at least:\s*([0-9.]+)/mi', $header, $match ) ) {
			$requirements['requires'] = $match[1];
		}
		if ( preg_match( '/^[ \t\/*#@]*Requires PHP:\s*([0-9.]+)/mi', $header, $match ) ) {
			$requirements['requires_php'] = $match[1];
		}

		return $requirements;
	}

	/**
	 * Minimal Markdown conversion for GitHub release notes.
	 *
	 * @param string $markdown Release body.
	 * @return string Safe HTML.
	 */
	private function markdown_to_html( $markdown ) {
		$html    = '';
		$in_list = false;

		foreach ( preg_split( '/\r\n|\r|\n/', $markdown ) as $line ) {
			$line = trim( $line );
			$item = preg_match( '/^[-*+]\s+(.*)$/', $line, $match );

			if ( ! $item && $in_list ) {
				$html   .= '</ul>';
				$in_list = false;
			}

			if ( '' === $line ) {
				continue;
			}

			if ( $item ) {
				if ( ! $in_list ) {
					$html   .= '<ul>';
					$in_list = true;
				}
				$html .= '<li>' . $this->inline_markdown( $match[1] ) . '</li>';
			} elseif ( preg_match( '/^#{1,6}\s+(.*)$/', $line, $match ) ) {
				$html .= '<p><strong>' . $this->inline_markdown( $match[1] ) . '</strong></p>';
			} else {
				$html .= '<p>' . $this->inline_markdown( $line ) . '</p>';
			}
		}

		if ( $in_list ) {
			$html .= '</ul>';
		}

		return $html;
	}

	/**
	 * Escape a line and convert links and bold text.
	 *
	 * @param string $text Markdown line.
	 * @return string
	 */
	private function inline_markdown( $text ) {
		$text = esc_html( $text );
		$text = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text );
		$text = preg_replace_callback(
			'/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/',
			static function ( $m ) {
				return '<a href="' . esc_url( html_entity_decode( $m[2] ) ) . '" target="_blank" rel="noopener noreferrer">' . $m[1] . '</a>';
			},
			$text
		);

		return preg_replace_callback(
			'/(?<!href=")(?<!">)(https?:\/\/[^\s<]+)/',
			static function ( $m ) {
				return '<a href="' . esc_url( html_entity_decode( $m[1] ) ) . '" target="_blank" rel="noopener noreferrer">' . $m[1] . '</a>';
			},
			$text
		);
	}
}
