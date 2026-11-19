<?php
/**
 * VLG_Meta_Box — the «English» box in the editor of products, pages and posts.
 *
 * Three fields (title, short text, content); empty = the Greek text stays.
 * Saved on save_post with its own nonce and edit_post for that post (§11).
 * Content goes through wp_kses_post, short texts through
 * sanitize_text_field / wp_kses_post. Revisions and autosaves are skipped.
 * After a save the page cache of that post is purged (Noxpress Core), so
 * the Greek page shows its new hreflang links.
 */

defined( 'ABSPATH' ) || exit;

final class VLG_Meta_Box {

	const NONCE = 'vlg_box';
	const FIELD = 'vlg_box_nonce';

	public static function init(): void {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add' ) );
		add_action( 'save_post', array( __CLASS__, 'save' ), 20, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/** @return string[] */
	public static function types(): array {
		$types = array( 'page', 'post' );
		if ( post_type_exists( 'product' ) ) {
			$types[] = 'product';
		}
		return (array) apply_filters( 'vlg_post_types', $types );
	}

	public static function assets( $hook ): void {
		if ( 'post.php' === $hook || 'post-new.php' === $hook ) {
			wp_enqueue_style( 'vlg-box', VLG_URL . 'assets/admin.css', array(), VLG_VERSION );
		}
	}

	public static function add(): void {
		foreach ( self::types() as $type ) {
			add_meta_box( 'vlg-english', __( 'English (Visitor Language)', 'visitor-language' ), array( __CLASS__, 'render' ), $type, 'normal', 'default' );
		}
	}

	/** @param WP_Post $post */
	public static function render( $post ): void {
		$id = (int) $post->ID;
		wp_nonce_field( self::NONCE, self::FIELD );
		$product = 'product' === $post->post_type;
		echo '<div class="vlg-box">';
		echo '<p class="vlg-hint">' . esc_html__( 'Τα κείμενα που βλέπει ο επισκέπτης στο /en/. Ό,τι μείνει κενό δείχνεται στα ελληνικά.', 'visitor-language' ) . '</p>';
		echo '<label for="vlg_title">' . esc_html( $product ? __( 'Όνομα προϊόντος', 'visitor-language' ) : __( 'Τίτλος', 'visitor-language' ) ) . '</label>';
		echo '<input type="text" id="vlg_title" name="vlg_title" value="' . esc_attr( (string) get_post_meta( $id, '_vlg_title', true ) ) . '" />';
		echo '<label for="vlg_excerpt">' . esc_html( $product ? __( 'Σύντομη περιγραφή', 'visitor-language' ) : __( 'Απόσπασμα', 'visitor-language' ) ) . '</label>';
		echo '<textarea id="vlg_excerpt" name="vlg_excerpt">' . esc_textarea( (string) get_post_meta( $id, '_vlg_excerpt', true ) ) . '</textarea>';
		echo '<label for="vlg_content">' . esc_html( $product ? __( 'Περιγραφή', 'visitor-language' ) : __( 'Περιεχόμενο', 'visitor-language' ) ) . '</label>';
		wp_editor(
			(string) get_post_meta( $id, '_vlg_content', true ),
			'vlg_content',
			array(
				'textarea_name' => 'vlg_content',
				'textarea_rows' => 10,
				'media_buttons' => true,
				'teeny'         => false,
			)
		);
		$link = VLG_Router::add_prefix( (string) get_permalink( $id ) );
		if ( 'publish' === $post->post_status ) {
			echo '<p class="vlg-hint"><a href="' . esc_url( $link ) . '" target="_blank" rel="noopener">' . esc_html__( 'Δες την αγγλική σελίδα', 'visitor-language' ) . '</a></p>';
		}
		echo '</div>';
	}

	/**
	 * @param int     $post_id
	 * @param WP_Post $post
	 */
	public static function save( $post_id, $post ): void {
		try {
			$post_id = (int) $post_id;
			if ( ! isset( $_POST[ self::FIELD ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::FIELD ] ) ), self::NONCE ) ) {
				return;
			}
			if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
				return;
			}
			if ( ! is_object( $post ) || ! in_array( $post->post_type, self::types(), true ) ) {
				return;
			}
			$vals = array(
				'_vlg_title'   => isset( $_POST['vlg_title'] ) ? sanitize_text_field( wp_unslash( $_POST['vlg_title'] ) ) : '',
				'_vlg_excerpt' => isset( $_POST['vlg_excerpt'] ) ? wp_kses_post( wp_unslash( $_POST['vlg_excerpt'] ) ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wp_kses_post.
				'_vlg_content' => isset( $_POST['vlg_content'] ) ? wp_kses_post( wp_unslash( $_POST['vlg_content'] ) ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wp_kses_post.
			);
			$changed = false;
			foreach ( $vals as $key => $val ) {
				$val = trim( (string) $val );
				if ( $val === (string) get_post_meta( $post_id, $key, true ) ) {
					continue;
				}
				$changed = true;
				if ( '' === $val ) {
					delete_post_meta( $post_id, $key );
				} else {
					update_post_meta( $post_id, $key, wp_slash( $val ) );
				}
			}
			if ( $changed && class_exists( 'Noxpress_Core' ) && method_exists( 'Noxpress_Core', 'purge_page_cache' ) ) {
				Noxpress_Core::purge_page_cache( array( $post_id ) );
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}
}
