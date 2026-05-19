<?php
/**
 * AgileCRM REST API client.
 *
 * Stateless: every call takes the form's decrypted credentials. 30s
 * timeout, one automatic retry on a 5xx response. Only metadata (form id,
 * HTTP code, contact id) is logged — never the contact body, for privacy.
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

	const TIMEOUT = 30;

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
	 *     @type bool   $ok    Whether a 2xx was received.
	 *     @type int    $code  HTTP status code (0 on transport error).
	 *     @type array  $json  Decoded JSON body (may be empty).
	 *     @type string $error Error message when not ok.
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
					'ok'    => false,
					'code'  => 0,
					'json'  => array(),
					'error' => $response->get_error_message(),
				);
			} else {
				$code = (int) wp_remote_retrieve_response_code( $response );
				$json = json_decode( wp_remote_retrieve_body( $response ), true );
				$result = array(
					'ok'    => $code >= 200 && $code < 300,
					'code'  => $code,
					'json'  => is_array( $json ) ? $json : array(),
					'error' => $code >= 200 && $code < 300 ? '' : 'HTTP ' . $code,
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
	 *     @type string $name       Full name.
	 *     @type string $email      Contact email.
	 *     @type string $phone      Contact phone.
	 *     @type array  $tags       Tag list.
	 *     @type array  $properties Extra AgileCRM properties [{name,value}].
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

		$properties = array();

		if ( ! empty( $contact_data['name'] ) ) {
			$parts = preg_split( '/\s+/', trim( $contact_data['name'] ), 2 );
			$properties[] = array(
				'type'  => 'SYSTEM',
				'name'  => 'first_name',
				'value' => $parts[0],
			);
			if ( ! empty( $parts[1] ) ) {
				$properties[] = array(
					'type'  => 'SYSTEM',
					'name'  => 'last_name',
					'value' => $parts[1],
				);
			}
		}
		if ( ! empty( $contact_data['email'] ) ) {
			$properties[] = array(
				'type'    => 'SYSTEM',
				'name'    => 'email',
				'value'   => $contact_data['email'],
				'subtype' => 'work',
			);
		}
		if ( ! empty( $contact_data['phone'] ) ) {
			$properties[] = array(
				'type'    => 'SYSTEM',
				'name'    => 'phone',
				'value'   => $contact_data['phone'],
				'subtype' => 'work',
			);
		}
		foreach ( (array) ( $contact_data['properties'] ?? array() ) as $prop ) {
			if ( empty( $prop['name'] ) || '' === (string) ( $prop['value'] ?? '' ) ) {
				continue;
			}
			$properties[] = array(
				'type'  => 'CUSTOM',
				'name'  => (string) $prop['name'],
				'value' => is_array( $prop['value'] ) ? implode( ', ', $prop['value'] ) : (string) $prop['value'],
			);
		}

		$payload = array(
			'tags'       => array_values( array_unique( array_filter( array_map( 'strval', (array) ( $contact_data['tags'] ?? array() ) ) ) ) ),
			'properties' => $properties,
		);

		$res = self::request(
			'POST',
			self::base_url( $subdomain ) . '/contacts',
			self::headers( $email, $api_key ),
			wp_json_encode( $payload )
		);

		$contact_id = isset( $res['json']['id'] ) ? (int) $res['json']['id'] : null;

		BF_Logger::log(
			'agilecrm',
			sprintf(
				'create_contact form_id=%d result=%s http=%d contact_id=%s%s',
				(int) $form_id,
				$res['ok'] ? 'ok' : 'fail',
				$res['code'],
				$contact_id ? $contact_id : '-',
				$res['ok'] ? '' : ' error="' . $res['error'] . '"'
			)
		);

		return array(
			'success'    => $res['ok'] && $contact_id,
			'contact_id' => $contact_id,
			'message'    => $res['ok'] ? 'ok' : ( $res['error'] ? $res['error'] : 'AgileCRM error' ),
		);
	}

	/**
	 * Attach a note to a contact.
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

		$res = self::request(
			'POST',
			self::base_url( $subdomain ) . '/notes',
			self::headers( $email, $api_key ),
			wp_json_encode( $payload )
		);

		BF_Logger::log(
			'agilecrm',
			sprintf( 'add_note form_id=%d contact_id=%d result=%s http=%d', (int) $form_id, (int) $contact_id, $res['ok'] ? 'ok' : 'fail', $res['code'] )
		);

		return $res['ok'];
	}

	/**
	 * Verify credentials by hitting the contacts count endpoint.
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
			self::base_url( $subdomain ) . '/contacts/count',
			self::headers( $email, $api_key )
		);

		BF_Logger::log( 'agilecrm', sprintf( 'test_connection subdomain=%s result=%s http=%d', $subdomain, $res['ok'] ? 'ok' : 'fail', $res['code'] ) );

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
}
