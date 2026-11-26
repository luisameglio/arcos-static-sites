<?php
/**
 * Plugin Name: Arcos Static Sites
 * Description: Generate clean, rate-limit-free static HTML/CSS/JS/media ZIP exports or push directly to a same-server live directory, with incremental delta builds and Bricks + ACF dynamic tag form bridging.
 * Version: 1.9.13
 * Author: Arcos Multimedia Group
 * License: GPL-2.0-or-later
 * Update URI: https://github.com/luisameglio/arcos-static-sites
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'ARCOS_STATIC_SITES_FILE' ) ) {
	define( 'ARCOS_STATIC_SITES_FILE', __FILE__ );
}

require_once __DIR__ . '/includes/trait-updater.php';
require_once __DIR__ . '/includes/trait-admin.php';
require_once __DIR__ . '/includes/trait-exporter.php';
require_once __DIR__ . '/includes/trait-forms-bridge.php';
require_once __DIR__ . '/includes/trait-ftp.php';
require_once __DIR__ . '/includes/trait-logs.php';

class Arcos_Static_Sites {

	use Arcos_Static_Sites_Updater;
	use Arcos_Static_Sites_Admin;
	use Arcos_Static_Sites_Exporter;
	use Arcos_Static_Sites_Forms_Bridge;
	use Arcos_Static_Sites_FTP;
	use Arcos_Static_Sites_Logs;


	const VERSION          = '1.9.13';
	const GITHUB_REPO      = 'luisameglio/arcos-static-sites';
	const UPDATE_CACHE_KEY = 'arcos_static_sites_gh_update';
	const NONCE_ACTION     = 'arcos_static_sites_nonce';
	const OPT_KEY          = 'arcos_static_sites_settings';
	const MANIFEST_KEY     = 'arcos_static_sites_manifest';
	const RELEASES_KEY     = 'arcos_static_sites_releases';
	const LOGS_KEY         = 'arcos_static_sites_logs';

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'add_admin_menu' ] );
		add_action( 'admin_bar_menu', [ __CLASS__, 'add_admin_bar_menu' ], 100 );
		add_action( 'template_redirect', [ __CLASS__, 'maybe_lock_frontend' ], 1 );
		add_action( 'rest_api_init', [ __CLASS__, 'register_rest_routes' ] );
		add_filter( 'plugins_api', [ __CLASS__, 'filter_plugins_api' ], 20, 3 );
		add_filter( 'plugin_row_meta', [ __CLASS__, 'filter_plugin_row_meta' ], 10, 2 );
		add_filter( 'pre_set_site_transient_update_plugins', [ __CLASS__, 'filter_update_plugins' ] );
		add_filter( 'http_request_args', [ __CLASS__, 'filter_http_request_args' ], 10, 2 );
		add_filter( 'upgrader_source_selection', [ __CLASS__, 'filter_upgrader_source_selection' ], 10, 4 );
		add_action( 'upgrader_process_complete', [ __CLASS__, 'on_upgrader_complete' ], 10, 2 );
		add_action( 'wp_ajax_arcos_ss_init', [ __CLASS__, 'ajax_init' ] );
		add_action( 'wp_ajax_arcos_ss_batch', [ __CLASS__, 'ajax_batch' ] );
		add_action( 'wp_ajax_arcos_ss_zip', [ __CLASS__, 'ajax_zip' ] );
		add_action( 'wp_ajax_arcos_ss_delete_zip', [ __CLASS__, 'ajax_delete_zip' ] );
		add_action( 'wp_ajax_arcos_ss_restore_zip', [ __CLASS__, 'ajax_restore_zip' ] );
		add_action( 'wp_ajax_arcos_ss_label_zip', [ __CLASS__, 'ajax_label_zip' ] );
		add_action( 'wp_ajax_arcos_ss_reset_manifest', [ __CLASS__, 'ajax_reset_manifest' ] );
		add_action( 'wp_ajax_arcos_ss_check_dir', [ __CLASS__, 'ajax_check_dir' ] );
		add_action( 'wp_ajax_arcos_ss_save_lock', [ __CLASS__, 'ajax_save_lock' ] );
		add_action( 'wp_ajax_arcos_ss_save_github_token', [ __CLASS__, 'ajax_save_github_token' ] );
		add_action( 'wp_ajax_arcos_ss_check_updates', [ __CLASS__, 'ajax_check_updates' ] );
		add_action( 'wp_ajax_arcos_ss_get_log', [ __CLASS__, 'ajax_get_log' ] );
		add_action( 'wp_ajax_arcos_ss_download_log', [ __CLASS__, 'ajax_download_log' ] );
		add_action( 'wp_ajax_arcos_ss_delete_log', [ __CLASS__, 'ajax_delete_log' ] );
		add_action( 'wp_ajax_arcos_ss_clear_logs', [ __CLASS__, 'ajax_clear_logs' ] );
		add_action( 'wp_ajax_arcos_ss_log_error', [ __CLASS__, 'ajax_log_error' ] );
		add_action( 'wp_ajax_arcos_ss_cancel', [ __CLASS__, 'ajax_cancel' ] );
		add_action( 'wp_ajax_arcos_ss_test_ftp', [ __CLASS__, 'ajax_test_ftp' ] );
	}


	private static function get_settings() {
		$defaults = [
			'enable_form_bridge' => 1,
			'allowed_origins'    => '*',
			'delivery_method'    => 'zip',
			'target_dir'         => '',
			'base_url'           => '/',
			'pagespeed_off'      => 1,
			'batch_size'         => 4,
			'canonical_domain'   => '',
			'force_index'        => 1,
			'generate_seo_files' => 1,
			'generate_htaccess'  => 0,
			'export_taxonomies'  => 1,
			'export_tags'        => 0,
			'ga4_id'             => '',
			'private_cms_mode'   => 0,
			'github_token'       => '',
			'ftp_host'           => '',
			'ftp_port'           => 21,
			'ftp_user'           => '',
			'ftp_pass'           => '',
			'ftp_path'           => '/',
			'ftp_ssl'            => 0,
			'ftp_passive'        => 1,
		];
		$saved = get_option( self::OPT_KEY, [] );
		return wp_parse_args( is_array( $saved ) ? $saved : [], $defaults );
	}

	private static function get_export_token() {
		$token = (string) get_option( 'arcos_static_sites_export_token', '' );
		if ( strlen( $token ) < 24 ) {
			$token = wp_generate_password( 32, false, false );
			update_option( 'arcos_static_sites_export_token', $token, false );
		}
		return $token;
	}

	public static function maybe_lock_frontend() {
		$settings = self::get_settings();
		if ( empty( $settings['private_cms_mode'] ) || is_user_logged_in() ) {
			return;
		}
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		$hdr = isset( $_SERVER['HTTP_X_ARCOS_EXPORT_TOKEN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_ARCOS_EXPORT_TOKEN'] ) ) : '';
		$qry = isset( $_GET['arcos_export_token'] ) ? sanitize_text_field( wp_unslash( $_GET['arcos_export_token'] ) ) : '';
		$tok = self::get_export_token();
		if ( ( '' !== $hdr && hash_equals( $tok, $hdr ) ) || ( '' !== $qry && hash_equals( $tok, $qry ) ) ) {
			return;
		}
		auth_redirect();
		exit;
	}

}

Arcos_Static_Sites::init();
