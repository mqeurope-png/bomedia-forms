<?php
/**
 * Per-form settings model.
 *
 * Thin accessor layer over the post meta that backs each `bf_form`. Kept
 * intentionally minimal in v0.1.0 — defaults and getters only. Will grow
 * as the editor tabs (Fields / AgileCRM / Captcha / Notifications /
 * Post-submit / Anti-spam) are built out in later releases.
 *
 * @package BomediaForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BF_Settings
 */
class BF_Settings {

	/**
	 * Meta key map.
	 */
	const META_FIELDS        = '_bf_fields';
	const META_AGILECRM      = '_bf_agilecrm';
	const META_CAPTCHA       = '_bf_captcha';
	const META_NOTIFICATIONS = '_bf_notifications';
	const META_POST_SUBMIT   = '_bf_post_submit';
	const META_ANTISPAM      = '_bf_antispam';

	/**
	 * Default configuration for a brand-new form.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'fields'        => array(
				array(
					'type'        => 'text',
					'name'        => 'name',
					'label'       => __( 'Name', 'bomedia-forms' ),
					'placeholder' => '',
					'required'    => true,
					'pattern'     => '',
					'default'     => '',
					'width'       => 'full',
					'options'     => array(),
				),
				array(
					'type'        => 'email',
					'name'        => 'email',
					'label'       => __( 'Email', 'bomedia-forms' ),
					'placeholder' => '',
					'required'    => true,
					'pattern'     => '',
					'default'     => '',
					'width'       => 'full',
					'options'     => array(),
				),
				array(
					'type'        => 'textarea',
					'name'        => 'message',
					'label'       => __( 'Message', 'bomedia-forms' ),
					'placeholder' => '',
					'required'    => false,
					'pattern'     => '',
					'default'     => '',
					'width'       => 'full',
					'options'     => array(),
				),
			),
			'agilecrm'      => array(
				'subdomain'     => '',
				'account_email' => '',
				'api_key'       => '', // Stored encrypted.
				'default_tags'  => array(),
				'field_mapping' => array(), // form field name => AgileCRM custom field.
			),
			'captcha'       => array(
				'provider'   => 'none', // none|recaptcha_v2|recaptcha_v3|math|turnstile|hcaptcha.
				'site_key'   => '',
				'secret_key' => '', // Stored encrypted.
			),
			'notifications' => array(
				'recipient' => get_option( 'admin_email' ),
				'subject'   => '',
				'body_html' => '',
				'reply_to'  => '', // Blank = use the submitter's email when available.
			),
			'post_submit'   => array(
				'mode'            => 'message', // message|redirect.
				'success_message' => __( 'Thank you! Your message has been sent.', 'bomedia-forms' ),
				'redirect_url'    => '',
				'submit_label'    => __( 'Send', 'bomedia-forms' ),
			),
			'antispam'      => array(
				'honeypot'         => true,
				'rate_limit_count' => 5, // Max submissions per hour per IP.
				'rate_limit_hours' => 1, // Window in hours.
				'min_seconds'      => 2, // Faster than this after render = bot.
				'blocked_words'    => '', // One per line; case-insensitive.
			),
		);
	}

	/**
	 * Get the full, defaults-merged config for a form.
	 *
	 * @param int $form_id Form post ID.
	 * @return array
	 */
	public static function get_config( $form_id ) {
		$defaults = self::defaults();

		$config = array(
			'fields'        => self::meta( $form_id, self::META_FIELDS, $defaults['fields'] ),
			'agilecrm'      => self::meta( $form_id, self::META_AGILECRM, $defaults['agilecrm'] ),
			'captcha'       => self::meta( $form_id, self::META_CAPTCHA, $defaults['captcha'] ),
			'notifications' => self::meta( $form_id, self::META_NOTIFICATIONS, $defaults['notifications'] ),
			'post_submit'   => self::meta( $form_id, self::META_POST_SUBMIT, $defaults['post_submit'] ),
			'antispam'      => self::meta( $form_id, self::META_ANTISPAM, $defaults['antispam'] ),
		);

		return $config;
	}

	/**
	 * Read a meta value with a typed default fallback.
	 *
	 * @param int    $form_id Post ID.
	 * @param string $key     Meta key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	private static function meta( $form_id, $key, $default ) {
		$value = get_post_meta( $form_id, $key, true );
		if ( '' === $value || null === $value ) {
			return $default;
		}
		if ( is_array( $default ) && is_array( $value ) ) {
			return array_merge( $default, $value );
		}
		return $value;
	}
}
