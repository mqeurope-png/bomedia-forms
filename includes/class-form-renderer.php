<?php
/**
 * Frontend form renderer.
 *
 * Outputs semantic, accessible (WCAG AA) HTML. The form works without
 * JavaScript; AJAX is a progressive enhancement layered on top by
 * assets/js/form.js. All CSS is scoped under `.bf-form`. Fields are laid
 * out on a 6-column grid honouring each field's configured width.
 *
 * @package BomediaForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BF_Form_Renderer
 */
class BF_Form_Renderer {

	/**
	 * Tracks whether assets have been enqueued for this request.
	 *
	 * @var bool
	 */
	private $assets_enqueued = false;

	/**
	 * Register (but do not yet print) the frontend assets.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		wp_register_style(
			'bomedia-forms',
			BF_PLUGIN_URL . 'assets/css/form.css',
			array(),
			BF_VERSION
		);

		wp_register_script(
			'bomedia-forms',
			BF_PLUGIN_URL . 'assets/js/form.js',
			array(),
			BF_VERSION,
			true
		);

		wp_localize_script(
			'bomedia-forms',
			'BomediaForms',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => 'bf_submit',
			)
		);
	}

	/**
	 * Ensure assets are actually loaded (called lazily from the shortcode).
	 *
	 * @return void
	 */
	private function ensure_assets() {
		if ( $this->assets_enqueued ) {
			return;
		}
		wp_enqueue_style( 'bomedia-forms' );
		wp_enqueue_script( 'bomedia-forms' );
		$this->assets_enqueued = true;
	}

	/**
	 * Shortcode handler: [bomedia_form id="N"] | [bomedia_form slug="..."].
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Rendered HTML.
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'   => 0,
				'slug' => '',
				'lang' => '',
			),
			$atts,
			'bomedia_form'
		);

		$post = null;
		if ( ! empty( $atts['slug'] ) ) {
			$post = BF_CPT::get_by_slug( $atts['slug'] );
		} elseif ( (int) $atts['id'] > 0 ) {
			$candidate = get_post( (int) $atts['id'] );
			if ( $candidate && BF_CPT::POST_TYPE === $candidate->post_type ) {
				$post = $candidate;
			}
		}

		if ( ! $post ) {
			return '';
		}

		$this->ensure_assets();

		$config = BF_Settings::get_config( $post->ID );
		return $this->build( (int) $post->ID, $config['fields'], $config, $atts['lang'], false );
	}

	/**
	 * Render a single form post to HTML.
	 *
	 * @param WP_Post $post Form post.
	 * @param string  $lang Optional forced language code.
	 * @return string
	 */
	public function render( WP_Post $post, $lang = '' ) {
		$config = BF_Settings::get_config( $post->ID );
		return $this->build( (int) $post->ID, $config['fields'], $config, $lang, false );
	}

	/**
	 * Render a preview from an arbitrary (possibly unsaved) fields array.
	 *
	 * Used by the admin "Preview" modal so editors see their in-progress
	 * configuration without saving. The preview form is inert.
	 *
	 * @param array  $fields Field definitions.
	 * @param string $lang   Optional language code.
	 * @return string
	 */
	public function render_preview( array $fields, $lang = '' ) {
		$config           = BF_Settings::defaults();
		$config['fields'] = $fields;
		return $this->build( 0, $fields, $config, $lang, true );
	}

	/**
	 * Build the form markup.
	 *
	 * @param int    $form_id    Form post ID (0 for preview).
	 * @param array  $fields     Field definitions.
	 * @param array  $config     Full form config.
	 * @param string $lang       Optional forced language code.
	 * @param bool   $is_preview Whether this is an inert admin preview.
	 * @return string
	 */
	private function build( $form_id, array $fields, array $config, $lang, $is_preview ) {
		$i18n   = Bomedia_Forms::instance()->i18n;
		$lang   = $lang ? sanitize_text_field( $lang ) : $i18n->current_lang();
		$dom_id = 'bf-form-' . ( $form_id ? $form_id : 'preview' );
		$nonce  = wp_create_nonce( 'bf_submit_' . $form_id );

		// Separate hidden inputs (no grid cell) from visible fields.
		$hidden  = array();
		$visible = array();
		foreach ( $fields as $field ) {
			if ( 'hidden' === ( $field['type'] ?? 'text' ) ) {
				$hidden[] = $field;
			} else {
				$visible[] = $field;
			}
		}

		ob_start();
		?>
		<form
			class="bf-form<?php echo $is_preview ? ' bf-form--preview' : ''; ?>"
			id="<?php echo esc_attr( $dom_id ); ?>"
			method="post"
			action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
			novalidate
			data-form-id="<?php echo esc_attr( $form_id ); ?>"
			<?php echo $is_preview ? 'data-preview="1" onsubmit="return false;"' : ''; ?>
		>
			<div class="bf-form__messages" role="status" aria-live="polite"></div>

			<input type="hidden" name="action" value="bf_submit" />
			<input type="hidden" name="bf_form_id" value="<?php echo esc_attr( $form_id ); ?>" />
			<input type="hidden" name="bf_lang" value="<?php echo esc_attr( $lang ); ?>" />
			<input type="hidden" name="bf_nonce" value="<?php echo esc_attr( $nonce ); ?>" />

			<?php if ( ! empty( $config['antispam']['honeypot'] ) ) : ?>
				<div class="bf-form__hp" aria-hidden="true">
					<label for="<?php echo esc_attr( $dom_id ); ?>-website">
						<?php esc_html_e( 'Leave this field empty', 'bomedia-forms' ); ?>
					</label>
					<input type="text" id="<?php echo esc_attr( $dom_id ); ?>-website" name="bf_hp_website" tabindex="-1" autocomplete="off" />
				</div>
			<?php endif; ?>

			<?php
			foreach ( $hidden as $field ) {
				echo $this->render_field( $field, $dom_id, 0, $i18n ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>

			<div class="bf-form__grid">
				<?php
				foreach ( $visible as $index => $field ) {
					echo $this->render_field( $field, $dom_id, $index, $i18n ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				?>
			</div>

			<?php echo $this->render_captcha( $config, $dom_id, $is_preview ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<div class="bf-form__actions">
				<?php
				$submit_label = $config['post_submit']['submit_label'] ?? __( 'Send', 'bomedia-forms' );
				$submit_label = $i18n->translate( $submit_label, 'submit_label' );
				?>
				<button type="submit" class="bf-form__submit"<?php echo $is_preview ? ' disabled' : ''; ?>>
					<span class="bf-form__submit-label"><?php echo esc_html( $submit_label ); ?></span>
					<span class="bf-form__spinner" aria-hidden="true"></span>
				</button>
			</div>
		</form>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the captcha widget for the configured provider and enqueue
	 * the provider script when needed.
	 *
	 * @param array  $config     Form config.
	 * @param string $dom_id     Form DOM id prefix.
	 * @param bool   $is_preview Whether this is an inert admin preview.
	 * @return string
	 */
	private function render_captcha( array $config, $dom_id, $is_preview ) {
		$cap      = $config['captcha'] ?? array();
		$provider = $cap['provider'] ?? 'none';
		$site_key = $cap['site_key'] ?? '';

		if ( 'none' === $provider ) {
			return '';
		}

		if ( 'math' === $provider ) {
			$challenge = BF_Submission_Handler::new_math_challenge();
			ob_start();
			?>
			<div class="bf-form__row bf-form__row--w-full bf-captcha bf-captcha--math">
				<label class="bf-form__label" for="<?php echo esc_attr( $dom_id ); ?>-captcha">
					<?php echo esc_html( $challenge['question'] ); ?>
					<span class="bf-form__req" aria-hidden="true">*</span>
				</label>
				<input type="number" class="bf-form__input" id="<?php echo esc_attr( $dom_id ); ?>-captcha"
					name="bf_captcha_answer" inputmode="numeric" autocomplete="off" required />
				<input type="hidden" name="bf_captcha_token" value="<?php echo esc_attr( $challenge['token'] ); ?>" />
			</div>
			<?php
			return ob_get_clean();
		}

		if ( '' === $site_key ) {
			return '';
		}

		// External provider scripts (skipped in the inert admin preview).
		if ( ! $is_preview ) {
			switch ( $provider ) {
				case 'recaptcha_v2':
					wp_enqueue_script( 'bf-recaptcha', 'https://www.google.com/recaptcha/api.js', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters
					break;
				case 'recaptcha_v3':
					wp_enqueue_script( 'bf-recaptcha', 'https://www.google.com/recaptcha/api.js?render=' . rawurlencode( $site_key ), array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters
					break;
				case 'turnstile':
					wp_enqueue_script( 'bf-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters
					break;
				case 'hcaptcha':
					wp_enqueue_script( 'bf-hcaptcha', 'https://js.hcaptcha.com/1/api.js', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters
					break;
			}
		}

		// TODO v1.x: switch v2/Turnstile/hCaptcha to explicit render() for
		// finer control over multiple widgets on one page. Automatic render
		// (class + data-sitekey) is used now as the conservative default.
		ob_start();
		echo '<div class="bf-form__row bf-form__row--w-full bf-captcha bf-captcha--' . esc_attr( $provider ) . '" data-provider="' . esc_attr( $provider ) . '" data-sitekey="' . esc_attr( $site_key ) . '">';
		switch ( $provider ) {
			case 'recaptcha_v2':
				echo '<div class="g-recaptcha" data-sitekey="' . esc_attr( $site_key ) . '"></div>';
				break;
			case 'recaptcha_v3':
				// Token injected by form.js on submit.
				echo '<input type="hidden" name="g-recaptcha-response" value="" data-v3="1" data-action="submit" />';
				break;
			case 'turnstile':
				echo '<div class="cf-turnstile" data-sitekey="' . esc_attr( $site_key ) . '"></div>';
				break;
			case 'hcaptcha':
				echo '<div class="h-captcha" data-sitekey="' . esc_attr( $site_key ) . '"></div>';
				break;
		}
		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * Map a width keyword to its grid-span modifier class.
	 *
	 * @param string $width full|half|third.
	 * @return string
	 */
	private function width_class( $width ) {
		$map = array(
			'full'  => 'bf-form__row--w-full',
			'half'  => 'bf-form__row--w-half',
			'third' => 'bf-form__row--w-third',
		);
		return isset( $map[ $width ] ) ? $map[ $width ] : $map['full'];
	}

	/**
	 * Render an individual field.
	 *
	 * @param array   $field  Field definition.
	 * @param string  $dom_id Form DOM id prefix.
	 * @param int     $index  Field index.
	 * @param BF_I18n $i18n   I18n helper.
	 * @return string
	 */
	private function render_field( array $field, $dom_id, $index, BF_I18n $i18n ) {
		$type        = isset( $field['type'] ) ? $field['type'] : 'text';
		$name        = isset( $field['name'] ) ? $field['name'] : 'field_' . $index;
		$label       = isset( $field['label'] ) ? $i18n->translate( $field['label'] ) : '';
		$placeholder = isset( $field['placeholder'] ) ? $i18n->translate( $field['placeholder'] ) : '';
		$required    = ! empty( $field['required'] );
		$pattern     = isset( $field['pattern'] ) ? $field['pattern'] : '';
		$default     = isset( $field['default'] ) ? $field['default'] : '';
		$width       = isset( $field['width'] ) ? $field['width'] : 'full';
		$options     = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();

		$field_id   = $dom_id . '-' . sanitize_html_class( $name );
		$input_name = 'bf_field[' . esc_attr( $name ) . ']';
		$req_attr   = $required ? ' required aria-required="true"' : '';
		$req_mark   = $required ? ' <span class="bf-form__req" aria-hidden="true">*</span>' : '';

		if ( 'hidden' === $type ) {
			return sprintf(
				'<input type="hidden" name="%s" value="%s" />',
				esc_attr( $input_name ),
				esc_attr( $default )
			);
		}

		ob_start();
		echo '<div class="bf-form__row bf-form__row--' . esc_attr( $type ) . ' ' . esc_attr( $this->width_class( $width ) ) . '">';

		if ( in_array( $type, array( 'text', 'email', 'tel', 'textarea', 'select', 'custom' ), true ) ) {
			printf(
				'<label class="bf-form__label" for="%s">%s%s</label>',
				esc_attr( $field_id ),
				esc_html( $label ),
				$req_mark // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		}

		switch ( $type ) {
			case 'textarea':
				printf(
					'<textarea class="bf-form__input" id="%s" name="%s" placeholder="%s"%s rows="5">%s</textarea>',
					esc_attr( $field_id ),
					esc_attr( $input_name ),
					esc_attr( $placeholder ),
					$req_attr, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					esc_textarea( $default )
				);
				break;

			case 'select':
				echo '<select class="bf-form__input" id="' . esc_attr( $field_id ) . '" name="' . esc_attr( $input_name ) . '"' . $req_attr . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '<option value="">' . esc_html__( '— Select —', 'bomedia-forms' ) . '</option>';
				foreach ( $options as $opt ) {
					$val = is_array( $opt ) ? ( isset( $opt['value'] ) ? $opt['value'] : '' ) : $opt;
					$txt = is_array( $opt ) ? ( isset( $opt['label'] ) ? $opt['label'] : $val ) : $opt;
					printf(
						'<option value="%s"%s>%s</option>',
						esc_attr( $val ),
						selected( $default, $val, false ),
						esc_html( $i18n->translate( $txt ) )
					);
				}
				echo '</select>';
				break;

			case 'checkbox':
			case 'radio':
				echo '<fieldset class="bf-form__group"><legend class="bf-form__label">' . esc_html( $label ) . $req_mark . '</legend>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$i = 0;
				foreach ( $options as $opt ) {
					$val    = is_array( $opt ) ? ( isset( $opt['value'] ) ? $opt['value'] : '' ) : $opt;
					$txt    = is_array( $opt ) ? ( isset( $opt['label'] ) ? $opt['label'] : $val ) : $opt;
					$opt_id = $field_id . '-' . $i++;
					$cname  = 'checkbox' === $type ? $input_name . '[]' : $input_name;
					printf(
						'<label class="bf-form__choice"><input type="%s" id="%s" name="%s" value="%s"%s /> %s</label>',
						esc_attr( $type ),
						esc_attr( $opt_id ),
						esc_attr( $cname ),
						esc_attr( $val ),
						$required && 0 === ( $i - 1 ) ? ' required' : '', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						esc_html( $i18n->translate( $txt ) )
					);
				}
				echo '</fieldset>';
				break;

			case 'custom':
			case 'text':
			case 'email':
			case 'tel':
			default:
				$input_type = in_array( $type, array( 'email', 'tel' ), true ) ? $type : 'text';
				printf(
					'<input type="%s" class="bf-form__input" id="%s" name="%s" placeholder="%s" value="%s"%s%s />',
					esc_attr( $input_type ),
					esc_attr( $field_id ),
					esc_attr( $input_name ),
					esc_attr( $placeholder ),
					esc_attr( $default ),
					$pattern ? ' pattern="' . esc_attr( $pattern ) . '"' : '', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					$req_attr // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				);
				break;
		}

		echo '<span class="bf-form__error" data-for="' . esc_attr( $name ) . '" role="alert"></span>';
		echo '</div>';

		return ob_get_clean();
	}
}
