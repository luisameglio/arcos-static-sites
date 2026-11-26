<?php
/**
 * Plugin Name: Arcos Static Sites
 * Description: Generate clean, rate-limit-free static HTML/CSS/JS/media ZIP exports or push directly to a same-server live directory, with incremental delta builds and Bricks + ACF dynamic tag form bridging.
 * Version: 1.9.6
 * Author: Arcos Multimedia Group
 * License: GPL-2.0-or-later
 * Update URI: https://github.com/luisameglio/arcos-static-sites
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arcos_Static_Sites {

	const VERSION          = '1.9.6';
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

	public static function add_admin_menu() {
		add_management_page(
			'Arcos Static Sites',
			'Arcos Static Sites',
			'manage_options',
			'arcos-static-sites',
			[ __CLASS__, 'render_admin_page' ]
		);
	}

	public static function add_admin_bar_menu( $wp_admin_bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = self::get_settings();
		$locked   = ! empty( $settings['private_cms_mode'] );
		$url      = admin_url( 'tools.php?page=arcos-static-sites' );
		$wp_admin_bar->add_node( [
			'id'    => 'arcos-static-sites',
			'title' => 'Static Sites' . ( $locked ? ' 🔒' : '' ),
			'href'  => $url,
			'meta'  => [ 'title' => 'Arcos Static Sites' ],
		] );
		$wp_admin_bar->add_node( [
			'id'     => 'arcos-static-sites-export',
			'parent' => 'arcos-static-sites',
			'title'  => 'Export / Push Static Site',
			'href'   => $url,
		] );
	}

	public static function filter_plugin_row_meta( $links, $file ) {
		if ( plugin_basename( __FILE__ ) === $file ) {
			$details_url = self_admin_url( 'plugin-install.php?tab=plugin-information&plugin=arcos-static-sites&TB_iframe=true&width=772&height=650' );
			$links[]     = '<a href="' . esc_url( $details_url ) . '" class="thickbox open-plugin-details-modal">View details &amp; Changelog</a>';
		}
		return $links;
	}

	public static function filter_plugins_api( $res, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || 'arcos-static-sites' !== $args->slug ) {
			return $res;
		}
		$changelog_html = self::parse_readme_changelog();
		$release        = self::fetch_latest_github_release();
		$info               = new stdClass();
		$info->name         = 'Arcos Static Sites';
		$info->slug         = 'arcos-static-sites';
		$info->version      = self::VERSION;
		$info->author       = '<a href="https://github.com/' . esc_attr( self::GITHUB_REPO ) . '">Arcos Multimedia Group</a>';
		$info->homepage     = 'https://github.com/' . self::GITHUB_REPO;
		$info->requires     = '6.0';
		$info->tested       = '7.1';
		$info->requires_php = '8.0';
		if ( ! is_wp_error( $release ) && ! empty( $release['version'] ) ) {
			if ( version_compare( $release['version'], self::VERSION, '>' ) ) {
				$info->version       = $release['version'];
				$info->download_link = $release['package'];
			}
			if ( ! empty( $release['published_at'] ) ) {
				$info->last_updated = $release['published_at'];
			}
			if ( ! empty( $release['body'] ) && version_compare( $release['version'], self::VERSION, '>' ) ) {
				$release_notes  = '<h4>' . esc_html( $release['version'] ) . ' (GitHub Release)</h4>';
				$release_notes .= '<p>' . nl2br( esc_html( $release['body'] ) ) . '</p>';
				$changelog_html = $release_notes . $changelog_html;
			}
		}
		$info->sections     = [
			'description' => '<p>Generate clean, rate-limit-free static HTML/CSS/JS/media ZIP exports or push directly to a same-server live directory, with incremental delta builds, Bricks + Bricksforge Pro Forms bridging, SEOPress SEO/GEO optimization, and client-side blog filtering.</p>',
			'changelog'   => $changelog_html,
		];
		return $info;
	}

	private static function get_github_token() {
		if ( defined( 'ARCOS_STATIC_SITES_GITHUB_TOKEN' ) && is_string( ARCOS_STATIC_SITES_GITHUB_TOKEN ) && '' !== trim( ARCOS_STATIC_SITES_GITHUB_TOKEN ) ) {
			return trim( ARCOS_STATIC_SITES_GITHUB_TOKEN );
		}
		$settings = self::get_settings();
		return isset( $settings['github_token'] ) ? trim( (string) $settings['github_token'] ) : '';
	}

	private static function fetch_latest_github_release( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::UPDATE_CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$token   = self::get_github_token();
		$headers = [
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ArcosStaticSites/' . self::VERSION,
		];
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$api_url  = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';
		$response = wp_remote_get( $api_url, [
			'timeout' => 15,
			'headers' => $headers,
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$msg  = is_array( $body ) && ! empty( $body['message'] ) ? $body['message'] : ( 'HTTP ' . $code );
			if ( 404 === $code && '' === $token ) {
				$msg = 'Repository or release not found (private repo requires a GitHub Personal Access Token).';
			}
			return new WP_Error( 'arcos_gh_http_' . $code, $msg );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			return new WP_Error( 'arcos_gh_invalid', 'Invalid GitHub release payload.' );
		}

		$version = ltrim( (string) $data['tag_name'], 'vV' );
		$package = ! empty( $data['zipball_url'] ) ? (string) $data['zipball_url'] : '';

		if ( ! empty( $data['assets'] ) && is_array( $data['assets'] ) ) {
			foreach ( $data['assets'] as $asset ) {
				if ( empty( $asset['name'] ) ) {
					continue;
				}
				if ( 'arcos-static-sites.zip' === $asset['name'] || preg_match( '/^arcos-static-sites.*\.zip$/i', $asset['name'] ) ) {
					if ( '' !== $token && ! empty( $asset['url'] ) ) {
						$package = (string) $asset['url'];
					} elseif ( ! empty( $asset['browser_download_url'] ) ) {
						$package = (string) $asset['browser_download_url'];
					} elseif ( ! empty( $asset['url'] ) ) {
						$package = (string) $asset['url'];
					}
					break;
				}
			}
		}

		$release = [
			'version'      => $version,
			'tag_name'     => (string) $data['tag_name'],
			'package'      => $package,
			'html_url'     => ! empty( $data['html_url'] ) ? (string) $data['html_url'] : '',
			'published_at' => ! empty( $data['published_at'] ) ? (string) $data['published_at'] : '',
			'body'         => ! empty( $data['body'] ) ? (string) $data['body'] : '',
		];

		set_transient( self::UPDATE_CACHE_KEY, $release, 6 * HOUR_IN_SECONDS );
		return $release;
	}

	public static function filter_update_plugins( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}

		$release = self::fetch_latest_github_release();
		if ( is_wp_error( $release ) || empty( $release['version'] ) || empty( $release['package'] ) ) {
			return $transient;
		}

		$plugin_file = plugin_basename( __FILE__ );
		$item        = (object) [
			'id'           => 'https://github.com/' . self::GITHUB_REPO,
			'slug'         => 'arcos-static-sites',
			'plugin'       => $plugin_file,
			'new_version'  => $release['version'],
			'url'          => ! empty( $release['html_url'] ) ? $release['html_url'] : 'https://github.com/' . self::GITHUB_REPO,
			'package'      => $release['package'],
			'requires'     => '6.0',
			'tested'       => '7.1',
			'requires_php' => '8.0',
		];

		if ( version_compare( $release['version'], self::VERSION, '>' ) ) {
			$transient->response[ $plugin_file ] = $item;
			unset( $transient->no_update[ $plugin_file ] );
		} else {
			$transient->no_update[ $plugin_file ] = $item;
			unset( $transient->response[ $plugin_file ] );
		}

		return $transient;
	}

	public static function filter_http_request_args( $args, $url ) {
		$api_prefix = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/';
		if ( 0 !== strpos( (string) $url, $api_prefix ) ) {
			return $args;
		}

		$token = self::get_github_token();
		if ( ! isset( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
			$args['headers'] = [];
		}
		if ( '' !== $token ) {
			$args['headers']['Authorization'] = 'Bearer ' . $token;
		}
		if ( false !== strpos( $url, '/releases/assets/' ) ) {
			$args['headers']['Accept'] = 'application/octet-stream';
		}
		return $args;
	}

	public static function filter_upgrader_source_selection( $source, $remote_source, $upgrader, $hook_extra ) {
		global $wp_filesystem;
		$plugin_file = plugin_basename( __FILE__ );
		$is_target   = ( isset( $hook_extra['plugin'] ) && $hook_extra['plugin'] === $plugin_file )
			|| ( isset( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) && in_array( $plugin_file, $hook_extra['plugins'], true ) );

		if ( ! $is_target || ! $wp_filesystem ) {
			return $source;
		}

		$source_norm = trailingslashit( $source );
		// Handle GitHub source zipball where the plugin lives inside a nested arcos-static-sites/ folder.
		if ( $wp_filesystem->exists( $source_norm . 'arcos-static-sites/arcos-static-sites.php' ) ) {
			$source_norm = $source_norm . 'arcos-static-sites/';
		}

		$desired = trailingslashit( $remote_source ) . 'arcos-static-sites/';
		if ( $source_norm !== $desired && $wp_filesystem->exists( $source_norm . 'arcos-static-sites.php' ) ) {
			if ( $wp_filesystem->exists( $desired ) ) {
				$wp_filesystem->delete( $desired, true );
			}
			if ( $wp_filesystem->move( $source_norm, $desired ) ) {
				return $desired;
			}
		}

		return $source_norm;
	}

	public static function on_upgrader_complete( $upgrader, $options ) {
		if ( empty( $options['action'] ) || 'update' !== $options['action'] || empty( $options['type'] ) || 'plugin' !== $options['type'] ) {
			return;
		}
		delete_transient( self::UPDATE_CACHE_KEY );
	}

	private static function parse_readme_changelog() {
		$readme = plugin_dir_path( __FILE__ ) . 'readme.txt';
		if ( ! file_exists( $readme ) ) {
			return '<p>No changelog found.</p>';
		}
		$raw = file_get_contents( $readme );
		if ( ! preg_match( '/==\s*Changelog\s*==(.+)$/is', $raw, $m ) ) {
			return '<p>No changelog entries found.</p>';
		}
		$lines = preg_split( "/\r?\n/", trim( $m[1] ) );
		$html  = '';
		$in_ul = false;
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( preg_match( '/^=\s*(.+?)\s*=$/', $line, $hm ) ) {
				if ( $in_ul ) {
					$html .= '</ul>';
					$in_ul = false;
				}
				$html .= '<h4>' . esc_html( $hm[1] ) . '</h4>';
			} elseif ( strpos( $line, '* ' ) === 0 ) {
				if ( ! $in_ul ) {
					$html .= '<ul>';
					$in_ul = true;
				}
				$item = esc_html( substr( $line, 2 ) );
				$item = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $item );
				$item = preg_replace( '/`(.+?)`/', '<code>$1</code>', $item );
				$html .= '<li>' . $item . '</li>';
			}
		}
		if ( $in_ul ) {
			$html .= '</ul>';
		}
		return $html;
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

	private static function get_manifest() {
		$m = get_option( self::MANIFEST_KEY, [] );
		return is_array( $m ) ? $m : [];
	}

	private static function compute_global_hash( $export_sig = '' ) {
		global $wpdb;
		$tpl_mod = (string) $wpdb->get_var(
			"SELECT MAX(post_modified_gmt) FROM {$wpdb->posts} WHERE post_type = 'bricks_template' AND post_status = 'publish'"
		);
		$menu_sig = (string) $wpdb->get_var(
			"SELECT CONCAT(COALESCE(MAX(post_modified_gmt),''), ':', COUNT(*)) FROM {$wpdb->posts} WHERE post_type = 'nav_menu_item'"
		);
		$menu_locs   = maybe_serialize( get_theme_mod( 'nav_menu_locations', [] ) );
		$bricks_glob = maybe_serialize( get_option( 'bricks_global_settings', [] ) ) . maybe_serialize( get_option( 'bricks_global_classes', [] ) );
		$acf_opts    = maybe_serialize( $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'options\_%' ORDER BY option_name" ) );
		$style_file  = get_stylesheet_directory() . '/style.css';
		$style_mtime = file_exists( $style_file ) ? (string) filemtime( $style_file ) : '0';
		return md5( $tpl_mod . '|' . $menu_sig . '|' . $menu_locs . '|' . $bricks_glob . '|' . $acf_opts . '|' . $style_mtime . '|' . $export_sig );
	}

	public static function register_rest_routes() {
		register_rest_route( 'arcos-static/v1', '/submit', [
			'methods'             => [ 'POST', 'OPTIONS' ],
			'callback'            => [ __CLASS__, 'handle_rest_form_submit' ],
			'permission_callback' => '__return_true',
		] );
	}

	private static function send_cors_headers() {
		$settings = self::get_settings();
		$allowed  = trim( (string) $settings['allowed_origins'] );
		$origin   = isset( $_SERVER['HTTP_ORIGIN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '*';

		if ( '' === $allowed || '*' === $allowed ) {
			header( 'Access-Control-Allow-Origin: *' );
		} else {
			$list = array_filter( array_map( 'trim', explode( ',', $allowed ) ) );
			if ( in_array( $origin, $list, true ) ) {
				header( 'Access-Control-Allow-Origin: ' . $origin );
				header( 'Vary: Origin' );
			}
		}
		header( 'Access-Control-Allow-Methods: POST, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Content-Type, X-Requested-With' );
	}

	public static function handle_rest_form_submit( WP_REST_Request $request ) {
		self::send_cors_headers();

		if ( 'OPTIONS' === $request->get_method() ) {
			return new WP_REST_Response( null, 204 );
		}

		$settings = self::get_settings();
		if ( empty( $settings['enable_form_bridge'] ) ) {
			return new WP_REST_Response( [ 'success' => false, 'message' => 'Static form bridge is disabled.' ], 403 );
		}

		$params  = $request->get_params();
		$form_id = isset( $params['formId'] ) ? sanitize_text_field( (string) $params['formId'] ) : '';
		$post_id = isset( $params['postId'] ) ? absint( $params['postId'] ) : 0;

		if ( '' === $form_id ) {
			return new WP_REST_Response( [ 'success' => false, 'message' => 'Missing Bricks formId.' ], 400 );
		}

		$found = self::find_bricks_form_element( $form_id, $post_id );
		if ( ! $found ) {
			return new WP_REST_Response( [ 'success' => false, 'message' => 'Form configuration not found.' ], 404 );
		}

		$form_el     = $found['element'];
		$is_pro_form = ( ( $form_el['name'] ?? '' ) === 'brf-pro-forms' );
		$el_settings = isset( $form_el['settings'] ) && is_array( $form_el['settings'] ) ? $form_el['settings'] : [];
		$fields_def  = isset( $el_settings['fields'] ) && is_array( $el_settings['fields'] ) ? $el_settings['fields'] : [];

		// Bricksforge Pro Forms nest field elements as children inside _bricks_page_content_2
		if ( $is_pro_form && empty( $fields_def ) && ! empty( $found['all_elements'] ) ) {
			$fields_def = self::collect_pro_form_fields( $form_id, $found['all_elements'] );
		}

		$submitted = [];
		$form_data = [];
		$bf_fields = [];
		$lines     = [];
		$rows_html = '';

		foreach ( $fields_def as $f ) {
			$fid    = isset( $f['id'] ) ? (string) $f['id'] : '';
			$el_id  = isset( $f['el_id'] ) ? (string) $f['el_id'] : '';
			$fname  = isset( $f['name'] ) ? (string) $f['name'] : '';
			$ftype  = isset( $f['type'] ) ? (string) $f['type'] : 'text';
			$label  = isset( $f['label'] ) && '' !== $f['label'] ? (string) $f['label'] : ( $f['placeholder'] ?? $fid );
			$val    = '';
			foreach ( array_filter( array_unique( [ 'form-field-' . $fid, $fid, 'form-field-' . $el_id, $el_id, $fname, 'form-field-' . $fname ] ) ) as $k ) {
				if ( isset( $params[ $k ] ) && '' !== $params[ $k ] ) {
					$val = $params[ $k ];
					break;
				}
			}

			if ( is_array( $val ) ) {
				$val = implode( ', ', array_map( 'sanitize_text_field', $val ) );
			} else {
				$val = sanitize_textarea_field( (string) $val );
			}

			if ( ! empty( $f['required'] ) && '' === trim( $val ) ) {
				return new WP_REST_Response( [
					'success' => false,
					'message' => sprintf( 'Please fill in the required field: %s.', $label ),
				], 422 );
			}

			$submitted[ $fid ] = $val;
			if ( 'html' !== $ftype && ! isset( $f['isHoneypot'] ) ) {
				$form_data[ $fid ] = [
					'type'  => $ftype,
					'value' => $val,
				];
				$bf_fields[] = [
					'id'    => $fid,
					'label' => $label,
					'value' => $val,
				];
			}
			$lines[] = $label . ': ' . $val;
			if ( '' !== trim( $val ) ) {
				$rows_html .= '<tr><td style="padding:10px 14px;border-bottom:1px solid #e2e8f0;font-weight:700;color:#0f172a;width:36%;vertical-align:top;">' . esc_html( $label ) . '</td><td style="padding:10px 14px;border-bottom:1px solid #e2e8f0;color:#334155;">' . nl2br( esc_html( $val ) ) . '</td></tr>';
			}
		}

		$site_title = esc_html( get_bloginfo( 'name' ) );
		$page_label = $post_id ? esc_html( get_the_title( $post_id ) ) : 'Website';
		$all_html   = '<div style="max-width:620px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;background:#ffffff;">'
			. '<div style="background:#0f172a;color:#ffffff;padding:16px 20px;">'
			. '<div style="font-size:16px;font-weight:700;">' . $site_title . '</div>'
			. '<div style="font-size:12px;color:#cbd5e1;margin-top:2px;">New form submission from ' . $page_label . '</div>'
			. '</div>'
			. '<table style="border-collapse:collapse;width:100%;background:#ffffff;">' . $rows_html . '</table>'
			. '</div>';

		$actions = isset( $el_settings['actions'] ) && is_array( $el_settings['actions'] ) ? $el_settings['actions'] : [ 'email' ];

		// Save to Bricksforge Submissions table when create_submission is enabled
		if ( in_array( 'create_submission', $actions, true ) ) {
			global $wpdb;
			$bf_table = defined( 'BRICKSFORGE_SUBMISSIONS_DB_TABLE' ) ? $wpdb->prefix . BRICKSFORGE_SUBMISSIONS_DB_TABLE : $wpdb->prefix . 'bricksforge_submissions';
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bf_table ) ) === $bf_table ) {
				$wpdb->insert(
					$bf_table,
					[
						'post_id'   => $post_id,
						'form_id'   => $form_id,
						'timestamp' => current_time( 'mysql' ),
						'fields'    => wp_json_encode( $bf_fields ),
					],
					[ '%d', '%s', '%s', '%s' ]
				);
			}
		}

		if ( ( in_array( 'save-submission', $actions, true ) || in_array( 'save_submission', $actions, true ) ) && class_exists( '\\Bricks\\Integrations\\Form\\Submission_Database' ) ) {
			$insert_data = [
				'post_id'   => $post_id,
				'form_id'   => $form_id,
				'form_data' => apply_filters( 'bricks/form/save-submission/form_data', $form_data, $form_id, $post_id ),
			];
			if ( class_exists( '\\Bricks\\Helpers' ) ) {
				$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
				if ( $ua ) {
					$browser = \Bricks\Helpers::user_agent_to_browser( $ua );
					$os      = \Bricks\Helpers::user_agent_to_os( $ua );
					if ( $browser ) {
						$insert_data['browser'] = $browser;
					}
					if ( $os ) {
						$insert_data['os'] = $os;
					}
				}
				if ( ! empty( $el_settings['submissionSaveIp'] ) ) {
					$insert_data['ip'] = \Bricks\Helpers::user_ip_address();
				}
			}
			$ref = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
			if ( $ref ) {
				$insert_data['referrer'] = $ref;
			}
			\Bricks\Integrations\Form\Submission_Database::get_instance();
			\Bricks\Integrations\Form\Submission_Database::insert_data( $insert_data );
		}

		if ( in_array( 'email', $actions, true ) ) {
			$to_raw = $el_settings['emailTo'] ?? 'admin_email';
			if ( 'admin_email' === $to_raw || '' === trim( (string) $to_raw ) ) {
				$to = get_option( 'admin_email' );
			} elseif ( 'custom' === $to_raw ) {
				$to = (string) ( $el_settings['emailToCustom'] ?? get_option( 'admin_email' ) );
			} else {
				$to = (string) $to_raw;
			}

			$to      = self::resolve_bricks_tokens( $to, $post_id, $submitted, implode( "\n", $lines ) );
			$subject = self::resolve_bricks_tokens( (string) ( $el_settings['emailSubject'] ?? ( 'New form submission — ' . get_bloginfo( 'name' ) ) ), $post_id, $submitted, implode( "\n", $lines ) );

			$body_tpl = isset( $el_settings['emailContent'] ) ? (string) $el_settings['emailContent'] : '';
			if ( '' !== trim( $body_tpl ) ) {
				$body = self::resolve_bricks_tokens( $body_tpl, $post_id, $submitted, $all_html );
				if ( ! preg_match( '/<(?:table|div|p|br|tr|td|ul|ol|li|h[1-6])\b/i', $body ) ) {
					$body = '<div style="max-width:620px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;background:#ffffff;">'
						. '<div style="background:#0f172a;color:#ffffff;padding:16px 20px;">'
						. '<div style="font-size:16px;font-weight:700;">' . $site_title . '</div>'
						. '<div style="font-size:12px;color:#cbd5e1;margin-top:2px;">New form submission from ' . $page_label . '</div>'
						. '</div>'
						. '<div style="padding:18px 20px;color:#334155;font-size:14px;line-height:1.7;">' . nl2br( esc_html( $body ) ) . '</div>'
						. '</div>';
				}
			} else {
				$body = $all_html;
			}

			$headers    = [ 'Content-Type: text/html; charset=UTF-8' ];
			$from_email = ! empty( $el_settings['fromEmail'] ) ? self::resolve_bricks_tokens( (string) $el_settings['fromEmail'], $post_id, $submitted, '' ) : '';
			$from_name  = ! empty( $el_settings['fromName'] ) ? self::resolve_bricks_tokens( (string) $el_settings['fromName'], $post_id, $submitted, '' ) : get_bloginfo( 'name' );
			if ( is_email( $from_email ) ) {
				$headers[] = 'From: ' . $from_name . ' <' . $from_email . '>';
			}

			$reply_to = ! empty( $el_settings['replyToEmail'] ) ? self::resolve_bricks_tokens( (string) $el_settings['replyToEmail'], $post_id, $submitted, '' ) : '';
			if ( is_email( $reply_to ) ) {
				$headers[] = 'Reply-To: ' . $reply_to;
			}

			$bcc = ! empty( $el_settings['emailBcc'] ) ? self::resolve_bricks_tokens( (string) $el_settings['emailBcc'], $post_id, $submitted, '' ) : '';
			if ( '' !== trim( $bcc ) ) {
				$headers[] = 'Bcc: ' . $bcc;
			}

			$sent = wp_mail( $to, $subject, $body, $headers );
			if ( ! $sent ) {
				return new WP_REST_Response( [
					'success' => false,
					'message' => 'Could not send email from WordPress mail server.',
				], 500 );
			}
		}

		$success_msg = ! empty( $el_settings['successMessage'] )
			? self::resolve_bricks_tokens( (string) $el_settings['successMessage'], $post_id, $submitted, '' )
			: 'Thank you! Your message has been sent.';

		$redirect     = '';
		$redirect_raw = $el_settings['redirect'] ?? $el_settings['redirectUrl'] ?? $el_settings['redirectTo'] ?? '';
		if ( in_array( 'redirect', $actions, true ) && '' !== trim( (string) $redirect_raw ) ) {
			$redirect_resolved = self::resolve_bricks_tokens( (string) $redirect_raw, $post_id, $submitted, '' );
			$home_slash        = trailingslashit( home_url( '/' ) );
			$home_http         = preg_replace( '#^https://#i', 'http://', $home_slash );
			if ( strpos( $redirect_resolved, $home_slash ) === 0 ) {
				$redirect = '/' . ltrim( substr( $redirect_resolved, strlen( $home_slash ) ), '/' );
			} elseif ( strpos( $redirect_resolved, $home_http ) === 0 ) {
				$redirect = '/' . ltrim( substr( $redirect_resolved, strlen( $home_http ) ), '/' );
			} else {
				$redirect = esc_url_raw( $redirect_resolved );
			}
		}

		return new WP_REST_Response( [
			'success'  => true,
			'message'  => $success_msg,
			'redirect' => $redirect,
		], 200 );
	}

	private static function resolve_bricks_tokens( $text, $post_id, $submitted, $all_fields ) {
		if ( '' === $text ) {
			return '';
		}
		if ( function_exists( 'bricks_render_dynamic_data' ) ) {
			$text = bricks_render_dynamic_data( $text, $post_id );
		}
		$text = str_replace( '{{all_fields}}', $all_fields, $text );
		foreach ( $submitted as $fid => $val ) {
			$text = str_replace( '{{' . $fid . '}}', $val, $text );
		}
		return $text;
	}

	private static function collect_pro_form_fields( $form_id, $all_elements ) {
		$map = [];
		foreach ( $all_elements as $el ) {
			if ( isset( $el['id'] ) ) {
				$map[ $el['id'] ] = $el;
			}
		}
		$fields = [];
		$walk   = function( $id ) use ( &$walk, &$fields, $map ) {
			if ( empty( $map[ $id ] ) ) {
				return;
			}
			$el   = $map[ $id ];
			$name = $el['name'] ?? '';
			if ( strpos( $name, 'brf-pro-forms-field-' ) === 0 && 'brf-pro-forms-field-submit-button' !== $name && 'brf-pro-forms-field-option' !== $name ) {
				$s = isset( $el['settings'] ) && is_array( $el['settings'] ) ? $el['settings'] : [];
				$fields[] = [
					'id'          => $s['id'] ?? $id,
					'el_id'       => $id,
					'name'        => $s['name'] ?? '',
					'type'        => str_replace( 'brf-pro-forms-field-', '', $name ),
					'label'       => $s['label'] ?? '',
					'placeholder' => $s['placeholder'] ?? '',
					'required'    => ! empty( $s['required'] ),
				];
			}
			foreach ( (array) ( $el['children'] ?? [] ) as $child_id ) {
				$walk( $child_id );
			}
		};
		$walk( $form_id );
		return $fields;
	}

	private static function find_bricks_form_element( $form_id, $post_id = 0 ) {
		$meta_keys  = [ '_bricks_page_content_2', '_bricks_page_header_2', '_bricks_page_footer_2' ];
		$form_names = [ 'form', 'brf-pro-forms' ];
		$embedded_tpls = [];
		if ( $post_id ) {
			foreach ( $meta_keys as $mk ) {
				$els = get_post_meta( $post_id, $mk, true );
				if ( is_array( $els ) ) {
					foreach ( $els as $el ) {
						if ( isset( $el['id'] ) && $el['id'] === $form_id && in_array( $el['name'] ?? '', $form_names, true ) ) {
							return [ 'element' => $el, 'all_elements' => $els ];
						}
						if ( ( $el['name'] ?? '' ) === 'template' && ! empty( $el['settings']['template'] ) ) {
							$embedded_tpls[] = absint( $el['settings']['template'] );
						}
					}
				}
			}
			// Check Bricks templates embedded inside this post before global fallback
			foreach ( array_unique( $embedded_tpls ) as $tpl_id ) {
				foreach ( $meta_keys as $mk ) {
					$els = get_post_meta( $tpl_id, $mk, true );
					if ( is_array( $els ) ) {
						foreach ( $els as $el ) {
							if ( isset( $el['id'] ) && $el['id'] === $form_id && in_array( $el['name'] ?? '', $form_names, true ) ) {
								return [ 'element' => $el, 'all_elements' => $els ];
							}
						}
					}
				}
			}
		}
		global $wpdb;
		$like = '%' . $wpdb->esc_like( $form_id ) . '%';
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ('_bricks_page_content_2','_bricks_page_header_2','_bricks_page_footer_2') AND meta_value LIKE %s LIMIT 10",
			$like
		) );
		foreach ( (array) $rows as $row ) {
			$els = maybe_unserialize( $row->meta_value );
			if ( is_array( $els ) ) {
				foreach ( $els as $el ) {
					if ( isset( $el['id'] ) && $el['id'] === $form_id && in_array( $el['name'] ?? '', $form_names, true ) ) {
						return [ 'element' => $el, 'all_elements' => $els ];
					}
				}
			}
		}
		return null;
	}

	private static function get_base_storage_dir() {
		$upload_dir = wp_upload_dir();
		$dir = trailingslashit( $upload_dir['basedir'] ) . 'arcos-static-sites';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
		}
		return $dir;
	}

	private static function get_base_storage_url() {
		$upload_dir = wp_upload_dir();
		return trailingslashit( $upload_dir['baseurl'] ) . 'arcos-static-sites';
	}

	private static function detect_server_folders() {
		$abs     = realpath( ABSPATH );
		$p1      = $abs ? dirname( $abs ) : '';
		$p2      = $p1 ? dirname( $p1 ) : '';
		$skip    = [ '.', '..', 'wp-admin', 'wp-content', 'wp-includes', 'cgi-bin', '.well-known' ];
		$folders = [];

		$add = function( $path ) use ( &$folders, $abs ) {
			$real = @realpath( $path );
			if ( ! $real || $real === $abs || ! @is_dir( $real ) || ! @is_writable( $real ) ) {
				return;
			}
			$folders[ $real ] = $real;
		};

		if ( $p1 ) {
			$add( $p1 );
		}
		foreach ( array_filter( [ $p2, $p1 ] ) as $base ) {
			if ( @is_dir( $base ) && @is_readable( $base ) ) {
				$entries = @scandir( $base );
				if ( is_array( $entries ) ) {
					foreach ( $entries as $entry ) {
						if ( in_array( $entry, $skip, true ) || strpos( $entry, '.' ) === 0 ) {
							continue;
						}
						$add( $base . '/' . $entry );
					}
				}
			}
		}
		return array_values( $folders );
	}

	private static function validate_target_dir( $dir ) {
		$dir = trim( (string) $dir );
		if ( '' === $dir ) {
			return new WP_Error( 'empty_dir', 'Please enter a target server directory path.' );
		}
		if ( ! is_dir( $dir ) ) {
			return new WP_Error( 'missing_dir', 'Directory does not exist: ' . $dir );
		}
		$real_target = realpath( $dir );
		$real_abs    = realpath( ABSPATH );
		if ( ! $real_target ) {
			return new WP_Error( 'invalid_dir', 'Could not resolve target directory.' );
		}
		if ( $real_target === $real_abs ) {
			return new WP_Error( 'unsafe_dir', 'Target directory cannot be the active WordPress ABSPATH itself.' );
		}
		if ( ! is_writable( $real_target ) ) {
			return new WP_Error( 'not_writable', 'Target directory is not writable by PHP: ' . $real_target );
		}
		return $real_target;
	}

	private static function get_environment_checks() {
		$checks = [];

		// 1. PHP Version
		$php_ok   = version_compare( PHP_VERSION, '8.0.0', '>=' );
		$checks[] = [
			'label'   => 'PHP Version',
			'current' => PHP_VERSION,
			'target'  => '8.0+ (8.1+ recommended)',
			'status'  => $php_ok ? 'ok' : 'fail',
			'tip'     => $php_ok ? '' : 'Upgrade PHP to 8.0 or newer.',
		];

		// 2. PHP Memory Limit
		$raw_mem   = (string) ini_get( 'memory_limit' );
		$mem_bytes = ( '-1' === trim( $raw_mem ) ) ? -1 : wp_convert_hr_to_bytes( $raw_mem );
		if ( -1 === $mem_bytes || $mem_bytes >= 512 * MB_IN_BYTES ) {
			$mem_status = 'ok';
			$mem_tip    = '';
		} elseif ( $mem_bytes >= 256 * MB_IN_BYTES ) {
			$mem_status = 'warn';
			$mem_tip    = '512M recommended for large Bricks + media sites.';
		} else {
			$mem_status = 'fail';
			$mem_tip    = 'Increase memory_limit to 512M (256M minimum) to prevent fatal errors during batch export or ZIP packaging.';
		}
		$checks[] = [
			'label'   => 'PHP memory_limit',
			'current' => '' !== $raw_mem ? $raw_mem : 'Unknown',
			'target'  => '512M (256M min)',
			'status'  => $mem_status,
			'tip'     => $mem_tip,
		];

		// 3. Max Execution Time
		$max_exec = (int) ini_get( 'max_execution_time' );
		if ( 0 === $max_exec || $max_exec >= 120 ) {
			$exec_status = 'ok';
			$exec_tip    = '';
		} elseif ( $max_exec >= 60 ) {
			$exec_status = 'warn';
			$exec_tip    = '120s–300s recommended (or lower Pages per Batch to 2).';
		} else {
			$exec_status = 'fail';
			$exec_tip    = 'Increase max_execution_time to 120–300s to avoid batch timeouts.';
		}
		$checks[] = [
			'label'   => 'PHP max_execution_time',
			'current' => 0 === $max_exec ? 'Unlimited (0)' : $max_exec . 's',
			'target'  => '120s–300s',
			'status'  => $exec_status,
			'tip'     => $exec_tip,
		];

		// 4. Max Input Vars
		$input_vars = (int) ini_get( 'max_input_vars' );
		$vars_ok    = ( $input_vars >= 3000 );
		$checks[]   = [
			'label'   => 'PHP max_input_vars',
			'current' => (string) $input_vars,
			'target'  => '3000–5000',
			'status'  => $vars_ok ? 'ok' : 'warn',
			'tip'     => $vars_ok ? '' : 'Increase to 5000 for Bricks Builder & large Selected Pages lists.',
		];

		// 5. ZipArchive
		$zip_ok   = class_exists( 'ZipArchive' );
		$checks[] = [
			'label'   => 'PHP ZipArchive Extension',
			'current' => $zip_ok ? 'Enabled' : 'Missing',
			'target'  => 'Enabled',
			'status'  => $zip_ok ? 'ok' : 'fail',
			'tip'     => $zip_ok ? '' : 'Enable php-zip extension to generate .zip archives and rollbacks.',
		];

		// 6. Export Storage Writable
		$storage_dir = self::get_base_storage_dir();
		$writable    = wp_is_writable( $storage_dir ) || wp_is_writable( dirname( $storage_dir ) );
		$checks[]    = [
			'label'   => 'Export Storage Directory',
			'current' => $writable ? 'Writable' : 'Not writable',
			'target'  => 'Writable (0755)',
			'status'  => $writable ? 'ok' : 'fail',
			'tip'     => $writable ? '' : 'Ensure wp-content/uploads/arcos-static-sites/ is writable.',
		];

		// 7. zlib.output_compression
		$zlib_on  = filter_var( ini_get( 'zlib.output_compression' ), FILTER_VALIDATE_BOOLEAN );
		$checks[] = [
			'label'   => 'zlib.output_compression',
			'current' => $zlib_on ? 'Enabled' : 'Off',
			'target'  => 'Off',
			'status'  => $zlib_on ? 'warn' : 'ok',
			'tip'     => $zlib_on ? 'Disable PHP zlib.output_compression; let Apache/LiteSpeed/Nginx handle Gzip.' : '',
		];

		// 8. PHP FTP Extension
		$ftp_ok   = extension_loaded( 'ftp' );
		$checks[] = [
			'label'   => 'PHP FTP Extension',
			'current' => $ftp_ok ? 'Enabled' : 'Missing',
			'target'  => 'Enabled (for FTP/FTPS)',
			'status'  => $ftp_ok ? 'ok' : 'warn',
			'tip'     => $ftp_ok ? '' : 'Enable php-ftp extension if you want to use FTP / FTPS delivery.',
		];

		return $checks;
	}

	public static function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$post_types = get_post_types( [ 'public' => true ], 'objects' );
		unset( $post_types['attachment'], $post_types['bricks_template'], $post_types['elementor_library'] );
		$settings     = self::get_settings();
		$manifest     = self::get_manifest();
		$storage_dir  = self::get_base_storage_dir();
		$storage_url  = self::get_base_storage_url();
		$zips         = glob( $storage_dir . '/*.zip' ) ?: [];
		rsort( $zips );
		$nonce        = wp_create_nonce( self::NONCE_ACTION );
		$rest_url     = rest_url( 'arcos-static/v1/submit' );
		$has_manifest     = ! empty( $manifest['last_export'] );
		$releases         = get_option( self::RELEASES_KEY, [] );
		if ( ! is_array( $releases ) ) {
			$releases = [];
		}
		$parent_hint      = trailingslashit( dirname( untrailingslashit( ABSPATH ) ) );
		$detected_folders = self::detect_server_folders();
		$picker_posts     = get_posts( [
			'post_type'      => array_keys( $post_types ),
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'post_type title',
			'order'          => 'ASC',
		] );
		$home_root = trailingslashit( home_url( '/' ) );
		$env_checks = self::get_environment_checks();
		$env_fails  = 0;
		$env_warns  = 0;
		foreach ( $env_checks as $c ) {
			if ( 'fail' === $c['status'] ) {
				$env_fails++;
			} elseif ( 'warn' === $c['status'] ) {
				$env_warns++;
			}
		}
		$env_ok_count = count( $env_checks ) - $env_fails - $env_warns;
		$env_badge_bg = $env_fails > 0 ? '#fee2e2' : ( $env_warns > 0 ? '#fef3c7' : '#dcfce7' );
		$env_badge_fg = $env_fails > 0 ? '#991b1b' : ( $env_warns > 0 ? '#92400e' : '#166534' );
		$env_summary  = $env_fails > 0
			? $env_fails . ' Action Needed' . ( $env_warns > 0 ? ', ' . $env_warns . ' Warning' : '' )
			: ( $env_warns > 0 ? $env_warns . ' Tweak' . ( $env_warns > 1 ? 's' : '' ) : 'All ' . $env_ok_count . ' Passed' );
		$export_logs = self::get_export_logs();
		$logs_dir    = self::get_logs_dir();
		?>
		<style>
			.arcos-dash { max-width: 1020px; margin-top: 16px; }
			.arcos-header { background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: #f8fafc; border-radius: 12px; padding: 20px 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; box-shadow: 0 4px 16px rgba(15, 23, 42, 0.12); }
			.arcos-header h1 { color: #fff; margin: 0 0 4px; font-size: 22px; font-weight: 700; display: flex; align-items: center; gap: 10px; }
			.arcos-ver-pill { font-size: 11px; font-weight: 600; background: rgba(59, 130, 246, 0.22); color: #93c5fd; border: 1px solid rgba(147, 197, 253, 0.3); padding: 2px 9px; border-radius: 999px; }
			.arcos-header p { margin: 0; color: #cbd5e1; font-size: 13px; }
			.arcos-header-meta { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
			.arcos-stat-pill { background: rgba(255, 255, 255, 0.08); border: 1px solid rgba(255, 255, 255, 0.14); border-radius: 8px; padding: 6px 12px; font-size: 12px; color: #e2e8f0; }
			.arcos-stat-pill strong { color: #fff; }
			.arcos-tabs { display: flex; gap: 6px; margin: 16px 0 0; border-bottom: 1px solid #cbd5e1; padding: 0 4px; flex-wrap: wrap; }
			.arcos-tab-btn { background: transparent; border: 1px solid transparent; border-bottom: none; border-radius: 8px 8px 0 0; padding: 10px 16px; font-size: 13px; font-weight: 600; color: #475569; cursor: pointer; display: inline-flex; align-items: center; gap: 7px; margin-bottom: -1px; transition: all .15s ease; }
			.arcos-tab-btn:hover { color: #0f172a; background: #f1f5f9; }
			.arcos-tab-btn.is-active { background: #fff; color: #1d4ed8; border-color: #cbd5e1; border-bottom-color: #fff; }
			.arcos-tab-count { font-size: 11px; font-weight: 700; padding: 1px 7px; border-radius: 999px; background: #e2e8f0; color: #334155; }
			.arcos-tab-btn.is-active .arcos-tab-count { background: #dbeafe; color: #1d4ed8; }
			.arcos-tab-panel { display: none; }
			.arcos-tab-panel.is-active { display: block; }
			.arcos-card { background: #fff; border: 1px solid #cbd5e1; border-top: none; border-radius: 0 0 10px 10px; padding: 22px 24px; box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04); }
			.arcos-card-standalone { background: #fff; border: 1px solid #cbd5e1; border-radius: 10px; padding: 20px 24px; margin-top: 16px; box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04); }
			.arcos-card h2, .arcos-card-standalone h2 { margin: 0 0 12px; font-size: 16px; color: #0f172a; }
			.arcos-dash .form-table th { width: 210px; padding-top: 16px; color: #1e293b; }
			.arcos-dash .form-table td { padding-top: 14px; }
			.arcos-dash .form-table tr { border-bottom: 1px solid #f1f5f9; }
			.arcos-dash .form-table tr:last-child { border-bottom: none; }
			.arcos-subbox { margin-top: 10px; padding: 14px 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; }
			.arcos-action-bar { margin-top: 18px; padding-top: 16px; border-top: 1px solid #e2e8f0; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
		</style>
		<div class="wrap arcos-dash">
			<div class="arcos-header">
				<div>
					<h1>Arcos Static Sites <span class="arcos-ver-pill">v<?php echo esc_html( self::VERSION ); ?></span></h1>
					<p>Export published WordPress &amp; Bricks pages into static ZIPs, local folders, or remote FTP/FTPS servers.</p>
				</div>
				<div class="arcos-header-meta">
					<?php if ( $has_manifest ) : ?>
						<div class="arcos-stat-pill"><strong>Last Export:</strong> <?php echo esc_html( $manifest['last_export'] ); ?> UTC</div>
						<div class="arcos-stat-pill"><strong>Pages:</strong> <?php echo esc_html( count( $manifest['pages'] ?? [] ) ); ?> &bull; <strong>Assets:</strong> <?php echo esc_html( count( $manifest['assets'] ?? [] ) ); ?></div>
						<button type="button" id="arcos-reset-manifest" class="button button-small">Reset Manifest</button>
					<?php else : ?>
						<div class="arcos-stat-pill">No baseline export yet (run <strong>Full Export</strong> first)</div>
					<?php endif; ?>
				</div>
			</div>

			<div class="arcos-tabs" role="tablist">
				<button type="button" class="arcos-tab-btn is-active" data-tab="export" role="tab">Export &amp; Delivery</button>
				<button type="button" class="arcos-tab-btn" data-tab="settings" role="tab">SEO, Forms &amp; Security</button>
				<button type="button" class="arcos-tab-btn" data-tab="archives" role="tab">Releases &amp; Archives <span class="arcos-tab-count"><?php echo esc_html( count( $zips ) ); ?></span></button>
				<button type="button" class="arcos-tab-btn" data-tab="logs" role="tab">Export Logs <span class="arcos-tab-count"><?php echo esc_html( count( $export_logs ) ); ?></span></button>
				<button type="button" class="arcos-tab-btn" data-tab="system" role="tab">
					System &amp; Updates
					<span class="arcos-tab-count" style="background:<?php echo esc_attr( $env_badge_bg ); ?>;color:<?php echo esc_attr( $env_badge_fg ); ?>;"><?php echo esc_html( $env_summary ); ?></span>
				</button>
			</div>

			<!-- TAB 1: EXPORT & DELIVERY -->
			<div class="arcos-tab-panel is-active" data-tab-panel="export">
				<div class="arcos-card">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="arcos-release-label">Release Label (Optional)</label></th>
							<td>
								<input type="text" id="arcos-release-label" value="" class="regular-text" placeholder="e.g. Before header redesign, SEO updates" />
								<p class="description">Tag this export/release so you can easily identify it later for rollback.</p>
							</td>
						</tr>
						<tr>
							<th scope="row">Export Mode</th>
							<td>
								<label style="display:block;margin-bottom:6px;">
									<input type="radio" name="arcos_mode" value="full" <?php checked( ! $has_manifest ); ?> />
									<strong>Full Export</strong> — Export all published pages &amp; assets (sets baseline manifest)
								</label>
								<label style="display:block;margin-bottom:6px;">
									<input type="radio" name="arcos_mode" value="incremental" <?php checked( $has_manifest ); ?> />
									<strong>Incremental Export (Changes Only)</strong> — Export only pages &amp; assets modified since last export
								</label>
								<label style="display:block;margin-bottom:8px;">
									<input type="radio" name="arcos_mode" value="selected" />
									<strong>Selected Pages Only</strong> — Pick specific pages or posts from the list below
								</label>
								<div id="arcos-selected-wrap" class="arcos-subbox" style="display:none;margin-left:22px;">
									<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:8px;">
										<input type="text" id="arcos-page-filter" class="regular-text" placeholder="Search pages by title, slug, or type..." style="flex:1;min-width:220px;" />
										<button type="button" id="arcos-sel-all" class="button button-small">Select Visible</button>
										<button type="button" id="arcos-sel-clear" class="button button-small">Clear</button>
										<span id="arcos-sel-count" style="font-size:12px;color:#475569;font-weight:600;">0 selected</span>
									</div>
									<div id="arcos-pages-picker" style="max-height:220px;overflow-y:auto;background:#fff;border:1px solid #cbd5e1;border-radius:4px;padding:6px 10px;">
										<?php foreach ( $picker_posts as $pp ) :
											$rel_slug = '/' . ltrim( str_replace( $home_root, '', (string) get_permalink( $pp->ID ) ), '/' );
											$search_hay = strtolower( $pp->post_type . ' ' . $pp->post_title . ' ' . $rel_slug . ' ' . $pp->ID );
											?>
											<label class="arcos-pick-row" data-search="<?php echo esc_attr( $search_hay ); ?>" style="display:flex;align-items:center;gap:8px;padding:4px 0;border-bottom:1px solid #f1f5f9;">
												<input type="checkbox" class="arcos-pick-cb" value="<?php echo esc_attr( $pp->ID ); ?>" />
												<span style="font-size:11px;text-transform:uppercase;background:#e2e8f0;color:#334155;padding:1px 6px;border-radius:4px;font-weight:700;"><?php echo esc_html( $pp->post_type ); ?></span>
												<strong><?php echo esc_html( $pp->post_title ?: '(no title)' ); ?></strong>
												<span style="color:#64748b;font-size:12px;"><?php echo esc_html( $rel_slug ); ?></span>
											</label>
										<?php endforeach; ?>
									</div>
								</div>
							</td>
						</tr>
						<tr>
							<th scope="row">Delivery Destination</th>
							<td>
								<label style="display:block;margin-bottom:6px;">
									<input type="radio" name="arcos_delivery" value="zip" <?php checked( $settings['delivery_method'], 'zip' ); ?> />
									<strong>Static ZIP Only</strong> — Package a downloadable <code>.zip</code> archive
								</label>
								<label style="display:block;margin-bottom:6px;">
									<input type="radio" name="arcos_delivery" value="push" <?php checked( $settings['delivery_method'], 'push' ); ?> />
									<strong>Push Directly to Server Folder</strong> — Copy files straight into the live static directory on this server (no ZIP download/unzip needed)
								</label>
								<label style="display:block;margin-bottom:6px;">
									<input type="radio" name="arcos_delivery" value="both" <?php checked( $settings['delivery_method'], 'both' ); ?> />
									<strong>Both</strong> — Push directly to server folder AND keep a downloadable <code>.zip</code> backup
								</label>
								<label style="display:block;margin-bottom:6px;">
									<input type="radio" name="arcos_delivery" value="ftp" <?php checked( $settings['delivery_method'], 'ftp' ); ?> />
									<strong>Push via FTP / FTPS</strong> — Upload static files directly to a remote FTP/FTPS server
								</label>
								<label style="display:block;margin-bottom:8px;">
									<input type="radio" name="arcos_delivery" value="zip_ftp" <?php checked( $settings['delivery_method'], 'zip_ftp' ); ?> />
									<strong>Both (ZIP + FTP / FTPS)</strong> — Save a downloadable <code>.zip</code> backup AND upload via FTP/FTPS
								</label>

								<div id="arcos-target-dir-wrap" class="arcos-subbox" style="<?php echo in_array( $settings['delivery_method'], [ 'push', 'both' ], true ) ? '' : 'display:none;'; ?>">
									<label for="arcos-dir-select"><strong>Detected Server Folders:</strong></label><br />
									<select id="arcos-dir-select" class="regular-text" style="margin-top:4px;max-width:100%;width:100%;">
										<option value="">— Select a detected folder or enter custom path below —</option>
										<?php foreach ( $detected_folders as $df ) : ?>
											<option value="<?php echo esc_attr( $df ); ?>" <?php selected( $settings['target_dir'], $df ); ?>><?php echo esc_html( $df ); ?></option>
										<?php endforeach; ?>
										<option value="__custom__" <?php selected( ! empty( $settings['target_dir'] ) && ! in_array( $settings['target_dir'], $detected_folders, true ) ); ?>>Custom path...</option>
									</select>
									<div style="margin-top:8px;">
										<label for="arcos-target-dir"><strong>Target Live Server Directory Path:</strong></label><br />
										<input type="text" id="arcos-target-dir" value="<?php echo esc_attr( $settings['target_dir'] ); ?>" class="large-text" placeholder="e.g. <?php echo esc_attr( $parent_hint ); ?>public_html" style="margin-top:4px;" />
									</div>
									<p style="margin:8px 0 4px;">
										<button type="button" id="arcos-check-dir-btn" class="button button-secondary button-small">Verify Folder</button>
										<span id="arcos-dir-status" style="margin-left:8px;font-weight:600;"></span>
									</p>
									<p class="description">Current WordPress path: <code><?php echo esc_html( untrailingslashit( ABSPATH ) ); ?></code>. Pick a detected folder above or type a custom path.</p>
								</div>

								<div id="arcos-ftp-wrap" class="arcos-subbox" style="<?php echo in_array( $settings['delivery_method'], [ 'ftp', 'zip_ftp' ], true ) ? '' : 'display:none;'; ?>">
									<div style="display:grid;grid-template-columns:2fr 1fr;gap:10px;margin-bottom:8px;">
										<div>
											<label for="arcos-ftp-host"><strong>FTP Host:</strong></label><br />
											<input type="text" id="arcos-ftp-host" value="<?php echo esc_attr( $settings['ftp_host'] ); ?>" class="regular-text" style="width:100%;margin-top:4px;" placeholder="e.g. ftp.example.com" />
										</div>
										<div>
											<label for="arcos-ftp-port"><strong>Port:</strong></label><br />
											<input type="number" id="arcos-ftp-port" value="<?php echo esc_attr( (string) $settings['ftp_port'] ); ?>" min="1" max="65535" style="width:100%;margin-top:4px;" />
										</div>
									</div>
									<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:8px;">
										<div>
											<label for="arcos-ftp-user"><strong>Username:</strong></label><br />
											<input type="text" id="arcos-ftp-user" value="<?php echo esc_attr( $settings['ftp_user'] ); ?>" class="regular-text" style="width:100%;margin-top:4px;" autocomplete="off" />
										</div>
										<div>
											<label for="arcos-ftp-pass"><strong>Password:</strong></label><br />
											<input type="password" id="arcos-ftp-pass" value="" class="regular-text" style="width:100%;margin-top:4px;" autocomplete="new-password" placeholder="<?php echo ! empty( $settings['ftp_pass'] ) ? '•••••••• (saved — leave blank to keep)' : 'FTP password'; ?>" />
										</div>
									</div>
									<div style="margin-bottom:8px;">
										<label for="arcos-ftp-path"><strong>Remote Directory Path:</strong></label><br />
										<input type="text" id="arcos-ftp-path" value="<?php echo esc_attr( $settings['ftp_path'] ); ?>" class="large-text" style="margin-top:4px;" placeholder="e.g. /public_html/" />
									</div>
									<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:8px;">
										<label><input type="checkbox" id="arcos-ftp-ssl" <?php checked( ! empty( $settings['ftp_ssl'] ) ); ?> /> Use FTPS (SSL/TLS)</label>
										<label><input type="checkbox" id="arcos-ftp-passive" <?php checked( ! empty( $settings['ftp_passive'] ) ); ?> /> Passive Mode (PASV, recommended)</label>
									</div>
									<p style="margin:8px 0 0;">
										<button type="button" id="arcos-test-ftp-btn" class="button button-secondary button-small">Test FTP Connection</button>
										<span id="arcos-ftp-status" style="margin-left:8px;font-weight:600;"></span>
									</p>
								</div>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="arcos-base-url">Target Base Path / URL</label></th>
							<td>
								<input type="text" id="arcos-base-url" value="<?php echo esc_attr( $settings['base_url'] ); ?>" class="regular-text" />
								<p class="description">Use <code>/</code> for root-relative hosting or <code>http://localhost:8080/</code>, or enter a full URL like <code>https://example.com/</code>.</p>
							</td>
						</tr>
					</table>

					<div class="arcos-action-bar">
						<button type="button" id="arcos-start-btn" class="button button-primary button-hero">Run Static Export</button>
						<button type="button" id="arcos-cancel-btn" class="button button-secondary button-hero" style="display:none;">Cancel Export</button>
					</div>

					<div id="arcos-progress-wrap" style="display:none;margin-top:18px;">
						<div style="background:#e2e8f0;border-radius:999px;height:14px;overflow:hidden;">
							<div id="arcos-progress-bar" style="width:0%;height:100%;background:#2563eb;transition:width .25s ease;"></div>
						</div>
						<p id="arcos-status-text" style="font-weight:600;margin:8px 0;"></p>
						<pre id="arcos-log" style="background:#0f172a;color:#e2e8f0;padding:12px 14px;border-radius:8px;max-height:240px;overflow:auto;font-size:12px;"></pre>
					</div>
				</div>
			</div>

			<!-- TAB 2: SEO, FORMS & SECURITY -->
			<div class="arcos-tab-panel" data-tab-panel="settings">
				<div class="arcos-card">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">SEO &amp; GEO (SEOPress)</th>
							<td>
								<label for="arcos-canonical-domain"><strong>Production Domain for Canonicals &amp; Schema (Optional):</strong></label><br />
								<input type="url" id="arcos-canonical-domain" value="<?php echo esc_attr( $settings['canonical_domain'] ); ?>" class="regular-text" placeholder="e.g. https://austintrs.com" style="margin:4px 0 8px;" />
								<p class="description" style="margin-bottom:10px;">When set, <code>&lt;link rel="canonical"&gt;</code>, <code>og:url</code>, JSON-LD schema, and <code>sitemap.xml</code> use this full domain even when asset paths are <code>/</code>.</p>
								<label style="display:block;margin-bottom:6px;">
									<input type="checkbox" id="arcos-force-index" <?php checked( ! empty( $settings['force_index'] ) ); ?> />
									<strong>Strip global <code>noindex</code></strong> — Output <code>index, follow</code> on exported pages even if WordPress CMS has &ldquo;Discourage search engines&rdquo; enabled (preserves per-page SEOPress <code>noindex</code> like Thank You pages)
								</label>
								<label style="display:block;margin-bottom:10px;">
									<input type="checkbox" id="arcos-gen-seo-files" <?php checked( ! empty( $settings['generate_seo_files'] ) ); ?> />
									<strong>Generate <code>sitemap.xml</code>, <code>robots.txt</code> &amp; <code>llms.txt</code></strong> (uses SEOPress PRO <code>llms.txt</code> template if configured)
								</label>
								<label style="display:block;margin-bottom:10px;">
									<input type="checkbox" id="arcos-gen-htaccess" <?php checked( ! empty( $settings['generate_htaccess'] ) ); ?> />
									<strong>Generate Optimized <code>.htaccess</code> (Caching &amp; Gzip)</strong> — Adds 1-year browser cache headers for static assets &amp; Gzip compression (Apache/LiteSpeed)
								</label>
								<label for="arcos-ga4-id"><strong>Google Tag / GA4 / GTM ID (Optional):</strong></label><br />
								<input type="text" id="arcos-ga4-id" value="<?php echo esc_attr( $settings['ga4_id'] ); ?>" class="regular-text" placeholder="e.g. GT-PHRS7VL, G-XXXXXXXXXX, or GTM-XXXXXXX" style="margin-top:4px;" />
								<p class="description">Supports <code>G-</code>, <code>GT-</code>, <code>AW-</code> (<code>gtag.js</code>) and <code>GTM-</code> (Google Tag Manager). Leave blank to keep existing page tags.</p>
							</td>
						</tr>
						<tr>
							<th scope="row">Post Types to Export</th>
							<td>
								<?php foreach ( $post_types as $pt ) : ?>
									<label style="display:inline-block;margin-right:16px;">
										<input type="checkbox" class="arcos-pt" value="<?php echo esc_attr( $pt->name ); ?>" checked />
										<?php echo esc_html( $pt->labels->name ); ?> (<code><?php echo esc_html( $pt->name ); ?></code>)
									</label>
								<?php endforeach; ?>
							</td>
						</tr>
						<tr>
							<th scope="row">Clean Assets &amp; Speed</th>
							<td>
								<label style="display:block;margin-bottom:8px;">
									<input type="checkbox" id="arcos-pagespeed-off" <?php checked( ! empty( $settings['pagespeed_off'] ) ); ?> />
									Append <code>?PageSpeed=off</code> when rendering pages (recommended on hosts using mod_pagespeed)
								</label>
								<label for="arcos-batch-size"><strong>Pages per Batch:</strong></label>
								<select id="arcos-batch-size" style="margin-left:6px;">
									<option value="2" <?php selected( (int) $settings['batch_size'], 2 ); ?>>2 (Slow / shared servers)</option>
									<option value="4" <?php selected( (int) $settings['batch_size'], 4 ); ?>>4 (Recommended)</option>
									<option value="8" <?php selected( (int) $settings['batch_size'], 8 ); ?>>8 (Fast servers)</option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row">Bricks Form Bridge</th>
							<td>
								<label style="display:block;margin-bottom:8px;">
									<input type="checkbox" id="arcos-form-bridge" <?php checked( ! empty( $settings['enable_form_bridge'] ) ); ?> />
									Inject AJAX bridge so Bricks forms submit through WordPress (<code><?php echo esc_html( $rest_url ); ?></code>), resolve dynamic tags, and save submissions
								</label>
								<label for="arcos-allowed-origins"><strong>Allowed CORS Origins:</strong></label><br />
								<input type="text" id="arcos-allowed-origins" value="<?php echo esc_attr( $settings['allowed_origins'] ); ?>" class="regular-text" />
							</td>
						</tr>
						<tr>
							<th scope="row">Private CMS Mode</th>
							<td>
								<label style="display:block;margin-bottom:6px;">
									<input type="checkbox" id="arcos-private-cms" <?php checked( ! empty( $settings['private_cms_mode'] ) ); ?> />
									<strong>Lock WordPress frontend</strong> — Redirect logged-out public visitors to <code>wp-login.php</code> (REST form endpoint &amp; internal static exports bypass automatically)
								</label>
								<span id="arcos-lock-status" style="font-size:12px;font-weight:600;color:#475569;"></span>
							</td>
						</tr>
					</table>
					<p class="description" style="margin-top:12px;">Settings on this tab are automatically saved when you click <strong>Run Static Export</strong> on the <strong>Export &amp; Delivery</strong> tab (Private CMS Mode saves immediately).</p>
				</div>
			</div>

			<!-- TAB 3: RELEASES & ARCHIVES -->
			<div class="arcos-tab-panel" data-tab-panel="archives">
				<div class="arcos-card">
					<h2>Generated Static Archives</h2>
					<table class="widefat striped" id="arcos-zips-table">
						<thead>
							<tr>
								<th>Label</th>
								<th>Archive</th>
								<th>Size</th>
								<th>Created</th>
								<th>Actions</th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $zips ) ) : ?>
								<tr id="arcos-empty-row"><td colspan="5">No static exports generated yet.</td></tr>
							<?php else : ?>
								<?php foreach ( $zips as $zip_path ) :
									$fname = basename( $zip_path );
									$url   = trailingslashit( $storage_url ) . rawurlencode( $fname );
									$size  = size_format( filesize( $zip_path ), 2 );
									$mtime = date_i18n( 'Y-m-d H:i:s', filemtime( $zip_path ) );
									$rel_meta = $releases[ $fname ] ?? [];
									$r_label  = is_array( $rel_meta ) && ! empty( $rel_meta['label'] ) ? (string) $rel_meta['label'] : '';
									?>
									<tr>
										<td>
											<span class="arcos-rel-label-text"><?php echo $r_label ? esc_html( $r_label ) : '<em style="color:#94a3b8;">No label</em>'; ?></span>
											<button type="button" class="button-link arcos-edit-label" data-file="<?php echo esc_attr( $fname ); ?>" data-label="<?php echo esc_attr( $r_label ); ?>" style="margin-left:6px;font-size:12px;">Edit</button>
										</td>
										<td><code><?php echo esc_html( $fname ); ?></code></td>
										<td><?php echo esc_html( $size ); ?></td>
										<td><?php echo esc_html( $mtime ); ?></td>
										<td>
											<a href="<?php echo esc_url( $url ); ?>" class="button button-primary button-small" download>Download ZIP</a>
											<button type="button" class="button button-secondary button-small arcos-restore-zip" data-file="<?php echo esc_attr( $fname ); ?>">Push / Restore to Folder</button>
											<button type="button" class="button button-small arcos-del-zip" data-file="<?php echo esc_attr( $fname ); ?>">Delete</button>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>

			<!-- TAB 4: EXPORT LOGS -->
			<div class="arcos-tab-panel" data-tab-panel="logs">
				<div class="arcos-card">
					<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px;flex-wrap:wrap;">
						<h2 style="margin:0;">Export Logs</h2>
						<?php if ( ! empty( $export_logs ) ) : ?>
							<button type="button" id="arcos-clear-logs" class="button button-small">Clear All Logs</button>
						<?php endif; ?>
					</div>
					<table class="widefat striped" id="arcos-logs-table">
						<thead>
							<tr>
								<th>Date (UTC)</th>
								<th>Mode / Delivery</th>
								<th>Label</th>
								<th>Pages</th>
								<th>Status</th>
								<th>Size</th>
								<th>Actions</th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $export_logs ) ) : ?>
								<tr><td colspan="7">No export logs recorded yet.</td></tr>
							<?php else : ?>
								<?php foreach ( $export_logs as $lg ) :
									$lid      = (string) ( $lg['id'] ?? '' );
									$lfile    = $logs_dir . '/export-' . sanitize_file_name( $lid ) . '.log';
									$lsize    = file_exists( $lfile ) ? size_format( filesize( $lfile ), 1 ) : '—';
									$lstatus  = (string) ( $lg['status'] ?? 'running' );
									$st_bg    = 'completed' === $lstatus ? '#dcfce7' : ( 'failed' === $lstatus ? '#fee2e2' : ( 'cancelled' === $lstatus ? '#fef3c7' : ( 'no_changes' === $lstatus ? '#f1f5f9' : '#dbeafe' ) ) );
									$st_fg    = 'completed' === $lstatus ? '#166534' : ( 'failed' === $lstatus ? '#991b1b' : ( 'cancelled' === $lstatus ? '#92400e' : ( 'no_changes' === $lstatus ? '#475569' : '#1e40af' ) ) );
									$dl_url   = add_query_arg( [
										'action' => 'arcos_ss_download_log',
										'nonce'  => $nonce,
										'id'     => $lid,
									], admin_url( 'admin-ajax.php' ) );
									?>
									<tr data-log-id="<?php echo esc_attr( $lid ); ?>">
										<td><?php echo esc_html( $lg['created_at'] ?? '' ); ?></td>
										<td><code><?php echo esc_html( ( $lg['mode'] ?? '' ) . ' / ' . ( $lg['delivery'] ?? '' ) ); ?></code></td>
										<td><?php echo ! empty( $lg['label'] ) ? esc_html( $lg['label'] ) : '<em style="color:#94a3b8;">—</em>'; ?></td>
										<td><?php echo esc_html( (string) ( $lg['total'] ?? 0 ) ); ?></td>
										<td>
											<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;background:<?php echo esc_attr( $st_bg ); ?>;color:<?php echo esc_attr( $st_fg ); ?>;">
												<?php echo esc_html( ucwords( str_replace( '_', ' ', $lstatus ) ) ); ?>
											</span>
										</td>
										<td><?php echo esc_html( $lsize ); ?></td>
										<td>
											<button type="button" class="button button-secondary button-small arcos-view-log" data-id="<?php echo esc_attr( $lid ); ?>">View</button>
											<a href="<?php echo esc_url( $dl_url ); ?>" class="button button-small">Download</a>
											<button type="button" class="button button-small arcos-del-log" data-id="<?php echo esc_attr( $lid ); ?>">Delete</button>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>

			<!-- TAB 5: SYSTEM & UPDATES -->
			<div class="arcos-tab-panel" data-tab-panel="system">
				<div class="arcos-card">
					<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px;flex-wrap:wrap;">
						<h2 style="margin:0;">Server &amp; PHP Readiness Checklist</h2>
						<span style="font-size:12px;font-weight:600;padding:3px 10px;border-radius:999px;background:<?php echo esc_attr( $env_badge_bg ); ?>;color:<?php echo esc_attr( $env_badge_fg ); ?>;">
							<?php echo esc_html( $env_summary ); ?>
						</span>
					</div>
					<table class="widefat striped" style="margin-bottom:20px;">
						<thead>
							<tr>
								<th>Check</th>
								<th>Current</th>
								<th>Recommended</th>
								<th>Status</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $env_checks as $c ) :
								$st_color = 'ok' === $c['status'] ? '#15803d' : ( 'warn' === $c['status'] ? '#b45309' : '#b91c1c' );
								$st_label = 'ok' === $c['status'] ? '✓ OK' : ( 'warn' === $c['status'] ? '⚠ Tweak' : '✕ Action Needed' );
							?>
								<tr>
									<td><strong><?php echo esc_html( $c['label'] ); ?></strong></td>
									<td><code><?php echo esc_html( $c['current'] ); ?></code></td>
									<td><?php echo esc_html( $c['target'] ); ?></td>
									<td>
										<strong style="color:<?php echo esc_attr( $st_color ); ?>;"><?php echo esc_html( $st_label ); ?></strong>
										<?php if ( '' !== $c['tip'] ) : ?>
											<div style="font-size:12px;color:#475569;margin-top:2px;"><?php echo esc_html( $c['tip'] ); ?></div>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<h2>GitHub Plugin Updates</h2>
					<div class="arcos-subbox">
						<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:6px;">
							<button type="button" id="arcos-check-updates" class="button button-secondary">Check for Updates Now</button>
							<span id="arcos-update-status" style="font-size:12px;font-weight:600;color:#475569;"></span>
						</div>
						<p class="description" style="margin-bottom:6px;">Automatic updates from GitHub Releases: <code><?php echo esc_html( self::GITHUB_REPO ); ?></code> (no token required for public repos).</p>
						<?php
						$has_const_token = defined( 'ARCOS_STATIC_SITES_GITHUB_TOKEN' ) && is_string( ARCOS_STATIC_SITES_GITHUB_TOKEN ) && '' !== trim( ARCOS_STATIC_SITES_GITHUB_TOKEN );
						$has_saved_token = ! empty( $settings['github_token'] );
						?>
						<?php if ( $has_const_token ) : ?>
							<p class="description">Authenticated via <code>ARCOS_STATIC_SITES_GITHUB_TOKEN</code> in <code>wp-config.php</code>.</p>
						<?php else : ?>
							<details style="margin-top:4px;">
								<summary style="cursor:pointer;font-size:12px;color:#475569;">Optional: GitHub Token (only for private forks / rate limits)</summary>
								<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:6px;">
									<input type="password" id="arcos-github-token" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $has_saved_token ? '•••••••••••••••• (token saved — enter new to replace)' : 'github_pat_... (optional)'; ?>" />
									<button type="button" id="arcos-save-token" class="button button-small">Save Token</button>
									<?php if ( $has_saved_token ) : ?>
										<button type="button" id="arcos-clear-token" class="button button-small">Clear</button>
									<?php endif; ?>
								</div>
							</details>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<div id="arcos-log-modal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.65);z-index:100100;align-items:center;justify-content:center;padding:24px;">
				<div style="background:#fff;border-radius:10px;max-width:860px;width:100%;max-height:85vh;display:flex;flex-direction:column;box-shadow:0 20px 50px rgba(0,0,0,0.3);">
					<div style="padding:14px 18px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;gap:10px;">
						<strong id="arcos-log-modal-title" style="font-size:14px;">Export Log</strong>
						<div style="display:flex;gap:8px;align-items:center;">
							<button type="button" id="arcos-log-modal-copy" class="button button-small">Copy</button>
							<a href="#" id="arcos-log-modal-dl" class="button button-small">Download</a>
							<button type="button" id="arcos-log-modal-close" class="button button-small">Close</button>
						</div>
					</div>
					<pre id="arcos-log-modal-body" style="margin:0;padding:16px 18px;background:#0f172a;color:#e2e8f0;font-size:12px;line-height:1.5;overflow:auto;flex:1;border-radius:0 0 10px 10px;white-space:pre-wrap;word-break:break-word;"></pre>
				</div>
			</div>
		</div>

		<script>
		(function() {
			const tabBtns   = document.querySelectorAll('.arcos-tab-btn');
			const tabPanels = document.querySelectorAll('.arcos-tab-panel');
			function activateTab(tabName) {
				if (!tabName) return;
				tabBtns.forEach(b => b.classList.toggle('is-active', b.dataset.tab === tabName));
				tabPanels.forEach(p => p.classList.toggle('is-active', p.dataset.tabPanel === tabName));
				try { sessionStorage.setItem('arcos_ss_active_tab', tabName); } catch (_) {}
			}
			tabBtns.forEach(btn => {
				btn.addEventListener('click', () => activateTab(btn.dataset.tab));
			});
			try {
				const savedTab = sessionStorage.getItem('arcos_ss_active_tab');
				if (savedTab && document.querySelector('.arcos-tab-btn[data-tab="' + savedTab + '"]')) {
					activateTab(savedTab);
				}
			} catch (_) {}

			const ajaxUrl   = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			const nonce     = <?php echo wp_json_encode( $nonce ); ?>;
			const startBtn  = document.getElementById('arcos-start-btn');
			const cancelBtn = document.getElementById('arcos-cancel-btn');
			const wrap      = document.getElementById('arcos-progress-wrap');
			const bar       = document.getElementById('arcos-progress-bar');
			const statusEl  = document.getElementById('arcos-status-text');
			const logEl     = document.getElementById('arcos-log');
			const selWrap   = document.getElementById('arcos-selected-wrap');
			const dirWrap   = document.getElementById('arcos-target-dir-wrap');
			const ftpWrap   = document.getElementById('arcos-ftp-wrap');
			const dirSelect = document.getElementById('arcos-dir-select');
			const dirInput  = document.getElementById('arcos-target-dir');
			const checkBtn  = document.getElementById('arcos-check-dir-btn');
			const dirStatus = document.getElementById('arcos-dir-status');

			if (dirSelect) {
				dirSelect.addEventListener('change', () => {
					if (dirSelect.value && dirSelect.value !== '__custom__') {
						dirInput.value = dirSelect.value;
					} else if (dirSelect.value === '__custom__') {
						dirInput.focus();
					}
				});
			}

			document.querySelectorAll('input[name="arcos_mode"]').forEach(r => {
				r.addEventListener('change', () => {
					selWrap.style.display = (r.value === 'selected' && r.checked) ? 'block' : 'none';
				});
			});

			const pageFilter = document.getElementById('arcos-page-filter');
			const selCount   = document.getElementById('arcos-sel-count');
			function updateSelCount() {
				selCount.textContent = document.querySelectorAll('.arcos-pick-cb:checked').length + ' selected';
			}
			if (pageFilter) {
				pageFilter.addEventListener('input', () => {
					const q = pageFilter.value.trim().toLowerCase();
					document.querySelectorAll('.arcos-pick-row').forEach(row => {
						row.style.display = (!q || row.dataset.search.includes(q)) ? 'flex' : 'none';
					});
				});
				document.getElementById('arcos-sel-all').addEventListener('click', () => {
					document.querySelectorAll('.arcos-pick-row').forEach(row => {
						if (row.style.display !== 'none') row.querySelector('.arcos-pick-cb').checked = true;
					});
					updateSelCount();
				});
				document.getElementById('arcos-sel-clear').addEventListener('click', () => {
					document.querySelectorAll('.arcos-pick-cb').forEach(cb => cb.checked = false);
					updateSelCount();
				});
				document.querySelectorAll('.arcos-pick-cb').forEach(cb => cb.addEventListener('change', updateSelCount));
			}

			document.querySelectorAll('input[name="arcos_delivery"]').forEach(r => {
				r.addEventListener('change', () => {
					const val = document.querySelector('input[name="arcos_delivery"]:checked').value;
					dirWrap.style.display = (val === 'push' || val === 'both') ? 'block' : 'none';
					if (ftpWrap) ftpWrap.style.display = (val === 'ftp' || val === 'zip_ftp') ? 'block' : 'none';
				});
			});

			function log(msg) {
				logEl.textContent += msg + "\n";
				logEl.scrollTop = logEl.scrollHeight;
			}

			async function postAction(action, data, signal) {
				const form = new FormData();
				form.append('action', action);
				form.append('nonce', nonce);
				for (const [k, v] of Object.entries(data || {})) {
					if (Array.isArray(v)) {
						v.forEach(item => form.append(k + '[]', item));
					} else {
						form.append(k, v);
					}
				}
				const fetchOpts = { method: 'POST', body: form };
				if (signal) fetchOpts.signal = signal;
				let res;
				try {
					res = await fetch(ajaxUrl, fetchOpts);
				} catch (netErr) {
					if (netErr && netErr.name === 'AbortError') throw netErr;
					throw new Error(action + ' network error: ' + (netErr && netErr.message ? netErr.message : 'Failed to fetch'));
				}
				const raw = await res.text();
				try {
					return JSON.parse(raw);
				} catch (parseErr) {
					const clean = raw
						.replace(/<style[\s\S]*?<\/style>/gi, ' ')
						.replace(/<script[\s\S]*?<\/script>/gi, ' ')
						.replace(/<[^>]+>/g, ' ')
						.replace(/\s+/g, ' ')
						.trim()
						.slice(0, 300);
					throw new Error(action + ' returned non-JSON (HTTP ' + res.status + '): ' + (clean || '[empty response]'));
				}
			}

			checkBtn.addEventListener('click', async function() {
				dirStatus.textContent = 'Checking...';
				dirStatus.style.color = '#475569';
				const res = await postAction('arcos_ss_check_dir', {
					target_dir: document.getElementById('arcos-target-dir').value.trim()
				});
				if (res.success) {
					dirStatus.textContent = 'OK: Writable (' + res.data.path + ')';
					dirStatus.style.color = '#15803d';
				} else {
					dirStatus.textContent = res.data || 'Invalid directory';
					dirStatus.style.color = '#b91c1c';
				}
			});

			const testFtpBtn = document.getElementById('arcos-test-ftp-btn');
			const ftpStatus  = document.getElementById('arcos-ftp-status');
			if (testFtpBtn) {
				testFtpBtn.addEventListener('click', async function() {
					ftpStatus.textContent = 'Connecting...';
					ftpStatus.style.color = '#475569';
					try {
						const res = await postAction('arcos_ss_test_ftp', {
							ftp_host: document.getElementById('arcos-ftp-host').value.trim(),
							ftp_port: document.getElementById('arcos-ftp-port').value.trim() || '21',
							ftp_user: document.getElementById('arcos-ftp-user').value.trim(),
							ftp_pass: document.getElementById('arcos-ftp-pass').value,
							ftp_path: document.getElementById('arcos-ftp-path').value.trim() || '/',
							ftp_ssl: document.getElementById('arcos-ftp-ssl').checked ? '1' : '0',
							ftp_passive: document.getElementById('arcos-ftp-passive').checked ? '1' : '0'
						});
						if (res.success) {
							ftpStatus.textContent = 'OK: ' + (res.data.message || 'Connected');
							ftpStatus.style.color = '#15803d';
						} else {
							ftpStatus.textContent = res.data || 'Connection failed';
							ftpStatus.style.color = '#b91c1c';
						}
					} catch (e) {
						ftpStatus.textContent = e.message || 'Connection failed';
						ftpStatus.style.color = '#b91c1c';
					}
				});
			}

			startBtn.addEventListener('click', async function() {
				const pts = Array.from(document.querySelectorAll('.arcos-pt:checked')).map(el => el.value);
				const modeEl = document.querySelector('input[name="arcos_mode"]:checked');
				const mode = modeEl ? modeEl.value : 'full';
				const delEl = document.querySelector('input[name="arcos_delivery"]:checked');
				const delivery = delEl ? delEl.value : 'zip';
				const targetDir = document.getElementById('arcos-target-dir').value.trim();
				const selectedIds = Array.from(document.querySelectorAll('.arcos-pick-cb:checked')).map(cb => cb.value);
				const baseUrl = document.getElementById('arcos-base-url').value.trim() || '/';
				const releaseLabel = document.getElementById('arcos-release-label').value.trim();
				const canonicalDomain = document.getElementById('arcos-canonical-domain').value.trim();
				const forceIndex = document.getElementById('arcos-force-index').checked ? '1' : '0';
				const genSeoFiles = document.getElementById('arcos-gen-seo-files').checked ? '1' : '0';
				const genHtaccess = document.getElementById('arcos-gen-htaccess').checked ? '1' : '0';
				const ga4Id = document.getElementById('arcos-ga4-id').value.trim();
				const pagespeedOff = document.getElementById('arcos-pagespeed-off').checked ? '1' : '0';
				const batchSize = parseInt(document.getElementById('arcos-batch-size').value, 10) || 4;
				const formBridge = document.getElementById('arcos-form-bridge').checked ? '1' : '0';
				const allowedOrigins = document.getElementById('arcos-allowed-origins').value.trim() || '*';

				window.onbeforeunload = function() { return 'Static export is still running. Leaving now will cancel it.'; };
				startBtn.disabled = true;
				let exportCancelled = false;
				let activeLogId = '';
				const abortCtrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
				if (cancelBtn) {
					cancelBtn.style.display = 'inline-block';
					cancelBtn.disabled = false;
					cancelBtn.textContent = 'Cancel Export';
					cancelBtn.onclick = function() {
						exportCancelled = true;
						cancelBtn.disabled = true;
						cancelBtn.textContent = 'Cancelling...';
						statusEl.textContent = 'Cancelling export...';
						if (abortCtrl) abortCtrl.abort();
					};
				}
				wrap.style.display = 'block';
				logEl.textContent = '';
				bar.style.width = '5%';
				statusEl.textContent = 'Analyzing changes...';

				try {
					const initRes = await postAction('arcos_ss_init', {
						mode,
						delivery,
						target_dir: targetDir,
						selected_ids: selectedIds,
						post_types: pts,
						base_url: baseUrl,
						release_label: releaseLabel,
						canonical_domain: canonicalDomain,
						force_index: forceIndex,
						gen_seo_files: genSeoFiles,
						gen_htaccess: genHtaccess,
						ga4_id: ga4Id,
						pagespeed_off: pagespeedOff,
						batch_size: batchSize,
						form_bridge: formBridge,
						allowed_origins: allowedOrigins,
						ftp_host: document.getElementById('arcos-ftp-host') ? document.getElementById('arcos-ftp-host').value.trim() : '',
						ftp_port: document.getElementById('arcos-ftp-port') ? (document.getElementById('arcos-ftp-port').value.trim() || '21') : '21',
						ftp_user: document.getElementById('arcos-ftp-user') ? document.getElementById('arcos-ftp-user').value.trim() : '',
						ftp_pass: document.getElementById('arcos-ftp-pass') ? document.getElementById('arcos-ftp-pass').value : '',
						ftp_path: document.getElementById('arcos-ftp-path') ? (document.getElementById('arcos-ftp-path').value.trim() || '/') : '/',
						ftp_ssl: document.getElementById('arcos-ftp-ssl') && document.getElementById('arcos-ftp-ssl').checked ? '1' : '0',
						ftp_passive: document.getElementById('arcos-ftp-passive') && document.getElementById('arcos-ftp-passive').checked ? '1' : '0'
					}, abortCtrl ? abortCtrl.signal : undefined);
					if (!initRes.success) throw new Error(initRes.data || 'Init failed');

					activeLogId = initRes.data.log_id || '';
					const total = initRes.data.total;
					if (initRes.data.note) log(initRes.data.note);

					if (total === 0) {
						bar.style.width = '100%';
						statusEl.textContent = 'No page or asset changes detected since last export.';
						log('Nothing new to export.');
						startBtn.disabled = false;
						return;
					}

					log('Queued ' + total + ' URL(s) [' + mode + ' mode, delivery=' + delivery + ', batch=' + batchSize + '].');

					for (let offset = 0; offset < total; offset += batchSize) {
						if (exportCancelled) break;
						statusEl.textContent = 'Exporting pages ' + (offset + 1) + '–' + Math.min(offset + batchSize, total) + ' of ' + total + '...';
						let bRes;
						try {
							bRes = await postAction('arcos_ss_batch', { offset, limit: batchSize }, abortCtrl ? abortCtrl.signal : undefined);
						} catch (batchErr) {
							if (exportCancelled || (batchErr && batchErr.name === 'AbortError')) throw batchErr;
							log('Batch ' + (offset + 1) + ' transient error (' + batchErr.message + '), retrying once...');
							await new Promise(r => setTimeout(r, 2000));
							bRes = await postAction('arcos_ss_batch', { offset, limit: batchSize }, abortCtrl ? abortCtrl.signal : undefined);
						}
						if (!bRes.success) throw new Error(bRes.data || 'Batch failed');
						bRes.data.logs.forEach(l => log(l));
						const pct = Math.min(90, Math.round(((offset + batchSize) / total) * 85) + 5);
						bar.style.width = pct + '%';
					}

					if (exportCancelled) {
						statusEl.textContent = 'Export cancelled.';
						log('CANCELLED: Export stopped by user.');
						try {
							await postAction('arcos_ss_cancel', { log_id: activeLogId });
						} catch (_) {}
						return;
					}

					statusEl.textContent = 'Finalizing delivery...';
					const finRes = await postAction('arcos_ss_zip', {}, abortCtrl ? abortCtrl.signal : undefined);
					if (!finRes.success) throw new Error(finRes.data || 'Finalize failed');

					bar.style.width = '100%';
					let summaryHtml = 'Done! ';
					if (finRes.data.pushed_files) {
						summaryHtml += 'Pushed ' + finRes.data.pushed_files + ' file(s) to live folder. ';
						log('Pushed ' + finRes.data.pushed_files + ' file(s) to ' + finRes.data.target_dir);
					}
					if (finRes.data.ftp_files) {
						summaryHtml += 'Uploaded ' + finRes.data.ftp_files + ' file(s) via FTP. ';
						log('Uploaded ' + finRes.data.ftp_files + ' file(s) via FTP to ' + finRes.data.ftp_dest);
					}
					if (finRes.data.url) {
						summaryHtml += '<a href="' + finRes.data.url + '" class="button button-primary" download>Download ' + finRes.data.filename + ' (' + finRes.data.size + ')</a>';
						log('Archive created: ' + finRes.data.filename + ' (' + finRes.data.files + ' files, ' + finRes.data.size + ')');
					}
					statusEl.innerHTML = summaryHtml;
				} catch (err) {
					if (exportCancelled || (err && err.name === 'AbortError')) {
						statusEl.textContent = 'Export cancelled.';
						log('CANCELLED: Export stopped by user.');
						try {
							await postAction('arcos_ss_cancel', { log_id: activeLogId });
						} catch (_) {}
					} else {
						statusEl.textContent = 'Error: ' + err.message;
						log('ERROR: ' + err.message);
						if (activeLogId) {
							try {
								await postAction('arcos_ss_log_error', { log_id: activeLogId, error: err.message });
							} catch (_) {}
						}
					}
				} finally {
					window.onbeforeunload = null;
					startBtn.disabled = false;
					if (cancelBtn) {
						cancelBtn.style.display = 'none';
						cancelBtn.onclick = null;
					}
				}
			});

			document.querySelectorAll('.arcos-edit-label').forEach(btn => {
				btn.addEventListener('click', async function() {
					const file = this.dataset.file;
					const current = this.dataset.label || '';
					const next = prompt('Enter release label for ' + file + ':', current);
					if (next === null) return;
					const res = await postAction('arcos_ss_label_zip', { file, label: next.trim() });
					if (res.success) location.reload();
				});
			});

			document.querySelectorAll('.arcos-del-zip').forEach(btn => {
				btn.addEventListener('click', async function() {
					const res = await postAction('arcos_ss_delete_zip', { file: this.dataset.file });
					if (res.success) location.reload();
				});
			});

			document.querySelectorAll('.arcos-restore-zip').forEach(btn => {
				btn.addEventListener('click', async function() {
					const file = this.dataset.file;
					const targetDir = (dirInput ? dirInput.value.trim() : '');
					if (!targetDir) {
						alert('Please select or enter a Target Live Server Directory Path above (under Push Directly to Server Folder / Both) first.');
						return;
					}
					if (!confirm('Extract and push "' + file + '" directly into:\n' + targetDir + '\n\nExisting matching files will be overwritten. Continue?')) {
						return;
					}
					const orig = this.textContent;
					this.disabled = true;
					this.textContent = 'Restoring...';
					try {
						const res = await postAction('arcos_ss_restore_zip', { file, target_dir: targetDir });
						if (res.success) {
							alert('Restored ' + res.data.files + ' file(s) into ' + res.data.target_dir);
						} else {
							alert('Restore failed: ' + (res.data || 'Unknown error'));
						}
					} finally {
						this.disabled = false;
						this.textContent = orig;
					}
				});
			});

			const resetBtn = document.getElementById('arcos-reset-manifest');
			if (resetBtn) {
				resetBtn.addEventListener('click', async function() {
					const res = await postAction('arcos_ss_reset_manifest', {});
					if (res.success) location.reload();
				});
			}

			const privCb = document.getElementById('arcos-private-cms');
			const lockSt = document.getElementById('arcos-lock-status');
			if (privCb) {
				privCb.addEventListener('change', async function() {
					lockSt.textContent = 'Saving...';
					const res = await postAction('arcos_ss_save_lock', { private_cms: privCb.checked ? '1' : '0' });
					if (res.success) {
						lockSt.textContent = privCb.checked ? 'Frontend locked (redirects visitors to login).' : 'Frontend unlocked.';
						lockSt.style.color = privCb.checked ? '#15803d' : '#475569';
					} else {
						lockSt.textContent = 'Failed to save setting.';
						lockSt.style.color = '#b91c1c';
					}
				});
			}

			const ghTokenInput = document.getElementById('arcos-github-token');
			const saveTokenBtn = document.getElementById('arcos-save-token');
			const clearTokenBtn = document.getElementById('arcos-clear-token');
			const checkUpdBtn  = document.getElementById('arcos-check-updates');
			const updStatus    = document.getElementById('arcos-update-status');

			if (saveTokenBtn && ghTokenInput) {
				saveTokenBtn.addEventListener('click', async function() {
					const token = ghTokenInput.value.trim();
					if (!token) {
						updStatus.textContent = 'Please enter a GitHub token first.';
						updStatus.style.color = '#b91c1c';
						return;
					}
					updStatus.textContent = 'Saving token...';
					updStatus.style.color = '#475569';
					const res = await postAction('arcos_ss_save_github_token', { token });
					if (res.success) {
						ghTokenInput.value = '';
						ghTokenInput.placeholder = '•••••••••••••••• (token saved — enter new to replace)';
						updStatus.textContent = 'GitHub token saved.';
						updStatus.style.color = '#15803d';
					} else {
						updStatus.textContent = (res.data || 'Failed to save token.');
						updStatus.style.color = '#b91c1c';
					}
				});
			}

			if (clearTokenBtn) {
				clearTokenBtn.addEventListener('click', async function() {
					updStatus.textContent = 'Clearing token...';
					const res = await postAction('arcos_ss_save_github_token', { clear: '1' });
					if (res.success) {
						if (ghTokenInput) {
							ghTokenInput.value = '';
							ghTokenInput.placeholder = 'github_pat_... (fine-grained token with Contents: Read)';
						}
						clearTokenBtn.remove();
						updStatus.textContent = 'GitHub token cleared.';
						updStatus.style.color = '#475569';
					}
				});
			}

			if (checkUpdBtn) {
				checkUpdBtn.addEventListener('click', async function() {
					const orig = checkUpdBtn.textContent;
					checkUpdBtn.disabled = true;
					checkUpdBtn.textContent = 'Checking...';
					updStatus.textContent = '';
					const res = await postAction('arcos_ss_check_updates', {});
					checkUpdBtn.disabled = false;
					checkUpdBtn.textContent = orig;
					if (res.success && res.data) {
						updStatus.innerHTML = res.data.message_html || '';
						updStatus.style.color = res.data.has_update ? '#1d4ed8' : '#15803d';
					} else {
						updStatus.textContent = (res.data || 'Update check failed.');
						updStatus.style.color = '#b91c1c';
					}
				});
			}

			const logModal      = document.getElementById('arcos-log-modal');
			const logModalTitle = document.getElementById('arcos-log-modal-title');
			const logModalBody  = document.getElementById('arcos-log-modal-body');
			const logModalDl    = document.getElementById('arcos-log-modal-dl');
			const logModalCopy  = document.getElementById('arcos-log-modal-copy');
			const logModalClose = document.getElementById('arcos-log-modal-close');

			if (logModalClose) {
				logModalClose.addEventListener('click', () => { logModal.style.display = 'none'; });
				logModal.addEventListener('click', (e) => { if (e.target === logModal) logModal.style.display = 'none'; });
			}
			if (logModalCopy) {
				logModalCopy.addEventListener('click', async () => {
					try {
						await navigator.clipboard.writeText(logModalBody.textContent || '');
						const orig = logModalCopy.textContent;
						logModalCopy.textContent = 'Copied!';
						setTimeout(() => { logModalCopy.textContent = orig; }, 1200);
					} catch (_) {}
				});
			}

			document.querySelectorAll('.arcos-view-log').forEach(btn => {
				btn.addEventListener('click', async function() {
					const id = this.dataset.id;
					const orig = this.textContent;
					this.disabled = true;
					this.textContent = 'Loading...';
					try {
						const res = await postAction('arcos_ss_get_log', { id });
						if (res.success && res.data) {
							logModalTitle.textContent = 'Export Log — ' + id;
							logModalBody.textContent = res.data.content || '(empty log)';
							logModalDl.href = ajaxUrl + '?action=arcos_ss_download_log&nonce=' + encodeURIComponent(nonce) + '&id=' + encodeURIComponent(id);
							logModal.style.display = 'flex';
						} else {
							alert(res.data || 'Could not load log.');
						}
					} catch (e) {
						alert(e.message || 'Could not load log.');
					} finally {
						this.disabled = false;
						this.textContent = orig;
					}
				});
			});

			document.querySelectorAll('.arcos-del-log').forEach(btn => {
				btn.addEventListener('click', async function() {
					if (!confirm('Delete this export log?')) return;
					const res = await postAction('arcos_ss_delete_log', { id: this.dataset.id });
					if (res.success) location.reload();
				});
			});

			const clearLogsBtn = document.getElementById('arcos-clear-logs');
			if (clearLogsBtn) {
				clearLogsBtn.addEventListener('click', async function() {
					if (!confirm('Delete ALL saved export logs?')) return;
					const res = await postAction('arcos_ss_clear_logs', {});
					if (res.success) location.reload();
				});
			}
		})();
		</script>
		<?php
	}

	public static function ajax_check_dir() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$target = isset( $_POST['target_dir'] ) ? sanitize_text_field( wp_unslash( $_POST['target_dir'] ) ) : '';
		$valid  = self::validate_target_dir( $target );
		if ( is_wp_error( $valid ) ) {
			wp_send_json_error( $valid->get_error_message() );
		}
		wp_send_json_success( [ 'path' => $valid ] );
	}

	public static function ajax_save_lock() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$settings = self::get_settings();
		$settings['private_cms_mode'] = ( isset( $_POST['private_cms'] ) && '1' === $_POST['private_cms'] ) ? 1 : 0;
		update_option( self::OPT_KEY, $settings );
		wp_send_json_success();
	}

	public static function ajax_save_github_token() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$settings = self::get_settings();
		if ( isset( $_POST['clear'] ) && '1' === $_POST['clear'] ) {
			$settings['github_token'] = '';
		} else {
			$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
			if ( '' === trim( $token ) ) {
				wp_send_json_error( 'Token cannot be empty.' );
			}
			$settings['github_token'] = trim( $token );
		}
		update_option( self::OPT_KEY, $settings );
		delete_transient( self::UPDATE_CACHE_KEY );
		wp_send_json_success();
	}

	public static function ajax_check_updates() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		delete_transient( self::UPDATE_CACHE_KEY );
		$release = self::fetch_latest_github_release( true );
		if ( is_wp_error( $release ) ) {
			wp_send_json_error( $release->get_error_message() );
		}

		// Force WordPress to refresh its update_plugins transient so Plugins page shows the update immediately.
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		$has_update = version_compare( $release['version'], self::VERSION, '>' );
		if ( $has_update ) {
			$plugins_url  = esc_url( self_admin_url( 'plugins.php' ) );
			$message_html = sprintf(
				'Update available: <strong>v%s</strong> (installed: v%s) — <a href="%s">Go to Plugins to update</a>',
				esc_html( $release['version'] ),
				esc_html( self::VERSION ),
				$plugins_url
			);
		} else {
			$message_html = sprintf(
				'Up to date (installed: v%s, latest on GitHub: v%s).',
				esc_html( self::VERSION ),
				esc_html( $release['version'] )
			);
		}

		wp_send_json_success( [
			'has_update'   => $has_update,
			'version'      => $release['version'],
			'message_html' => $message_html,
		] );
	}

	public static function ajax_reset_manifest() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		delete_option( self::MANIFEST_KEY );
		wp_send_json_success();
	}

	public static function ajax_init() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$mode            = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'full';
		$delivery        = isset( $_POST['delivery'] ) ? sanitize_key( wp_unslash( $_POST['delivery'] ) ) : 'zip';
		$target_dir      = isset( $_POST['target_dir'] ) ? sanitize_text_field( wp_unslash( $_POST['target_dir'] ) ) : '';
		$selected_ids    = isset( $_POST['selected_ids'] ) ? array_map( 'absint', (array) $_POST['selected_ids'] ) : [];
		$selected_raw    = isset( $_POST['selected_targets'] ) ? sanitize_text_field( wp_unslash( $_POST['selected_targets'] ) ) : '';
		$post_types      = isset( $_POST['post_types'] ) ? array_map( 'sanitize_key', (array) $_POST['post_types'] ) : [ 'page', 'post' ];
		$base_url         = isset( $_POST['base_url'] ) ? sanitize_text_field( wp_unslash( $_POST['base_url'] ) ) : '/';
		$release_label    = isset( $_POST['release_label'] ) ? sanitize_text_field( wp_unslash( $_POST['release_label'] ) ) : '';
		$canonical_domain = isset( $_POST['canonical_domain'] ) ? esc_url_raw( wp_unslash( $_POST['canonical_domain'] ) ) : '';
		$force_index      = ! isset( $_POST['force_index'] ) || '1' === $_POST['force_index'];
		$gen_seo_files    = ! isset( $_POST['gen_seo_files'] ) || '1' === $_POST['gen_seo_files'];
		$gen_htaccess     = isset( $_POST['gen_htaccess'] ) && '1' === $_POST['gen_htaccess'];
		$ga4_id           = isset( $_POST['ga4_id'] ) ? sanitize_text_field( wp_unslash( $_POST['ga4_id'] ) ) : '';
		$pagespeed_off    = isset( $_POST['pagespeed_off'] ) && '1' === $_POST['pagespeed_off'];
		$batch_size       = isset( $_POST['batch_size'] ) ? max( 1, min( 12, (int) $_POST['batch_size'] ) ) : 4;
		$form_bridge      = ! isset( $_POST['form_bridge'] ) || '1' === $_POST['form_bridge'];
		$allowed_origins  = isset( $_POST['allowed_origins'] ) ? sanitize_text_field( wp_unslash( $_POST['allowed_origins'] ) ) : '*';
		$prev_settings    = self::get_settings();
		$ftp_host         = isset( $_POST['ftp_host'] ) ? sanitize_text_field( wp_unslash( $_POST['ftp_host'] ) ) : (string) $prev_settings['ftp_host'];
		$ftp_port         = isset( $_POST['ftp_port'] ) ? max( 1, min( 65535, (int) $_POST['ftp_port'] ) ) : (int) $prev_settings['ftp_port'];
		$ftp_user         = isset( $_POST['ftp_user'] ) ? sanitize_text_field( wp_unslash( $_POST['ftp_user'] ) ) : (string) $prev_settings['ftp_user'];
		$ftp_pass_in      = isset( $_POST['ftp_pass'] ) ? (string) wp_unslash( $_POST['ftp_pass'] ) : '';
		$ftp_pass         = '' !== $ftp_pass_in ? $ftp_pass_in : (string) $prev_settings['ftp_pass'];
		$ftp_path         = isset( $_POST['ftp_path'] ) ? sanitize_text_field( wp_unslash( $_POST['ftp_path'] ) ) : (string) $prev_settings['ftp_path'];
		$ftp_ssl          = isset( $_POST['ftp_ssl'] ) ? ( '1' === $_POST['ftp_ssl'] ? 1 : 0 ) : (int) $prev_settings['ftp_ssl'];
		$ftp_passive      = isset( $_POST['ftp_passive'] ) ? ( '1' === $_POST['ftp_passive'] ? 1 : 0 ) : (int) $prev_settings['ftp_passive'];
		if ( '' === $ftp_path ) {
			$ftp_path = '/';
		}

		if ( '' !== $canonical_domain ) {
			$canonical_domain = untrailingslashit( $canonical_domain );
		}

		if ( ! in_array( $delivery, [ 'zip', 'push', 'both', 'ftp', 'zip_ftp' ], true ) ) {
			$delivery = 'zip';
		}

		$resolved_target = '';
		if ( 'push' === $delivery || 'both' === $delivery ) {
			$valid = self::validate_target_dir( $target_dir );
			if ( is_wp_error( $valid ) ) {
				wp_send_json_error( $valid->get_error_message() );
			}
			$resolved_target = $valid;
		}

		if ( 'ftp' === $delivery || 'zip_ftp' === $delivery ) {
			$ftp_cfg = [
				'host'    => $ftp_host,
				'port'    => $ftp_port,
				'user'    => $ftp_user,
				'pass'    => $ftp_pass,
				'path'    => $ftp_path,
				'ssl'     => $ftp_ssl,
				'passive' => $ftp_passive,
			];
			$conn = self::ftp_connect_and_login( $ftp_cfg );
			if ( is_wp_error( $conn ) ) {
				wp_send_json_error( $conn->get_error_message() );
			}
			@ftp_close( $conn );
		}

		update_option( self::OPT_KEY, [
			'enable_form_bridge' => $form_bridge ? 1 : 0,
			'allowed_origins'    => '' !== $allowed_origins ? $allowed_origins : '*',
			'delivery_method'    => $delivery,
			'target_dir'         => $target_dir,
			'base_url'           => $base_url,
			'pagespeed_off'      => $pagespeed_off ? 1 : 0,
			'batch_size'         => $batch_size,
			'canonical_domain'   => $canonical_domain,
			'force_index'        => $force_index ? 1 : 0,
			'generate_seo_files' => $gen_seo_files ? 1 : 0,
			'generate_htaccess'  => $gen_htaccess ? 1 : 0,
			'ga4_id'             => $ga4_id,
			'private_cms_mode'   => ! empty( $prev_settings['private_cms_mode'] ) ? 1 : 0,
			'github_token'       => isset( $prev_settings['github_token'] ) ? (string) $prev_settings['github_token'] : '',
			'ftp_host'           => $ftp_host,
			'ftp_port'           => $ftp_port,
			'ftp_user'           => $ftp_user,
			'ftp_pass'           => $ftp_pass,
			'ftp_path'           => $ftp_path,
			'ftp_ssl'            => $ftp_ssl,
			'ftp_passive'        => $ftp_passive,
		] );

		if ( '' === $base_url ) {
			$base_url = '/';
		}
		if ( substr( $base_url, -1 ) !== '/' ) {
			$base_url .= '/';
		}

		$manifest     = self::get_manifest();
		$prev_pages   = $manifest['pages'] ?? [];
		$prev_assets  = $manifest['assets'] ?? [];
		$export_sig   = $base_url . '|' . $canonical_domain . '|' . ( $force_index ? '1' : '0' ) . '|' . $ga4_id . '|' . ( $form_bridge ? '1' : '0' );
		$global_hash  = self::compute_global_hash( $export_sig );
		$global_dirty = empty( $manifest['global_hash'] ) || $manifest['global_hash'] !== $global_hash;
		$note         = '';

		$items = [];

		if ( 'selected' === $mode ) {
			foreach ( array_filter( $selected_ids ) as $pid ) {
				$p = get_post( $pid );
				if ( $p && 'publish' === $p->post_status ) {
					$items[] = [ 'id' => $p->ID, 'url' => get_permalink( $p->ID ), 'mod' => $p->post_modified_gmt ];
				}
			}
			$tokens = array_filter( array_map( 'trim', explode( ',', $selected_raw ) ) );
			foreach ( $tokens as $tok ) {
				if ( is_numeric( $tok ) ) {
					$p = get_post( absint( $tok ) );
					if ( $p && 'publish' === $p->post_status ) {
						$items[] = [ 'id' => $p->ID, 'url' => get_permalink( $p->ID ), 'mod' => $p->post_modified_gmt ];
					}
				} else {
					$slug_url = home_url( '/' . ltrim( $tok, '/' ) );
					$pid      = url_to_postid( $slug_url );
					if ( $pid ) {
						$p = get_post( $pid );
						$items[] = [ 'id' => $pid, 'url' => get_permalink( $pid ), 'mod' => $p->post_modified_gmt ];
					} else {
						$items[] = [ 'id' => 0, 'url' => $slug_url, 'mod' => gmdate( 'Y-m-d H:i:s' ) ];
					}
				}
			}
			$note = 'Selected-pages delta export.';
		} else {
			if ( 'incremental' === $mode && empty( $prev_pages ) ) {
				$mode = 'full';
				$note = 'No baseline manifest found — running Full Export to establish baseline.';
			} elseif ( 'incremental' === $mode && $global_dirty ) {
				$note = 'Global Header/Footer/Template or child style.css changed — refreshing all HTML pages (unchanged assets still skipped).';
			}

			$front_id = (int) get_option( 'page_on_front' );
			$ids      = get_posts( [
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			] );

			$seen_urls = [];
			if ( ! $front_id ) {
				$home = home_url( '/' );
				$seen_urls[ $home ] = true;
				if ( 'full' === $mode || $global_dirty ) {
					$items[] = [ 'id' => 0, 'url' => $home, 'mod' => gmdate( 'Y-m-d H:i:s' ) ];
				}
			}

			foreach ( $ids as $p ) {
				$link = get_permalink( $p->ID );
				if ( ! $link || isset( $seen_urls[ $link ] ) ) {
					continue;
				}
				$seen_urls[ $link ] = true;
				if ( 'full' === $mode || $global_dirty || empty( $prev_pages[ $p->ID ] ) || $prev_pages[ $p->ID ] !== $p->post_modified_gmt ) {
					$items[] = [ 'id' => $p->ID, 'url' => $link, 'mod' => $p->post_modified_gmt ];
				}
			}

			// Include CPT archive pages (has_archive => true) for selected post types
			foreach ( $post_types as $pt ) {
				$pt_obj = get_post_type_object( $pt );
				if ( $pt_obj && ! empty( $pt_obj->has_archive ) ) {
					$arch_url = get_post_type_archive_link( $pt );
					if ( $arch_url && ! isset( $seen_urls[ $arch_url ] ) ) {
						$seen_urls[ $arch_url ] = true;
						if ( 'full' === $mode || $global_dirty ) {
							$items[] = [ 'id' => 0, 'url' => $arch_url, 'mod' => gmdate( 'Y-m-d H:i:s' ) ];
						}
					}
				}
			}

			// Include public taxonomy term archives (categories, tags, and public custom taxonomies)
			$taxonomies = get_taxonomies( [ 'public' => true ], 'names' );
			unset( $taxonomies['post_format'] );
			$tax_dirty = ( 'full' === $mode || $global_dirty || ! empty( $items ) );
			foreach ( $taxonomies as $tax ) {
				$terms = get_terms( [
					'taxonomy'   => $tax,
					'hide_empty' => true,
				] );
				if ( is_wp_error( $terms ) || empty( $terms ) ) {
					continue;
				}
				foreach ( $terms as $term ) {
					$term_url = get_term_link( $term );
					if ( is_wp_error( $term_url ) || ! $term_url || isset( $seen_urls[ $term_url ] ) ) {
						continue;
					}
					$seen_urls[ $term_url ] = true;
					if ( $tax_dirty ) {
						$items[] = [ 'id' => 0, 'url' => $term_url, 'mod' => gmdate( 'Y-m-d H:i:s' ) ];
					}
				}
			}
		}

		$storage   = self::get_base_storage_dir();
		$build_dir = $storage . '/build';
		self::rrmdir( $build_dir );
		wp_mkdir_p( $build_dir );

		$log_id = self::create_export_log( [
			'mode'     => $mode,
			'delivery' => $delivery,
			'label'    => $release_label,
			'total'    => count( $items ),
			'target'   => $resolved_target,
			'note'     => $note,
			'batch'    => $batch_size,
		] );

		if ( empty( $items ) ) {
			self::finish_export_log( $log_id, 'no_changes', [ 'No page or asset changes detected since last export.' ] );
		}

		set_transient( 'arcos_ss_job_' . get_current_user_id(), [
			'mode'             => $mode,
			'delivery'         => $delivery,
			'target_dir'       => $resolved_target,
			'items'            => $items,
			'base_url'         => $base_url,
			'release_label'    => $release_label,
			'canonical_domain' => $canonical_domain,
			'force_index'      => $force_index,
			'gen_seo_files'    => $gen_seo_files,
			'gen_htaccess'     => $gen_htaccess,
			'ga4_id'           => $ga4_id,
			'post_types'       => $post_types,
			'pagespeed_off'    => $pagespeed_off,
			'form_bridge'      => $form_bridge,
			'global_hash'      => $global_hash,
			'prev_assets'      => ( 'full' === $mode ) ? [] : $prev_assets,
			'new_pages'        => ( 'full' === $mode ) ? [] : $prev_pages,
			'new_assets'       => ( 'full' === $mode ) ? [] : $prev_assets,
			'seen_assets'      => [],
			'ftp'              => [
				'host'    => $ftp_host,
				'port'    => $ftp_port,
				'user'    => $ftp_user,
				'pass'    => $ftp_pass,
				'path'    => $ftp_path,
				'ssl'     => $ftp_ssl,
				'passive' => $ftp_passive,
			],
			'log_id'           => $log_id,
			'started_at'       => microtime( true ),
		], HOUR_IN_SECONDS );

		wp_send_json_success( [
			'total'  => count( $items ),
			'note'   => $note,
			'log_id' => $log_id,
		] );
	}

	public static function ajax_batch() {
		@set_time_limit( 120 );
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$job = get_transient( 'arcos_ss_job_' . get_current_user_id() );
		if ( ! is_array( $job ) || empty( $job['items'] ) ) {
			wp_send_json_error( 'Export session expired. Please start again.' );
		}

		$offset        = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
		$limit         = isset( $_POST['limit'] ) ? max( 1, min( 20, (int) $_POST['limit'] ) ) : 8;
		$slice         = array_slice( $job['items'], $offset, $limit );
		$build_dir     = self::get_base_storage_dir() . '/build';
		$home          = trailingslashit( home_url( '/' ) );
		$home_path     = trim( (string) wp_parse_url( $home, PHP_URL_PATH ), '/' );
		$target_base      = $job['base_url'];
		$canonical_domain = $job['canonical_domain'] ?? '';
		$force_index      = ! empty( $job['force_index'] );
		$ga4_id           = isset( $job['ga4_id'] ) ? trim( (string) $job['ga4_id'] ) : '';
		$pagespeed_off    = ! empty( $job['pagespeed_off'] );
		$form_bridge      = ! empty( $job['form_bridge'] );
		$rest_endpoint    = rest_url( 'arcos-static/v1/submit' );

		$prev_assets = $job['prev_assets'] ?? [];
		$new_assets  = $job['new_assets'] ?? [];
		$new_pages   = $job['new_pages'] ?? [];
		$seen_assets = $job['seen_assets'] ?? [];
		$logs        = [];

		foreach ( $slice as $item ) {
			$url = $item['url'];
			$pid = (int) $item['id'];
			$mod = (string) $item['mod'];

			$fetch_url = $pagespeed_off ? add_query_arg( 'PageSpeed', 'off', $url ) : $url;
			if ( ! empty( self::get_settings()['private_cms_mode'] ) ) {
				$fetch_url = add_query_arg( 'arcos_export_token', self::get_export_token(), $fetch_url );
			}
			$req_args  = [
				'timeout'   => 45,
				'sslverify' => false,
				'headers'   => [
					'User-Agent'           => 'ArcosStaticSites/' . self::VERSION,
					'X-Arcos-Export-Token' => self::get_export_token(),
				],
			];
			$resp = wp_remote_get( $fetch_url, $req_args );
			if ( is_wp_error( $resp ) || (int) wp_remote_retrieve_response_code( $resp ) >= 500 ) {
				usleep( 400000 );
				$resp = wp_remote_get( $fetch_url, $req_args );
			}

			if ( is_wp_error( $resp ) ) {
				$logs[] = 'SKIP (error): ' . $url . ' — ' . $resp->get_error_message();
				continue;
			}
			$code = wp_remote_retrieve_response_code( $resp );
			$html = wp_remote_retrieve_body( $resp );
			if ( 200 !== $code || '' === $html ) {
				$logs[] = 'SKIP (HTTP ' . $code . '): ' . $url;
				continue;
			}

			$asset_paths = self::extract_local_assets( $html, $home_path );
			foreach ( $asset_paths as $rel ) {
				if ( empty( $seen_assets[ $rel ] ) ) {
					$seen_assets[ $rel ] = true;
					self::copy_asset_from_disk( $rel, $build_dir, $home, $target_base, $home_path, $seen_assets, $prev_assets, $new_assets );
				}
			}

			$rewritten = self::rewrite_urls( $html, $home, $target_base, $home_path );
			if ( '' !== $canonical_domain ) {
				$rewritten = self::apply_canonical_domain( $rewritten, $target_base, $canonical_domain );
			}
			if ( $force_index ) {
				$rewritten = self::apply_smart_robots( $rewritten, $pid );
			}
			if ( '' !== $ga4_id && strpos( $rewritten, $ga4_id ) === false ) {
				$gid_esc = esc_attr( $ga4_id );
				$gid_js  = wp_json_encode( $ga4_id );
				if ( stripos( $ga4_id, 'GTM-' ) === 0 ) {
					$gtm_head  = "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer',{$gid_js});</script>";
					$gtm_body  = "<noscript><iframe src=\"https://www.googletagmanager.com/ns.html?id={$gid_esc}\" height=\"0\" width=\"0\" style=\"display:none;visibility:hidden\"></iframe></noscript>";
					$rewritten = preg_replace( '#</head>#i', $gtm_head . "\n</head>", $rewritten, 1 );
					$rewritten = preg_replace( '#(<body[^>]*>)#i', "$1\n" . $gtm_body, $rewritten, 1 );
				} else {
					$gtag      = "<script async src=\"https://www.googletagmanager.com/gtag/js?id={$gid_esc}\"></script>\n<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config',{$gid_js});</script>";
					$rewritten = preg_replace( '#</head>#i', $gtag . "\n</head>", $rewritten, 1 );
				}
			}
			if ( $form_bridge && ( stripos( $rewritten, 'brxe-form' ) !== false || stripos( $rewritten, 'brxe-brf-pro-forms' ) !== false ) ) {
				$rewritten = self::inject_form_bridge_script( $rewritten, $rest_endpoint );
			}
			if ( stripos( $rewritten, 'brxe-filter-' ) !== false || stripos( $rewritten, 'bricks-pagination' ) !== false ) {
				$rewritten = self::inject_blog_bridge_script( $rewritten, $target_base );
			}

			$rel_page = self::url_to_rel_html_path( $url, $home_path );
			$dest     = $build_dir . '/' . $rel_page;
			wp_mkdir_p( dirname( $dest ) );
			file_put_contents( $dest, $rewritten );

			if ( $pid ) {
				$new_pages[ $pid ] = $mod;
			}
			$logs[] = 'OK: /' . $rel_page;
		}

		$job['seen_assets'] = $seen_assets;
		$job['new_assets']  = $new_assets;
		$job['new_pages']   = $new_pages;
		set_transient( 'arcos_ss_job_' . get_current_user_id(), $job, HOUR_IN_SECONDS );

		if ( ! empty( $job['log_id'] ) ) {
			self::append_export_log( $job['log_id'], $logs );
		}

		wp_send_json_success( [ 'logs' => $logs ] );
	}

	private static function inject_blog_bridge_script( $html, $target_base ) {
		$index_url = wp_json_encode( trailingslashit( $target_base ) . 'blog-index.json' );
		$script = <<<HTML
<script id="arcos-static-blog-bridge">
(function(){
  var INDEX_URL = {$index_url};
  var targetQid = '';
  var filterEls = document.querySelectorAll('[data-brx-filter]');
  for (var fi = 0; fi < filterEls.length; fi++) {
    try {
      var fcfg = JSON.parse(filterEls[fi].getAttribute('data-brx-filter') || '{}');
      if (fcfg && fcfg.targetQueryId) { targetQid = fcfg.targetQueryId; break; }
    } catch (e) {}
  }

  var trails = Array.prototype.slice.call(document.querySelectorAll('.brx-query-trail[data-query-element-id]'));
  if (!trails.length) return;

  var trail = null;
  if (targetQid) {
    trail = trails.find(function(t) { return t.getAttribute('data-query-element-id') === targetQid; }) || null;
  }
  if (!trail) {
    trail = trails.slice().sort(function(a, b) {
      return (parseInt(b.getAttribute('data-max-pages') || '1', 10) || 1) - (parseInt(a.getAttribute('data-max-pages') || '1', 10) || 1);
    })[0];
  }
  if (!trail) return;

  var qid = trail.getAttribute('data-query-element-id');
  var cards = Array.prototype.slice.call(document.querySelectorAll('.brxe-' + qid));
  if (!cards.length) return;

  var perPage = 0;
  try {
    var qv = JSON.parse(trail.getAttribute('data-query-vars') || '{}');
    if (qv && qv.posts_per_page) perPage = parseInt(qv.posts_per_page, 10) || 0;
  } catch (e) {}
  if (!perPage) perPage = cards.length || 10;

  var container = cards[0].parentNode;
  var templateCard = cards[0].cloneNode(true);
  var pagWrap = document.querySelector('.bricks-pagination');

  var postsCache = null;
  var state = { page: 1, search: '', cats: [] };
  var pageLang = ((document.documentElement.getAttribute('lang') || '').split('-')[0] || '').toLowerCase();

  function loadIndex() {
    if (postsCache) return Promise.resolve(postsCache);
    return fetch(INDEX_URL).then(function(r){ return r.json(); }).then(function(data){
      postsCache = Array.isArray(data) ? data : [];
      return postsCache;
    });
  }

  function renderCard(p) {
    var el = templateCard.cloneNode(true);
    el.classList.remove('bricks-lazy-hidden');
    var img = el.querySelector('img');
    if (img) {
      if (p.image) {
        img.src = p.image;
        img.removeAttribute('data-src');
        img.removeAttribute('data-srcset');
        img.removeAttribute('srcset');
      }
      img.alt = p.title || '';
      img.classList.remove('bricks-lazy-hidden');
    }
    el.querySelectorAll('a').forEach(function(a) {
      a.href = p.url;
      if (!a.querySelector('img') && a.classList.contains('brxe-text-basic')) {
        a.textContent = p.title;
      }
    });
    var titleEl = el.querySelector('h2, h3, h4, .brxe-heading');
    if (titleEl) titleEl.textContent = p.title;
    var excerptEl = el.querySelector('.brxe-post-excerpt, .brxe-text');
    if (excerptEl && !excerptEl.querySelector('a')) excerptEl.textContent = p.excerpt || '';
    return el;
  }

  function renderPagination(totalPages) {
    if (!pagWrap) return;
    if (totalPages <= 1) { pagWrap.innerHTML = ''; return; }
    var ul = document.createElement('ul');
    ul.className = 'page-numbers';
    function addItem(label, page, isCurrent, isNextPrev) {
      var li = document.createElement('li');
      if (isCurrent) {
        var sp = document.createElement('span');
        sp.className = 'page-numbers current';
        sp.setAttribute('aria-current', 'page');
        sp.textContent = label;
        li.appendChild(sp);
      } else {
        var a = document.createElement('a');
        a.className = (isNextPrev ? isNextPrev + ' ' : '') + 'page-numbers';
        a.href = '#page-' + page;
        a.dataset.arcosPage = String(page);
        a.textContent = label;
        li.appendChild(a);
      }
      ul.appendChild(li);
    }
    if (state.page > 1) addItem('« Prev', state.page - 1, false, 'prev');
    for (var i = 1; i <= totalPages; i++) {
      if (i === 1 || i === totalPages || Math.abs(i - state.page) <= 2) {
        addItem(String(i), i, i === state.page, '');
      } else if (Math.abs(i - state.page) === 3) {
        var dot = document.createElement('li');
        dot.innerHTML = '<span class="page-numbers dots">&hellip;</span>';
        ul.appendChild(dot);
      }
    }
    if (state.page < totalPages) addItem('Next »', state.page + 1, false, 'next');
    pagWrap.innerHTML = '';
    pagWrap.appendChild(ul);
  }

  function applyFilter() {
    loadIndex().then(function(all) {
      var hasLangs = pageLang && all.some(function(p) { return p.lang && p.lang === pageLang; });
      var q = state.search.trim().toLowerCase();
      var filtered = all.filter(function(p) {
        if (hasLangs && p.lang && p.lang !== pageLang) return false;
        if (state.cats.length) {
          var pcats = p.categories || [];
          var matchCat = state.cats.some(function(c) { return pcats.indexOf(c) !== -1; });
          if (!matchCat) return false;
        }
        if (q) {
          var hay = ((p.title || '') + ' ' + (p.excerpt || '') + ' ' + (p.categories || []).join(' ')).toLowerCase();
          if (hay.indexOf(q) === -1) return false;
        }
        return true;
      });

      var totalPages = Math.max(1, Math.ceil(filtered.length / perPage));
      if (state.page > totalPages) state.page = 1;
      var start = (state.page - 1) * perPage;
      var slice = filtered.slice(start, start + perPage);

      Array.prototype.slice.call(container.querySelectorAll('.brxe-' + qid)).forEach(function(n) { n.remove(); });
      var emptyMsg = container.querySelector('.arcos-blog-empty');
      if (emptyMsg) emptyMsg.remove();

      if (!slice.length) {
        var div = document.createElement('div');
        div.className = 'arcos-blog-empty';
        div.style.cssText = 'grid-column:1/-1;padding:32px;text-align:center;color:#475569;font-weight:600;';
        div.textContent = 'No articles matched your filter.';
        container.appendChild(div);
      } else {
        slice.forEach(function(p) { container.appendChild(renderCard(p)); });
      }
      renderPagination(totalPages);
    });
  }

  // Intercept Bricks Search Filter
  var debTimer = null;
  document.addEventListener('input', function(e) {
    var inp = e.target;
    if (!inp || !inp.closest || !inp.closest('.brxe-filter-search')) return;
    e.stopImmediatePropagation();
    state.search = inp.value || '';
    state.page = 1;
    clearTimeout(debTimer);
    debTimer = setTimeout(applyFilter, 180);
  }, true);

  document.addEventListener('submit', function(e) {
    if (e.target && e.target.closest && e.target.closest('.brxe-filter-search')) {
      e.preventDefault();
      e.stopImmediatePropagation();
      applyFilter();
    }
  }, true);

  // Intercept Bricks Checkbox / Radio / Select Category Filters
  document.addEventListener('change', function(e) {
    var el = e.target;
    if (!el || !el.closest) return;
    if (el.closest('.brxe-filter-checkbox, .brxe-filter-radio, .brxe-filter-select')) {
      e.stopImmediatePropagation();
      var radioWrap = el.closest('.brxe-filter-radio');
      if (radioWrap) {
        radioWrap.querySelectorAll('.brx-option-active').forEach(function(n){ n.classList.remove('brx-option-active'); });
        var lbl = el.closest('label') || el.parentElement;
        if (lbl) {
          lbl.classList.add('brx-option-active');
          var txt = lbl.querySelector('.brx-option-text');
          if (txt) txt.classList.add('brx-option-active');
        }
      }
      var checked = Array.prototype.slice.call(document.querySelectorAll('.brxe-filter-checkbox input:checked, .brxe-filter-radio input:checked')).map(function(i){ return i.value; });
      document.querySelectorAll('.brxe-filter-select select').forEach(function(s){ if (s.value) checked.push(s.value); });
      state.cats = checked.filter(Boolean);
      state.page = 1;
      applyFilter();
    }
  }, true);

  // Intercept Bricks Pagination
  document.addEventListener('click', function(e) {
    var a = e.target && e.target.closest ? e.target.closest('.bricks-pagination a.page-numbers') : null;
    if (!a) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    var p = a.dataset.arcosPage ? parseInt(a.dataset.arcosPage, 10) : 0;
    if (!p) {
      var m = (a.getAttribute('href') || '').match(/\/page\/(\d+)/);
      p = m ? parseInt(m[1], 10) : 1;
    }
    state.page = p || 1;
    applyFilter();
    if (container && container.scrollIntoView) container.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }, true);
})();
</script>
HTML;
		if ( stripos( $html, '</body>' ) !== false ) {
			return preg_replace( '#</body>#i', $script . "\n</body>", $html, 1 );
		}
		return $html . "\n" . $script;
	}

	private static function generate_htaccess_file( $build_dir ) {
		$ht = <<<'HT'
# Arcos Static Sites — Caching & Compression
<IfModule mod_deflate.c>
  AddOutputFilterByType DEFLATE text/html text/plain text/css application/javascript application/json application/xml image/svg+xml
</IfModule>

<IfModule mod_expires.c>
  ExpiresActive On
  ExpiresByType text/html "access plus 0 seconds"
  ExpiresByType application/json "access plus 0 seconds"
  ExpiresByType application/xml "access plus 0 seconds"
  ExpiresByType text/css "access plus 1 year"
  ExpiresByType application/javascript "access plus 1 year"
  ExpiresByType image/webp "access plus 1 year"
  ExpiresByType image/jpeg "access plus 1 year"
  ExpiresByType image/png "access plus 1 year"
  ExpiresByType image/svg+xml "access plus 1 year"
  ExpiresByType font/woff2 "access plus 1 year"
  ExpiresByType font/woff "access plus 1 year"
</IfModule>

<IfModule mod_headers.c>
  <FilesMatch "\.(css|js|webp|jpe?g|png|gif|svg|woff2?|ttf|ico)$">
    Header set Cache-Control "public, max-age=31536000, immutable"
  </FilesMatch>
  <FilesMatch "\.(html?|json|xml)$">
    Header set Cache-Control "no-cache, must-revalidate"
  </FilesMatch>
</IfModule>
HT;
		file_put_contents( trailingslashit( $build_dir ) . '.htaccess', $ht . "\n" );
	}

	private static function generate_blog_index( $build_dir, $job ) {
		$home        = trailingslashit( home_url( '/' ) );
		$home_path   = trim( (string) wp_parse_url( $home, PHP_URL_PATH ), '/' );
		$target_base = $job['base_url'];
		$posts       = get_posts( [
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		] );
		$items = [];
		foreach ( $posts as $p ) {
			$url      = self::rewrite_urls( (string) get_permalink( $p->ID ), $home, $target_base, $home_path );
			$thumb    = get_the_post_thumbnail_url( $p->ID, 'large' );
			$thumb    = $thumb ? self::rewrite_urls( (string) $thumb, $home, $target_base, $home_path ) : '';
			$excerpt  = has_excerpt( $p->ID ) ? get_the_excerpt( $p ) : wp_trim_words( wp_strip_all_tags( $p->post_content ), 24 );
			$cats     = wp_get_post_categories( $p->ID, [ 'fields' => 'all' ] );
			$cat_slugs = [];
			foreach ( (array) $cats as $c ) {
				if ( isset( $c->slug ) ) {
					$cat_slugs[] = $c->slug;
				}
			}
			$lang = function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $p->ID, 'slug' ) : '';
			$items[] = [
				'id'         => $p->ID,
				'title'      => html_entity_decode( get_the_title( $p->ID ), ENT_QUOTES, 'UTF-8' ),
				'url'        => $url,
				'date'       => get_the_date( 'M j, Y', $p->ID ),
				'excerpt'    => html_entity_decode( wp_strip_all_tags( $excerpt ), ENT_QUOTES, 'UTF-8' ),
				'image'      => $thumb,
				'categories' => array_values( array_unique( $cat_slugs ) ),
				'lang'       => $lang,
			];
		}
		file_put_contents( $build_dir . '/blog-index.json', wp_json_encode( $items ) );
	}

	private static function inject_form_bridge_script( $html, $rest_endpoint ) {
		$endpoint_json = wp_json_encode( esc_url_raw( $rest_endpoint ) );
		$script = <<<HTML
<script id="arcos-static-form-bridge">
(function(){
  var ENDPOINT = {$endpoint_json};
  document.addEventListener('submit', function(e) {
    var form = e.target;
    if (!form || !form.classList || (!form.classList.contains('brxe-form') && !form.classList.contains('brxe-brf-pro-forms'))) return;
    e.preventDefault();
    e.stopImmediatePropagation();

    var btn = form.querySelector('button[type="submit"], input[type="submit"]');
    if (btn) btn.disabled = true;

    var oldMsg = form.querySelector('.arcos-form-msg');
    if (oldMsg) oldMsg.remove();

    var data = new FormData(form);
    if (!data.get('formId')) {
      var fid = form.getAttribute('data-script-id') || form.getAttribute('data-element-id') || (form.id ? form.id.replace(/^brxe-/, '') : '');
      if (fid) data.append('formId', fid);
    }
    if (!data.get('postId')) {
      var pid = form.getAttribute('data-post-id') || '';
      if (!pid) {
        var m = document.body.className.match(/(?:page-id-|postid-)(\d+)/);
        if (m) pid = m[1];
      }
      if (pid) data.append('postId', pid);
    }

    fetch(ENDPOINT, { method: 'POST', body: data })
      .then(function(r){ return r.json(); })
      .then(function(res) {
        if (res && res.success) {
          if (res.redirect) { window.location.href = res.redirect; return; }
          var msg = document.createElement('div');
          msg.className = 'message success arcos-form-msg';
          msg.style.cssText = 'margin-top:12px;padding:12px 16px;border-radius:8px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;font-weight:600;';
          msg.textContent = res.message || 'Thank you! Your message has been sent.';
          form.appendChild(msg);
          form.reset();
        } else {
          var err = document.createElement('div');
          err.className = 'message danger arcos-form-msg';
          err.style.cssText = 'margin-top:12px;padding:12px 16px;border-radius:8px;background:#fef2f2;color:#991b1b;border:1px solid #fecaca;font-weight:600;';
          err.textContent = (res && res.message) ? res.message : 'Unable to submit form right now.';
          form.appendChild(err);
        }
      })
      .catch(function() {
        var err = document.createElement('div');
        err.className = 'message danger arcos-form-msg';
        err.style.cssText = 'margin-top:12px;padding:12px 16px;border-radius:8px;background:#fef2f2;color:#991b1b;border:1px solid #fecaca;font-weight:600;';
        err.textContent = 'Network error submitting form. Please call us directly.';
        form.appendChild(err);
      })
      .finally(function(){ if (btn) btn.disabled = false; });
  }, true);
})();
</script>
HTML;
		if ( stripos( $html, '</body>' ) !== false ) {
			return preg_replace( '#</body>#i', $script . "\n</body>", $html, 1 );
		}
		return $html . "\n" . $script;
	}

	public static function ajax_zip() {
		@set_time_limit( 300 );
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$job        = get_transient( 'arcos_ss_job_' . get_current_user_id() );
		$mode       = is_array( $job ) ? ( $job['mode'] ?? 'full' ) : 'full';
		$delivery   = is_array( $job ) ? ( $job['delivery'] ?? 'zip' ) : 'zip';
		$target_dir = is_array( $job ) ? ( $job['target_dir'] ?? '' ) : '';
		$storage    = self::get_base_storage_dir();
		$build_dir  = $storage . '/build';

		if ( ! is_dir( $build_dir ) ) {
			wp_send_json_error( 'Build directory not found.' );
		}

		if ( is_array( $job ) && ! empty( $job['gen_seo_files'] ) ) {
			self::generate_seo_files( $build_dir, $job );
		}
		if ( is_array( $job ) ) {
			self::generate_blog_index( $build_dir, $job );
			if ( ! empty( $job['gen_htaccess'] ) ) {
				self::generate_htaccess_file( $build_dir );
			}
		}

		$response = [
			'filename'     => '',
			'url'          => '',
			'size'         => '',
			'files'        => 0,
			'pushed_files' => 0,
			'target_dir'   => $target_dir,
			'ftp_files'    => 0,
			'ftp_dest'     => '',
		];

		// 1. Direct push to live directory on same server
		if ( ( 'push' === $delivery || 'both' === $delivery ) && $target_dir ) {
			$pushed = 0;
			$base_len = strlen( trailingslashit( $build_dir ) );
			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $build_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);
			foreach ( $it as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}
				$full     = $file->getPathname();
				$rel      = str_replace( '\\', '/', substr( $full, $base_len ) );
				$dest     = trailingslashit( $target_dir ) . $rel;
				$dest_dir = dirname( $dest );
				if ( ! is_dir( $dest_dir ) ) {
					self::mkdir_0755( $dest_dir, $target_dir );
				}
				if ( copy( $full, $dest ) ) {
					@chmod( $dest, 0644 );
					$pushed++;
				}
			}
			$response['pushed_files'] = $pushed;
		}

		// 2. ZIP archive (if 'zip', 'both', or 'zip_ftp')
		if ( 'zip' === $delivery || 'both' === $delivery || 'zip_ftp' === $delivery ) {
			if ( ! class_exists( 'ZipArchive' ) ) {
				wp_send_json_error( 'PHP ZipArchive extension is not enabled on this server.' );
			}
			$prefix   = ( 'full' === $mode ) ? 'arcos-static-site-' : 'arcos-static-delta-';
			$filename = $prefix . gmdate( 'Ymd-His' ) . '.zip';
			$zip_path = $storage . '/' . $filename;

			$zip = new ZipArchive();
			if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
				wp_send_json_error( 'Could not create ZIP file.' );
			}

			$count    = 0;
			$base_len = strlen( trailingslashit( $build_dir ) );
			$it       = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $build_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);
			foreach ( $it as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}
				$full     = $file->getPathname();
				$rel_name = str_replace( '\\', '/', substr( $full, $base_len ) );
				$zip->addFile( $full, $rel_name );
				$count++;
			}
			$zip->close();

			$response['filename'] = $filename;
			$response['url']      = trailingslashit( self::get_base_storage_url() ) . rawurlencode( $filename );
			$response['size']     = size_format( filesize( $zip_path ), 2 );
			$response['files']    = $count;

			$releases = get_option( self::RELEASES_KEY, [] );
			if ( ! is_array( $releases ) ) {
				$releases = [];
			}
			$releases[ $filename ] = [
				'label'   => is_array( $job ) ? (string) ( $job['release_label'] ?? '' ) : '',
				'mode'    => $mode,
				'created' => gmdate( 'Y-m-d H:i:s' ),
			];
			update_option( self::RELEASES_KEY, $releases, false );
		}

		// 3. FTP / FTPS upload (if 'ftp' or 'zip_ftp')
		if ( ( 'ftp' === $delivery || 'zip_ftp' === $delivery ) && is_array( $job ) && ! empty( $job['ftp'] ) ) {
			$ftp_res = self::ftp_push_directory( $build_dir, $job['ftp'] );
			if ( is_wp_error( $ftp_res ) ) {
				if ( ! empty( $job['log_id'] ) ) {
					self::finish_export_log( $job['log_id'], 'failed', [ 'FTP ERROR: ' . $ftp_res->get_error_message() ] );
				}
				wp_send_json_error( 'FTP upload failed: ' . $ftp_res->get_error_message() );
			}
			$response['ftp_files'] = (int) $ftp_res;
			$response['ftp_dest']  = ( $job['ftp']['host'] ?? '' ) . ':' . ( $job['ftp']['path'] ?? '/' );
		}

		if ( is_array( $job ) ) {
			update_option( self::MANIFEST_KEY, [
				'last_export' => gmdate( 'Y-m-d H:i:s' ),
				'global_hash' => $job['global_hash'] ?? self::compute_global_hash(),
				'pages'       => $job['new_pages'] ?? [],
				'assets'      => $job['new_assets'] ?? [],
			], false );
		}

		if ( ! empty( $job['log_id'] ) ) {
			$summary = [];
			if ( ! empty( $response['pushed_files'] ) ) {
				$summary[] = 'Pushed ' . $response['pushed_files'] . ' file(s) to ' . ( $response['target_dir'] ?? '' );
			}
			if ( ! empty( $response['ftp_files'] ) ) {
				$summary[] = 'Uploaded ' . $response['ftp_files'] . ' file(s) via FTP to ' . ( $response['ftp_dest'] ?? '' );
			}
			if ( ! empty( $response['filename'] ) ) {
				$summary[] = 'Archive created: ' . $response['filename'] . ' (' . ( $response['files'] ?? 0 ) . ' files, ' . ( $response['size'] ?? '' ) . ')';
			}
			$elapsed   = ! empty( $job['started_at'] ) ? round( microtime( true ) - (float) $job['started_at'], 2 ) : 0;
			$summary[] = 'Completed in ' . $elapsed . 's';
			self::finish_export_log( $job['log_id'], 'completed', $summary );
		}

		self::rrmdir( $build_dir );
		delete_transient( 'arcos_ss_job_' . get_current_user_id() );

		wp_send_json_success( $response );
	}

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

	public static function ajax_delete_zip() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$file = isset( $_POST['file'] ) ? sanitize_file_name( wp_unslash( $_POST['file'] ) ) : '';
		if ( $file && substr( $file, -4 ) === '.zip' ) {
			$target = self::get_base_storage_dir() . '/' . $file;
			if ( file_exists( $target ) ) {
				unlink( $target );
			}
			$releases = get_option( self::RELEASES_KEY, [] );
			if ( is_array( $releases ) && isset( $releases[ $file ] ) ) {
				unset( $releases[ $file ] );
				update_option( self::RELEASES_KEY, $releases, false );
			}
		}
		wp_send_json_success();
	}

	public static function ajax_label_zip() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$file  = isset( $_POST['file'] ) ? sanitize_file_name( wp_unslash( $_POST['file'] ) ) : '';
		$label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		if ( ! $file || substr( $file, -4 ) !== '.zip' ) {
			wp_send_json_error( 'Invalid archive.' );
		}
		$releases = get_option( self::RELEASES_KEY, [] );
		if ( ! is_array( $releases ) ) {
			$releases = [];
		}
		$releases[ $file ] = array_merge( $releases[ $file ] ?? [], [ 'label' => $label ] );
		update_option( self::RELEASES_KEY, $releases, false );
		wp_send_json_success();
	}

	public static function ajax_restore_zip() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_send_json_error( 'PHP ZipArchive extension is not enabled on this server.' );
		}

		$file       = isset( $_POST['file'] ) ? sanitize_file_name( wp_unslash( $_POST['file'] ) ) : '';
		$target_dir = isset( $_POST['target_dir'] ) ? sanitize_text_field( wp_unslash( $_POST['target_dir'] ) ) : '';
		$zip_path   = self::get_base_storage_dir() . '/' . $file;

		if ( ! $file || substr( $file, -4 ) !== '.zip' || ! file_exists( $zip_path ) ) {
			wp_send_json_error( 'Archive not found.' );
		}

		$valid_dir = self::validate_target_dir( $target_dir );
		if ( is_wp_error( $valid_dir ) ) {
			wp_send_json_error( $valid_dir->get_error_message() );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			wp_send_json_error( 'Could not open ZIP archive.' );
		}

		$count = 0;
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$entry = $zip->getNameIndex( $i );
			if ( ! $entry || substr( $entry, -1 ) === '/' || strpos( $entry, '..' ) !== false ) {
				continue;
			}
			$entry    = ltrim( str_replace( '\\', '/', $entry ), '/' );
			$dest     = trailingslashit( $valid_dir ) . $entry;
			$dest_dir = dirname( $dest );
			if ( ! is_dir( $dest_dir ) ) {
				self::mkdir_0755( $dest_dir, $valid_dir );
			}
			$stream = $zip->getStream( $entry );
			if ( $stream ) {
				$out = fopen( $dest, 'wb' );
				if ( $out ) {
					stream_copy_to_stream( $stream, $out );
					fclose( $out );
					@chmod( $dest, 0644 );
					$count++;
				}
				fclose( $stream );
			}
		}
		$zip->close();

		wp_send_json_success( [
			'files'      => $count,
			'target_dir' => $valid_dir,
		] );
	}

	private static function extract_local_assets( $content, $home_path ) {
		$found  = [];
		$prefix = $home_path ? preg_quote( '/' . $home_path . '/', '#' ) : '';
		$regex  = '#(?:https?:)?(?:' . $prefix . '|/)?(wp-(?:content|includes)/[^\s"\'<>()\?\#]+)#i';
		if ( preg_match_all( $regex, $content, $matches ) ) {
			foreach ( $matches[1] as $rel ) {
				$rel = ltrim( str_replace( '\\/', '/', $rel ), '/' );
				if ( strpos( $rel, '..' ) === false ) {
					$found[] = $rel;
				}
			}
		}
		return array_values( array_unique( $found ) );
	}

	private static function copy_asset_from_disk( $rel, $build_dir, $home, $target_base, $home_path, &$seen_assets, $prev_assets, &$new_assets ) {
		$abs_root = realpath( ABSPATH );
		$src      = realpath( $abs_root . '/' . $rel );
		if ( ! $src || strpos( $src, $abs_root ) !== 0 || ! is_file( $src ) ) {
			return;
		}
		$mtime = (string) filemtime( $src );
		$new_assets[ $rel ] = $mtime;

		$is_css    = substr( strtolower( $rel ), -4 ) === '.css';
		$skip_copy = isset( $prev_assets[ $rel ] ) && $prev_assets[ $rel ] === $mtime;

		if ( $is_css ) {
			$css     = file_get_contents( $src );
			$css_dir = dirname( $rel );
			if ( preg_match_all( '#url\(\s*["\']?([^"\')]+)["\']?\s*\)#i', $css, $m ) ) {
				foreach ( $m[1] as $raw_ref ) {
					$raw_ref = trim( $raw_ref );
					if ( '' === $raw_ref || preg_match( '#^(data:|https?://|//)#i', $raw_ref ) ) {
						continue;
					}
					$clean_ref = strtok( $raw_ref, '?#' );
					if ( strpos( $clean_ref, '/' ) === 0 ) {
						$sub_rel = ltrim( $clean_ref, '/' );
						if ( $home_path && strpos( $sub_rel, $home_path . '/' ) === 0 ) {
							$sub_rel = substr( $sub_rel, strlen( $home_path ) + 1 );
						}
					} else {
						$sub_rel = self::normalize_rel_path( $css_dir . '/' . $clean_ref );
					}
					if ( $sub_rel && empty( $seen_assets[ $sub_rel ] ) ) {
						$seen_assets[ $sub_rel ] = true;
						self::copy_asset_from_disk( $sub_rel, $build_dir, $home, $target_base, $home_path, $seen_assets, $prev_assets, $new_assets );
					}
				}
			}
			if ( ! $skip_copy ) {
				$dest = $build_dir . '/' . $rel;
				wp_mkdir_p( dirname( $dest ) );
				$css = self::rewrite_urls( $css, $home, $target_base, $home_path );
				file_put_contents( $dest, $css );
			}
		} elseif ( ! $skip_copy ) {
			$dest = $build_dir . '/' . $rel;
			wp_mkdir_p( dirname( $dest ) );
			copy( $src, $dest );
		}
	}

	private static function normalize_rel_path( $path ) {
		$parts = [];
		foreach ( explode( '/', str_replace( '\\', '/', $path ) ) as $seg ) {
			if ( '' === $seg || '.' === $seg ) {
				continue;
			}
			if ( '..' === $seg ) {
				array_pop( $parts );
			} else {
				$parts[] = $seg;
			}
		}
		return implode( '/', $parts );
	}

	private static function apply_smart_robots( $html, $post_id ) {
		// Respect per-post SEOPress noindex (e.g., Thank You pages)
		if ( $post_id && 'yes' === get_post_meta( $post_id, '_seopress_robots_index', true ) ) {
			return $html;
		}
		// Replace global blog_public=0 noindex meta tag with index, follow
		return preg_replace(
			'#<meta\s+name=["\']robots["\']\s+content=["\'][^"\']*noindex[^"\']*["\']\s*/?>#i',
			'<meta name="robots" content="index, follow" />',
			$html
		);
	}

	private static function apply_canonical_domain( $html, $target_base, $canonical_domain ) {
		$base_slash = trailingslashit( $target_base );
		$dom_slash  = trailingslashit( $canonical_domain );
		// Rewrite <link rel="canonical" href="/..."> and og:url to full production domain
		$html = preg_replace_callback(
			'#(<link[^>]+rel=["\']canonical["\'][^>]+href=["\'])([^"\']+)(["\'])#i',
			function( $m ) use ( $base_slash, $dom_slash ) {
				$href = $m[2];
				if ( strpos( $href, $base_slash ) === 0 ) {
					$href = $dom_slash . substr( $href, strlen( $base_slash ) );
				}
				return $m[1] . $href . $m[3];
			},
			$html
		);
		$html = preg_replace_callback(
			'#(<meta[^>]+property=["\']og:url["\'][^>]+content=["\'])([^"\']+)(["\'])#i',
			function( $m ) use ( $base_slash, $dom_slash ) {
				$val = $m[2];
				if ( strpos( $val, $base_slash ) === 0 ) {
					$val = $dom_slash . substr( $val, strlen( $base_slash ) );
				}
				return $m[1] . $val . $m[3];
			},
			$html
		);
		// Rewrite root-relative URLs inside application/ld+json blocks
		$html = preg_replace_callback(
			'#(<script[^>]+type=["\']application/ld\+json["\'][^>]*>)(.*?)(</script>)#is',
			function( $m ) use ( $dom_slash ) {
				$json = str_replace( '"\\/', '"' . str_replace( '/', '\\/', $dom_slash ), $m[2] );
				$json = preg_replace( '#"(?=/(?!/)[^"]*")#', '"' . untrailingslashit( $dom_slash ), $json );
				return $m[1] . $json . $m[3];
			},
			$html
		);
		return $html;
	}

	private static function generate_seo_files( $build_dir, $job ) {
		$canonical = ! empty( $job['canonical_domain'] ) ? untrailingslashit( $job['canonical_domain'] ) : '';
		$home      = trailingslashit( home_url( '/' ) );
		$home_path = trim( (string) wp_parse_url( $home, PHP_URL_PATH ), '/' );
		$base_url  = $canonical ? trailingslashit( $canonical ) : trailingslashit( $job['base_url'] );
		$pts       = ! empty( $job['post_types'] ) ? $job['post_types'] : [ 'page', 'post' ];

		// 1. sitemap.xml
		$posts = get_posts( [
			'post_type'      => $pts,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'post_modified_gmt',
			'order'          => 'DESC',
		] );
		$xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
		foreach ( $posts as $p ) {
			if ( 'yes' === get_post_meta( $p->ID, '_seopress_robots_index', true ) ) {
				continue;
			}
			$rel_path = self::url_to_rel_html_path( get_permalink( $p->ID ), $home_path );
			$loc_path = ( 'index.html' === $rel_path ) ? '' : preg_replace( '#/index\.html$#', '/', $rel_path );
			$loc      = $base_url . ltrim( $loc_path, '/' );
			$lastmod  = mysql2date( 'Y-m-d\TH:i:s+00:00', $p->post_modified_gmt, false );
			$xml     .= "  <url>\n    <loc>" . esc_xml( $loc ) . "</loc>\n    <lastmod>" . esc_xml( $lastmod ) . "</lastmod>\n  </url>\n";
		}
		$xml .= "</urlset>\n";
		file_put_contents( $build_dir . '/sitemap.xml', $xml );

		// 2. robots.txt
		$sitemap_loc = $base_url . 'sitemap.xml';
		$robots      = "User-agent: *\nAllow: /\n\nSitemap: " . $sitemap_loc . "\n";
		file_put_contents( $build_dir . '/robots.txt', $robots );

		// 3. llms.txt (pull from SEOPress PRO if available, else generate clean Markdown summary)
		$sp_pro = get_option( 'seopress_pro_option_name', [] );
		$llms   = is_array( $sp_pro ) && ! empty( $sp_pro['seopress_llms_file'] ) ? (string) $sp_pro['seopress_llms_file'] : '';
		if ( '' !== trim( $llms ) ) {
			$latest_md = '';
			foreach ( array_slice( $posts, 0, 5 ) as $lp ) {
				$rel_path   = self::url_to_rel_html_path( get_permalink( $lp->ID ), $home_path );
				$loc_path   = ( 'index.html' === $rel_path ) ? '' : preg_replace( '#/index\.html$#', '/', $rel_path );
				$latest_md .= '- [' . $lp->post_title . '](' . $base_url . ltrim( $loc_path, '/' ) . ")\n";
			}
			$llms = str_replace(
				[ '{{site_name}}', '{{site_description}}', '{{current_date}}', '{{search_url}}', '{{latest_posts:5}}', '{{sitemap_url}}' ],
				[ get_bloginfo( 'name' ), get_bloginfo( 'description' ), gmdate( 'Y-m-d' ), $base_url, trim( $latest_md ), $sitemap_loc ],
				$llms
			);
		} else {
			$llms = '# ' . get_bloginfo( 'name' ) . "\n\n> " . get_bloginfo( 'description' ) . "\n\nSitemap: " . $sitemap_loc . "\n";
		}
		file_put_contents( $build_dir . '/llms.txt', $llms );
	}

	private static function rewrite_urls( $text, $home, $target_base, $home_path ) {
		$http_home  = preg_replace( '#^https://#i', 'http://', $home );
		$https_home = preg_replace( '#^http://#i', 'https://', $home );
		$esc_home   = str_replace( '/', '\\/', $https_home );
		$esc_target = str_replace( '/', '\\/', $target_base );

		$text = str_replace( [ $https_home, $http_home ], $target_base, $text );
		$text = str_replace( $esc_home, $esc_target, $text );

		// Also rewrite bare home URLs without a trailing slash (e.g. Bricks Logo <a class="brxe-logo" href="https://site.local">).
		$target_root       = '/' === $target_base ? '/' : untrailingslashit( $target_base );
		$esc_target_root   = str_replace( '/', '\\/', $target_root );
		$https_no_slash    = untrailingslashit( $https_home );
		$http_no_slash     = untrailingslashit( $http_home );
		$esc_https_no_sl   = str_replace( '/', '\\/', $https_no_slash );
		$esc_http_no_sl    = str_replace( '/', '\\/', $http_no_slash );
		$boundary          = '(?=["\'\s<&#?\\\\]|$)';

		$replaced = preg_replace(
			[
				'~' . preg_quote( $https_no_slash, '~' ) . $boundary . '~i',
				'~' . preg_quote( $http_no_slash, '~' ) . $boundary . '~i',
			],
			$target_root,
			$text
		);
		if ( null !== $replaced ) {
			$text = $replaced;
		}
		$replaced = preg_replace(
			[
				'~' . preg_quote( $esc_https_no_sl, '~' ) . $boundary . '~i',
				'~' . preg_quote( $esc_http_no_sl, '~' ) . $boundary . '~i',
			],
			$esc_target_root,
			$text
		);
		if ( null !== $replaced ) {
			$text = $replaced;
		}

		if ( $home_path ) {
			$sub_prefix = '/' . $home_path . '/';
			$text = str_replace(
				[ '"' . $sub_prefix, "'" . $sub_prefix, '(' . $sub_prefix ],
				[ '"' . $target_base, "'" . $target_base, '(' . $target_base ],
				$text
			);
		}
		return $text;
	}

	private static function url_to_rel_html_path( $url, $home_path ) {
		$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		if ( $home_path && strpos( $path, $home_path ) === 0 ) {
			$path = trim( substr( $path, strlen( $home_path ) ), '/' );
		}
		if ( '' === $path ) {
			return 'index.html';
		}
		if ( pathinfo( $path, PATHINFO_EXTENSION ) ) {
			return $path;
		}
		return $path . '/index.html';
	}

	private static function mkdir_0755( $dir, $stop_at ) {
		$dir     = untrailingslashit( $dir );
		$stop_at = untrailingslashit( $stop_at );
		if ( is_dir( $dir ) ) {
			return;
		}
		$parts = [];
		$cur   = $dir;
		while ( $cur && $cur !== $stop_at && ! is_dir( $cur ) ) {
			$parts[] = $cur;
			$parent  = dirname( $cur );
			if ( $parent === $cur ) {
				break;
			}
			$cur = $parent;
		}
		foreach ( array_reverse( $parts ) as $mk ) {
			if ( ! is_dir( $mk ) ) {
				@mkdir( $mk, 0755 );
				@chmod( $mk, 0755 );
			}
		}
	}

	private static function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $item ) {
			if ( $item->isDir() ) {
				rmdir( $item->getPathname() );
			} else {
				unlink( $item->getPathname() );
			}
		}
		rmdir( $dir );
	}
}

Arcos_Static_Sites::init();
