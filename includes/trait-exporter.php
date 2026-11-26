<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Arcos_Static_Sites_Exporter {

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
