<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Arcos_Static_Sites_Updater {

	public static function filter_plugin_row_meta( $links, $file ) {
		if ( plugin_basename( ARCOS_STATIC_SITES_FILE ) === $file ) {
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

		$plugin_file = plugin_basename( ARCOS_STATIC_SITES_FILE );
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
		$plugin_file = plugin_basename( ARCOS_STATIC_SITES_FILE );
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
		$readme = plugin_dir_path( ARCOS_STATIC_SITES_FILE ) . 'readme.txt';
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

}
