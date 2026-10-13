<?php
/**
 * GLP_Bulk — on-demand conversion of existing slugs and file names.
 *
 * Items are keys "p:<post ID>", "t:<term ID>" and "f:<attachment ID>".
 * scan() lists the candidates; process() works on one item, as a dry run
 * (nothing written) or for real. The admin page runs them in AJAX batches.
 * The new value is always computed here from the current data, never
 * taken from the browser.
 *
 * Posts: post_name is updated directly (no wp_update_post: no new
 * modified date, no content filters); WordPress's own old-slug redirect
 * gets _wp_old_slug for published non-hierarchical posts, as
 * wp_check_for_changed_slugs() would add; hierarchical posts (pages) get
 * their old path in the redirect table. Products fire
 * noxpress_products_changed (Bible §6).
 *
 * Terms: wp_update_term(); the old slug goes to the redirect table.
 *
 * Files: the file and all its sizes / sources (plus .webp / .avif copies
 * next to them) are renamed in place; _wp_attached_file and the metadata
 * follow; URLs are replaced in post content and excerpts and in the
 * WooCommerce downloadable files of products and variations (download IDs
 * stay, so customers' downloads keep working); every old path goes to
 * the redirect table. References elsewhere are counted in the dry run and
 * left to the 301.
 *
 * Undo (Bible §12, bulk data changes): every apply run has a log (glp_log,
 * not autoloaded) that a new run replaces. Each item's entry, with the
 * values from before, is written to the log BEFORE the item changes (per
 * item, not per batch: an item depends on the ones before it, e.g. a name
 * just taken). If the entry cannot be written, the item is not touched and
 * the run stops; the page then undoes the whole run. undo() reverts the
 * items newest first, only where the current value is still the one the
 * run wrote. The log can be downloaded as JSON and imported back (Tools).
 */

defined( 'ABSPATH' ) || exit;

final class GLP_Bulk {

	const LOG        = 'glp_log';
	const SCAN_LIMIT = 3000;
	const STATUSES   = array( 'publish', 'future', 'draft', 'pending', 'private', 'inherit' );

	/* =====================================================================
	 * What can be scanned
	 * =================================================================== */

	/** Post types offered: public ones and those with an admin screen, minus WordPress internals. */
	public static function post_types(): array {
		$out = array();
		foreach ( get_post_types( array(), 'objects' ) as $pt ) {
			if ( ( $pt->public || $pt->show_ui ) && ! in_array( $pt->name, array( 'revision', 'nav_menu_item', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face', 'shop_order', 'shop_order_refund', 'shop_order_placehold', 'shop_coupon' ), true ) ) {
				$out[ $pt->name ] = $pt->labels->name;
			}
		}
		return $out;
	}

	/** Post types checked by default: the public ones except attachments. */
	public static function default_post_types(): array {
		$out = array();
		foreach ( array_keys( self::post_types() ) as $name ) {
			$pt = get_post_type_object( $name );
			if ( $pt && $pt->public && 'attachment' !== $name ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	public static function taxonomies(): array {
		$out = array();
		foreach ( get_taxonomies( array(), 'objects' ) as $tx ) {
			if ( ( $tx->public || $tx->show_ui ) && ! in_array( $tx->name, array( 'nav_menu', 'link_category', 'post_format', 'wp_theme', 'wp_template_part_area', 'wp_pattern_category', 'product_type', 'product_visibility', 'product_shipping_class' ), true ) ) {
				$out[ $tx->name ] = $tx->labels->name;
			}
		}
		return $out;
	}

	/* =====================================================================
	 * Scan
	 * =================================================================== */

	/** SQL condition: an encoded Greek byte sequence in a slug column. */
	private static function greek_like( string $col ): string {
		global $wpdb;
		$or = array();
		foreach ( array( '%cd%', '%ce%', '%cf%', '%e1%bc%', '%e1%bd%', '%e1%be%', '%e1%bf%' ) as $seq ) {
			$or[] = $wpdb->prepare( "LOWER({$col}) LIKE %s", '%' . $wpdb->esc_like( $seq ) . '%' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- column name is ours.
		}
		return '(' . implode( ' OR ', $or ) . ')';
	}

	/**
	 * Candidates. Rows: k, kind (post|term|file), type (label), title,
	 * old, new (before uniqueness), edit (admin URL).
	 *
	 * @return array{rows: array, counts: array, total: int, more: bool}
	 */
	public static function scan( array $post_types, array $taxonomies, bool $files ): array {
		global $wpdb;
		$rows   = array();
		$counts = array();
		$total  = 0;
		$opt    = GLP_Settings::slug_opt();
		$types  = self::post_types();
		$taxes  = self::taxonomies();

		$post_types = array_values( array_intersect( $post_types, array_keys( $types ) ) );
		if ( $post_types ) {
			$in   = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
			$st   = implode( ',', array_fill( 0, count( self::STATUSES ), '%s' ) );
			$sql  = "SELECT ID, post_name, post_type, post_title FROM {$wpdb->posts} WHERE post_type IN ($in) AND post_status IN ($st) AND " . self::greek_like( 'post_name' ) . ' ORDER BY post_type, ID';
			$list = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $post_types, self::STATUSES ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery -- placeholders built above; admin-only scan.
			foreach ( (array) $list as $r ) {
				$old = rawurldecode( (string) $r->post_name );
				if ( ! GLP_Converter::has_greek( $old ) ) {
					continue;
				}
				$total++;
				$counts[ $types[ $r->post_type ] ] = isset( $counts[ $types[ $r->post_type ] ] ) ? $counts[ $types[ $r->post_type ] ] + 1 : 1;
				if ( count( $rows ) < self::SCAN_LIMIT ) {
					$rows[] = array(
						'k'     => 'p:' . (int) $r->ID,
						'kind'  => 'post',
						'type'  => $types[ $r->post_type ],
						'title' => wp_strip_all_tags( (string) $r->post_title ),
						'old'   => $old,
						'new'   => self::slug_of( $old, $opt ),
						'edit'  => (string) get_edit_post_link( (int) $r->ID, 'raw' ),
					);
				}
			}
		}

		$taxonomies = array_values( array_intersect( $taxonomies, array_keys( $taxes ) ) );
		if ( $taxonomies ) {
			$in   = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );
			$sql  = "SELECT t.term_id, t.slug, t.name, tt.taxonomy FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy IN ($in) AND " . self::greek_like( 't.slug' ) . ' ORDER BY tt.taxonomy, t.term_id';
			$list = $wpdb->get_results( $wpdb->prepare( $sql, $taxonomies ) ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery -- placeholders built above; admin-only scan.
			foreach ( (array) $list as $r ) {
				$old = rawurldecode( (string) $r->slug );
				if ( ! GLP_Converter::has_greek( $old ) ) {
					continue;
				}
				$total++;
				$label            = $taxes[ $r->taxonomy ];
				$counts[ $label ] = isset( $counts[ $label ] ) ? $counts[ $label ] + 1 : 1;
				if ( count( $rows ) < self::SCAN_LIMIT ) {
					$rows[] = array(
						'k'     => 't:' . (int) $r->term_id,
						'kind'  => 'term',
						'type'  => $label,
						'title' => (string) $r->name,
						'old'   => $old,
						'new'   => self::slug_of( $old, $opt ),
						'edit'  => (string) get_edit_term_link( (int) $r->term_id, (string) $r->taxonomy ),
					);
				}
			}
		}

		if ( $files ) {
			$fopt  = GLP_Settings::file_opt();
			$label = __( 'Αρχεία', 'greeklish-permalinks' );
			$after = 0;
			do {
				// Plain chunks: non-ASCII detection in SQL differs between engines.
				$list = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_id > %d ORDER BY meta_id LIMIT 5000", $after ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery -- admin-only scan.
				foreach ( (array) $list as $r ) {
					$after = (int) $r->meta_id;
					$rel   = (string) $r->meta_value;
					if ( ! GLP_Converter::has_greek( $rel ) ) {
						continue;
					}
					$total++;
					$counts[ $label ] = isset( $counts[ $label ] ) ? $counts[ $label ] + 1 : 1;
					if ( count( $rows ) < self::SCAN_LIMIT ) {
						$rows[] = array(
							'k'     => 'f:' . (int) $r->post_id,
							'kind'  => 'file',
							'type'  => $label,
							'title' => (string) get_the_title( (int) $r->post_id ),
							'old'   => $rel,
							'new'   => self::dir_of( $rel ) . GLP_Converter::file_name( basename( $rel ), $fopt ),
							'edit'  => (string) get_edit_post_link( (int) $r->post_id, 'raw' ),
						);
					}
				}
			} while ( is_array( $list ) && 5000 === count( $list ) );
		}

		return array(
			'rows'   => $rows,
			'counts' => $counts,
			'total'  => $total,
			'more'   => $total > count( $rows ),
		);
	}

	/** New slug of an existing (decoded) slug, before uniqueness. */
	private static function slug_of( string $old, array $opt ): string {
		return sanitize_title_with_dashes( GLP_Converter::convert( $old, $opt ), '', 'save' );
	}

	/** "2025/12/" for "2025/12/x.jpg", '' for "x.jpg". */
	private static function dir_of( string $rel ): string {
		$d = dirname( $rel );
		return '.' === $d || '' === $d ? '' : trailingslashit( $d );
	}

	/* =====================================================================
	 * Process one item
	 * =================================================================== */

	/**
	 * @param string $run Run ID of the log (apply) or '' (dry run).
	 * @return array k, status ok|skip|error, new, note, refs (files, dry
	 *               run) and stop (apply: the undo entry was not written).
	 */
	public static function process( string $key, string $run = '' ): array {
		$res = array(
			'k'      => $key,
			'status' => 'error',
			'new'    => '',
			'note'   => '',
		);
		if ( ! preg_match( '/^([ptf]):(\d+)$/', $key, $m ) ) {
			$res['note'] = __( 'Άκυρο στοιχείο.', 'greeklish-permalinks' );
			return $res;
		}
		try {
			switch ( $m[1] ) {
				case 'p':
					return self::post( (int) $m[2], $run, $res );
				case 't':
					return self::term( (int) $m[2], $run, $res );
				default:
					return self::file( (int) $m[2], $run, $res );
			}
		} catch ( \Throwable $e ) {
			$res['status'] = 'error';
			$res['note']   = $e->getMessage();
			return $res;
		}
	}

	/**
	 * Write the undo entry before the change. False: the item must not be
	 * touched and the run stops (the result says why).
	 */
	private static function save_entry( string $run, array $entry, array &$res ): bool {
		if ( self::append( $run, $entry ) ) {
			return true;
		}
		$res['status'] = 'error';
		$res['stop']   = true;
		$res['note']   = __( 'Το αντίγραφο ασφαλείας δεν γράφτηκε, οπότε το στοιχείο δεν άλλαξε και η εφαρμογή σταμάτησε.', 'greeklish-permalinks' );
		return false;
	}

	private static function skip( array $res, string $note ): array {
		$res['status'] = 'skip';
		$res['note']   = $note;
		return $res;
	}

	private static function post( int $id, string $run, array $res ): array {
		$apply = '' !== $run;
		global $wpdb;
		$post = get_post( $id );
		if ( ! $post || ! isset( self::post_types()[ $post->post_type ] ) || ! in_array( $post->post_status, self::STATUSES, true ) ) {
			return self::skip( $res, __( 'Δεν βρέθηκε.', 'greeklish-permalinks' ) );
		}
		$old_raw = (string) $post->post_name;
		$old     = rawurldecode( $old_raw );
		if ( ! GLP_Converter::has_greek( $old ) ) {
			return self::skip( $res, __( 'Δεν έχει πια ελληνικά.', 'greeklish-permalinks' ) );
		}
		$conv = self::slug_of( $old, GLP_Settings::slug_opt() );
		if ( '' === $conv ) {
			return self::skip( $res, __( 'Το νέο slug θα ήταν κενό.', 'greeklish-permalinks' ) );
		}
		$new        = wp_unique_post_slug( $conv, $id, $post->post_status, $post->post_type, (int) $post->post_parent );
		$res['new'] = rawurldecode( $new );
		if ( $new !== $conv ) {
			$res['note'] = __( 'Υπάρχει ήδη: πήρε αριθμό.', 'greeklish-permalinks' );
		}
		$res['status'] = 'ok';
		if ( ! $apply ) {
			return $res;
		}

		$hier         = is_post_type_hierarchical( $post->post_type );
		$old_path     = $hier && 'publish' === $post->post_status ? trim( rawurldecode( (string) get_page_uri( $post ) ), '/' ) : '';
		$redirectable = 'publish' === $post->post_status || ( 'attachment' === $post->post_type && 'inherit' === $post->post_status );
		$add_meta     = ! $hier && $redirectable && ! in_array( $old_raw, get_post_meta( $id, '_wp_old_slug' ), true );

		$entry = array(
			'k'    => 'p:' . $id,
			'old'  => $old_raw,
			'new'  => $new,
			'meta' => $add_meta,
			'path' => $hier ? $old_path : '',
		);
		if ( ! self::save_entry( $run, $entry, $res ) ) {
			return $res;
		}

		$ok = $wpdb->update( $wpdb->posts, array( 'post_name' => $new ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- only the slug changes (see class doc).
		if ( false === $ok ) {
			self::drop_entry( $run, $entry['k'] );
			$res['status'] = 'error';
			$res['note']   = $wpdb->last_error;
			return $res;
		}
		clean_post_cache( $id );
		if ( $add_meta ) {
			add_post_meta( $id, '_wp_old_slug', $old_raw );
		} elseif ( '' !== $entry['path'] ) {
			$m                       = GLP_Redirects::map();
			$m['paths'][ $old_path ] = $id;
			GLP_Redirects::save_map( $m );
		}
		self::touched_post( $post );
		return $res;
	}

	/** Caches and the suite hook after a product changed. */
	private static function touched_post( $post ): void {
		if ( in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
			$pid = 'product_variation' === $post->post_type ? (int) $post->post_parent : (int) $post->ID;
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $pid );
			}
			do_action( 'noxpress_products_changed', array( $pid ) );
		}
	}

	private static function term( int $id, string $run, array $res ): array {
		$apply = '' !== $run;
		$term = get_term( $id );
		if ( ! $term || is_wp_error( $term ) || ! isset( self::taxonomies()[ $term->taxonomy ] ) ) {
			return self::skip( $res, __( 'Δεν βρέθηκε.', 'greeklish-permalinks' ) );
		}
		$old = rawurldecode( (string) $term->slug );
		if ( ! GLP_Converter::has_greek( $old ) ) {
			return self::skip( $res, __( 'Δεν έχει πια ελληνικά.', 'greeklish-permalinks' ) );
		}
		$conv = self::slug_of( $old, GLP_Settings::slug_opt() );
		if ( '' === $conv ) {
			return self::skip( $res, __( 'Το νέο slug θα ήταν κενό.', 'greeklish-permalinks' ) );
		}
		$new = $conv;
		$n   = 2;
		while ( true ) {
			$dup = get_term_by( 'slug', $new, $term->taxonomy );
			if ( ! $dup || (int) $dup->term_id === $id ) {
				break;
			}
			$new = $conv . '-' . $n++;
		}
		$res['new'] = $new;
		if ( $new !== $conv ) {
			$res['note'] = __( 'Υπάρχει ήδη: πήρε αριθμό.', 'greeklish-permalinks' );
		}
		$res['status'] = 'ok';
		if ( ! $apply ) {
			return $res;
		}

		$entry = array(
			'k'   => 't:' . $id,
			'tax' => $term->taxonomy,
			'old' => (string) $term->slug,
			'new' => $new,
			'map' => $old,
		);
		if ( ! self::save_entry( $run, $entry, $res ) ) {
			return $res;
		}

		$r = wp_update_term( $id, $term->taxonomy, array( 'slug' => $new ) );
		if ( is_wp_error( $r ) ) {
			self::drop_entry( $run, $entry['k'] );
			$res['status'] = 'error';
			$res['note']   = $r->get_error_message();
			return $res;
		}
		$m = GLP_Redirects::map();
		if ( ! isset( $m['terms'][ $old ] ) || ! is_array( $m['terms'][ $old ] ) ) {
			$m['terms'][ $old ] = array();
		}
		$m['terms'][ $old ][ $term->taxonomy ] = $id;
		GLP_Redirects::save_map( $m );
		return $res;
	}

	/* =====================================================================
	 * Files
	 * =================================================================== */

	/** Every file name of an attachment, from its metadata (basenames in one folder). */
	private static function file_names( string $main, $meta ): array {
		$names = array( $main );
		if ( is_array( $meta ) ) {
			if ( ! empty( $meta['original_image'] ) && is_string( $meta['original_image'] ) ) {
				$names[] = $meta['original_image'];
			}
			foreach ( array( 'sources' ) as $k ) {
				if ( ! empty( $meta[ $k ] ) && is_array( $meta[ $k ] ) ) {
					foreach ( $meta[ $k ] as $src ) {
						if ( is_array( $src ) && ! empty( $src['file'] ) && is_string( $src['file'] ) ) {
							$names[] = basename( $src['file'] );
						}
					}
				}
			}
			if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $size ) {
					if ( is_array( $size ) && ! empty( $size['file'] ) && is_string( $size['file'] ) ) {
						$names[] = basename( $size['file'] );
						if ( ! empty( $size['sources'] ) && is_array( $size['sources'] ) ) {
							foreach ( $size['sources'] as $src ) {
								if ( is_array( $src ) && ! empty( $src['file'] ) && is_string( $src['file'] ) ) {
									$names[] = basename( $src['file'] );
								}
							}
						}
					}
				}
			}
		}
		return array_values( array_unique( $names ) );
	}

	/** Stem shared by the sizes: the original image's (no "-scaled"), else the main file's. */
	private static function stem( string $main, $meta ): string {
		$orig = is_array( $meta ) && ! empty( $meta['original_image'] ) && is_string( $meta['original_image'] ) ? $meta['original_image'] : $main;
		return (string) pathinfo( $orig, PATHINFO_FILENAME );
	}

	/**
	 * Old basename → new basename for every file of the attachment (plus
	 * .webp / .avif copies that exist on disk), with a "-2", "-3"… suffix on
	 * the stem when a new name is taken by another file.
	 */
	private static function rename_plan( string $dir_abs, string $main, $meta ): array {
		$fopt  = GLP_Settings::file_opt();
		$names = self::file_names( $main, $meta );
		foreach ( $names as $nm ) {
			foreach ( array( $nm . '.webp', $nm . '.avif', pathinfo( $nm, PATHINFO_FILENAME ) . '.webp', pathinfo( $nm, PATHINFO_FILENAME ) . '.avif' ) as $extra ) {
				if ( ! in_array( $extra, $names, true ) && file_exists( $dir_abs . $extra ) ) {
					$names[] = $extra;
				}
			}
		}
		$stem     = self::stem( $main, $meta );
		$stem_new = GLP_Converter::file_name( $stem, $fopt );

		for ( $n = 1; $n < 100; $n++ ) {
			$suffix = 1 === $n ? '' : '-' . $n;
			$plan   = array();
			foreach ( $names as $nm ) {
				if ( '' !== $stem && 0 === strpos( $nm, $stem ) ) {
					$rest = substr( $nm, strlen( $stem ) );
					$rest = GLP_Converter::has_greek( $rest ) ? GLP_Converter::file_name( $rest, $fopt ) : $rest;
					$new  = $stem_new . $suffix . ( ! empty( $fopt['lower'] ) ? strtolower( $rest ) : $rest );
				} else {
					$conv = GLP_Converter::file_name( $nm, $fopt );
					$dot  = strrpos( $conv, '.' );
					$new  = false === $dot || '' === $suffix ? $conv : substr( $conv, 0, $dot ) . $suffix . substr( $conv, $dot );
				}
				$plan[ $nm ] = $new;
			}
			$taken = false;
			foreach ( $plan as $old => $new ) {
				if ( $old !== $new && ! isset( $plan[ $new ] ) && file_exists( $dir_abs . $new ) ) {
					$taken = true;
					break;
				}
			}
			// Two different old names must not land on one new name either.
			if ( ! $taken && count( array_unique( $plan ) ) === count( $plan ) ) {
				return array_filter(
					$plan,
					static function ( $new, $old ) {
						return $new !== $old;
					},
					ARRAY_FILTER_USE_BOTH
				);
			}
		}
		throw new RuntimeException( __( 'Δεν βρέθηκε ελεύθερο όνομα αρχείου.', 'greeklish-permalinks' ) );
	}

	/** The needles a URL of an upload path can appear as: raw and encoded (upper and lower hex). */
	private static function needles( string $rel ): array {
		$up   = wp_get_upload_dir();
		$base = basename( untrailingslashit( (string) wp_parse_url( $up['baseurl'], PHP_URL_PATH ) ) );
		$raw  = $base . '/' . $rel;
		$enc  = $base . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $rel ) ) );
		return array_values(
			array_unique(
				array(
					$raw,
					$enc,
					preg_replace_callback(
						'/%[0-9A-F]{2}/',
						static function ( $m ) {
							return strtolower( $m[0] );
						},
						$enc
					),
				)
			)
		);
	}

	/** Old needle → new needle pairs for a rename plan. */
	private static function replacements( string $dir_rel, array $plan ): array {
		$pairs = array();
		foreach ( $plan as $old => $new ) {
			$on = self::needles( $dir_rel . $old );
			$nn = self::needles( $dir_rel . $new );
			foreach ( $on as $i => $o ) {
				$pairs[ $o ] = isset( $nn[ $i ] ) ? $nn[ $i ] : $nn[0];
			}
		}
		// Longer first, so "x.jpg" never eats the start of "x.jpg.webp".
		uksort(
			$pairs,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		return $pairs;
	}

	/** IDs of posts whose content or excerpt has one of the needles. */
	private static function posts_with( array $needles ): array {
		global $wpdb;
		$or   = array();
		$args = array();
		foreach ( $needles as $n ) {
			$or[]   = 'post_content LIKE %s OR post_excerpt LIKE %s';
			$like   = '%' . $wpdb->esc_like( $n ) . '%';
			$args[] = $like;
			$args[] = $like;
		}
		$sql = "SELECT ID FROM {$wpdb->posts} WHERE post_type <> 'revision' AND (" . implode( ' OR ', $or ) . ')';
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery -- placeholders built above.
	}

	/** Meta rows (post_id, meta_key) with one of the needles; $only = a meta key, or '' for all others. */
	private static function meta_with( array $needles, string $only ): array {
		global $wpdb;
		$or   = array();
		$args = array();
		foreach ( $needles as $n ) {
			$or[]   = 'meta_value LIKE %s';
			$args[] = '%' . $wpdb->esc_like( $n ) . '%';
		}
		if ( '' !== $only ) {
			$where  = 'meta_key = %s';
			$args[] = $only;
		} else {
			$where = "meta_key NOT IN ('_downloadable_files', '_wp_attached_file', '_wp_attachment_metadata', '_wp_old_slug')";
		}
		$sql = "SELECT post_id, meta_key FROM {$wpdb->postmeta} WHERE (" . implode( ' OR ', $or ) . ") AND $where";
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery -- placeholders built above.
	}

	private static function options_with( array $needles ): int {
		global $wpdb;
		$or   = array();
		$args = array();
		foreach ( $needles as $n ) {
			$or[]   = 'option_value LIKE %s';
			$args[] = '%' . $wpdb->esc_like( $n ) . '%';
		}
		$sql = "SELECT COUNT(*) FROM {$wpdb->options} WHERE (" . implode( ' OR ', $or ) . ") AND option_name NOT LIKE '\\_transient%' AND option_name NOT LIKE '\\_site\\_transient%' AND option_name NOT IN ('glp_map', 'glp_log')";
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery -- placeholders built above.
	}

	private static function file( int $id, string $run, array $res ): array {
		$apply = '' !== $run;
		global $wpdb;
		$post = get_post( $id );
		$rel  = $post ? (string) get_post_meta( $id, '_wp_attached_file', true ) : '';
		if ( ! $post || 'attachment' !== $post->post_type || '' === $rel ) {
			return self::skip( $res, __( 'Δεν βρέθηκε.', 'greeklish-permalinks' ) );
		}
		if ( ! GLP_Converter::has_greek( $rel ) ) {
			return self::skip( $res, __( 'Δεν έχει πια ελληνικά.', 'greeklish-permalinks' ) );
		}
		if ( preg_match( '#^(https?:)?//#i', $rel ) || 0 === strpos( $rel, '/' ) || false !== strpos( $rel, '..' ) ) {
			return self::skip( $res, __( 'Το αρχείο δεν είναι στον φάκελο uploads.', 'greeklish-permalinks' ) );
		}
		$up      = wp_get_upload_dir();
		$dir_rel = self::dir_of( $rel );
		$dir_abs = trailingslashit( $up['basedir'] ) . $dir_rel;
		$main    = basename( $rel );
		if ( ! file_exists( $dir_abs . $main ) ) {
			return self::skip( $res, __( 'Το αρχείο λείπει από τον δίσκο.', 'greeklish-permalinks' ) );
		}
		$meta = wp_get_attachment_metadata( $id, true );
		$plan = self::rename_plan( $dir_abs, $main, $meta );
		if ( ! isset( $plan[ $main ] ) ) {
			return self::skip( $res, __( 'Το νέο όνομα είναι ίδιο.', 'greeklish-permalinks' ) );
		}
		$res['new']    = $dir_rel . $plan[ $main ];
		$res['status'] = 'ok';
		$needles       = array();
		foreach ( array_keys( $plan ) as $old ) {
			$needles = array_merge( $needles, self::needles( $dir_rel . $old ) );
		}
		$needles = array_values( array_unique( $needles ) );

		if ( ! $apply ) {
			$posts = self::posts_with( $needles );
			$dl    = self::meta_with( $needles, '_downloadable_files' );
			$other = self::meta_with( $needles, '' );
			// The attachment page itself has its own URL only in guid: not a reference.
			$res['refs'] = array(
				'files'   => count( $plan ),
				'posts'   => count( $posts ),
				'dl'      => count( $dl ),
				'other'   => count( $other ) + self::options_with( $needles ),
				'others'  => array_slice( array_values( array_unique( wp_list_pluck( $other, 'meta_key' ) ) ), 0, 5 ),
			);
			if ( $res['new'] !== $dir_rel . GLP_Converter::file_name( $main, GLP_Settings::file_opt() ) ) {
				$res['note'] = __( 'Υπάρχει ήδη: πήρε αριθμό.', 'greeklish-permalinks' );
			}
			return $res;
		}

		// 0. Everything this item will change, with the values from before,
		// goes to the log first.
		$moving = array();
		foreach ( $plan as $old => $new ) {
			if ( file_exists( $dir_abs . $old ) ) {
				$moving[ $old ] = $new; // A size that is gone: only the metadata changes.
			}
		}
		$pairs   = self::replacements( $dir_rel, $plan );
		$content = self::plan_posts( self::posts_with( array_keys( $pairs ) ), $pairs );
		$dl      = self::plan_downloads( self::meta_with( array_keys( $pairs ), '_downloadable_files' ), $pairs );
		$entry   = array(
			'k'     => 'f:' . $id,
			'rel'   => $rel,
			'dir'   => $dir_rel,
			'plan'  => $plan,
			'moved' => $moving,
			'meta'  => $meta,
			'posts' => array_map(
				static function ( $c ) {
					return array( $c[0], $c[1] );
				},
				$content
			),
			'dl'    => array_map(
				static function ( $d ) {
					return $d[0];
				},
				$dl
			),
		);
		if ( ! self::save_entry( $run, $entry, $res ) ) {
			return $res;
		}

		// 1. Rename on disk; roll back on the first failure.
		$done = array();
		foreach ( $moving as $old => $new ) {
			if ( file_exists( $dir_abs . $new ) || ! @rename( $dir_abs . $old, $dir_abs . $new ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.rename_rename -- failure handled below.
				foreach ( array_reverse( $done, true ) as $o => $n ) {
					@rename( $dir_abs . $n, $dir_abs . $o ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.rename_rename -- best-effort rollback.
				}
				self::drop_entry( $run, $entry['k'] );
				$res['status'] = 'error';
				/* translators: %s: file name */
				$res['note'] = sprintf( __( 'Η μετονομασία του %s απέτυχε (δικαιώματα φακέλου;). Δεν άλλαξε τίποτα.', 'greeklish-permalinks' ), $old );
				return $res;
			}
			$done[ $old ] = $new;
		}

		// 2. Attachment data.
		update_attached_file( $id, $dir_abs . $plan[ $main ] );
		if ( is_array( $meta ) ) {
			wp_update_attachment_metadata( $id, self::meta_renamed( $meta, $plan ) );
		}

		// 3. References: content, excerpts, downloadable files.
		self::write_posts( $content );
		self::write_downloads( $dl );

		// 4. Redirects from every old path.
		$m = GLP_Redirects::map();
		foreach ( $plan as $old => $new ) {
			$m['files'][ $dir_rel . $old ] = $dir_rel . $new;
		}
		GLP_Redirects::save_map( $m );
		clean_post_cache( $id );
		return $res;
	}

	/** Metadata with every file name of the plan replaced. */
	private static function meta_renamed( array $meta, array $plan ): array {
		$swap = static function ( $file ) use ( $plan ) {
			if ( ! is_string( $file ) ) {
				return $file;
			}
			$base = basename( $file );
			if ( ! isset( $plan[ $base ] ) ) {
				return $file;
			}
			$dir = dirname( $file );
			return ( '.' === $dir || '' === $dir ? '' : $dir . '/' ) . $plan[ $base ];
		};
		if ( isset( $meta['file'] ) ) {
			$meta['file'] = $swap( $meta['file'] );
		}
		if ( isset( $meta['original_image'] ) ) {
			$meta['original_image'] = $swap( $meta['original_image'] );
		}
		if ( ! empty( $meta['sources'] ) && is_array( $meta['sources'] ) ) {
			foreach ( $meta['sources'] as $k => $src ) {
				if ( is_array( $src ) && isset( $src['file'] ) ) {
					$meta['sources'][ $k ]['file'] = $swap( $src['file'] );
				}
			}
		}
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $s => $size ) {
				if ( ! is_array( $size ) ) {
					continue;
				}
				if ( isset( $size['file'] ) ) {
					$meta['sizes'][ $s ]['file'] = $swap( $size['file'] );
				}
				if ( ! empty( $size['sources'] ) && is_array( $size['sources'] ) ) {
					foreach ( $size['sources'] as $k => $src ) {
						if ( is_array( $src ) && isset( $src['file'] ) ) {
							$meta['sizes'][ $s ]['sources'][ $k ]['file'] = $swap( $src['file'] );
						}
					}
				}
			}
		}
		return $meta;
	}

	/**
	 * Posts whose content or excerpt holds a URL of the plan:
	 * ID => array( old content, old excerpt, new content, new excerpt ).
	 */
	private static function plan_posts( array $ids, array $pairs ): array {
		global $wpdb;
		$out = array();
		foreach ( $ids as $pid ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT post_content, post_excerpt FROM {$wpdb->posts} WHERE ID = %d", $pid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- raw values, no filters.
			if ( ! $row ) {
				continue;
			}
			$c = strtr( (string) $row->post_content, $pairs );
			$e = strtr( (string) $row->post_excerpt, $pairs );
			if ( $c !== $row->post_content || $e !== $row->post_excerpt ) {
				$out[ (int) $pid ] = array( (string) $row->post_content, (string) $row->post_excerpt, $c, $e );
			}
		}
		return $out;
	}

	/** Write plan_posts(): only where the text is still the one in the log (Bible §12). */
	private static function write_posts( array $plan ): void {
		global $wpdb;
		foreach ( $plan as $pid => $c ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_content = %s, post_excerpt = %s WHERE ID = %d AND post_content = %s AND post_excerpt = %s", $c[2], $c[3], $pid, $c[0], $c[1] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- exact URL swap, no modified date.
			clean_post_cache( $pid );
		}
	}

	/** Products whose downloadable files hold a URL of the plan: ID => array( old value, new value ). */
	private static function plan_downloads( array $rows, array $pairs ): array {
		$out = array();
		foreach ( $rows as $r ) {
			$pid   = (int) $r->post_id;
			$files = get_post_meta( $pid, '_downloadable_files', true );
			if ( is_array( $files ) ) {
				$new = self::swap_downloads( $files, $pairs );
				if ( $new !== $files ) {
					$out[ $pid ] = array( $files, $new );
				}
			}
		}
		return $out;
	}

	/** _downloadable_files with the plan's URLs replaced (file URLs only). */
	private static function swap_downloads( array $files, array $pairs ): array {
		foreach ( $files as $k => $f ) {
			if ( is_array( $f ) && isset( $f['file'] ) && is_string( $f['file'] ) ) {
				$files[ $k ]['file'] = strtr( $f['file'], $pairs );
			}
		}
		return $files;
	}

	/** Write plan_downloads(): only where the value is still the one in the log. */
	private static function write_downloads( array $plan ): void {
		foreach ( $plan as $pid => $d ) {
			if ( get_post_meta( $pid, '_downloadable_files', true ) !== $d[0] ) {
				continue;
			}
			update_post_meta( $pid, '_downloadable_files', $d[1] );
			$post = get_post( $pid );
			if ( $post ) {
				clean_post_cache( $pid );
				self::touched_post( $post );
			}
		}
	}

	/* =====================================================================
	 * Log and undo
	 * =================================================================== */

	public static function log(): array {
		$l = get_option( self::LOG, array() );
		return is_array( $l ) && isset( $l['items'] ) && is_array( $l['items'] ) ? $l : array();
	}

	/** Start a new run: the previous log is replaced. Returns the run ID. */
	public static function start_run(): string {
		$run = wp_generate_password( 12, false );
		update_option(
			self::LOG,
			array(
				'run'   => $run,
				'time'  => time(),
				'user'  => get_current_user_id(),
				'items' => array(),
			),
			false
		);
		return $run;
	}

	/** Add one entry to the run's log; true only when it was written. */
	public static function append( string $run, array $entry ): bool {
		$l = self::log();
		if ( ! $l || $l['run'] !== $run ) {
			return false;
		}
		$l['items'][] = $entry;
		return update_option( self::LOG, $l, false );
	}

	/** Remove the entry of an item that did not change after all. */
	private static function drop_entry( string $run, string $k ): void {
		$l = self::log();
		if ( ! $l || $l['run'] !== $run || ! $l['items'] ) {
			return;
		}
		$last = end( $l['items'] );
		if ( is_array( $last ) && isset( $last['k'] ) && $last['k'] === $k ) {
			array_pop( $l['items'] );
			update_option( self::LOG, $l, false );
		}
	}

	/**
	 * A log from a downloaded backup, checked strictly; null when anything
	 * in it is not what a run writes. Paths stay inside uploads and file
	 * names are plain names, so an import can only touch what undo() would.
	 */
	public static function sanitize_log( $l ): ?array {
		if ( ! is_array( $l ) || ! isset( $l['run'], $l['time'], $l['items'] ) || ! is_array( $l['items'] )
			|| ! is_string( $l['run'] ) || ! preg_match( '/^[A-Za-z0-9]{1,32}$/', $l['run'] ) || ! $l['items'] ) {
			return null;
		}
		$items = array();
		foreach ( array_values( $l['items'] ) as $e ) {
			$e = self::sanitize_entry( $e );
			if ( null === $e ) {
				return null;
			}
			$items[] = $e;
		}
		return array(
			'run'   => $l['run'],
			'time'  => (int) $l['time'],
			'user'  => isset( $l['user'] ) ? (int) $l['user'] : 0,
			'items' => $items,
		);
	}

	private static function sanitize_entry( $e ): ?array {
		if ( ! is_array( $e ) || ! isset( $e['k'] ) || ! is_string( $e['k'] ) || ! preg_match( '/^([ptf]):(\d{1,20})$/', $e['k'], $m ) ) {
			return null;
		}
		$str = static function ( $v ): bool {
			return is_string( $v ) && strlen( $v ) <= 2000;
		};
		if ( 'p' === $m[1] ) {
			if ( ! isset( $e['old'], $e['new'], $e['path'] ) || ! $str( $e['old'] ) || ! $str( $e['new'] ) || ! $str( $e['path'] ) ) {
				return null;
			}
			return array(
				'k'    => $e['k'],
				'old'  => $e['old'],
				'new'  => $e['new'],
				'meta' => ! empty( $e['meta'] ),
				'path' => $e['path'],
			);
		}
		if ( 't' === $m[1] ) {
			if ( ! isset( $e['tax'], $e['old'], $e['new'], $e['map'] ) || ! $str( $e['old'] ) || ! $str( $e['new'] ) || ! $str( $e['map'] )
				|| ! is_string( $e['tax'] ) || ! taxonomy_exists( $e['tax'] ) ) {
				return null;
			}
			return array(
				'k'   => $e['k'],
				'tax' => $e['tax'],
				'old' => $e['old'],
				'new' => $e['new'],
				'map' => $e['map'],
			);
		}
		// File: a folder under uploads and plain file names in it.
		if ( ! isset( $e['rel'], $e['dir'], $e['plan'], $e['moved'], $e['posts'], $e['dl'] ) || ! is_string( $e['dir'] ) || ! is_string( $e['rel'] )
			|| ! is_array( $e['plan'] ) || ! is_array( $e['moved'] ) || ! is_array( $e['posts'] ) || ! is_array( $e['dl'] ) || ! $e['plan'] ) {
			return null;
		}
		$dir = $e['dir'];
		if ( '' !== $dir && ( false !== strpos( $dir, "\0" ) || ! preg_match( '#^[^/\\\\]+(/[^/\\\\]+)*/$#', $dir ) || preg_match( '#(^|/)\.\.?/#', $dir ) ) ) {
			return null;
		}
		$name = static function ( $v ): bool {
			return is_string( $v ) && '' !== $v && strlen( $v ) <= 255 && ! preg_match( '#[/\\\\]#', $v ) && false === strpos( $v, "\0" ) && '.' !== $v && '..' !== $v;
		};
		foreach ( $e['plan'] as $old => $new ) {
			if ( ! $name( (string) $old ) || ! $name( $new ) ) {
				return null;
			}
		}
		foreach ( $e['moved'] as $old => $new ) {
			if ( ! isset( $e['plan'][ $old ] ) || $e['plan'][ $old ] !== $new ) {
				return null;
			}
		}
		if ( 0 !== strpos( $e['rel'], $dir ) || ! isset( $e['plan'][ substr( $e['rel'], strlen( $dir ) ) ] ) ) {
			return null;
		}
		$posts = array();
		foreach ( $e['posts'] as $pid => $c ) {
			if ( ! is_array( $c ) || ! isset( $c[0], $c[1] ) || ! is_string( $c[0] ) || ! is_string( $c[1] ) ) {
				return null;
			}
			$posts[ (int) $pid ] = array( $c[0], $c[1] );
		}
		$dl = array();
		foreach ( $e['dl'] as $pid => $files ) {
			if ( ! is_array( $files ) ) {
				return null;
			}
			$dl[ (int) $pid ] = $files;
		}
		return array(
			'k'     => $e['k'],
			'rel'   => $e['rel'],
			'dir'   => $dir,
			'plan'  => $e['plan'],
			'moved' => $e['moved'],
			'meta'  => isset( $e['meta'] ) && is_array( $e['meta'] ) ? $e['meta'] : false,
			'posts' => $posts,
			'dl'    => $dl,
		);
	}

	/** Put an imported run back as the undo log (replaces the current one). */
	public static function import_log( array $l ): bool {
		delete_option( self::LOG );
		return add_option( self::LOG, $l, '', false );
	}

	/** Undo up to $n items, newest first. Returns array( done, errors[], left ). */
	public static function undo( int $n ): array {
		$l      = self::log();
		$done   = 0;
		$errors = array();
		if ( ! $l ) {
			return array( 0, array(), 0 );
		}
		while ( $done < $n && $l['items'] ) {
			$e = array_pop( $l['items'] );
			try {
				$err = self::undo_one( is_array( $e ) ? $e : array() );
			} catch ( \Throwable $ex ) {
				$err = $ex->getMessage();
			}
			if ( '' !== $err ) {
				$errors[] = $err;
			}
			$done++;
		}
		if ( $l['items'] ) {
			update_option( self::LOG, $l, false );
		} else {
			delete_option( self::LOG );
		}
		return array( $done, $errors, count( $l['items'] ) );
	}

	/** Revert one log entry; '' or an error text. */
	private static function undo_one( array $e ): string {
		global $wpdb;
		if ( empty( $e['k'] ) || ! preg_match( '/^([ptf]):(\d+)$/', (string) $e['k'], $mm ) ) {
			return '';
		}
		$id   = (int) $mm[2];
		$warn = '';

		if ( 'p' === $mm[1] ) {
			$post = get_post( $id );
			if ( ! $post || $post->post_name !== $e['new'] ) {
				/* translators: %d: post ID */
				return sprintf( __( 'Το #%d άλλαξε μετά τη μετατροπή: δεν αναιρέθηκε.', 'greeklish-permalinks' ), $id );
			}
			$wpdb->update( $wpdb->posts, array( 'post_name' => (string) $e['old'] ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- reverts our own change.
			if ( ! empty( $e['meta'] ) ) {
				delete_post_meta( $id, '_wp_old_slug', (string) $e['old'] );
			}
			if ( ! empty( $e['path'] ) ) {
				$m = GLP_Redirects::map();
				unset( $m['paths'][ $e['path'] ] );
				GLP_Redirects::save_map( $m );
			}
			clean_post_cache( $id );
			self::touched_post( $post );
			return '';
		}

		if ( 't' === $mm[1] ) {
			$term = get_term( $id, (string) $e['tax'] );
			if ( ! $term || is_wp_error( $term ) || $term->slug !== $e['new'] ) {
				/* translators: %d: term ID */
				return sprintf( __( 'Ο όρος #%d άλλαξε μετά τη μετατροπή: δεν αναιρέθηκε.', 'greeklish-permalinks' ), $id );
			}
			// The Greek slug must not be converted again on its way back.
			if ( class_exists( 'GLP_Hooks' ) ) {
				GLP_Hooks::$paused = true;
			}
			$r = wp_update_term( $id, (string) $e['tax'], array( 'slug' => rawurldecode( (string) $e['old'] ) ) );
			if ( class_exists( 'GLP_Hooks' ) ) {
				GLP_Hooks::$paused = false;
			}
			if ( is_wp_error( $r ) ) {
				return $r->get_error_message();
			}
			$m = GLP_Redirects::map();
			unset( $m['terms'][ $e['map'] ][ $e['tax'] ] );
			if ( isset( $m['terms'][ $e['map'] ] ) && ! $m['terms'][ $e['map'] ] ) {
				unset( $m['terms'][ $e['map'] ] );
			}
			GLP_Redirects::save_map( $m );
			return '';
		}

		// File.
		$up      = wp_get_upload_dir();
		$dir_rel = (string) $e['dir'];
		$dir_abs = trailingslashit( $up['basedir'] ) . $dir_rel;
		$plan    = is_array( $e['plan'] ) ? $e['plan'] : array();
		$moved   = is_array( $e['moved'] ) ? $e['moved'] : array();
		$cur     = (string) get_post_meta( $id, '_wp_attached_file', true );
		if ( $cur !== $dir_rel . ( isset( $plan[ basename( (string) $e['rel'] ) ] ) ? $plan[ basename( (string) $e['rel'] ) ] : '' ) ) {
			/* translators: %d: attachment ID */
			return sprintf( __( 'Το αρχείο #%d άλλαξε μετά τη μετατροπή: δεν αναιρέθηκε.', 'greeklish-permalinks' ), $id );
		}
		foreach ( $moved as $old => $new ) {
			if ( file_exists( $dir_abs . $new ) && ! file_exists( $dir_abs . $old ) ) {
				@rename( $dir_abs . $new, $dir_abs . $old ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.rename_rename -- checked below.
			}
		}
		update_attached_file( $id, trailingslashit( $up['basedir'] ) . (string) $e['rel'] );
		if ( is_array( $e['meta'] ) ) {
			wp_update_attachment_metadata( $id, $e['meta'] );
		}
		// Content and downloads: the values from before the run, where nothing
		// else changed them since.
		foreach ( (array) $e['posts'] as $pid => $orig ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT post_content, post_excerpt FROM {$wpdb->posts} WHERE ID = %d", (int) $pid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- raw values.
			if ( $row && is_array( $orig ) ) {
				$pairs = self::replacements( $dir_rel, $plan );
				if ( strtr( (string) $orig[0], $pairs ) === $row->post_content && strtr( (string) $orig[1], $pairs ) === $row->post_excerpt ) {
					$wpdb->update( $wpdb->posts, array( 'post_content' => (string) $orig[0], 'post_excerpt' => (string) $orig[1] ), array( 'ID' => (int) $pid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- reverts our own change.
					clean_post_cache( (int) $pid );
				} else {
					/* translators: %d: post ID */
					$warn = sprintf( __( 'Το κείμενο του #%d άλλαξε μετά τη μετατροπή: οι σύνδεσμοι δεν γύρισαν πίσω.', 'greeklish-permalinks' ), (int) $pid );
				}
			}
		}
		foreach ( (array) $e['dl'] as $pid => $orig ) {
			if ( ! is_array( $orig ) ) {
				continue;
			}
			if ( get_post_meta( (int) $pid, '_downloadable_files', true ) !== self::swap_downloads( $orig, self::replacements( $dir_rel, $plan ) ) ) {
				/* translators: %d: product ID */
				$warn = sprintf( __( 'Τα αρχεία για download του #%d άλλαξαν μετά τη μετατροπή: δεν γύρισαν πίσω.', 'greeklish-permalinks' ), (int) $pid );
				continue;
			}
			update_post_meta( (int) $pid, '_downloadable_files', $orig );
			$post = get_post( (int) $pid );
			if ( $post ) {
				clean_post_cache( (int) $pid );
				self::touched_post( $post );
			}
		}

		$m = GLP_Redirects::map();
		foreach ( array_keys( $plan ) as $old ) {
			unset( $m['files'][ $dir_rel . $old ] );
		}
		GLP_Redirects::save_map( $m );
		clean_post_cache( $id );

		foreach ( $moved as $old => $new ) {
			if ( ! file_exists( $dir_abs . $old ) ) {
				/* translators: %s: file name */
				return sprintf( __( 'Το %s δεν γύρισε στο παλιό όνομα.', 'greeklish-permalinks' ), $old );
			}
		}
		return $warn;
	}
}
