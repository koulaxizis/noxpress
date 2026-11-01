<?php
/**
 * RDR_Suggest — where a 404 path probably went (admin only, never on the
 * front end; nothing is applied without a click).
 *
 * In order, the first hits win (at most 3 suggestions):
 *  1. WordPress's own old slugs (_wp_old_slug) for the last segment.
 *  2. Greeklish Permalinks' redirect table (glp_map: files, paths, terms).
 *  3. The Greek address converted the way Greeklish Permalinks makes new
 *     slugs (glp_convert()), when a post or a term lives there.
 *  4. An upload: the same file name in another month folder, or without
 *     «-1», a size suffix or «-scaled».
 *  5. A slug that contains the old one or is contained in it
 *     (christos-karras-to-moraki… → to-moraki…), or the closest slug by
 *     edit distance, in the same post type (products under the product
 *     base) or the same taxonomy (category bases).
 * Never «the home page»: Google treats that as a soft 404.
 */

defined( 'ABSPATH' ) || exit;

final class RDR_Suggest {

	/**
	 * Suggestions for a normalised path.
	 *
	 * @return array[] Each: url, label, why.
	 */
	public static function for_path( string $path ): array {
		$out = array();
		try {
			$segs = explode( '/', $path );
			$last = (string) end( $segs );

			self::old_slug( $last, $out );
			self::glp_map( $path, $out );
			self::glp_greek( $path, $out );
			if ( count( $out ) < 3 && self::is_upload( $path ) ) {
				self::upload( $path, $out );
			}
			if ( count( $out ) < 3 && ! self::is_upload( $path ) ) {
				$tax = self::taxonomy_of( $segs );
				if ( '' !== $tax ) {
					self::similar_term( $last, $tax, $out );
				} else {
					self::similar_post( $last, self::types_of( $segs ), $out );
				}
			}
		} catch ( \Throwable $e ) {
			return $out;
		}
		return array_slice( $out, 0, 3 );
	}

	private static function push( array &$out, string $url, string $label, string $why ): void {
		if ( '' === $url ) {
			return;
		}
		foreach ( $out as $o ) {
			if ( $o['url'] === $url ) {
				return;
			}
		}
		$out[] = array(
			'url'   => $url,
			'label' => $label,
			'why'   => $why,
		);
	}

	/** A published, viewable post's link and title. */
	private static function post_link( int $id ): array {
		$p = get_post( $id );
		if ( ! $p || 'publish' !== $p->post_status || ! is_post_type_viewable( $p->post_type ) ) {
			return array( '', '' );
		}
		return array( (string) get_permalink( $p ), wp_strip_all_tags( get_the_title( $p ) ) );
	}

	private static function old_slug( string $last, array &$out ): void {
		global $wpdb;
		if ( '' === $last ) {
			return;
		}
		$vals = array_unique( array( $last, strtolower( rawurlencode( $last ) ), sanitize_title( $last ) ) );
		$in   = implode( ',', array_fill( 0, count( $vals ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_old_slug' AND meta_value IN ({$in}) ORDER BY meta_id DESC LIMIT 3", $vals ) );
		foreach ( (array) $ids as $id ) {
			list( $url, $title ) = self::post_link( (int) $id );
			self::push( $out, $url, $title, __( 'Παλιό slug του WordPress', 'redirects' ) );
		}
	}

	/** Greeklish Permalinks' table, keys compared in lower case. */
	private static function glp_map( string $path, array &$out ): void {
		if ( ! class_exists( 'GLP_Redirects' ) || ! method_exists( 'GLP_Redirects', 'map' ) ) {
			return;
		}
		$m    = GLP_Redirects::map();
		$why  = __( 'Πίνακας του Greeklish Permalinks', 'redirects' );
		$up   = self::upload_rel( $path );
		if ( '' !== $up && ! empty( $m['files'] ) && method_exists( 'GLP_Redirects', 'upload_url' ) ) {
			foreach ( $m['files'] as $old => $new ) {
				if ( RDR_Path::lower( (string) $old ) === $up ) {
					self::push( $out, GLP_Redirects::upload_url( (string) $new ), basename( (string) $new ), $why );
					return;
				}
			}
		}
		foreach ( (array) $m['paths'] as $old => $id ) {
			if ( RDR_Path::lower( trim( (string) $old, '/' ) ) === $path ) {
				list( $url, $title ) = self::post_link( (int) $id );
				self::push( $out, $url, $title, $why );
				return;
			}
		}
		$segs = explode( '/', $path );
		$last = (string) end( $segs );
		foreach ( (array) $m['terms'] as $old => $taxes ) {
			if ( RDR_Path::lower( (string) $old ) !== $last || ! is_array( $taxes ) ) {
				continue;
			}
			foreach ( $taxes as $tax => $tid ) {
				$link = get_term_link( (int) $tid, (string) $tax );
				if ( ! is_wp_error( $link ) ) {
					$t = get_term( (int) $tid, (string) $tax );
					self::push( $out, (string) $link, $t instanceof WP_Term ? $t->name : '', $why );
				}
			}
		}
	}

	private static function has_greek( string $s ): bool {
		return 1 === preg_match( '/[\x{0370}-\x{03FF}\x{1F00}-\x{1FFF}]/u', $s );
	}

	/** The Greek address converted like a new slug (Greeklish Permalinks). */
	private static function glp_greek( string $path, array &$out ): void {
		if ( ! function_exists( 'glp_convert' ) || ! self::has_greek( $path ) ) {
			return;
		}
		$why  = __( 'Η ελληνική διεύθυνση σε greeklish', 'redirects' );
		$segs = array();
		foreach ( explode( '/', $path ) as $seg ) {
			$segs[] = self::has_greek( $seg ) ? sanitize_title_with_dashes( glp_convert( $seg, 'slug' ), '', 'save' ) : $seg;
		}
		$new = implode( '/', $segs );
		if ( self::is_upload( $path ) ) {
			$rel  = self::upload_rel( $path );
			$dir  = dirname( $rel );
			$conv = glp_convert( basename( $rel ), 'file' );
			$id   = self::attachment_by_rel( ( '.' === $dir ? '' : $dir . '/' ) . $conv );
			if ( $id ) {
				self::push( $out, (string) wp_get_attachment_url( $id ), $conv, $why );
			}
			return;
		}
		$id = url_to_postid( home_url( user_trailingslashit( $new ) ) );
		if ( $id > 0 ) {
			list( $url, $title ) = self::post_link( $id );
			self::push( $out, $url, $title, $why );
			return;
		}
		$last = (string) end( $segs );
		foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $tax ) {
			$term = get_term_by( 'slug', $last, $tax );
			if ( $term instanceof WP_Term ) {
				$link = get_term_link( $term );
				if ( ! is_wp_error( $link ) ) {
					self::push( $out, (string) $link, $term->name, $why );
				}
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Uploads
	 * ------------------------------------------------------------------- */

	/** Normalised prefix of the uploads folder, e.g. 'wp-content/uploads/'. */
	private static function uploads_prefix(): string {
		$up = wp_upload_dir( null, false );
		$n  = RDR_Path::norm( (string) $up['baseurl'] );
		return null === $n ? 'wp-content/uploads/' : $n . '/';
	}

	private static function is_upload( string $path ): bool {
		return 0 === strpos( $path, self::uploads_prefix() );
	}

	/** Path relative to uploads ('' when not under it). */
	private static function upload_rel( string $path ): string {
		return self::is_upload( $path ) ? substr( $path, strlen( self::uploads_prefix() ) ) : '';
	}

	private static function attachment_by_rel( string $rel ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one lookup in admin.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND LOWER(meta_value) = %s LIMIT 1", RDR_Path::lower( $rel ) ) );
	}

	private static function upload( string $path, array &$out ): void {
		global $wpdb;
		$file = basename( $path );
		$dot  = strrpos( $file, '.' );
		$ext  = false === $dot ? '' : substr( $file, $dot );
		$stem = false === $dot ? $file : substr( $file, 0, $dot );
		if ( function_exists( 'glp_convert' ) && self::has_greek( $stem ) ) {
			$stem = RDR_Path::lower( glp_convert( $stem . $ext, 'file' ) );
			$stem = substr( $stem, 0, strlen( $stem ) - strlen( $ext ) );
		}
		$stem = (string) preg_replace( '/(-\d+x\d+|-scaled|-\d{1,2})+$/', '', $stem );
		if ( strlen( $stem ) < 4 ) {
			return;
		}
		$like = '%' . $wpdb->esc_like( '/' . $stem ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin lookup.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND LOWER(meta_value) LIKE %s ORDER BY post_id DESC LIMIT 20", $like ), ARRAY_A );
		$best = array();
		foreach ( (array) $rows as $r ) {
			$name  = RDR_Path::lower( basename( (string) $r['meta_value'] ) );
			$score = ( '' !== $ext && substr( $name, -strlen( $ext ) ) === $ext ) ? 2 : 1;
			if ( 0 === strpos( $name, $stem . '.' ) || 0 === strpos( $name, $stem . '-' ) ) {
				++$score;
			}
			$best[] = array( $score, (int) $r['post_id'], (string) $r['meta_value'] );
		}
		usort(
			$best,
			static function ( $a, $b ) {
				return $b[0] <=> $a[0];
			}
		);
		foreach ( array_slice( $best, 0, 2 ) as $b ) {
			$url = wp_get_attachment_url( $b[1] );
			if ( $url ) {
				self::push( $out, (string) $url, basename( $b[2] ), __( 'Αρχείο με παρόμοιο όνομα', 'redirects' ) );
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Similar slugs
	 * ------------------------------------------------------------------- */

	/** Taxonomy of a path that starts with a public taxonomy's base. */
	private static function taxonomy_of( array $segs ): string {
		if ( count( $segs ) < 2 ) {
			return '';
		}
		$first = RDR_Path::lower( rawurldecode( (string) $segs[0] ) );
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tx ) {
			$slug = is_array( $tx->rewrite ) && ! empty( $tx->rewrite['slug'] ) ? trim( (string) $tx->rewrite['slug'], '/' ) : '';
			if ( '' !== $slug && RDR_Path::lower( (string) strtok( $slug, '/' ) ) === $first ) {
				return (string) $tx->name;
			}
		}
		return '';
	}

	/** Post types to search: products under the product base, else all viewable. */
	private static function types_of( array $segs ): array {
		$first = (string) $segs[0];
		if ( count( $segs ) > 1 && post_type_exists( 'product' ) ) {
			$wc   = get_option( 'woocommerce_permalinks', array() );
			$base = is_array( $wc ) && ! empty( $wc['product_base'] ) ? trim( (string) $wc['product_base'], '/' ) : 'product';
			if ( RDR_Path::lower( (string) strtok( $base, '/' ) ) === $first ) {
				return array( 'product' );
			}
		}
		$types = array();
		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $t ) {
			if ( 'attachment' !== $t ) {
				$types[] = (string) $t;
			}
		}
		return $types;
	}

	/** Similarity 0–1 of two slugs (containment counts as 0.9). */
	private static function score( string $a, string $b ): float {
		if ( '' === $a || '' === $b ) {
			return 0.0;
		}
		if ( strlen( $b ) >= 6 && false !== strpos( $a, $b ) ) {
			return 0.9 + 0.1 * strlen( $b ) / max( 1, strlen( $a ) );
		}
		if ( strlen( $a ) >= 6 && false !== strpos( $b, $a ) ) {
			return 0.9 + 0.1 * strlen( $a ) / max( 1, strlen( $b ) );
		}
		if ( strlen( $a ) > 255 || strlen( $b ) > 255 ) {
			return 0.0;
		}
		return 1.0 - levenshtein( $a, $b ) / max( strlen( $a ), strlen( $b ) );
	}

	private static function similar_post( string $last, array $types, array &$out ): void {
		global $wpdb;
		$slug = sanitize_title( $last );
		if ( strlen( $slug ) < 4 || ! $types ) {
			return;
		}
		$in   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$cand = array();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above; admin lookup.
		$queries = array(
			// The new slug is inside the old one (a prefix or a suffix was dropped).
			$wpdb->prepare( "SELECT ID, post_name FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$in}) AND LENGTH(post_name) >= 6 AND %s LIKE CONCAT('%%', post_name, '%%') ORDER BY LENGTH(post_name) DESC LIMIT 10", array_merge( $types, array( $slug ) ) ),
			// The old slug is inside the new one.
			$wpdb->prepare( "SELECT ID, post_name FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$in}) AND post_name LIKE %s ORDER BY LENGTH(post_name) ASC LIMIT 10", array_merge( $types, array( '%' . $wpdb->esc_like( $slug ) . '%' ) ) ),
			// Same beginning (a typo or a changed ending).
			$wpdb->prepare( "SELECT ID, post_name FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$in}) AND post_name LIKE %s LIMIT 50", array_merge( $types, array( $wpdb->esc_like( substr( $slug, 0, 6 ) ) . '%' ) ) ),
		);
		foreach ( $queries as $sql ) {
			foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $r ) {
				$cand[ (int) $r['ID'] ] = (string) $r['post_name'];
			}
		}
		// phpcs:enable
		$scored = array();
		foreach ( $cand as $id => $name ) {
			$s = self::score( $slug, $name );
			if ( $s >= 0.6 ) {
				$scored[] = array( $s, $id );
			}
		}
		usort(
			$scored,
			static function ( $a, $b ) {
				return $b[0] <=> $a[0];
			}
		);
		foreach ( array_slice( $scored, 0, 2 ) as $s ) {
			list( $url, $title ) = self::post_link( $s[1] );
			self::push( $out, $url, $title, $s[0] >= 0.9 ? __( 'Slug που περιέχει το παλιό ή περιέχεται σε αυτό', 'redirects' ) : __( 'Παρόμοιο slug', 'redirects' ) );
		}
	}

	private static function similar_term( string $last, string $tax, array &$out ): void {
		$slug = sanitize_title( $last );
		if ( strlen( $slug ) < 3 ) {
			return;
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $tax,
				'hide_empty' => false,
				'number'     => 1000,
			)
		);
		if ( ! is_array( $terms ) ) {
			return;
		}
		$scored = array();
		foreach ( $terms as $t ) {
			if ( $t instanceof WP_Term ) {
				$s = self::score( $slug, (string) $t->slug );
				if ( $s >= 0.6 ) {
					$scored[] = array( $s, $t );
				}
			}
		}
		usort(
			$scored,
			static function ( $a, $b ) {
				return $b[0] <=> $a[0];
			}
		);
		foreach ( array_slice( $scored, 0, 2 ) as $s ) {
			$link = get_term_link( $s[1] );
			if ( ! is_wp_error( $link ) ) {
				self::push( $out, (string) $link, $s[1]->name, $s[0] >= 0.9 ? __( 'Slug που περιέχει το παλιό ή περιέχεται σε αυτό', 'redirects' ) : __( 'Παρόμοιο slug', 'redirects' ) );
			}
		}
	}
}
