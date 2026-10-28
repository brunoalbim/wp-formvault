<?php
/**
 * Private storage, validation and retention for file fields.
 *
 * @package WPFormVault
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPFV_Attachments {
	/** Default size limit, in MB, for file fields that do not declare `max_size`. */
	const DEFAULT_MAX_SIZE_MB = 5;

	/** Extensions accepted when a file field does not declare `accept`. */
	const DEFAULT_EXTENSIONS = array( 'pdf' );

	/** Extensions never accepted, even when declared and allowed by WordPress. */
	const BLOCKED_EXTENSIONS = array( 'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'pht', 'html', 'htm', 'shtml', 'svg', 'svgz', 'js', 'mjs', 'exe', 'sh', 'bat', 'cmd', 'com', 'msi', 'jar', 'htaccess', 'xml', 'swf' );

	private function table() {
		global $wpdb;
		return $wpdb->prefix . 'wpfv_attachments';
	}

	/**
	 * Absolute path of the private directory. Override with the `wpfv_attachments_dir`
	 * filter to keep files outside the public web root.
	 *
	 * @return string
	 */
	public static function dir() {
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . 'wpfv-private';
		return untrailingslashit( apply_filters( 'wpfv_attachments_dir', $dir ) );
	}

	/** Create the private directory and its access guards. */
	private function ensure_dir() {
		$dir = self::dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$guards = array(
			'.htaccess'  => "# WP FormVault: anexos privados.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
		);
		foreach ( $guards as $name => $contents ) {
			if ( ! file_exists( $dir . '/' . $name ) ) {
				file_put_contents( $dir . '/' . $name, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		return true;
	}

	/**
	 * Normalize the `accept` list of a field to extensions WordPress allows and the plugin does not block.
	 *
	 * @param mixed $accept Declared extensions.
	 * @return string[]
	 */
	public static function normalize_extensions( $accept ) {
		$accept     = is_array( $accept ) && $accept ? $accept : self::DEFAULT_EXTENSIONS;
		$extensions = array();
		foreach ( $accept as $extension ) {
			$extension = strtolower( ltrim( sanitize_text_field( (string) $extension ), '.' ) );
			if ( $extension && preg_match( '/^[a-z0-9]{1,10}$/', $extension ) && ! in_array( $extension, self::BLOCKED_EXTENSIONS, true ) && self::mime_for( $extension ) ) {
				$extensions[] = $extension;
			}
		}
		return array_values( array_unique( $extensions ) );
	}

	/** MIME type WordPress associates with an extension, or an empty string. */
	public static function mime_for( $extension ) {
		foreach ( get_allowed_mime_types() as $pattern => $mime ) {
			if ( in_array( $extension, explode( '|', $pattern ), true ) ) {
				return $mime;
			}
		}
		return '';
	}

	/** Effective size limit in bytes, capped by the server upload limit. */
	public static function max_bytes( $definition ) {
		$megabytes = isset( $definition['max_size'] ) && (float) $definition['max_size'] > 0 ? (float) $definition['max_size'] : self::DEFAULT_MAX_SIZE_MB;
		$bytes     = (int) floor( $megabytes * MB_IN_BYTES );
		$server    = (int) wp_max_upload_size();
		return $server > 0 ? min( $bytes, $server ) : $bytes;
	}

	/** Sum of the size limits of every file field in a form. */
	public static function form_max_bytes( $form ) {
		$total = 0;
		foreach ( isset( $form['fields'] ) ? $form['fields'] : array() as $definition ) {
			if ( 'file' === $definition['type'] ) {
				$total += self::max_bytes( $definition );
			}
		}
		return $total;
	}

	/** Human-readable size such as "5 MB". */
	public static function format_size( $bytes ) {
		return size_format( $bytes, $bytes >= MB_IN_BYTES && 0 !== $bytes % MB_IN_BYTES ? 1 : 0 );
	}

	/**
	 * Validate one uploaded file from `$_FILES`.
	 *
	 * @param string $field_id Field identifier.
	 * @param array  $definition Normalized field definition.
	 * @return array{status:string,message?:string,upload?:array} status is `empty`, `invalid` or `valid`.
	 */
	public function validate_upload( $field_id, $definition ) {
		$file = isset( $_FILES[ $field_id ] ) && is_array( $_FILES[ $field_id ] ) ? $_FILES[ $field_id ] : null; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $file || ! isset( $file['error'] ) || is_array( $file['error'] ) || UPLOAD_ERR_NO_FILE === (int) $file['error'] ) {
			return array( 'status' => 'empty' );
		}

		$max        = self::max_bytes( $definition );
		$extensions = $definition['accept'];
		$too_large  = sprintf( __( 'O arquivo excede o limite de %s.', 'wp-formvault' ), self::format_size( $max ) );
		$bad_type   = sprintf( __( 'Formato não permitido. Envie: %s.', 'wp-formvault' ), strtoupper( implode( ', ', $extensions ) ) );

		if ( in_array( (int) $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ) {
			return array( 'status' => 'invalid', 'message' => $too_large );
		}
		if ( UPLOAD_ERR_OK !== (int) $file['error'] || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return array( 'status' => 'invalid', 'message' => __( 'Não foi possível receber o arquivo. Tente novamente.', 'wp-formvault' ) );
		}

		$size = (int) filesize( $file['tmp_name'] );
		if ( $size <= 0 ) {
			return array( 'status' => 'invalid', 'message' => __( 'O arquivo enviado está vazio.', 'wp-formvault' ) );
		}
		if ( $size > $max ) {
			return array( 'status' => 'invalid', 'message' => $too_large );
		}

		$name    = sanitize_file_name( wp_basename( (string) $file['name'] ) );
		$allowed = array();
		foreach ( $extensions as $extension ) {
			$allowed[ $extension ] = self::mime_for( $extension );
		}
		$check = wp_check_filetype_and_ext( $file['tmp_name'], $name, $allowed );
		if ( empty( $check['ext'] ) || empty( $check['type'] ) || ! in_array( $check['ext'], $extensions, true ) ) {
			return array( 'status' => 'invalid', 'message' => $bad_type );
		}
		if ( ! empty( $check['proper_filename'] ) ) {
			$name = sanitize_file_name( $check['proper_filename'] );
		}

		return array(
			'status' => 'valid',
			'upload' => array(
				'field_id'      => $field_id,
				'tmp_name'      => $file['tmp_name'],
				'original_name' => $name,
				'extension'     => $check['ext'],
				'mime_type'     => $check['type'],
				'size'          => $size,
				'sha256'        => hash_file( 'sha256', $file['tmp_name'] ),
			),
		);
	}

	/**
	 * Move validated uploads to private storage under random names.
	 *
	 * @param array $uploads Valid uploads from validate_upload().
	 * @return array|false Stored uploads with `stored_name`, or false after cleaning up.
	 */
	public function store( $uploads ) {
		if ( ! $uploads ) {
			return array();
		}
		if ( ! $this->ensure_dir() ) {
			return false;
		}
		$stored = array();
		foreach ( $uploads as $upload ) {
			$subdir = gmdate( 'Y/m' );
			if ( ! wp_mkdir_p( self::dir() . '/' . $subdir ) ) {
				$this->remove_files( $stored );
				return false;
			}
			$upload['stored_name'] = $subdir . '/' . wp_generate_password( 32, false, false ) . '.' . $upload['extension'];
			$target                = self::dir() . '/' . $upload['stored_name'];
			if ( ! $this->move( $upload['tmp_name'], $target ) ) {
				$this->remove_files( $stored );
				return false;
			}
			chmod( $target, 0640 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$stored[] = $upload;
		}
		return $stored;
	}

	/** Move an uploaded file. Filterable for environments that pre-process uploads. */
	private function move( $source, $target ) {
		$moved = apply_filters( 'wpfv_move_uploaded_file', null, $source, $target );
		return null === $moved ? @move_uploaded_file( $source, $target ) : (bool) $moved; // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/** Remove stored files of uploads that could not be linked to a submission. */
	public function remove_files( $stored ) {
		foreach ( (array) $stored as $upload ) {
			if ( ! empty( $upload['stored_name'] ) ) {
				$this->delete_file( $upload['stored_name'] );
			}
		}
	}

	/** Link stored files to a submission. */
	public function attach( $submission_id, $stored ) {
		global $wpdb;
		if ( ! $stored ) {
			return;
		}
		$now = current_time( 'mysql', true );
		foreach ( (array) $stored as $upload ) {
			$wpdb->insert(
				$this->table(),
				array(
					'submission_id' => absint( $submission_id ),
					'field_id'      => sanitize_key( $upload['field_id'] ),
					'original_name' => $upload['original_name'],
					'stored_name'   => $upload['stored_name'],
					'mime_type'     => $upload['mime_type'],
					'size'          => absint( $upload['size'] ),
					'sha256'        => $upload['sha256'],
					'created_at'    => $now,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
			);
		}
	}

	/** Attachment rows of a submission. */
	public function for_submission( $submission_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE submission_id = %d ORDER BY id ASC", absint( $submission_id ) ) );
	}

	public function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", absint( $id ) ) );
	}

	/** Absolute path of an attachment that is still available, or an empty string. */
	public function path( $attachment ) {
		if ( ! $attachment || ! empty( $attachment->purged_at ) || ! preg_match( '#^\d{4}/\d{2}/[A-Za-z0-9]+\.[a-z0-9]+$#', (string) $attachment->stored_name ) ) {
			return '';
		}
		$path = self::dir() . '/' . $attachment->stored_name;
		return is_file( $path ) ? $path : '';
	}

	private function delete_file( $stored_name ) {
		if ( preg_match( '#^\d{4}/\d{2}/[A-Za-z0-9]+\.[a-z0-9]+$#', (string) $stored_name ) ) {
			$path = self::dir() . '/' . $stored_name;
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/** Delete files and rows of a submission. */
	public function delete_for_submission( $submission_id ) {
		global $wpdb;
		foreach ( $this->for_submission( $submission_id ) as $attachment ) {
			$this->delete_file( $attachment->stored_name );
		}
		return false !== $wpdb->delete( $this->table(), array( 'submission_id' => absint( $submission_id ) ), array( '%d' ) );
	}

	/** Delete files and rows of spam submissions created before a cutoff. */
	public function delete_for_spam_before( $cutoff ) {
		global $wpdb;
		$submissions = $wpdb->prefix . 'wpfv_submissions';
		$rows        = $wpdb->get_results( $wpdb->prepare( "SELECT a.id, a.stored_name FROM {$this->table()} a INNER JOIN {$submissions} s ON s.id = a.submission_id WHERE s.status = %s AND s.created_at < %s", 'spam', $cutoff ) );
		foreach ( $rows as $row ) {
			$this->delete_file( $row->stored_name );
			$wpdb->delete( $this->table(), array( 'id' => absint( $row->id ) ), array( '%d' ) );
		}
		return count( $rows );
	}

	/**
	 * Remove files older than the global retention, keeping name and metadata for the record.
	 *
	 * @param int $days Retention in days; 0 keeps files indefinitely.
	 * @return int Files removed.
	 */
	public function purge_expired( $days ) {
		global $wpdb;
		$days = absint( $days );
		if ( ! $days ) {
			return 0;
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * $days ) );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT id, stored_name FROM {$this->table()} WHERE purged_at IS NULL AND created_at < %s LIMIT 500", $cutoff ) );
		$now    = current_time( 'mysql', true );
		foreach ( $rows as $row ) {
			$this->delete_file( $row->stored_name );
			$wpdb->update( $this->table(), array( 'purged_at' => $now ), array( 'id' => absint( $row->id ) ), array( '%s' ), array( '%d' ) );
		}
		return count( $rows );
	}
}
