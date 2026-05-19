<?php
/**
 * AgileCRM REST API client.
 *
 * v0.1.0 stub. Authentication and the contact-creation surface are in
 * place so the submission handler can call it, but the field-mapping and
 * full error-handling layer lands in a later release.
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

	/**
	 * @var string
	 */
	private $subdomain;

	/**
	 * @var string
	 */
	private $account_email;

	/**
	 * @var string
	 */
	private $api_key;

	/**
	 * Build a client from a form's decrypted AgileCRM config.
	 *
	 * @param string $subdomain     AgileCRM subdomain (without .agilecrm.com).
	 * @param string $account_email Account owner email.
	 * @param string $api_key       Decrypted REST API key.
	 */
	public function __construct( $subdomain, $account_email, $api_key ) {
		$this->subdomain     = trim( (string) $subdomain );
		$this->account_email = trim( (string) $account_email );
		$this->api_key       = trim( (string) $api_key );
	}

	/**
	 * Whether the client has the minimum credentials to make a request.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== $this->subdomain && '' !== $this->account_email && '' !== $this->api_key;
	}

	/**
	 * API base URL for the configured subdomain.
	 *
	 * @return string
	 */
	private function base_url() {
		return sprintf( 'https://%s.agilecrm.com/dev/api', rawurlencode( $this->subdomain ) );
	}

	/**
	 * Create (or upsert) a contact in AgileCRM.
	 *
	 * STUB: full field-mapping and dedupe-by-email logic arrives in a
	 * later release. Currently performs a minimal create request.
	 *
	 * @param array $properties AgileCRM "properties" array.
	 * @param array $tags       Tags to attach.
	 * @return array {
	 *     @type bool        $success
	 *     @type int|null    $contact_id
	 *     @type string      $message
	 * }
	 */
	public function create_contact( array $properties, array $tags = array() ) {
		if ( ! $this->is_configured() ) {
			return array(
				'success'    => false,
				'contact_id' => null,
				'message'    => 'AgileCRM not configured',
			);
		}

		$payload = array(
			'tags'       => array_values( array_filter( array_map( 'strval', $tags ) ) ),
			'properties' => $properties,
		);

		$response = $this->request( 'POST', '/contacts', $payload );

		if ( is_wp_error( $response ) ) {
			return array(
				'success'    => false,
				'contact_id' => null,
				'message'    => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 && isset( $body['id'] ) ) {
			return array(
				'success'    => true,
				'contact_id' => (int) $body['id'],
				'message'    => 'ok',
			);
		}

		return array(
			'success'    => false,
			'contact_id' => null,
			'message'    => 'AgileCRM error (HTTP ' . $code . ')',
		);
	}

	/**
	 * Perform an authenticated HTTP request against the AgileCRM API.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Path relative to the API base.
	 * @param array  $body   JSON body for write requests.
	 * @return array|WP_Error wp_remote_* response.
	 */
	private function request( $method, $path, array $body = array() ) {
		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
				'Authorization' => 'Basic ' . base64_encode( $this->account_email . ':' . $this->api_key ),
			),
		);

		if ( ! empty( $body ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		return wp_remote_request( $this->base_url() . $path, $args );
	}
}
