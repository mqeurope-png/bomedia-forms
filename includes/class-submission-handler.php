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
				// Silently accept (logged + stored as spam) to avoid
				// tipping off bots.
				$this->discard_as_spam( $post, $lang, 'honeypot', 0 );
			}
		}

		// 2b. Time-based check (signed render timestamp).
		$ts      = isset( $_POST['bf_ts'] ) ? (int) $_POST['bf_ts'] : 0;
		$tsig    = isset( $_POST['bf_tsig'] ) ? sanitize_text_field( wp_unslash( $_POST['bf_tsig'] ) ) : '';
		$elapsed = self::verify_timestamp( $ts, $tsig );

		if ( 'expired' === $elapsed['state'] ) {
			$this->fail( __( 'This form has expired. Please reload the page and try again.', 'bomedia-forms' ) );
		}
		$min_seconds = isset( $config['antispam']['min_seconds'] ) ? (int) $config['antispam']['min_seconds'] : 2;
		if ( 'invalid' === $elapsed['state'] || $elapsed['seconds'] < max( 0, $min_seconds ) ) {
			$this->discard_as_spam( $post, $lang, 'too_fast', $elapsed['seconds'] );
		}

		// 3. Rate limit.
		if ( ! $this->check_rate_limit( $form_id, $config ) ) {
			BF_Logger::log( 'spam', sprintf( 'form_id=%d ip_hash=%s reason=rate_limit', $form_id, self::ip_hash( $this->client_ip() ) ) );
			wp_send_json_error( array( 'message' => __( 'Too many submissions. Please try again later.', 'bomedia-forms' ) ), 429 );
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

		// 5b. Blocked words (case-insensitive) — discard silently.
		$blocked = array_filter(
			array_map(
				'trim',
				preg_split( '/\r\n|\r|\n/', (string) ( $config['antispam']['blocked_words'] ?? '' ) )
			)
		);
		if ( $blocked ) {
			$haystack = strtolower( wp_json_encode( $data ) );
			foreach ( $blocked as $word ) {
				if ( '' !== $word && false !== strpos( $haystack, strtolower( $word ) ) ) {
					$this->discard_as_spam( $post, $lang, 'blocked_word', $elapsed['seconds'] );
				}
			}
		}

		// 5c. All optional fields empty AND submitted fast (<5s) -> spam.
		if ( $elapsed['seconds'] < 5 ) {
			$optional_filled = false;
			foreach ( (array) $config['fields'] as $f ) {
				if ( empty( $f['name'] ) || ! empty( $f['required'] ) || 'hidden' === ( $f['type'] ?? '' ) ) {
					continue;
				}
				$v = $data[ $f['name'] ] ?? '';
				if ( '' !== ( is_array( $v ) ? implode( '', $v ) : (string) $v ) ) {
					$optional_filled = true;
					break;
				}
			}
			if ( ! $optional_filled ) {
				$this->discard_as_spam( $post, $lang, 'empty_fast', $elapsed['seconds'] );
			}
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

		$key     = 'bf_rate_' . $form_id . '_' . self::ip_hash( $this->client_ip() );
		$current = (int) get_transient( $key );

		if ( $current >= $count ) {
			return false;
		}

		set_transient( $key, $current + 1, max( 1, $hours ) * HOUR_IN_SECONDS );
		return true;
	}

	/**
	 * Stable, salted IP hash for rate-limit / log correlation.
	 *
	 * @param string $ip Client IP.
	 * @return string
	 */
	private static function ip_hash( $ip ) {
		return md5( $ip . '|' . self::ts_secret() );
	}

	/**
	 * Secret used for timestamp signing and IP hashing.
	 *
	 * @return string
	 */
	private static function ts_secret() {
		if ( defined( 'AUTH_KEY' ) && AUTH_KEY ) {
			return AUTH_KEY;
		}
		return wp_salt( 'auth' );
	}

	/**
	 * Sign the current render time. Returns [ts, sig] for hidden fields.
	 *
	 * @return array
	 */
	public static function sign_timestamp() {
		$ts = time();
		return array(
			'ts'  => $ts,
			'sig' => hash_hmac( 'sha256', (string) $ts, self::ts_secret() ),
		);
	}

	/**
	 * Verify a signed render timestamp.
	 *
	 * @param int    $ts  Claimed render timestamp.
	 * @param string $sig HMAC signature.
	 * @return array { @type string $state ok|too_fast|expired|invalid; @type int $seconds }
	 */
	public static function verify_timestamp( $ts, $sig ) {
		if ( $ts <= 0 || '' === $sig ) {
			return array(
				'state'   => 'invalid',
				'seconds' => 0,
			);
		}
		$expected = hash_hmac( 'sha256', (string) $ts, self::ts_secret() );
		if ( ! hash_equals( $expected, (string) $sig ) ) {
			return array(
				'state'   => 'invalid',
				'seconds' => 0,
			);
		}

		$elapsed = time() - (int) $ts;
		if ( $elapsed > DAY_IN_SECONDS ) {
			return array(
				'state'   => 'expired',
				'seconds' => $elapsed,
			);
		}
		return array(
			'state'   => 'ok',
			'seconds' => max( 0, $elapsed ),
		);
	}

	/**
	 * Silently discard a submission as spam: log it, store it with
	 * status=spam for admin visibility, and return a success-looking
	 * response so bots get no signal.
	 *
	 * @param WP_Post $post    Form post.
	 * @param string  $lang    Language code.
	 * @param string  $reason  Spam reason tag.
	 * @param int     $seconds Elapsed seconds since render.
	 * @return void Sends JSON and exits.
	 */
	private function discard_as_spam( WP_Post $post, $lang, $reason, $seconds ) {
		BF_Logger::log(
			'spam',
			sprintf(
				'form_id=%d ip_hash=%s reason=%s elapsed=%ds',
				$post->ID,
				self::ip_hash( $this->client_ip() ),
				$reason,
				(int) $seconds
			)
		);

		$this->store_submission( $post->ID, $lang, array( '_spam_reason' => $reason ), null, 'spam' );

		$config = BF_Settings::get_config( $post->ID );
		wp_send_json_success( array( 'message' => $this->success_message( $config ) ) );
	}

	/**
	 * Verify the configured captcha provider.
	 *
	 * If the secret key cannot be decrypted (e.g. AUTH_KEY missing) the
	 * captcha is treated as disabled rather than blocking every lead — the
	 * AUTH_KEY admin notice already warns the operator.
	 *
	 * @param array $config Form config.
	 * @return bool
	 */
	private function verify_captcha( array $config ) {
		$cap      = $config['captcha'] ?? array();
		$provider = $cap['provider'] ?? 'none';

		if ( 'none' === $provider ) {
			return true;
		}

		if ( 'math' === $provider ) {
			$token  = isset( $_POST['bf_captcha_token'] ) ? sanitize_text_field( wp_unslash( $_POST['bf_captcha_token'] ) ) : '';
			$answer = isset( $_POST['bf_captcha_answer'] ) ? (int) wp_unslash( $_POST['bf_captcha_answer'] ) : null;
			if ( '' === $token ) {
				return false;
			}
			$expected = get_transient( 'bf_math_' . $token );
			delete_transient( 'bf_math_' . $token );
			return null !== $expected && (int) $expected === $answer;
		}

		$secret = '' !== ( $cap['secret_key'] ?? '' ) ? BF_Encryption::decrypt( $cap['secret_key'] ) : '';
		if ( '' === $secret ) {
			// Confirmed posture: secret unavailable (e.g. AUTH_KEY missing)
			// -> captcha fail-open (disabled) so leads are not lost; a
			// highly visible admin notice warns the operator.
			BF_Logger::log( 'spam', sprintf( 'captcha provider=%s disabled (secret unavailable)', $provider ) );
			return true;
		}

		$field_map = array(
			'recaptcha_v2' => 'g-recaptcha-response',
			'recaptcha_v3' => 'g-recaptcha-response',
			'turnstile'    => 'cf-turnstile-response',
			'hcaptcha'     => 'h-captcha-response',
		);
		$endpoints = array(
			'recaptcha_v2' => 'https://www.google.com/recaptcha/api/siteverify',
			'recaptcha_v3' => 'https://www.google.com/recaptcha/api/siteverify',
			'turnstile'    => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			'hcaptcha'     => 'https://hcaptcha.com/siteverify',
		);
		if ( ! isset( $field_map[ $provider ] ) ) {
			return false;
		}

		$response_token = isset( $_POST[ $field_map[ $provider ] ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field_map[ $provider ] ] ) ) : '';
		if ( '' === $response_token ) {
			return false;
		}

		$verify = wp_remote_post(
			$endpoints[ $provider ],
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => $secret,
					'response' => $response_token,
					'remoteip' => $this->client_ip(),
				),
			)
		);

		if ( is_wp_error( $verify ) ) {
			BF_Logger::log( 'spam', sprintf( 'captcha provider=%s verify_error="%s"', $provider, $verify->get_error_message() ) );
			return false;
		}

		$result  = json_decode( wp_remote_retrieve_body( $verify ), true );
		$success = ! empty( $result['success'] );

		// reCAPTCHA v3 returns a score; require a conservative threshold.
		if ( $success && 'recaptcha_v3' === $provider && isset( $result['score'] ) ) {
			// Confirmed: reCAPTCHA v3 score threshold 0.5.
			$success = (float) $result['score'] >= 0.5;
		}

		if ( ! $success ) {
			BF_Logger::log( 'spam', sprintf( 'captcha provider=%s failed', $provider ) );
		}

		return $success;
	}

	/**
	 * Generate and persist a math captcha challenge.
	 *
	 * @return array { @type string $token; @type string $question }
	 */
	public static function new_math_challenge() {
		$a     = wp_rand( 1, 9 );
		$b     = wp_rand( 1, 9 );
		$token = wp_generate_password( 16, false );
		set_transient( 'bf_math_' . $token, $a + $b, HOUR_IN_SECONDS );
		return array(
			'token'    => $token,
			'question' => sprintf( '%d + %d = ?', $a, $b ),
		);
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
	 * Build the AgileCRM contact payload from the submitted data.
	 *
	 * Produces the shape consumed by BF_AgileCRM_Client::create_contact():
	 *   { system: assoc<system_property,string>, custom: [{name,value}], tags: [] }
	 *
	 * Field-to-property resolution (in order):
	 *   1. Admin-configured mapping (`field_mapping[key] = property_name`).
	 *      If the configured name is a known AgileCRM SYSTEM property
	 *      (first_name, last_name, email, phone, company, website, …) the
	 *      value is placed under `system`; otherwise under `custom`.
	 *   2. Field type: `email` → system.email, `tel` → system.phone.
	 *   3. Normalized field key looked up in a small ES/EN keyword map
	 *      (name/nombre/fullname → first_name, telefono → phone, etc.).
	 *   4. Anything else → CUSTOM with name = snake_case(label).
	 *
	 * Post-processing: if first_name has whitespace and last_name is empty
	 * the name is split; if first_name is still empty but email is present,
	 * the email local-part is used so AgileCRM does not 400 on "no name".
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
		$system_props = BF_AgileCRM_Client::system_properties();

		$system = array();
		$custom = array();

		foreach ( (array) $config['fields'] as $field ) {
			$key = isset( $field['name'] ) ? (string) $field['name'] : '';
			if ( '' === $key || ! isset( $data[ $key ] ) ) {
				continue;
			}
			$value = is_array( $data[ $key ] ) ? implode( ', ', $data[ $key ] ) : (string) $data[ $key ];
			$value = trim( $value );
			if ( '' === $value ) {
				continue;
			}

			$target = self::resolve_property_target( $field, $mapping, $system_props );
			if ( null === $target ) {
				continue;
			}

			if ( 'SYSTEM' === $target['type'] ) {
				$prop = $target['name'];
				if ( empty( $system[ $prop ] ) ) {
					$system[ $prop ] = $value;
				}
			} else {
				$custom[] = array(
					'name'  => $target['name'],
					'value' => $value,
				);
			}
		}

		// If a single "name" field was supplied (no separate last_name) and
		// it contains whitespace, split it so AgileCRM gets both fields.
		if ( ! empty( $system['first_name'] ) && empty( $system['last_name'] ) && preg_match( '/\s/', $system['first_name'] ) ) {
			$parts                 = preg_split( '/\s+/', $system['first_name'], 2 );
			$system['first_name']  = $parts[0];
			$system['last_name']   = $parts[1];
		}

		// AgileCRM requires at least one of first_name / last_name / email.
		// Form-level validation usually guarantees an email; if first_name
		// is still blank, fall back to the email local-part so the contact
		// has a human-readable name in the CRM.
		if ( empty( $system['first_name'] ) && empty( $system['last_name'] ) && ! empty( $system['email'] ) ) {
			$at                   = strpos( $system['email'], '@' );
			$system['first_name'] = false === $at ? $system['email'] : substr( $system['email'], 0, $at );
		}

		// Tags: configured defaults + automatic language + form slug.
		// AgileCRM rejects tags with characters outside [A-Za-z0-9_ ]
		// (e.g. ":" or "-"), so the colon-form "lang:xx" used to crash the
		// entire create_contact with HTTP 400. We emit "lang_xx" here and
		// let BF_AgileCRM_Client::sanitize_tags() coerce the rest
		// (admin-configured defaults, slugs with hyphens) into the
		// allowed shape, dedupe, and skip empties.
		$tags = (array) ( $agile['default_tags'] ?? array() );
		if ( $lang ) {
			$tags[] = 'lang_' . $lang;
		}
		$tags[] = $post->post_name ? $post->post_name : ( 'form_' . $post->ID );

		return array(
			'system' => $system,
			'custom' => $custom,
			'tags'   => $tags,
		);
	}

	/**
	 * Decide where a submitted field's value should land in AgileCRM.
	 *
	 * @param array $field        Field definition.
	 * @param array $mapping      Admin field-mapping config.
	 * @param array $system_props Known system property names.
	 * @return array|null { @type string $type SYSTEM|CUSTOM; @type string $name } or null to skip.
	 */
	private static function resolve_property_target( array $field, array $mapping, array $system_props ) {
		$key  = isset( $field['name'] ) ? (string) $field['name'] : '';
		$type = isset( $field['type'] ) ? (string) $field['type'] : 'text';

		// 1) Explicit admin mapping wins.
		if ( ! empty( $mapping[ $key ] ) ) {
			$prop = strtolower( trim( $mapping[ $key ] ) );
			if ( in_array( $prop, $system_props, true ) ) {
				return array(
					'type' => 'SYSTEM',
					'name' => $prop,
				);
			}
			return array(
				'type' => 'CUSTOM',
				'name' => $mapping[ $key ],
			);
		}

		// 2) Field type hints.
		if ( 'email' === $type ) {
			return array(
				'type' => 'SYSTEM',
				'name' => 'email',
			);
		}
		if ( 'tel' === $type ) {
			return array(
				'type' => 'SYSTEM',
				'name' => 'phone',
			);
		}

		// 3) Normalized-key heuristics (ES + EN keyword variants).
		$normalized = self::normalize_key( $key );
		$alias      = self::system_property_alias( $normalized );
		if ( null !== $alias ) {
			return array(
				'type' => 'SYSTEM',
				'name' => $alias,
			);
		}

		// 4) Fallback to CUSTOM with a snake_case-of-label property name.
		return array(
			'type' => 'CUSTOM',
			'name' => self::default_property_name( $field ),
		);
	}

	/**
	 * Normalise a key for keyword matching: ASCII-fold, lowercase, collapse
	 * separators to underscore.
	 *
	 * @param string $key Raw key.
	 * @return string
	 */
	private static function normalize_key( $key ) {
		$key = remove_accents( (string) $key );
		$key = strtolower( $key );
		$key = preg_replace( '/[^a-z0-9]+/', '_', $key );
		return trim( (string) $key, '_' );
	}

	/**
	 * Map a normalized key to an AgileCRM SYSTEM property, if recognised.
	 *
	 * @param string $key Normalized key.
	 * @return string|null
	 */
	private static function system_property_alias( $key ) {
		$map = array(
			'first_name'  => array( 'first_name', 'firstname', 'name', 'nombre', 'fullname', 'full_name', 'nombre_completo' ),
			'last_name'   => array( 'last_name', 'lastname', 'apellido', 'apellidos', 'surname', 'family_name' ),
			'email'       => array( 'email', 'e_mail', 'correo', 'correo_electronico', 'mail' ),
			'phone'       => array( 'phone', 'telephone', 'tel', 'tlf', 'telefono', 'movil', 'mobile', 'celular', 'whatsapp' ),
			'company'     => array( 'company', 'empresa', 'organizacion', 'organization', 'compania' ),
			'website'     => array( 'website', 'web', 'url', 'sitio_web', 'pagina_web' ),
			'address'     => array( 'address', 'direccion', 'domicilio' ),
			'title'       => array( 'title', 'puesto', 'cargo', 'job_title' ),
		);
		foreach ( $map as $prop => $aliases ) {
			if ( in_array( $key, $aliases, true ) ) {
				return $prop;
			}
		}
		return null;
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
		$notif = $config['notifications'] ?? array();
		$i18n  = Bomedia_Forms::instance()->i18n;

		// Recipients: configured (comma-separated, multiple) or admin_email.
		$recipients = array();
		foreach ( explode( ',', (string) ( $notif['recipient'] ?? '' ) ) as $r ) {
			$r = sanitize_email( trim( $r ) );
			if ( $r && is_email( $r ) ) {
				$recipients[] = $r;
			}
		}
		if ( ! $recipients ) {
			$recipients[] = get_option( 'admin_email' );
		}

		$form_name = $post->post_title;

		// Variable map shared by subject and body.
		$vars = array(
			'{form_name}'      => $form_name,
			'{date}'           => date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
			'{captcha_passed}' => $captcha_passed ? __( 'yes', 'bomedia-forms' ) : __( 'no', 'bomedia-forms' ),
		);
		foreach ( $data as $key => $value ) {
			$vars[ '{field_' . $key . '}' ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}

		// Subject: configured (with variables) or a sensible default.
		$subject = ! empty( $notif['subject'] )
			? $i18n->translate( $notif['subject'] )
			/* translators: %s: form name. */
			: sprintf( __( 'New submission from %s', 'bomedia-forms' ), $form_name );
		$subject = strtr( $subject, $vars );

		// Body: configured (with variables) or an auto field list.
		if ( ! empty( $notif['body_html'] ) ) {
			$content = strtr( wp_kses_post( $i18n->translate( $notif['body_html'] ) ), $vars );
			$content = str_replace( '{{fields}}', $this->fields_table( $config, $data ), $content );
		} else {
			$content = '<p>' . esc_html( $form_name ) . '</p>' . $this->fields_table( $config, $data );
		}

		$body = $this->email_wrapper( $content );

		// Reply-To: configured, else the submitter's email when present.
		$reply_to = sanitize_email( (string) ( $notif['reply_to'] ?? '' ) );
		if ( '' === $reply_to ) {
			$reply_to = $this->detect_submitter_email( $config, $data );
		}

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( $reply_to && is_email( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		$sent = wp_mail( $recipients, $subject, $body, $headers );

		BF_Logger::log(
			'email',
			sprintf(
				'form_id=%d to=%s result=%s',
				$post->ID,
				implode( ';', $recipients ),
				$sent ? 'sent' : 'failed'
			)
		);
	}

	/**
	 * Build a simple HTML table of submitted fields (labels + values).
	 *
	 * @param array $config Form config.
	 * @param array $data   Submitted data.
	 * @return string
	 */
	private function fields_table( array $config, array $data ) {
		$labels = array();
		foreach ( (array) $config['fields'] as $f ) {
			if ( ! empty( $f['name'] ) ) {
				$labels[ $f['name'] ] = $f['label'] ?? $f['name'];
			}
		}

		$rows = '';
		foreach ( $data as $name => $value ) {
			$label = isset( $labels[ $name ] ) ? $labels[ $name ] : $name;
			$rows .= '<tr><th align="left" style="padding:6px 10px;border-bottom:1px solid #eee;vertical-align:top">' .
				esc_html( $label ) .
				'</th><td style="padding:6px 10px;border-bottom:1px solid #eee">' .
				esc_html( is_array( $value ) ? implode( ', ', $value ) : (string) $value ) .
				'</td></tr>';
		}

		return '<table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:14px">' . $rows . '</table>';
	}

	/**
	 * Wrap content in a responsive, table-based HTML email shell with the
	 * site logo header and a "sent from {site_url}" footer.
	 *
	 * @param string $content Inner HTML.
	 * @return string
	 */
	private function email_wrapper( $content ) {
		$site_name = get_bloginfo( 'name' );
		$site_url  = home_url( '/' );

		$logo = '';
		if ( function_exists( 'has_custom_logo' ) && has_custom_logo() ) {
			$logo_id  = get_theme_mod( 'custom_logo' );
			$logo_src = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
			if ( $logo_src ) {
				$logo = '<img src="' . esc_url( $logo_src ) . '" alt="' . esc_attr( $site_name ) . '" style="max-height:48px;height:auto" />';
			}
		}
		if ( '' === $logo ) {
			$logo = '<strong style="font-size:18px">' . esc_html( $site_name ) . '</strong>';
		}

		ob_start();
		?>
<!DOCTYPE html>
<html><head><meta charset="utf-8" /><meta name="viewport" content="width=device-width,initial-scale=1" /></head>
<body style="margin:0;padding:0;background:#f4f4f5">
<table cellpadding="0" cellspacing="0" role="presentation" style="width:100%;background:#f4f4f5">
<tr><td align="center" style="padding:24px 12px">
<table cellpadding="0" cellspacing="0" role="presentation" style="width:100%;max-width:600px;background:#ffffff;border-radius:6px;overflow:hidden;font-family:Arial,Helvetica,sans-serif;color:#1d2327">
<tr><td style="padding:20px 24px;border-bottom:1px solid #ededed"><?php echo $logo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
<tr><td style="padding:24px"><?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
<tr><td style="padding:16px 24px;border-top:1px solid #ededed;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#787c82">
<?php
/* translators: %s: site URL. */
echo esc_html( sprintf( __( 'Sent from %s', 'bomedia-forms' ), $site_url ) );
?>
</td></tr>
</table>
</td></tr>
</table>
</body></html>
		<?php
		return ob_get_clean();
	}

	/**
	 * Find the submitter's email from the first email-type field.
	 *
	 * @param array $config Form config.
	 * @param array $data   Submitted data.
	 * @return string
	 */
	private function detect_submitter_email( array $config, array $data ) {
		foreach ( (array) $config['fields'] as $f ) {
			if ( 'email' === ( $f['type'] ?? '' ) && ! empty( $f['name'] ) && ! empty( $data[ $f['name'] ] ) ) {
				$candidate = is_array( $data[ $f['name'] ] ) ? reset( $data[ $f['name'] ] ) : $data[ $f['name'] ];
				if ( is_email( $candidate ) ) {
					return $candidate;
				}
			}
		}
		return '';
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

		$table        = self::table_name();
		$global_days  = (int) apply_filters( 'bf_retention_days', (int) get_option( 'bf_retention_days', 30 ) );

		// Per-form overrides ( _bf_retention > 0 ).
		$overrides = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_bf_retention' AND meta_value+0 > 0",
			ARRAY_A
		);

		$override_ids = array();
		foreach ( (array) $overrides as $o ) {
			$fid  = (int) $o['post_id'];
			$days = (int) $o['meta_value'];
			if ( $fid <= 0 || $days <= 0 ) {
				continue;
			}
			$override_ids[] = $fid;
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE form_id = %d AND created_at < %s",
					$fid,
					gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS )
				)
			);
		}

		// Global retention for everything else (0 = keep forever).
		if ( $global_days <= 0 ) {
			return;
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $global_days * DAY_IN_SECONDS );

		if ( $override_ids ) {
			$ph = implode( ',', array_fill( 0, count( $override_ids ), '%d' ) );
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE created_at < %s AND form_id NOT IN ({$ph})",
					array_merge( array( $cutoff ), $override_ids )
				)
			);
		} else {
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff )
			);
		}
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
