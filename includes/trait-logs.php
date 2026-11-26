<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Arcos_Static_Sites_Logs {

	private static function get_logs_dir() {
		$dir = self::get_base_storage_dir() . '/logs';
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
			@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
			@file_put_contents( $dir . '/.htaccess', "Deny from all\n" );
		}
		return $dir;
	}

	private static function get_export_logs() {
		$logs = get_option( self::LOGS_KEY, [] );
		return is_array( $logs ) ? $logs : [];
	}

	private static function create_export_log( $meta ) {
		$log_id = gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 6, false, false ) );
		$user   = wp_get_current_user();
		$header = [
			'=== Arcos Static Sites Export Log ===',
			'Log ID:      ' . $log_id,
			'Started:     ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC',
			'User:        ' . ( $user && $user->user_login ? $user->user_login : 'unknown' ),
			'Mode:        ' . ( $meta['mode'] ?? '' ),
			'Delivery:    ' . ( $meta['delivery'] ?? '' ),
			'Batch Size:  ' . ( $meta['batch'] ?? 4 ),
			'Total URLs:  ' . ( $meta['total'] ?? 0 ),
		];
		if ( ! empty( $meta['label'] ) ) {
			$header[] = 'Label:       ' . $meta['label'];
		}
		if ( ! empty( $meta['target'] ) ) {
			$header[] = 'Target Dir:  ' . $meta['target'];
		}
		if ( ! empty( $meta['note'] ) ) {
			$header[] = 'Note:        ' . $meta['note'];
		}
		$header[] = str_repeat( '-', 48 );

		$file = self::get_logs_dir() . '/export-' . sanitize_file_name( $log_id ) . '.log';
		@file_put_contents( $file, implode( "\n", $header ) . "\n" );

		$logs = self::get_export_logs();
		array_unshift( $logs, [
			'id'         => $log_id,
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
			'mode'       => (string) ( $meta['mode'] ?? '' ),
			'delivery'   => (string) ( $meta['delivery'] ?? '' ),
			'label'      => (string) ( $meta['label'] ?? '' ),
			'total'      => (int) ( $meta['total'] ?? 0 ),
			'status'     => 'running',
		] );
		if ( count( $logs ) > 50 ) {
			$removed = array_splice( $logs, 50 );
			foreach ( $removed as $rem ) {
				if ( ! empty( $rem['id'] ) ) {
					@unlink( self::get_logs_dir() . '/export-' . sanitize_file_name( $rem['id'] ) . '.log' );
				}
			}
		}
		update_option( self::LOGS_KEY, $logs, false );
		return $log_id;
	}

	private static function append_export_log( $log_id, $lines ) {
		if ( empty( $log_id ) || empty( $lines ) ) {
			return;
		}
		$file = self::get_logs_dir() . '/export-' . sanitize_file_name( $log_id ) . '.log';
		$ts   = gmdate( 'H:i:s' );
		$out  = '';
		foreach ( (array) $lines as $line ) {
			$out .= '[' . $ts . '] ' . $line . "\n";
		}
		@file_put_contents( $file, $out, FILE_APPEND );
	}

	private static function finish_export_log( $log_id, $status, $summary_lines = [] ) {
		if ( empty( $log_id ) ) {
			return;
		}
		$file = self::get_logs_dir() . '/export-' . sanitize_file_name( $log_id ) . '.log';
		$out  = str_repeat( '-', 48 ) . "\n";
		foreach ( (array) $summary_lines as $line ) {
			$out .= '[' . gmdate( 'H:i:s' ) . '] ' . $line . "\n";
		}
		$out .= 'Finished: ' . gmdate( 'Y-m-d H:i:s' ) . " UTC (Status: " . $status . ")\n";
		@file_put_contents( $file, $out, FILE_APPEND );

		$logs = self::get_export_logs();
		foreach ( $logs as &$lg ) {
			if ( isset( $lg['id'] ) && $lg['id'] === $log_id ) {
				$lg['status']      = $status;
				$lg['finished_at'] = gmdate( 'Y-m-d H:i:s' );
				break;
			}
		}
		update_option( self::LOGS_KEY, $logs, false );
	}

	public static function ajax_get_log() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$id   = isset( $_POST['id'] ) ? sanitize_file_name( wp_unslash( $_POST['id'] ) ) : '';
		$file = self::get_logs_dir() . '/export-' . $id . '.log';
		if ( '' === $id || ! file_exists( $file ) ) {
			wp_send_json_error( 'Log file not found.' );
		}
		wp_send_json_success( [ 'content' => (string) file_get_contents( $file ) ] );
	}

	public static function ajax_download_log() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized', 403 );
		}
		$id   = isset( $_GET['id'] ) ? sanitize_file_name( wp_unslash( $_GET['id'] ) ) : '';
		$file = self::get_logs_dir() . '/export-' . $id . '.log';
		if ( '' === $id || ! file_exists( $file ) ) {
			wp_die( 'Log file not found.', 404 );
		}
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="arcos-export-' . $id . '.log"' );
		header( 'Content-Length: ' . filesize( $file ) );
		readfile( $file );
		exit;
	}

	public static function ajax_delete_log() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$id   = isset( $_POST['id'] ) ? sanitize_file_name( wp_unslash( $_POST['id'] ) ) : '';
		$file = self::get_logs_dir() . '/export-' . $id . '.log';
		if ( file_exists( $file ) ) {
			@unlink( $file );
		}
		$logs = array_values( array_filter( self::get_export_logs(), function( $lg ) use ( $id ) {
			return isset( $lg['id'] ) && $lg['id'] !== $id;
		} ) );
		update_option( self::LOGS_KEY, $logs, false );
		wp_send_json_success();
	}

	public static function ajax_clear_logs() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$files = glob( self::get_logs_dir() . '/*.log' ) ?: [];
		foreach ( $files as $f ) {
			@unlink( $f );
		}
		update_option( self::LOGS_KEY, [], false );
		wp_send_json_success();
	}

	public static function ajax_log_error() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$log_id = isset( $_POST['log_id'] ) ? sanitize_file_name( wp_unslash( $_POST['log_id'] ) ) : '';
		$error  = isset( $_POST['error'] ) ? sanitize_text_field( wp_unslash( $_POST['error'] ) ) : 'Unknown error';
		if ( '' !== $log_id ) {
			self::finish_export_log( $log_id, 'failed', [ 'ERROR: ' . $error ] );
		}
		wp_send_json_success();
	}

	public static function ajax_cancel() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$user_key = 'arcos_ss_job_' . get_current_user_id();
		$job      = get_transient( $user_key );
		$log_id   = isset( $_POST['log_id'] ) ? sanitize_file_name( wp_unslash( $_POST['log_id'] ) ) : '';
		if ( '' === $log_id && is_array( $job ) && ! empty( $job['log_id'] ) ) {
			$log_id = sanitize_file_name( (string) $job['log_id'] );
		}
		$build_dir = self::get_base_storage_dir() . '/build';
		self::rrmdir( $build_dir );
		delete_transient( $user_key );
		if ( '' !== $log_id ) {
			self::finish_export_log( $log_id, 'cancelled', [ 'CANCELLED: Export stopped by user.' ] );
		}
		wp_send_json_success();
	}

}
