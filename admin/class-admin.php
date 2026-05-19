<?php
/**
 * Admin UI: top-level menu, form list, and the tabbed form editor.
 *
 * v0.1.0 ships the menu scaffold and a tabbed meta box with basic field
 * editing. The drag-and-drop field editor and the "Submissions" sub-menu
 * (filterable table + CSV export) arrive in later releases.
 *
 * @package BomediaForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BF_Admin
 */
class BF_Admin {

	const MENU_SLUG = 'bomedia-forms';

	/**
	 * Register the top-level menu and sub-menus.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Bomedia Forms', 'bomedia-forms' ),
			__( 'Bomedia Forms', 'bomedia-forms' ),
			'edit_posts',
			self::MENU_SLUG,
			array( $this, 'render_list_page' ),
			'dashicons-feedback',
			26
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'All Forms', 'bomedia-forms' ),
			__( 'All Forms', 'bomedia-forms' ),
			'edit_posts',
			self::MENU_SLUG,
			array( $this, 'render_list_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Add Form', 'bomedia-forms' ),
			__( 'Add Form', 'bomedia-forms' ),
			'edit_posts',
			'post-new.php?post_type=' . BF_CPT::POST_TYPE
		);

		// Placeholder — implemented in a later release.
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Submissions', 'bomedia-forms' ),
			__( 'Submissions', 'bomedia-forms' ),
			'edit_posts',
			self::MENU_SLUG . '-submissions',
			array( $this, 'render_submissions_page' )
		);
	}

	/**
	 * Enqueue admin assets on plugin screens only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_cpt = $screen && BF_CPT::POST_TYPE === $screen->post_type;
		$is_bf  = false !== strpos( (string) $hook, self::MENU_SLUG );

		if ( ! $is_cpt && ! $is_bf ) {
			return;
		}

		wp_enqueue_style(
			'bomedia-forms-admin',
			BF_PLUGIN_URL . 'assets/css/form.css',
			array(),
			BF_VERSION
		);

		if ( $is_cpt ) {
			wp_enqueue_script(
				'bomedia-forms-admin',
				BF_PLUGIN_URL . 'assets/js/admin-form-editor.js',
				array(),
				BF_VERSION,
				true
			);

			wp_localize_script(
				'bomedia-forms-admin',
				'BomediaFormsAdmin',
				array(
					'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
					'previewNonce' => wp_create_nonce( 'bf_get_form' ),
					'fieldTypes'   => self::field_type_labels(),
					'widths'       => array(
						'full'  => __( 'Full width', 'bomedia-forms' ),
						'half'  => __( 'Half', 'bomedia-forms' ),
						'third' => __( 'Third', 'bomedia-forms' ),
					),
					'i18n'         => array(
						'untitled'  => __( 'Untitled field', 'bomedia-forms' ),
						'dupKeys'   => __( 'Duplicate field keys are not allowed. Please make every field key unique.', 'bomedia-forms' ),
						'confirmRm' => __( 'Remove this field?', 'bomedia-forms' ),
						'previewer' => __( 'Form preview', 'bomedia-forms' ),
						'loading'   => __( 'Loading preview…', 'bomedia-forms' ),
						'prevErr'   => __( 'Could not load preview.', 'bomedia-forms' ),
						'close'     => __( 'Close', 'bomedia-forms' ),
					),
				)
			);
		}
	}

	/**
	 * Selectable field type labels.
	 *
	 * @return array
	 */
	private static function field_type_labels() {
		return array(
			'text'     => __( 'Text', 'bomedia-forms' ),
			'email'    => __( 'Email', 'bomedia-forms' ),
			'tel'      => __( 'Phone', 'bomedia-forms' ),
			'textarea' => __( 'Textarea', 'bomedia-forms' ),
			'select'   => __( 'Select', 'bomedia-forms' ),
			'checkbox' => __( 'Checkbox', 'bomedia-forms' ),
			'radio'    => __( 'Radio', 'bomedia-forms' ),
			'hidden'   => __( 'Hidden', 'bomedia-forms' ),
			'custom'   => __( 'Custom', 'bomedia-forms' ),
		);
	}

	/**
	 * Forms list page (table of bf_form posts).
	 *
	 * @return void
	 */
	public function render_list_page() {
		$forms = get_posts(
			array(
				'post_type'      => BF_CPT::POST_TYPE,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Bomedia Forms', 'bomedia-forms' ) . '</h1>';
		printf(
			' <a href="%s" class="page-title-action">%s</a>',
			esc_url( admin_url( 'post-new.php?post_type=' . BF_CPT::POST_TYPE ) ),
			esc_html__( 'Add Form', 'bomedia-forms' )
		);

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Name', 'bomedia-forms' ) . '</th>';
		echo '<th>' . esc_html__( 'Slug', 'bomedia-forms' ) . '</th>';
		echo '<th>' . esc_html__( 'Language', 'bomedia-forms' ) . '</th>';
		echo '<th>' . esc_html__( 'Submissions (30d)', 'bomedia-forms' ) . '</th>';
		echo '<th>' . esc_html__( 'Shortcode', 'bomedia-forms' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'bomedia-forms' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( ! $forms ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No forms yet.', 'bomedia-forms' ) . '</td></tr>';
		}

		foreach ( $forms as $form ) {
			$edit = get_edit_post_link( $form->ID );
			echo '<tr>';
			echo '<td><strong><a href="' . esc_url( $edit ) . '">' . esc_html( $form->post_title ) . '</a></strong></td>';
			echo '<td><code>' . esc_html( $form->post_name ) . '</code></td>';
			echo '<td>' . esc_html( $this->form_language( $form->ID ) ) . '</td>';
			echo '<td>' . esc_html( (string) $this->submission_count( $form->ID ) ) . '</td>';
			echo '<td><code>[bomedia_form id="' . esc_html( (string) $form->ID ) . '"]</code></td>';
			echo '<td><a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'bomedia-forms' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table></div>';
	}

	/**
	 * Submissions page placeholder.
	 *
	 * @return void
	 */
	public function render_submissions_page() {
		echo '<div class="wrap"><h1>' . esc_html__( 'Submissions', 'bomedia-forms' ) . '</h1>';
		echo '<p>' . esc_html__( 'The filterable submissions table and CSV export will be available in a future release.', 'bomedia-forms' ) . '</p></div>';
	}

	/**
	 * Register the tabbed editor meta box.
	 *
	 * @return void
	 */
	public function register_meta_boxes() {
		add_meta_box(
			'bf_form_editor',
			__( 'Form Configuration', 'bomedia-forms' ),
			array( $this, 'render_meta_box' ),
			BF_CPT::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Render the tabbed editor.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		$config = BF_Settings::get_config( $post->ID );
		wp_nonce_field( 'bf_save_meta_' . $post->ID, 'bf_meta_nonce' );

		$tabs = array(
			'fields'        => __( 'Fields', 'bomedia-forms' ),
			'agilecrm'      => __( 'AgileCRM', 'bomedia-forms' ),
			'captcha'       => __( 'Captcha', 'bomedia-forms' ),
			'notifications' => __( 'Notifications', 'bomedia-forms' ),
			'post_submit'   => __( 'Post-submit', 'bomedia-forms' ),
			'antispam'      => __( 'Anti-spam', 'bomedia-forms' ),
		);

		echo '<div class="bf-admin-tabs"><nav class="bf-admin-tabs__nav">';
		$first = true;
		foreach ( $tabs as $key => $label ) {
			printf(
				'<button type="button" class="bf-admin-tabs__tab%s" data-tab="%s">%s</button>',
				$first ? ' is-active' : '',
				esc_attr( $key ),
				esc_html( $label )
			);
			$first = false;
		}
		echo '</nav>';

		// Fields tab.
		$this->panel_open( 'fields', true );
		$this->render_fields_editor( $config['fields'] );
		$this->panel_close();

		// AgileCRM tab.
		$this->panel_open( 'agilecrm' );
		$agile   = $config['agilecrm'];
		$has_key = ! empty( $agile['api_key'] );
		$this->text_row( 'bf_agilecrm[subdomain]', __( 'Subdomain', 'bomedia-forms' ), $agile['subdomain'] );
		$this->text_row( 'bf_agilecrm[account_email]', __( 'Account email', 'bomedia-forms' ), $agile['account_email'] );
		echo '<p><label>' . esc_html__( 'API key', 'bomedia-forms' ) . '<br />';
		echo '<input type="password" class="regular-text" name="bf_agilecrm[api_key]" autocomplete="new-password" placeholder="' . ( $has_key ? esc_attr__( '•••••• (stored, leave blank to keep)', 'bomedia-forms' ) : '' ) . '" /></label></p>';
		$this->text_row( 'bf_agilecrm[default_tags]', __( 'Default tags (comma separated)', 'bomedia-forms' ), implode( ', ', (array) $agile['default_tags'] ) );
		$this->panel_close();

		// Captcha tab.
		$this->panel_open( 'captcha' );
		$cap       = $config['captcha'];
		$providers = array(
			'none'         => __( 'None', 'bomedia-forms' ),
			'recaptcha_v2' => 'reCAPTCHA v2',
			'recaptcha_v3' => 'reCAPTCHA v3',
			'math'         => __( 'Math', 'bomedia-forms' ),
			'turnstile'    => 'Turnstile',
			'hcaptcha'     => 'hCaptcha',
		);
		echo '<p><label>' . esc_html__( 'Provider', 'bomedia-forms' ) . '<br /><select name="bf_captcha[provider]">';
		foreach ( $providers as $val => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $val ), selected( $cap['provider'], $val, false ), esc_html( $label ) );
		}
		echo '</select></label></p>';
		$this->text_row( 'bf_captcha[site_key]', __( 'Site key', 'bomedia-forms' ), $cap['site_key'] );
		echo '<p><label>' . esc_html__( 'Secret key', 'bomedia-forms' ) . '<br /><input type="password" class="regular-text" name="bf_captcha[secret_key]" autocomplete="new-password" placeholder="' . ( ! empty( $cap['secret_key'] ) ? esc_attr__( '•••••• (stored, leave blank to keep)', 'bomedia-forms' ) : '' ) . '" /></label></p>';
		$this->panel_close();

		// Notifications tab.
		$this->panel_open( 'notifications' );
		$notif = $config['notifications'];
		$this->text_row( 'bf_notifications[recipient]', __( 'Recipient email', 'bomedia-forms' ), $notif['recipient'] );
		$this->text_row( 'bf_notifications[subject]', __( 'Subject', 'bomedia-forms' ), $notif['subject'] );
		echo '<p><label>' . esc_html__( 'Body HTML (use {{fields}} for the data table)', 'bomedia-forms' ) . '<br />';
		echo '<textarea name="bf_notifications[body_html]" rows="6" class="large-text code">' . esc_textarea( $notif['body_html'] ) . '</textarea></label></p>';
		$this->panel_close();

		// Post-submit tab.
		$this->panel_open( 'post_submit' );
		$ps = $config['post_submit'];
		echo '<p><label><input type="radio" name="bf_post_submit[mode]" value="message"' . checked( $ps['mode'], 'message', false ) . '> ' . esc_html__( 'Show success message', 'bomedia-forms' ) . '</label><br />';
		echo '<label><input type="radio" name="bf_post_submit[mode]" value="redirect"' . checked( $ps['mode'], 'redirect', false ) . '> ' . esc_html__( 'Redirect', 'bomedia-forms' ) . '</label></p>';
		$this->text_row( 'bf_post_submit[success_message]', __( 'Success message', 'bomedia-forms' ), $ps['success_message'] );
		$this->text_row( 'bf_post_submit[redirect_url]', __( 'Redirect URL', 'bomedia-forms' ), $ps['redirect_url'] );
		$this->panel_close();

		// Anti-spam tab.
		$this->panel_open( 'antispam' );
		$as = $config['antispam'];
		echo '<p><label><input type="checkbox" name="bf_antispam[honeypot]" value="1"' . checked( ! empty( $as['honeypot'] ), true, false ) . '> ' . esc_html__( 'Enable honeypot', 'bomedia-forms' ) . '</label></p>';
		$this->text_row( 'bf_antispam[rate_limit_count]', __( 'Rate limit: max submissions', 'bomedia-forms' ), (string) $as['rate_limit_count'] );
		$this->text_row( 'bf_antispam[rate_limit_hours]', __( 'Rate limit: per N hours', 'bomedia-forms' ), (string) $as['rate_limit_hours'] );
		$this->panel_close();

		echo '</div>';

		// Minimal inline tab switcher (no build step).
		echo '<script>(function(){var w=document.currentScript.closest(".bf-admin-tabs");if(!w)return;w.querySelectorAll(".bf-admin-tabs__tab").forEach(function(b){b.addEventListener("click",function(){w.querySelectorAll(".bf-admin-tabs__tab").forEach(function(x){x.classList.remove("is-active")});w.querySelectorAll(".bf-admin-panel").forEach(function(p){p.style.display="none"});b.classList.add("is-active");var t=w.querySelector(\'.bf-admin-panel[data-panel="\'+b.dataset.tab+\'"]\');if(t)t.style.display="block"})})})();</script>';
	}

	/**
	 * Persist meta on save.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function save_meta( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST['bf_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bf_meta_nonce'] ) ), 'bf_save_meta_' . $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Fields (JSON).
		if ( isset( $_POST['bf_fields_json'] ) ) {
			$decoded = json_decode( wp_unslash( $_POST['bf_fields_json'] ), true );
			if ( is_array( $decoded ) ) {
				update_post_meta( $post_id, BF_Settings::META_FIELDS, self::sanitize_fields( $decoded ) );
			}
		}

		// AgileCRM (encrypt api_key; keep existing when blank).
		if ( isset( $_POST['bf_agilecrm'] ) && is_array( $_POST['bf_agilecrm'] ) ) {
			$in       = wp_unslash( $_POST['bf_agilecrm'] );
			$existing = get_post_meta( $post_id, BF_Settings::META_AGILECRM, true );
			$existing = is_array( $existing ) ? $existing : array();

			$agile = array(
				'subdomain'     => sanitize_text_field( $in['subdomain'] ?? '' ),
				'account_email' => sanitize_email( $in['account_email'] ?? '' ),
				'default_tags'  => array_filter( array_map( 'trim', explode( ',', $in['default_tags'] ?? '' ) ) ),
				'field_mapping' => $existing['field_mapping'] ?? array(),
				'api_key'       => $existing['api_key'] ?? '',
			);
			if ( ! empty( $in['api_key'] ) ) {
				$agile['api_key'] = BF_Encryption::encrypt( sanitize_text_field( $in['api_key'] ) );
			}
			update_post_meta( $post_id, BF_Settings::META_AGILECRM, $agile );
		}

		// Captcha (encrypt secret; keep existing when blank).
		if ( isset( $_POST['bf_captcha'] ) && is_array( $_POST['bf_captcha'] ) ) {
			$in       = wp_unslash( $_POST['bf_captcha'] );
			$existing = get_post_meta( $post_id, BF_Settings::META_CAPTCHA, true );
			$existing = is_array( $existing ) ? $existing : array();

			$cap = array(
				'provider'   => sanitize_key( $in['provider'] ?? 'none' ),
				'site_key'   => sanitize_text_field( $in['site_key'] ?? '' ),
				'secret_key' => $existing['secret_key'] ?? '',
			);
			if ( ! empty( $in['secret_key'] ) ) {
				$cap['secret_key'] = BF_Encryption::encrypt( sanitize_text_field( $in['secret_key'] ) );
			}
			update_post_meta( $post_id, BF_Settings::META_CAPTCHA, $cap );
		}

		// Notifications.
		if ( isset( $_POST['bf_notifications'] ) && is_array( $_POST['bf_notifications'] ) ) {
			$in = wp_unslash( $_POST['bf_notifications'] );
			update_post_meta(
				$post_id,
				BF_Settings::META_NOTIFICATIONS,
				array(
					'recipient' => sanitize_email( $in['recipient'] ?? '' ),
					'subject'   => sanitize_text_field( $in['subject'] ?? '' ),
					'body_html' => wp_kses_post( $in['body_html'] ?? '' ),
				)
			);
		}

		// Post-submit.
		if ( isset( $_POST['bf_post_submit'] ) && is_array( $_POST['bf_post_submit'] ) ) {
			$in = wp_unslash( $_POST['bf_post_submit'] );
			update_post_meta(
				$post_id,
				BF_Settings::META_POST_SUBMIT,
				array(
					'mode'            => 'redirect' === ( $in['mode'] ?? '' ) ? 'redirect' : 'message',
					'success_message' => sanitize_text_field( $in['success_message'] ?? '' ),
					'redirect_url'    => esc_url_raw( $in['redirect_url'] ?? '' ),
				)
			);
		}

		// Anti-spam.
		if ( isset( $_POST['bf_antispam'] ) && is_array( $_POST['bf_antispam'] ) ) {
			$in = wp_unslash( $_POST['bf_antispam'] );
			update_post_meta(
				$post_id,
				BF_Settings::META_ANTISPAM,
				array(
					'honeypot'         => ! empty( $in['honeypot'] ),
					'rate_limit_count' => max( 0, (int) ( $in['rate_limit_count'] ?? 5 ) ),
					'rate_limit_hours' => max( 1, (int) ( $in['rate_limit_hours'] ?? 1 ) ),
				)
			);
		}
	}

	/**
	 * Sanitize a decoded fields array.
	 *
	 * Enforces unique field keys (auto-suffixing collisions) and a known
	 * width keyword. Options accept either ["v|Label", ...] strings or
	 * [{value,label}, ...] and are normalised to {value,label}.
	 *
	 * @param array $fields Raw fields.
	 * @return array
	 */
	public static function sanitize_fields( array $fields ) {
		$allowed = array( 'text', 'email', 'tel', 'textarea', 'select', 'checkbox', 'radio', 'hidden', 'custom' );
		$widths  = array( 'full', 'half', 'third' );
		$clean   = array();
		$seen    = array();
		$auto    = 0;

		foreach ( $fields as $f ) {
			if ( ! is_array( $f ) ) {
				continue;
			}

			$key = sanitize_key( $f['name'] ?? '' );
			if ( '' === $key ) {
				$key = 'field_' . ( ++$auto );
			}
			while ( isset( $seen[ $key ] ) ) {
				$key = preg_replace( '/_\d+$/', '', $key ) . '_' . ( ++$auto );
			}
			$seen[ $key ] = true;

			$clean[] = array(
				'type'        => in_array( ( $f['type'] ?? 'text' ), $allowed, true ) ? $f['type'] : 'text',
				'name'        => $key,
				'label'       => sanitize_text_field( $f['label'] ?? '' ),
				'placeholder' => sanitize_text_field( $f['placeholder'] ?? '' ),
				'required'    => ! empty( $f['required'] ),
				'pattern'     => isset( $f['pattern'] ) ? (string) $f['pattern'] : '',
				'default'     => sanitize_text_field( $f['default'] ?? '' ),
				'width'       => in_array( ( $f['width'] ?? 'full' ), $widths, true ) ? $f['width'] : 'full',
				'options'     => self::sanitize_options( $f['options'] ?? array() ),
			);
		}
		return $clean;
	}

	/**
	 * Normalise field options to a list of {value,label} pairs.
	 *
	 * @param mixed $options Raw options (array of strings or pairs).
	 * @return array
	 */
	private static function sanitize_options( $options ) {
		if ( ! is_array( $options ) ) {
			return array();
		}
		$out = array();
		foreach ( $options as $opt ) {
			if ( is_array( $opt ) ) {
				$val = sanitize_text_field( $opt['value'] ?? '' );
				$lbl = sanitize_text_field( $opt['label'] ?? $val );
			} else {
				$parts = explode( '|', (string) $opt, 2 );
				$val   = sanitize_text_field( trim( $parts[0] ) );
				$lbl   = sanitize_text_field( trim( isset( $parts[1] ) ? $parts[1] : $parts[0] ) );
			}
			if ( '' === $val && '' === $lbl ) {
				continue;
			}
			$out[] = array(
				'value' => $val,
				'label' => $lbl,
			);
		}
		return $out;
	}

	/**
	 * Render the drag-and-drop fields editor shell.
	 *
	 * Rows are built client-side from the JSON payload below and serialised
	 * back into the hidden #bf_fields_json input on save.
	 *
	 * @param array $fields Current field definitions.
	 * @return void
	 */
	private function render_fields_editor( array $fields ) {
		?>
		<div id="bf-fields-editor" class="bf-fields-editor">
			<div class="bf-fields-editor__toolbar">
				<button type="button" class="button" id="bf-preview-btn">
					<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
					<?php esc_html_e( 'Preview', 'bomedia-forms' ); ?>
				</button>
				<span class="bf-fields-editor__add">
					<select id="bf-add-type" aria-label="<?php esc_attr_e( 'New field type', 'bomedia-forms' ); ?>">
						<?php foreach ( self::field_type_labels() as $val => $label ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="button" class="button button-primary" id="bf-add-field">
						<?php esc_html_e( '+ Add field', 'bomedia-forms' ); ?>
					</button>
				</span>
			</div>

			<div id="bf-fields-list" class="bf-fields-list" aria-live="polite"></div>
			<p class="bf-fields-empty description"><?php esc_html_e( 'No fields yet. Use “+ Add field” to start.', 'bomedia-forms' ); ?></p>

			<input type="hidden" name="bf_fields_json" id="bf_fields_json" />
			<script type="application/json" id="bf-fields-data">
				<?php echo wp_json_encode( array_values( $fields ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</script>
		</div>

		<div id="bf-preview-modal" class="bf-preview-modal" hidden>
			<div class="bf-preview-modal__overlay" data-close="1"></div>
			<div class="bf-preview-modal__dialog" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Form preview', 'bomedia-forms' ); ?>">
				<button type="button" class="bf-preview-modal__close" data-close="1" aria-label="<?php esc_attr_e( 'Close', 'bomedia-forms' ); ?>">&times;</button>
				<div class="bf-preview-modal__body"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Resolve a form's configured language for the list table.
	 *
	 * @param int $form_id Form ID.
	 * @return string
	 */
	private function form_language( $form_id ) {
		if ( function_exists( 'pll_get_post_language' ) ) {
			return (string) pll_get_post_language( $form_id );
		}
		if ( defined( 'ICL_LANGUAGE_CODE' ) ) {
			return (string) apply_filters( 'wpml_post_language_details', null, $form_id )['language_code'] ?? '';
		}
		return strtolower( substr( get_locale(), 0, 2 ) );
	}

	/**
	 * Count submissions for a form in the last 30 days.
	 *
	 * @param int $form_id Form ID.
	 * @return int
	 */
	private function submission_count( $form_id ) {
		global $wpdb;
		$table = BF_Submission_Handler::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE form_id = %d AND created_at >= %s",
				$form_id,
				gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS )
			)
		);
	}

	/**
	 * Open a tab panel container.
	 *
	 * @param string $key    Panel key.
	 * @param bool   $active Whether visible by default.
	 * @return void
	 */
	private function panel_open( $key, $active = false ) {
		printf(
			'<div class="bf-admin-panel" data-panel="%s" style="display:%s">',
			esc_attr( $key ),
			$active ? 'block' : 'none'
		);
	}

	/**
	 * Close a tab panel container.
	 *
	 * @return void
	 */
	private function panel_close() {
		echo '</div>';
	}

	/**
	 * Render a labelled text input row.
	 *
	 * @param string $name  Input name.
	 * @param string $label Label.
	 * @param string $value Current value.
	 * @return void
	 */
	private function text_row( $name, $label, $value ) {
		printf(
			'<p><label>%s<br /><input type="text" class="regular-text" name="%s" value="%s" /></label></p>',
			esc_html( $label ),
			esc_attr( $name ),
			esc_attr( $value )
		);
	}
}
