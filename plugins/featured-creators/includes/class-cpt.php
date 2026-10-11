<?php
/**
 * CRT_CPT — the nox_creator post type and the creator's own data.
 *
 * Fields (Bible §12: post meta with the plugin prefix, JSON for lists):
 *  - photo           the featured image
 *  - biography       the content (block or classic editor)
 *  - _crt_roles_text "Poet, musician" ('' = from the roles with products)
 *  - _crt_sort_name  index sort name ('' = the name as written)
 *  - _crt_links      JSON [ { url, label } ]
 *  - _crt_press      JSON [ { type: interview|video|review, date: Y-m-d,
 *                    outlet, title, url, image: attachment id } ]
 *  - _crt_key        matching key ('' = the post slug)
 *  - _crt_extra      JSON [ term_taxonomy_id ] extra terms (pseudonyms)
 *  - _crt_exclude    JSON [ term_taxonomy_id ] excluded terms (homonyms)
 *  - _crt_terms      JSON [ term_taxonomy_id ] terms it owns (computed)
 *  - _crt_roles      JSON [ taxonomy ] roles with published products (computed)
 *  - _crt_count      published products (computed)
 *
 * The meta is not exposed to REST. Capabilities follow pages (editors,
 * administrators and shop managers).
 */

defined( 'ABSPATH' ) || exit;

final class CRT_CPT {

	const PT = 'nox_creator';

	const M_ROLES_TEXT = '_crt_roles_text';
	const M_SORT       = '_crt_sort_name';
	const M_LINKS      = '_crt_links';
	const M_PRESS      = '_crt_press';
	const M_KEY        = '_crt_key';
	const M_EXTRA      = '_crt_extra';
	const M_EXCLUDE    = '_crt_exclude';
	const M_TERMS      = '_crt_terms';
	const M_ROLES      = '_crt_roles';
	const M_COUNT      = '_crt_count';

	const PRESS_TYPES = array( 'interview', 'video', 'review' );

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ), 5 );
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 99 );
		add_filter( 'use_block_editor_for_post_type', array( __CLASS__, 'classic_editor' ), 10, 2 );
		add_filter( 'post_updated_messages', array( __CLASS__, 'messages' ) );
	}

	/** "Creator updated." instead of "Post updated." */
	public static function messages( $messages ) {
		$post = get_post();
		if ( ! is_array( $messages ) || ! $post instanceof WP_Post || self::PT !== $post->post_type ) {
			return $messages;
		}
		$view = '';
		if ( 'publish' === $post->post_status ) {
			$view = ' <a href="' . esc_url( (string) get_permalink( $post ) ) . '">' . esc_html__( 'Προβολή σελίδας', 'featured-creators' ) . '</a>';
		}
		$updated  = esc_html__( 'Ο δημιουργός ενημερώθηκε.', 'featured-creators' );
		$messages[ self::PT ] = array(
			0  => '',
			1  => $updated . $view,
			4  => $updated,
			6  => esc_html__( 'Ο δημιουργός δημοσιεύτηκε.', 'featured-creators' ) . $view,
			7  => esc_html__( 'Ο δημιουργός αποθηκεύτηκε.', 'featured-creators' ),
			8  => esc_html__( 'Ο δημιουργός υποβλήθηκε.', 'featured-creators' ),
			9  => esc_html__( 'Ο δημιουργός προγραμματίστηκε.', 'featured-creators' ),
			10 => esc_html__( 'Το προσχέδιο του δημιουργού ενημερώθηκε.', 'featured-creators' ),
		);
		return $messages;
	}

	/**
	 * The creator screen is mostly meta boxes (details, lists, matching):
	 * the classic editor shows them next to the biography, where the block
	 * editor would fold them into a panel under the content.
	 */
	public static function classic_editor( $use, $post_type ) {
		return self::PT === $post_type ? false : $use;
	}

	public static function register(): void {
		$s = CRT_Settings::get();
		register_post_type(
			self::PT,
			array(
				'labels'              => array(
					'name'               => __( 'Δημιουργοί', 'featured-creators' ),
					'singular_name'      => __( 'Δημιουργός', 'featured-creators' ),
					'add_new'            => __( 'Νέος δημιουργός', 'featured-creators' ),
					'add_new_item'       => __( 'Νέος δημιουργός', 'featured-creators' ),
					'edit_item'          => __( 'Επεξεργασία δημιουργού', 'featured-creators' ),
					'new_item'           => __( 'Νέος δημιουργός', 'featured-creators' ),
					'view_item'          => __( 'Προβολή δημιουργού', 'featured-creators' ),
					'search_items'       => __( 'Αναζήτηση δημιουργών', 'featured-creators' ),
					'not_found'          => __( 'Δεν βρέθηκαν δημιουργοί.', 'featured-creators' ),
					'not_found_in_trash' => __( 'Δεν βρέθηκαν δημιουργοί στα διαγραμμένα.', 'featured-creators' ),
					'all_items'          => __( 'Δημιουργοί', 'featured-creators' ),
					'featured_image'     => __( 'Φωτογραφία', 'featured-creators' ),
					'set_featured_image' => __( 'Ορισμός φωτογραφίας', 'featured-creators' ),
					'remove_featured_image' => __( 'Αφαίρεση φωτογραφίας', 'featured-creators' ),
					'use_featured_image' => __( 'Χρήση ως φωτογραφία', 'featured-creators' ),
				),
				'public'              => true,
				'publicly_queryable'  => true,
				'exclude_from_search' => false,
				'show_ui'             => true,
				'show_in_menu'        => false, // Submenu of "Noxpress" (CRT_Admin_UI).
				'show_in_nav_menus'   => true,
				'show_in_rest'        => true, // Block editor.
				'rest_base'           => 'nox-creators',
				'has_archive'         => false, // The index is a page with [nox_creators].
				'hierarchical'        => false,
				'capability_type'     => 'page',
				'map_meta_cap'        => true,
				'supports'            => array( 'title', 'editor', 'thumbnail', 'excerpt', 'revisions' ),
				'rewrite'             => array(
					'slug'       => $s['base'],
					'with_front' => false,
					'feeds'      => false,
					'pages'      => false,
				),
				'query_var'           => self::PT,
				'can_export'          => true,
				'delete_with_user'    => false,
				'menu_icon'           => 'dashicons-groups',
			)
		);
	}

	/** Rewrite rules after activation or a change of the URL base. */
	public static function maybe_flush(): void {
		if ( get_option( 'crt_flush' ) ) {
			delete_option( 'crt_flush' );
			flush_rewrite_rules( false );
		}
	}

	/* =====================================================================
	 * Reading
	 * =================================================================== */

	/** Published, without a password: the only creators visitors see. */
	public static function is_public( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}
		$p = get_post( $id );
		return $p instanceof WP_Post && self::PT === $p->post_type && 'publish' === $p->post_status && '' === $p->post_password;
	}

	/** A JSON list meta as an array. */
	public static function json_meta( int $id, string $key ): array {
		$raw = get_post_meta( $id, $key, true );
		if ( is_array( $raw ) ) {
			return $raw;
		}
		$v = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
		return is_array( $v ) ? $v : array();
	}

	/** Stores a list as JSON (wp_slash: update_post_meta unslashes). */
	public static function set_json_meta( int $id, string $key, array $value ): void {
		if ( ! $value ) {
			delete_post_meta( $id, $key );
			return;
		}
		update_post_meta( $id, $key, wp_slash( (string) wp_json_encode( array_values( $value ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) );
	}

	/** Integer list meta (term_taxonomy_ids). */
	public static function ids_meta( int $id, string $key ): array {
		return array_values( array_unique( array_filter( array_map( 'intval', self::json_meta( $id, $key ) ) ) ) );
	}

	/** Matching key: the stored key, else the post slug. */
	public static function key( int $id ): string {
		$k = (string) get_post_meta( $id, self::M_KEY, true );
		if ( '' === $k ) {
			$p = get_post( $id );
			$k = $p instanceof WP_Post ? urldecode( (string) $p->post_name ) : '';
		}
		return $k;
	}

	public static function links( int $id ): array {
		return self::sanitize_links( self::json_meta( $id, self::M_LINKS ) );
	}

	/** Press items, newest first. */
	public static function press( int $id ): array {
		$items = self::sanitize_press( self::json_meta( $id, self::M_PRESS ) );
		usort(
			$items,
			static function ( $a, $b ) {
				return strcmp( $b['date'], $a['date'] );
			}
		);
		return $items;
	}

	/** Name used for sorting the index. */
	public static function sort_name( int $id ): string {
		$v = trim( (string) get_post_meta( $id, self::M_SORT, true ) );
		return '' !== $v ? $v : (string) get_the_title( $id );
	}

	/* =====================================================================
	 * Validation (shared by the metabox and imports)
	 * =================================================================== */

	public static function sanitize_links( array $raw ): array {
		$out = array();
		foreach ( $raw as $row ) {
			if ( is_string( $row ) ) {
				$row = array( 'url' => $row );
			}
			if ( ! is_array( $row ) ) {
				continue;
			}
			$url = isset( $row['url'] ) && is_string( $row['url'] ) ? esc_url_raw( trim( $row['url'] ), array( 'http', 'https' ) ) : '';
			if ( '' === $url ) {
				continue;
			}
			$out[] = array(
				'url'   => $url,
				'label' => isset( $row['label'] ) && is_string( $row['label'] ) ? CRT_Settings::clip( sanitize_text_field( $row['label'] ), 60 ) : '',
			);
			if ( count( $out ) >= 30 ) {
				break;
			}
		}
		return $out;
	}

	public static function sanitize_press( array $raw ): array {
		$out = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$type  = isset( $row['type'] ) && in_array( $row['type'], self::PRESS_TYPES, true ) ? $row['type'] : 'review';
			$url   = isset( $row['url'] ) && is_string( $row['url'] ) ? esc_url_raw( trim( $row['url'] ), array( 'http', 'https' ) ) : '';
			$title = isset( $row['title'] ) && is_string( $row['title'] ) ? CRT_Settings::clip( sanitize_text_field( $row['title'] ), 200 ) : '';
			if ( '' === $url && '' === $title ) {
				continue;
			}
			$date = isset( $row['date'] ) && is_string( $row['date'] ) ? trim( $row['date'] ) : '';
			if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
				$date = '';
			}
			$img = isset( $row['image'] ) ? (int) $row['image'] : 0;
			if ( $img > 0 && ! wp_attachment_is_image( $img ) ) {
				$img = 0;
			}
			$out[] = array(
				'type'   => $type,
				'date'   => $date,
				'outlet' => isset( $row['outlet'] ) && is_string( $row['outlet'] ) ? CRT_Settings::clip( sanitize_text_field( $row['outlet'] ), 120 ) : '',
				'title'  => $title,
				'url'    => $url,
				'image'  => 'video' === $type ? $img : 0,
			);
			if ( count( $out ) >= 200 ) {
				break;
			}
		}
		return $out;
	}
}
