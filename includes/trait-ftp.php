<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Arcos_Static_Sites_FTP {

	private static function ftp_connect_and_login( $cfg ) {
		if ( ! extension_loaded( 'ftp' ) ) {
			return new WP_Error( 'arcos_ftp_missing', 'PHP FTP extension is not enabled on this server.' );
		}
		$host = trim( (string) ( $cfg['host'] ?? '' ) );
		$port = max( 1, min( 65535, (int) ( $cfg['port'] ?? 21 ) ) );
		$user = (string) ( $cfg['user'] ?? '' );
		$pass = (string) ( $cfg['pass'] ?? '' );
		$ssl  = ! empty( $cfg['ssl'] );
		$pasv = ! isset( $cfg['passive'] ) || ! empty( $cfg['passive'] );

		if ( '' === $host || '' === $user || '' === $pass ) {
			return new WP_Error( 'arcos_ftp_creds', 'FTP host, username, and password are required.' );
		}
		if ( $ssl && ! function_exists( 'ftp_ssl_connect' ) ) {
			return new WP_Error( 'arcos_ftps_unsupported', 'FTPS (ftp_ssl_connect) is not supported by PHP on this server.' );
		}

		$conn = $ssl ? @ftp_ssl_connect( $host, $port, 15 ) : @ftp_connect( $host, $port, 15 );
		if ( ! $conn ) {
			return new WP_Error( 'arcos_ftp_connect', 'Could not connect to ' . $host . ':' . $port );
		}
		if ( ! @ftp_login( $conn, $user, $pass ) ) {
			@ftp_close( $conn );
			return new WP_Error( 'arcos_ftp_login', 'FTP login failed for user ' . $user );
		}
		@ftp_pasv( $conn, $pasv );
		return $conn;
	}

	private static function ftp_ensure_dir( $conn, $remote_dir, &$cache ) {
		$remote_dir = '/' . trim( str_replace( '\\', '/', $remote_dir ), '/' );
		if ( '/' === $remote_dir || isset( $cache[ $remote_dir ] ) ) {
			return true;
		}
		$parts = explode( '/', ltrim( $remote_dir, '/' ) );
		$cur   = '';
		foreach ( $parts as $p ) {
			if ( '' === $p ) {
				continue;
			}
			$cur .= '/' . $p;
			if ( isset( $cache[ $cur ] ) ) {
				continue;
			}
			if ( ! @ftp_chdir( $conn, $cur ) ) {
				if ( ! @ftp_mkdir( $conn, $cur ) ) {
					return false;
				}
			}
			$cache[ $cur ] = true;
		}
		return true;
	}

	private static function ftp_push_directory( $local_dir, $cfg ) {
		$conn = self::ftp_connect_and_login( $cfg );
		if ( is_wp_error( $conn ) ) {
			return $conn;
		}
		$remote_root = '/' . trim( str_replace( '\\', '/', (string) ( $cfg['path'] ?? '/' ) ), '/' );
		if ( '/' !== $remote_root ) {
			$dir_cache = [];
			if ( ! self::ftp_ensure_dir( $conn, $remote_root, $dir_cache ) ) {
				@ftp_close( $conn );
				return new WP_Error( 'arcos_ftp_mkdir', 'Could not access or create remote directory: ' . $remote_root );
			}
		} else {
			$dir_cache = [ '/' => true ];
		}

		$uploaded = 0;
		$base_len = strlen( trailingslashit( $local_dir ) );
		$it       = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $local_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);
		foreach ( $it as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$full        = $file->getPathname();
			$rel         = str_replace( '\\', '/', substr( $full, $base_len ) );
			$remote_file = ( '/' === $remote_root ? '' : $remote_root ) . '/' . ltrim( $rel, '/' );
			$remote_dir  = dirname( $remote_file );
			if ( ! self::ftp_ensure_dir( $conn, $remote_dir, $dir_cache ) ) {
				@ftp_close( $conn );
				return new WP_Error( 'arcos_ftp_mkdir', 'Failed creating remote directory: ' . $remote_dir );
			}
			if ( ! @ftp_put( $conn, $remote_file, $full, FTP_BINARY ) ) {
				@ftp_close( $conn );
				return new WP_Error( 'arcos_ftp_put', 'Failed uploading file: ' . $remote_file );
			}
			$uploaded++;
		}
		@ftp_close( $conn );
		return $uploaded;
	}

	public static function ajax_test_ftp() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$prev = self::get_settings();
		$pass = isset( $_POST['ftp_pass'] ) ? (string) wp_unslash( $_POST['ftp_pass'] ) : '';
		$cfg  = [
			'host'    => isset( $_POST['ftp_host'] ) ? sanitize_text_field( wp_unslash( $_POST['ftp_host'] ) ) : '',
			'port'    => isset( $_POST['ftp_port'] ) ? max( 1, min( 65535, (int) $_POST['ftp_port'] ) ) : 21,
			'user'    => isset( $_POST['ftp_user'] ) ? sanitize_text_field( wp_unslash( $_POST['ftp_user'] ) ) : '',
			'pass'    => '' !== $pass ? $pass : (string) ( $prev['ftp_pass'] ?? '' ),
			'path'    => isset( $_POST['ftp_path'] ) ? sanitize_text_field( wp_unslash( $_POST['ftp_path'] ) ) : '/',
			'ssl'     => isset( $_POST['ftp_ssl'] ) && '1' === $_POST['ftp_ssl'],
			'passive' => ! isset( $_POST['ftp_passive'] ) || '1' === $_POST['ftp_passive'],
		];
		$conn = self::ftp_connect_and_login( $cfg );
		if ( is_wp_error( $conn ) ) {
			wp_send_json_error( $conn->get_error_message() );
		}
		$remote_path = '/' . trim( str_replace( '\\', '/', $cfg['path'] ), '/' );
		$cache       = [];
		$dir_ok      = self::ftp_ensure_dir( $conn, $remote_path, $cache );
		@ftp_close( $conn );
		if ( ! $dir_ok ) {
			wp_send_json_error( 'Logged in, but could not access or create ' . $remote_path );
		}
		wp_send_json_success( [ 'message' => 'Connected & verified ' . $remote_path ] );
	}

}
