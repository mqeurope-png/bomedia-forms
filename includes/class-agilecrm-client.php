<?php
/**
 * AgileCRM REST API client.
 *
 * Stateless: every call takes the form's decrypted credentials. 30s
 * timeout, one automatic retry on 5xx / transport error.
 *
 * Privacy: the request body (contact data) is NEVER logged. The response
 * body is logged on failure — truncated and stripped of newlines — because
 * AgileCRM returns the reason in plain text or JSON there and it is
 * essential for diagnosing 4xx errors. Field/property names are server-
 * side so they are safe to log; the response should not contain submitter
 * PII for create_contact failures.
 *
 * @package BomediaForms
 *
 * @see https://github.com/agilecrm/rest-api
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BF_AgileCRM_Client
 */
class BF_AgileCRM_Client {

	const TIMEOUT          = 30;
	const LOG_BODY_LIMIT   = 500;

	/**
	 * AgileCRM SYSTEM property names (anything else must be CUSTOM).
	 *
	 * @return string[]
	 */
	public static function system_properties() {
		return array( 'first_name', 'last_name', 'email', 'phone', 'company', 'website', 'address', 'title' );
	}

	/**
	 * Sanitise a tag to match AgileCRM's rules.
	 *
	 * AgileCRM rejects the whole create_contact request when ANY tag
	 * "contains special characters other than underscore and space" or
	 * does not start with a letter. So we coerce every incoming tag —
	 * automatic or admin-configured — into the allowed shape:
	 *
	 *   - any char outside [A-Za-z0-9_ ] becomes "_"
	 *   - accents are stripped first so "soporte técnico" -> "soporte tecnico"
	 *   - leading/trailing whitespace trimmed
	 *   - if it does not start with a letter, prefixed with "x_"
	 *   - empty input (or fully-stripped) returns ''
	 *
	 * @param string $raw Raw tag.
	 * @return string Sanitised tag, or '' to skip.
	 */
	public static function sanitize_tag( $raw ) {
		$tag = is_scalar( $raw ) ? (string) $raw : '';
		if ( '' === $tag ) {
			return '';
		}
		$tag = remove_accents( $tag );
		$tag = preg_replace( '/[^A-Za-z0-9_ ]/', '_', $tag );
		$tag = trim( (string) $tag );
		if ( '' === $tag ) {
			return '';
		}
		if ( ! preg_match( '/^[A-Za-z]/', $tag ) ) {
			$tag = 'x_' . $tag;
		}
		return $tag;
	}

	/**
	 * Sanitise + dedupe a list of tags.
	 *
	 * @param array $tags Raw tags.
	 * @return string[]
	 */
	public static function sanitize_tags( array $tags ) {
		$out = array();
		foreach ( $tags as $t ) {
			$clean = self::sanitize_tag( $t );
			if ( '' !== $clean ) {
				$out[] = $clean;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Build the API base URL for a subdomain.
	 *
	 * @param string $subdomain AgileCRM subdomain.
	 * @return string
	 */
	private static function base_url( $subdomain ) {
		return sprintf( 'https://%s.agilecrm.com/dev/api', rawurlencode( trim( (string) $subdomain ) ) );
	}

	/**
	 * Authorization + content headers for Basic auth.
	 *
	 * @param string $email   Account email.
	 * @param string $api_key REST API key.
	 * @return array
	 */
	private static function headers( $email, $api_key ) {
		return array(
			'Accept'        => 'application/json',
			'Content-Type'  => 'application/json',
			'Authorization' => 'Basic ' . base64_encode( trim( $email ) . ':' . trim( $api_key ) ),
		);
	}

	/**
	 * Perform a request with a single retry on 5xx / transport error.
	 *
	 * @param string $method  HTTP method.
	 * @param string $url     Full URL.
	 * @param array  $headers Headers.
	 * @param string $body    Raw body (optional).
	 * @return array {
	 *     @type bool   $ok           Whether a 2xx was received.
	 *     @type int    $code         HTTP status code (0 on transport error).
	 *     @type array  $json         Decoded JSON body (may be empty).
	 *     @type string $body_excerpt First N chars of raw response body, single-line.
	 *     @type string $error        Error message when not ok.
	 * }
	 */
	private static function request( $method, $url, array $headers, $body = null ) {
		$args = array(
			'method'  => $method,
			'timeout' => self::TIMEOUT,
			'headers' => $headers,
		);
		if ( null !== $body ) {
			$args['body'] = $body;
		}

		$attempt = 0;
		do {
			$attempt++;
			$response = wp_remote_request( $url, $args );

			if ( is_wp_error( $response ) ) {
				$result = array(
					'ok'           => false,
					'code'         => 0,
					'json'         => array(),
					'body_excerpt' => '',
					'error'        => $response->get_error_message(),
				);
			} else {
				$code        = (int) wp_remote_retrieve_response_code( $response );
				$raw_body    = (string) wp_remote_retrieve_body( $response );
				$json        = json_decode( $raw_body, true );
				$body_single = trim( preg_replace( '/\s+/', ' ', $raw_body ) );
				$result      = array(
					'ok'           => $code >= 200 && $code < 300,
					'code'         => $code,
					'json'         => is_array( $json ) ? $json : array(),
					'body_excerpt' => substr( $body_single, 0, self::LOG_BODY_LIMIT ),
					'error'        => $code >= 200 && $code < 300 ? '' : 'HTTP ' . $code,
				);
			}

			$retryable = ( 0 === $result['code'] ) || ( $result['code'] >= 500 && $result['code'] < 600 );
		} while ( ! $result['ok'] && $retryable && $attempt < 2 );

		return $result;
	}

	/**
	 * Create (or upsert) a contact.
	 *
	 * @param string $subdomain    AgileCRM subdomain.
	 * @param string $email        Account email.
	 * @param string $api_key      Decrypted REST API key.
	 * @param array  $contact_data {
	 *     @type array $system Map of system field => value
	 *                         (first_name, last_name, email, phone, …).
	 *     @type array $custom List of {name, value} for custom properties.
	 *     @type array $tags   Tag list.
	 * }
	 * @param int    $form_id      Form id, for log correlation.
	 * @return array { @type bool $success; @type int|null $contact_id; @type string $message }
	 */
	public static function create_contact( $subdomain, $email, $api_key, array $contact_data, $form_id = 0 ) {
		if ( '' === trim( (string) $subdomain ) || '' === trim( (string) $email ) || '' === trim( (string) $api_key ) ) {
			return array(
				'success'    => false,
				'contact_id' => null,
				'message'    => 'AgileCRM not configured',
			);
		}

		$system = isset( $contact_data['system'] ) && is_array( $contact_data['system'] ) ? $contact_data['system'] : array();
		$custom = isset( $contact_data['custom'] ) && is_array( $contact_data['custom'] ) ? $contact_data['custom'] : array();
		$tags   = isset( $contact_data['tags'] )   && is_array( $contact_data['tags'] )   ? $contact_data['tags']   : array();

		// AgileCRM rejects contacts with neither a name nor an email.
		if ( '' === trim( (string) ( $system['first_name'] ?? '' ) )
			&& '' === trim( (string) ( $system['last_name'] ?? '' ) )
			&& '' === trim( (string) ( $system['email'] ?? '' ) ) ) {
			BF_Logger::log( 'agilecrm', sprintf( 'create_contact form_id=%d result=fail http=0 error="no first_name/last_name/email"', (int) $form_id ) );
			return array(
				'success'    => false,
				'contact_id' => null,
				'message'    => 'no first_name/last_name/email',
			);
		}

		$properties = array();

		foreach ( self::system_properties() as $name ) {
			$value = isset( $system[ $name ] ) ? trim( (string) $system[ $name ] ) : '';
			if ( '' === $value ) {
				continue;
			}
			$entry = array(
				'type'  => 'SYSTEM',
				'name'  => $name,
				'value' => $value,
			);
			if ( 'email' === $name || 'phone' === $name ) {
				$entry['subtype'] = 'work';
			}
			$properties[] = $entry;
		}

		foreach ( $custom as $prop ) {
			if ( ! is_array( $prop ) || empty( $prop['name'] ) ) {
				continue;
			}
			$value = is_array( $prop['value'] ?? null ) ? implode( ', ', $prop['value'] ) : (string) ( $prop['value'] ?? '' );
			$value = trim( $value );
			if ( '' === $value ) {
				continue;
			}
			$properties[] = array(
				'type'  => 'CUSTOM',
				'name'  => (string) $prop['name'],
				'value' => $value,
			);
		}

		$clean_tags = self::sanitize_tags( $tags );

		$payload = array(
			'tags'       => $clean_tags,
			'properties' => $properties,
		);

		$body = wp_json_encode( $payload );
		$res  = self::request(
			'POST',
			self::base_url( $subdomain ) . '/contacts',
			self::headers( $email, $api_key ),
			$body
		);

		$contact_id = isset( $res['json']['id'] ) ? (int) $res['json']['id'] : null;

		BF_Logger::log(
			'agilecrm',
			sprintf(
				'create_contact form_id=%d endpoint=POST /contacts result=%s http=%d req_bytes=%d contact_id=%s tags=%s%s',
				(int) $form_id,
				$res['ok'] ? 'ok' : 'fail',
				$res['code'],
				strlen( (string) $body ),
				$contact_id ? $contact_id : '-',
				self::log_quote( implode( '|', $clean_tags ) ),
				$res['ok'] ? '' : ' response=' . self::log_quote( $res['body_excerpt'] )
			)
		);

		return array(
			'success'    => $res['ok'] && $contact_id,
			'contact_id' => $contact_id,
			'message'    => $res['ok'] ? 'ok' : ( $res['body_excerpt'] ? $res['body_excerpt'] : ( $res['error'] ?: 'AgileCRM error' ) ),
		);
	}

	/**
	 * Attach a note to a contact.
	 *
	 * Endpoint and payload verified against the AgileCRM REST docs:
	 *   POST /dev/api/notes   { subject, description, contact_ids:[id] }
	 *
	 * @param string $subdomain  AgileCRM subdomain.
	 * @param string $email      Account email.
	 * @param string $api_key    Decrypted REST API key.
	 * @param int    $contact_id Contact id.
	 * @param string $subject    Note subject.
	 * @param string $body       Note body.
	 * @param int    $form_id    Form id, for log correlation.
	 * @return bool
	 */
	public static function add_note( $subdomain, $email, $api_key, $contact_id, $subject, $body, $form_id = 0 ) {
		if ( ! $contact_id ) {
			return false;
		}

		$payload = array(
			'subject'     => (string) $subject,
			'description' => (string) $body,
			'contact_ids' => array( (string) (int) $contact_id ),
		);

		$req_body = wp_json_encode( $payload );
		$res      = self::request(
			'POST',
			self::base_url( $subdomain ) . '/notes',
			self::headers( $email, $api_key ),
			$req_body
		);

		BF_Logger::log(
			'agilecrm',
			sprintf(
				'add_note form_id=%d contact_id=%d endpoint=POST /notes result=%s http=%d req_bytes=%d%s',
				(int) $form_id,
				(int) $contact_id,
				$res['ok'] ? 'ok' : 'fail',
				$res['code'],
				strlen( (string) $req_body ),
				$res['ok'] ? '' : ' response=' . self::log_quote( $res['body_excerpt'] )
			)
		);

		return $res['ok'];
	}

	/**
	 * Verify credentials with a minimal authenticated read.
	 *
	 * Probes `GET /dev/api/contacts?page_size=1`: a documented, cheap
	 * endpoint that returns 200 with a (possibly empty) JSON array when
	 * the Basic-auth credentials are valid. The previous probe targeted
	 * `/contacts/count`, which the AgileCRM router interprets as
	 * `/contacts/{contact-id}` and rejects with HTTP 400.
	 *
	 * @param string $subdomain AgileCRM subdomain.
	 * @param string $email     Account email.
	 * @param string $api_key   Decrypted REST API key.
	 * @return array { @type bool $success; @type string $message }
	 */
	public static function test_connection( $subdomain, $email, $api_key ) {
		if ( '' === trim( (string) $subdomain ) || '' === trim( (string) $email ) || '' === trim( (string) $api_key ) ) {
			return array(
				'success' => false,
				'message' => __( 'Subdomain, account email and API key are all required.', 'bomedia-forms' ),
			);
		}

		$res = self::request(
			'GET',
			self::base_url( $subdomain ) . '/contacts?page_size=1',
			self::headers( $email, $api_key )
		);

		BF_Logger::log(
			'agilecrm',
			sprintf(
				'test_connection subdomain=%s endpoint=GET /contacts?page_size=1 result=%s http=%d%s',
				$subdomain,
				$res['ok'] ? 'ok' : 'fail',
				$res['code'],
				$res['ok'] ? '' : ' response=' . self::log_quote( $res['body_excerpt'] )
			)
		);

		if ( $res['ok'] ) {
			return array(
				'success' => true,
				'message' => __( 'Connection successful.', 'bomedia-forms' ),
			);
		}

		if ( 401 === $res['code'] || 403 === $res['code'] ) {
			return array(
				'success' => false,
				'message' => __( 'Authentication failed — check the account email and API key.', 'bomedia-forms' ),
			);
		}

		return array(
			'success' => false,
			'message' => 0 === $res['code']
				? __( 'Could not reach AgileCRM (network error).', 'bomedia-forms' )
				: sprintf( /* translators: %d: HTTP status code. */ __( 'AgileCRM returned HTTP %d.', 'bomedia-forms' ), $res['code'] ),
		);
	}

	/**
	 * Quote an arbitrary string for the log file in a way that survives
	 * grep and never injects newlines.
	 *
	 * @param string $s Response excerpt.
	 * @return string
	 */
	private static function log_quote( $s ) {
		return '"' . str_replace( array( "\r", "\n", '"' ), array( ' ', ' ', "'" ), (string) $s ) . '"';
	}
}
