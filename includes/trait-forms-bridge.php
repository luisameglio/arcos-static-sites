<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Arcos_Static_Sites_Forms_Bridge {

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

}
