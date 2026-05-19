<?php
/**
 * Custom Post Type registration for forms.
 *
 * Each `bf_form` post is one form definition. The CPT is registered as
 * WPML-translatable; technical config is not duplicated per language
 * (handled in BF_I18n).
 *
 * @package BomediaForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BF_CPT
 */
class BF_CPT {

	const POST_TYPE = 'bf_form';

	/**
	 * Register the custom post type.
	 *
	 * @return void
	 */
	public function register() {
		$labels = array(
			'name'               => _x( 'Bomedia Forms', 'post type general name', 'bomedia-forms' ),
			'singular_name'      => _x( 'Form', 'post type singular name', 'bomedia-forms' ),
			'menu_name'          => _x( 'Bomedia Forms', 'admin menu', 'bomedia-forms' ),
			'add_new'            => __( 'Add Form', 'bomedia-forms' ),
			'add_new_item'       => __( 'Add New Form', 'bomedia-forms' ),
			'edit_item'          => __( 'Edit Form', 'bomedia-forms' ),
			'new_item'           => __( 'New Form', 'bomedia-forms' ),
			'view_item'          => __( 'View Form', 'bomedia-forms' ),
			'search_items'       => __( 'Search Forms', 'bomedia-forms' ),
			'not_found'          => __( 'No forms found', 'bomedia-forms' ),
			'not_found_in_trash' => __( 'No forms found in Trash', 'bomedia-forms' ),
			'all_items'          => __( 'All Forms', 'bomedia-forms' ),
		);

		$args = array(
			'labels'              => $labels,
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => false, // Mounted under the custom top-level menu.
			'show_in_rest'        => false,
			'capability_type'     => 'post',
			'hierarchical'        => false,
			'supports'            => array( 'title' ),
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'exclude_from_search' => true,
			// WPML translatable flag.
			'translatable'        => true,
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Fetch a form post by slug.
	 *
	 * @param string $slug Post slug.
	 * @return WP_Post|null
	 */
	public static function get_by_slug( $slug ) {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return null;
		}

		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'name'           => $slug,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
			)
		);

		return $posts ? $posts[0] : null;
	}
}
