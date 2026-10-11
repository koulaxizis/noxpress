<?php
/**
 * CRT_Import — draft creators from the role terms (design §8 A).
 *
 * The settings page lists one person per slug (CRT_Match::persons()); the
 * chosen ones are created in batches of 20 over AJAX with a progress bar
 * (Bible §5). Each new creator is a DRAFT with the term's name as title
 * and the slug as both post slug and matching key. Running it again never
 * creates duplicates: a slug that already has an owner, or a creator with
 * that key or post slug, is skipped.
 */

defined( 'ABSPATH' ) || exit;

final class CRT_Import {

	const NONCE = 'crt_import';
	const BATCH = 20;

	public static function init(): void {
		add_action( 'wp_ajax_crt_import', array( __CLASS__, 'ajax' ) );
	}

	/** Slugs that already have a creator (by key or post slug), any status but trash. */
	public static function existing_keys(): array {
		$keys = array();
		foreach ( CRT_Match::creator_ids() as $cid ) {
			$keys[ CRT_CPT::key( $cid ) ] = $cid;
			$p                            = get_post( $cid );
			if ( $p instanceof WP_Post && '' !== $p->post_name ) {
				$keys[ urldecode( $p->post_name ) ] = $cid;
			}
		}
		return $keys;
	}

	public static function ajax(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_pages' ) ) {
			wp_send_json_error( array(), 403 );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each value is checked against the persons list below.
		$raw = isset( $_POST['slugs'] ) && is_array( $_POST['slugs'] ) ? wp_unslash( $_POST['slugs'] ) : array();
		$raw = array_slice( array_values( array_filter( $raw, 'is_string' ) ), 0, self::BATCH );

		$persons  = CRT_Match::persons();
		$existing = self::existing_keys();
		$created  = 0;
		$skipped  = 0;
		foreach ( $raw as $slug ) {
			if ( ! isset( $persons[ $slug ] ) || $persons[ $slug ]['owner'] || isset( $existing[ $slug ] ) ) {
				++$skipped;
				continue;
			}
			$id = wp_insert_post(
				array(
					'post_type'   => CRT_CPT::PT,
					'post_status' => 'draft',
					'post_title'  => wp_slash( $persons[ $slug ]['name'] ),
					'post_name'   => sanitize_title( $slug ),
				),
				true
			);
			if ( is_wp_error( $id ) || ! $id ) {
				++$skipped;
				continue;
			}
			update_post_meta( (int) $id, CRT_CPT::M_KEY, wp_slash( $slug ) );
			$existing[ $slug ] = (int) $id;
			++$created;
		}
		// Owners and stats now, so the next batch and the page see them.
		CRT_Match::recompute();
		wp_send_json_success(
			array(
				'created' => $created,
				'skipped' => $skipped,
			)
		);
	}
}
