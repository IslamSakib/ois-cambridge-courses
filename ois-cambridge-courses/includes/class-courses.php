<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "ois_course" custom post type. Featured image = course thumbnail,
 * post_content = long description shown on the course detail step,
 * post_excerpt = short blurb for the grid card. Everything else (price,
 * category tag, instructor, duration) lives in a meta box.
 */
class OIS_CC_Courses {

	const POST_TYPE = 'ois_course';

	public function __construct() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_meta_box' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'column_content' ), 10, 2 );
	}

	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'          => 'Cambridge Courses',
					'singular_name' => 'Course',
					'add_new_item'  => 'Add New Course',
					'edit_item'     => 'Edit Course',
					'menu_name'     => 'Cambridge Courses',
				),
				'public'       => false,
				'show_ui'      => true,
				// Not auto-placed in the menu (show_in_menu => false) — OIS_CC_Admin::register_menu()
				// adds the "Courses" submenu item explicitly instead, so there's exactly one
				// registration path and no race with WordPress's own submenu auto-insertion.
				'show_in_menu' => false,
				'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail', 'page-attributes' ),
				'has_archive'  => false,
			)
		);
	}

	public function add_meta_box() {
		add_meta_box( 'ois_cc_course_details', 'Course Details', array( $this, 'render_meta_box' ), self::POST_TYPE, 'normal', 'high' );
	}

	public function render_meta_box( $post ) {
		wp_nonce_field( 'ois_cc_course_meta', 'ois_cc_course_meta_nonce' );
		$fields = array(
			'category'   => 'Category tag (e.g. Cambridge IGCSE)',
			'instructor' => 'Instructor name',
			'duration'   => 'Duration (e.g. 6 months)',
			'price'      => 'Price (BDT)',
			'list_price' => 'Original / list price (BDT, optional — shown struck through)',
		);
		echo '<table class="form-table">';
		foreach ( $fields as $key => $label ) {
			$value = get_post_meta( $post->ID, '_ois_cc_' . $key, true );
			printf(
				'<tr><th><label for="ois_cc_%1$s">%2$s</label></th><td><input type="text" class="regular-text" id="ois_cc_%1$s" name="ois_cc_%1$s" value="%3$s"></td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( $value )
			);
		}
		echo '</table>';
	}

	public function save_meta_box( $post_id ) {
		if ( ! isset( $_POST['ois_cc_course_meta_nonce'] ) || ! wp_verify_nonce( $_POST['ois_cc_course_meta_nonce'], 'ois_cc_course_meta' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		foreach ( array( 'category', 'instructor', 'duration', 'price', 'list_price' ) as $key ) {
			if ( isset( $_POST[ 'ois_cc_' . $key ] ) ) {
				update_post_meta( $post_id, '_ois_cc_' . $key, sanitize_text_field( wp_unslash( $_POST[ 'ois_cc_' . $key ] ) ) );
			}
		}
	}

	public function columns( $columns ) {
		$columns['ois_cc_price'] = 'Price';
		return $columns;
	}

	public function column_content( $column, $post_id ) {
		if ( 'ois_cc_price' === $column ) {
			echo esc_html( get_post_meta( $post_id, '_ois_cc_price', true ) ) . ' BDT';
		}
	}

	/**
	 * Public read model used by the REST API / frontend.
	 */
	public static function get_course_data( $post ) {
		$post = get_post( $post );
		// Called with a raw REST-supplied ID (create-order), so this must reject
		// any non-course or unpublished post itself — not just trust callers —
		// or arbitrary draft/private post content leaks through the order response.
		if ( ! $post || self::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return null;
		}
		return array(
			'id'         => $post->ID,
			// get_the_title() returns HTML-entity-encoded text (e.g. "&#038;") meant for
			// direct echo; decode it since the frontend JS treats this as plain text and
			// re-escapes it itself before inserting into the DOM.
			'title'      => wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES ),
			'excerpt'    => wp_specialchars_decode( has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_trim_words( $post->post_content, 20 ), ENT_QUOTES ),
			'description'=> apply_filters( 'the_content', $post->post_content ),
			'image'      => get_the_post_thumbnail_url( $post, 'medium_large' ),
			'category'   => get_post_meta( $post->ID, '_ois_cc_category', true ),
			'instructor' => get_post_meta( $post->ID, '_ois_cc_instructor', true ),
			'duration'   => get_post_meta( $post->ID, '_ois_cc_duration', true ),
			'price'      => (float) get_post_meta( $post->ID, '_ois_cc_price', true ),
			'list_price' => (float) get_post_meta( $post->ID, '_ois_cc_list_price', true ),
		);
	}

	public static function get_all_courses() {
		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'menu_order',
				'order'          => 'ASC',
			)
		);
		return array_map( array( __CLASS__, 'get_course_data' ), $posts );
	}

	/**
	 * Seed the 8 Cambridge English exams on activation so the shortcode isn't
	 * empty on first test. Admin can edit/replace these from wp-admin.
	 */
	public static function seed_default_courses() {
		if ( get_option( 'ois_cc_seeded' ) ) {
			return;
		}

		$courses = array(
			array( 'Pre A1 Starters', 'Cambridge English', 6400 ),
			array( 'A1 Movers', 'Cambridge English', 6900 ),
			array( 'A2 Flyers', 'Cambridge English', 7400 ),
			array( 'A2 Key', 'Cambridge English', 8300 ),
			array( 'B1 Preliminary', 'Cambridge English', 10400 ),
			array( 'B2 First', 'Cambridge English', 13700 ),
			array( 'C1 Advanced', 'Cambridge English', 16300 ),
			array( 'TKT Per Module', 'Cambridge English', 8350 ),
		);

		foreach ( $courses as $c ) {
			list( $title, $category, $price ) = $c;
			$post_id = wp_insert_post(
				array(
					'post_type'    => self::POST_TYPE,
					'post_title'   => $title,
					'post_excerpt' => 'Cambridge English course offered by Oxford International School.',
					'post_content' => 'Cambridge English: ' . $title . ' — a course offered by Oxford International School.',
					'post_status'  => 'publish',
				)
			);
			if ( $post_id && ! is_wp_error( $post_id ) ) {
				update_post_meta( $post_id, '_ois_cc_category', $category );
				update_post_meta( $post_id, '_ois_cc_instructor', 'OIS Faculty' );
				update_post_meta( $post_id, '_ois_cc_price', $price );
			}
		}

		update_option( 'ois_cc_seeded', 1 );
	}
}
