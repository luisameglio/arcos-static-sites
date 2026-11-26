<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Arcos_Static_Sites_Admin {

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
		$post_types = array_merge(
			get_post_types( [ 'public' => true ], 'objects' ),
			get_post_types( [ 'publicly_queryable' => true ], 'objects' )
		);
		unset(
			$post_types['attachment'],
			$post_types['bricks_template'],
			$post_types['elementor_library'],
			$post_types['e-landing-page'],
			$post_types['wp_navigation']
		);
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
			'post_type'        => array_keys( $post_types ),
			'post_status'      => 'publish',
			'posts_per_page'   => -1,
			'orderby'          => 'post_type title',
			'order'            => 'ASC',
			'suppress_filters' => true,
			'lang'             => '',
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
								<input type="url" id="arcos-canonical-domain" value="<?php echo esc_attr( $settings['canonical_domain'] ); ?>" class="regular-text" placeholder="e.g. https://domain.com" style="margin:4px 0 8px;" />
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
								<input type="text" id="arcos-ga4-id" value="<?php echo esc_attr( $settings['ga4_id'] ); ?>" class="regular-text" placeholder="e.g. GT-XXXXXXX, G-XXXXXXXXXX, or GTM-XXXXXXX" style="margin-top:4px;" />
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
							<th scope="row">Taxonomy Archives</th>
							<td>
								<label style="display:block;margin-bottom:6px;">
									<input type="checkbox" id="arcos-export-taxonomies" <?php checked( ! empty( $settings['export_taxonomies'] ) ); ?> />
									<strong>Export Categories &amp; Custom Taxonomy Archives</strong> (<code>/category/...</code>, custom taxonomies)
								</label>
								<label style="display:block;">
									<input type="checkbox" id="arcos-export-tags" <?php checked( ! empty( $settings['export_tags'] ) ); ?> />
									<strong>Export Post Tag Archives</strong> (<code>/tag/...</code> — leave unchecked to avoid generating dozens of tag pages)
								</label>
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
						export_taxonomies: document.getElementById('arcos-export-taxonomies') && document.getElementById('arcos-export-taxonomies').checked ? '1' : '0',
						export_tags: document.getElementById('arcos-export-tags') && document.getElementById('arcos-export-tags').checked ? '1' : '0',
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

}
