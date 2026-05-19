<?php
/**
 * AJAX submission handler + submissions table installer.
 *
 * Pipeline: nonce -> honeypot -> rate limit -> captcha -> required-field
 * validation -> AgileCRM -> notification email -> persist -> JSON response.
 *
 * AgileCRM and captcha verification are stubbed in v0.1.0; the surrounding
 * validation, persistence, schema, and cron are functional.
 *
 * @package BomediaForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BF_Submission_Handler
 */
class BF_Submission_Handler {

	const TABLE = 'bf_submissions';

	/**
	 * Fully-qualified submissions table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create / migrate the submissions table (v1 schema) via dbDelta.
	 *
	 * @return void
	 */
	public static function install_table() {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			form_id BIGINT(20) UNSIGNED NOT NULL,
			lang VARCHAR(8) NOT NULL DEFAULT '',
			data LONGTEXT NOT NULL,
			ip VARCHAR(45) NOT NULL DEFAULT '',
			user_agent VARCHAR(255) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			agilecrm_contact_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'received',
			PRIMARY KEY  (id),
			KEY form_id (form_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'bf_db_version', BF_DB_VERSION );
	}

	/**
	 * AJAX: bf_submit.
	 *
	 * @return void Sends JSON and exits.
	 */
	public function handle_submit() {
		$form_id = isset( $_POST['bf_form_id'] ) ? (int) $_POST['bf_form_id'] : 0;
		$lang    = isset( $_POST['bf_lang'] ) ? sanitize_text_field( wp_unslash( $_POST['bf_lang'] ) ) : '';

		// 1. Nonce.
		$nonce = isset( $_POST['bf_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['bf_nonce'] ) ) : '';
		if ( ! $form_id || ! wp_verify_nonce( $nonce, 'bf_submit_' . $form_id ) ) {
			$this->fail( __( 'Security check failed. Please reload the page.', 'bomedia-forms' ) );
		}

		$post = get_post( $form_id );
		if ( ! $post || BF_CPT::POST_TYPE !== $post->post_type ) {
			$this->fail( __( 'Form not found.', 'bomedia-forms' ) );
		}

		$config = BF_Settings::get_config( $form_id );

		// 2. Honeypot.
		if ( ! empty( $config['antispam']['honeypot'] ) ) {
			$hp = isset( $_POST['bf_hp_website'] ) ? trim( wp_unslash( $_POST['bf_hp_website'] ) ) : '';
			if ( '' !== $hp ) {
				// Silently accept to avoid tipping off bots.
				wp_send_json_success( array( 'message' => $this->success_message( $config ) ) );
			}
		}

		// 3. Rate limit.
		if ( ! $this->check_rate_limit( $form_id, $config ) ) {
			$this->fail( __( 'Too many submissions. Please try again later.', 'bomedia-forms' ) );
		}

		// 4. Captcha.
		$captcha_passed = $this->verify_captcha( $config );
		if ( ! $captcha_passed ) {
			wp_send_json_error( array( 'message' => __( 'Captcha verification failed.', 'bomedia-forms' ) ), 403 );
		}

		// 5. Required-field validation.
		$raw    = isset( $_POST['bf_field'] ) && is_array( $_POST['bf_field'] ) ? wp_unslash( $_POST['bf_field'] ) : array();
		$data   = array();
		$errors = array();

		foreach ( (array) $config['fields'] as $field ) {
			if ( empty( $field['name'] ) || 'hidden' === ( $field['type'] ?? '' ) ) {
				if ( ! empty( $field['name'] ) ) {
					$data[ $field['name'] ] = isset( $raw[ $field['name'] ] ) ? sanitize_text_field( $raw[ $field['name'] ] ) : ( $field['default'] ?? '' );
				}
				continue;
			}

			$name  = $field['name'];
			$value = isset( $raw[ $name ] ) ? $raw[ $name ] : '';
			$value = is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : sanitize_textarea_field( $value );

			if ( ! empty( $field['required'] ) && ( '' === $value || array() === $value ) ) {
				$errors[ $name ] = __( 'This field is required.', 'bomedia-forms' );
				continue;
			}

			if ( 'email' === ( $field['type'] ?? '' ) && '' !== $value && ! is_email( $value ) ) {
				$errors[ $name ] = __( 'Please enter a valid email address.', 'bomedia-forms' );
				continue;
			}

			$data[ $name ] = $value;
		}

		if ( $errors ) {
			wp_send_json_error(
				array(
					'message' => __( 'Please correct the highlighted fields.', 'bomedia-forms' ),
					'fields'  => $errors,
				),
				422
			);
		}

		// 6. AgileCRM — failure must never lose the lead.
		$agile      = $this->push_agilecrm( $post, $config, $data, $lang );
		$contact_id = $agile['contact_id'];
		$status     = $agile['status'];

		// 7. Notification email (always, even on AgileCRM failure).
		$this->send_notification( $post, $config, $data, $lang, $captcha_passed );

		// 8. Persist.
		$this->store_submission( $form_id, $lang, $data, $contact_id, $status );

		// 9. Response.
		if ( 'redirect' === ( $config['post_submit']['mode'] ?? 'message' ) && ! empty( $config['post_submit']['redirect_url'] ) ) {
			wp_send_json_success(
				array(
					'redirect' => esc_url_raw( $config['post_submit']['redirect_url'] ),
				)
			);
		}

		wp_send_json_success( array( 'message' => $this->success_message( $config ) ) );
	}

	/**
	 * AJAX: bf_get_form — admin preview helper (STUB).
	 *
	 * @return void
	 */
	public function handle_get_form() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		check_ajax_referer( 'bf_get_form', 'nonce' );

		// Preview from unsaved editor state when fields are posted.
		if ( isset( $_POST['bf_preview_fields'] ) ) {
			$decoded = json_decode( wp_unslash( $_POST['bf_preview_fields'] ), true );
			if ( ! is_array( $decoded ) ) {
				wp_send_json_error( array( 'message' => 'invalid fields' ), 400 );
			}
			$fields = BF_Admin::sanitize_fields( $decoded );
			$html   = Bomedia_Forms::instance()->renderer->render_preview( $fields );
			wp_send_json_success( array( 'html' => $html ) );
		}

		$form_id = isset( $_REQUEST['form_id'] ) ? (int) $_REQUEST['form_id'] : 0;
		$post    = $form_id ? get_post( $form_id ) : null;

		if ( ! $post || BF_CPT::POST_TYPE !== $post->post_type ) {
			wp_send_json_error( array( 'message' => 'not found' ), 404 );
		}

		$html = Bomedia_Forms::instance()->renderer->render( $post );
		wp_send_json_success( array( 'html' => $html ) );
	}

	/**
	 * Enforce a per-IP rate limit using a transient counter.
	 *
	 * @param int   $form_id Form ID.
	 * @param array $config  Form config.
	 * @return bool True if within limit.
	 */
	private function check_rate_limit( $form_id, array $config ) {
		$count = isset( $config['antispam']['rate_limit_count'] ) ? (int) $config['antispam']['rate_limit_count'] : 5;
		$hours = isset( $config['antispam']['rate_limit_hours'] ) ? (int) $config['antispam']['rate_limit_hours'] : 1;
		if ( $count <= 0 ) {
			return true;
		}

		$key     = 'bf_rl_' . $form_id . '_' . md5( $this->client_ip() );
		$current = (int) get_transient( $key );

		if ( $current >= $count ) {
			return false;
		}

		set_transient( $key, $current + 1, max( 1, $hours ) * HOUR_IN_SECONDS );
		return true;
	}

	/**
	 * Verify the configured captcha provider.
	 *
	 * STUB: returns true unless a provider is set, in which case provider
	 * verification will be implemented in a later release. For now, when a
	 * provider is configured we accept (do not block submissions).
	 *
	 * @param array $config Form config.
	 * @return bool
	 */
	private function verify_captcha( array $config ) {
		$provider = $config['captcha']['provider'] ?? 'none';
		if ( 'none' === $provider ) {
			return true;
		}
		// TODO: per-provider verification (reCAPTCHA v2/v3, Turnstile, hCaptcha, Math).
		return true;
	}

	/**
	 * Push a contact to AgileCRM. On any failure the lead is still kept
	 * (status flagged) so it is never lost.
	 *
	 * @param WP_Post $post   Form post.
	 * @param array   $config Form config.
	 * @param array   $data   Submitted data.
	 * @param string  $lang   Language code.
	 * @return array { @type int|null $contact_id; @type string $status }
	 */
	private function push_agilecrm( WP_Post $post, array $config, array $data, $lang ) {
		$agile = $config['agilecrm'] ?? array();

		// Not configured: a normal, successful submission.
		if ( empty( $agile['subdomain'] ) || empty( $agile['api_key'] ) || empty( $agile['account_email'] ) ) {
			return array(
				'contact_id' => null,
				'status'     => 'sent',
			);
		}

		$api_key = BF_Encryption::decrypt( $agile['api_key'] );
		if ( '' === $api_key ) {
			BF_Logger::log( 'agilecrm', sprintf( 'form_id=%d result=fail error="api key could not be decrypted (AUTH_KEY?)"', $post->ID ) );
			return array(
				'contact_id' => null,
				'status'     => 'agilecrm_failed',
			);
		}

		$contact_data = $this->build_contact_data( $config, $data, $post, $lang );

		$result = BF_AgileCRM_Client::create_contact(
			$agile['subdomain'],
			$agile['account_email'],
			$api_key,
			$contact_data,
			$post->ID
		);

		if ( $result['success'] ) {
			return array(
				'contact_id' => $result['contact_id'],
				'status'     => 'sent',
			);
		}

		return array(
			'contact_id' => null,
			'status'     => 'agilecrm_failed',
		);
	}

	/**
	 * Build the AgileCRM contact payload from the field mapping.
	 *
	 * name/email/phone are auto-detected from field types; everything else
	 * goes to custom properties using the configured (or default) mapping.
	 *
	 * @param array   $config Form config.
	 * @param array   $data   Submitted data.
	 * @param WP_Post $post   Form post.
	 * @param string  $lang   Language code.
	 * @return array
	 */
	private function build_contact_data( array $config, array $data, WP_Post $post, $lang ) {
		$agile   = $config['agilecrm'] ?? array();
		$mapping = is_array( $agile['field_mapping'] ?? null ) ? $agile['field_mapping'] : array();

		$name  = '';
		$email = '';
		$phone = '';
		$props = array();

		foreach ( (array) $config['fields'] as $field ) {
			$key  = $field['name'] ?? '';
			$type = $field['type'] ?? 'text';
			if ( '' === $key || ! isset( $data[ $key ] ) ) {
				continue;
			}
			$value = is_array( $data[ $key ] ) ? implode( ', ', $data[ $key ] ) : $data[ $key ];
			if ( '' === (string) $value ) {
				continue;
			}

			if ( 'email' === $type && '' === $email ) {
				$email = $value;
				continue;
			}
			if ( 'tel' === $type && '' === $phone ) {
				$phone = $value;
				continue;
			}
			if ( '' === $name && ( 'name' === $key || false !== strpos( strtolower( (string) ( $field['label'] ?? '' ) ), 'name' ) ) ) {
				$name = $value;
				continue;
			}

			$prop_name = ! empty( $mapping[ $key ] ) ? $mapping[ $key ] : self::default_property_name( $field );
			$props[]   = array(
				'name'  => $prop_name,
				'value' => $value,
			);
		}

		// Tags: configured defaults + an automatic language tag.
		// CONFIRM: per-language / per-trigger tag rules beyond "lang:xx"
		// and the form slug — confirm desired taxonomy with Bart.
		$tags = (array) ( $agile['default_tags'] ?? array() );
		if ( $lang ) {
			$tags[] = 'lang:' . $lang;
		}
		$tags[] = $post->post_name ? $post->post_name : ( 'form-' . $post->ID );

		return array(
			'name'       => $name,
			'email'      => $email,
			'phone'      => $phone,
			'tags'       => $tags,
			'properties' => $props,
		);
	}

	/**
	 * Default AgileCRM property name for a field (snake_case of its label).
	 *
	 * @param array $field Field definition.
	 * @return string
	 */
	public static function default_property_name( array $field ) {
		$base = ! empty( $field['label'] ) ? $field['label'] : ( $field['name'] ?? '' );
		$base = remove_accents( (string) $base );
		$base = strtolower( preg_replace( '/[^a-zA-Z0-9]+/', '_', $base ) );
		return trim( $base, '_' );
	}

	/**
	 * Send the notification email.
	 *
	 * @param WP_Post $post   Form post.
	 * @param array   $config Form config.
	 * @param array   $data           Submitted data.
	 * @param string  $lang           Language code.
	 * @param bool    $captcha_passed Whether captcha verification passed.
	 * @return void
	 */
	private function send_notification( WP_Post $post, array $config, array $data, $lang, $captcha_passed = true ) {
		$notif     = $config['notifications'] ?? array();
		$recipient = ! empty( $notif['recipient'] ) ? $notif['recipient'] : get_option( 'admin_email' );
		$subject   = ! empty( $notif['subject'] ) ? $notif['subject'] : __( 'New form submission', 'bomedia-forms' );

		$rows = '';
		foreach ( $data as $name => $value ) {
			$rows .= '<tr><th align="left">' . esc_html( $name ) . '</th><td>' . esc_html( is_array( $value ) ? implode( ', ', $value ) : $value ) . '</td></tr>';
		}

		$body = ! empty( $notif['body_html'] )
			? str_replace( '{{fields}}', '<table>' . $rows . '</table>', wp_kses_post( $notif['body_html'] ) )
			: '<p>' . esc_html( $post->post_title ) . '</p><table>' . $rows . '</table>';

		wp_mail(
			$recipient,
			$subject,
			$body,
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
	}

	/**
	 * Insert a submission row.
	 *
	 * @param int      $form_id    Form ID.
	 * @param string   $lang       Language code.
	 * @param array    $data       Submitted data.
	 * @param int|null $contact_id AgileCRM contact id.
	 * @param string   $status     Submission status (sent|agilecrm_failed|spam).
	 * @return int Inserted row id.
	 */
	private function store_submission( $form_id, $lang, array $data, $contact_id, $status = 'sent' ) {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table_name(),
			array(
				'form_id'             => $form_id,
				'lang'                => substr( $lang, 0, 8 ),
				'data'                => wp_json_encode( $data ),
				'ip'                  => $this->client_ip(),
				'user_agent'          => substr( isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '', 0, 255 ),
				'created_at'          => current_time( 'mysql' ),
				'agilecrm_contact_id' => $contact_id ? (int) $contact_id : null,
				'status'              => $status,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Daily cron: delete submissions older than the retention window.
	 *
	 * @return void
	 */
	public function cleanup_old_submissions() {
		global $wpdb;

		$days = (int) apply_filters( 'bf_retention_days', (int) get_option( 'bf_retention_days', 30 ) );
		if ( $days <= 0 ) {
			return;
		}

		$table = self::table_name();
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < %s",
				gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS )
			)
		);
	}

	/**
	 * Best-effort client IP.
	 *
	 * @return string
	 */
	private function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Translated success message for a form.
	 *
	 * @param array $config Form config.
	 * @return string
	 */
	private function success_message( array $config ) {
		$msg = $config['post_submit']['success_message'] ?? __( 'Thank you!', 'bomedia-forms' );
		return Bomedia_Forms::instance()->i18n->translate( $msg );
	}

	/**
	 * Send a JSON error response and exit.
	 *
	 * @param string $message Error message.
	 * @return void
	 */
	private function fail( $message ) {
		wp_send_json_error( array( 'message' => $message ), 400 );
	}
}
