<?php
/**
 * SF_Targets — Επίλυση στόχων + πεδία + run plan (v1.1.0).
 *
 * Responsibilities (dispatch map):
 *  - Selection modes: all / specific products / categories / tags
 *    → resolution σε sorted unique array product IDs (μία φορά
 *    ανά request, static cache).
 *  - Fields registry: ποια πεδία του προϊόντος μορφοποιούνται.
 *  - Plan builder: units (product/term × field) → transform →
 *    entries {before, after, changed} — dry run και apply από
 *    το ίδιο code path (μία υλοποίηση ερμηνείας, όπως το
 *    current_period/current_products του RS).
 *
 * Semantics (ρητά, προς αποφυγή παρερμηνειών):
 *  - 'all' = ΟΛΑ τα δημοσιευμένα προϊόντα (status publish — ενιαία
 *    πολιτική με το product picker του RS). Variations ΔΕΝ αγγίζονται
 *    (parent products μόνο) — οι περιγραφές variation είναι ξεχωριστό
 *    surface, εκτός scope.
 *  - Categories/tags modes: untrusted IDs (absint, dedupe, sort) →
 *    slugs → wc_get_products(). Ένα προϊόν σε 2 επιλεγμένες κατηγορίες
 *    εμφανίζεται ΜΙΑ φορά.
 *  - 'sf_term_description': πεδίο που ισχύει ΜΟΝΟ σε category/tag
 *    modes — μορφοποιεί τις DESCRIPTIONS των ίδιων των όρων που
 *    διάλεξες (denormalized custom taxonomy content), όχι πεδία
 *    προϊόντων.
 *  - Attributes: ΜΟΝΟ custom (non-taxonomy) attributes — τα values
 *    των global attributes είναι SHARED taxonomy terms σε όλο το
 *    κατάστημα· η αλλαγή τους θα επηρέαζε προϊόντα που δεν
 *    επέλεξες. Global → ρητά skipped με λόγο (entry, όχι σιωπηλό).
 *
 * Data writes: ΠΟΤΕ direct SQL/wpdb — μόνο WooCommerce CRUD setters
 * + save() ώστε να τρέχουν όλα τα Woo hooks (caches/revisions).
 * Term descriptions: wp_update_term() (canonical API).
 */

defined( 'ABSPATH' ) || exit;

final class SF_Targets {

	/* Selection modes (persisted στα GET/profiles) */
	const MODE_ALL       = 'all';
	const MODE_PRODUCTS  = 'products';
	const MODE_CATEGORIES = 'categories';
	const MODE_TAGS      = 'tags';

	/** Whitelist modes. */
	const MODES = array( self::MODE_ALL, self::MODE_PRODUCTS, self::MODE_CATEGORIES, self::MODE_TAGS );

	/* Field IDs (persisted στα checkboxes των profiles) */
	const F_SHORT_DESC   = 'sf_short_description';
	const F_LONG_DESC    = 'sf_long_description';
	const F_PURCHASE     = 'sf_purchase_note';
	const F_ATTRIBUTES   = 'sf_custom_attributes';
	const F_TERM_DESC    = 'sf_term_description';

	/** Batch size default (το Admin UI το καλεί ανά slice). */
	const BATCH = 20;

	/**
	 * Registry πεδίων — labels ως Greek msgids (μετάφραση στο render).
	 *
	 * kind 'product': πεδίο του WC_Product.
	 * kind 'term':     description όρου product_cat/product_tag
	 *                 (διαθέσιμο μόνο σε category/tag modes).
	 */
	public static function fields(): array {
		return array(
			self::F_SHORT_DESC => array(
				'id'    => self::F_SHORT_DESC,
				'label' => 'Σύντομη περιγραφή',
				'kind'  => 'product',
			),
			self::F_LONG_DESC  => array(
				'id'    => self::F_LONG_DESC,
				'label' => 'Αναλυτική περιγραφή',
				'kind'  => 'product',
			),
			self::F_PURCHASE   => array(
				'id'    => self::F_PURCHASE,
				'label' => 'Σημείωση αγοράς (purchase note)',
				'kind'  => 'product',
			),
			self::F_ATTRIBUTES => array(
				'id'    => self::F_ATTRIBUTES,
				'label' => 'Ιδιότητες (custom μόνο)',
				'kind'  => 'product',
			),
			self::F_TERM_DESC  => array(
				'id'    => self::F_TERM_DESC,
				'label' => 'Περιγραφές κατηγοριών/ετικετών',
				'kind'  => 'term',
			),
		);
	}

	/** Whitelist validation ενός συνόλου πεδίων από UI (checkboxes). */
	public static function valid_fields( array $raw ): array {
		$ok = array();
		foreach ( $raw as $f ) {
			$f = (string) $f;
			if ( isset( self::fields()[ $f ] ) ) {
				$ok[ $f ] = true;
			}
		}
		return array_keys( $ok );
	}

	/* =====================================================================
	 * Target resolution
	 * =================================================================== */

	/**
	 * Selection → sorted unique product IDs.
	 *
	 * $sel = array(
	 *   'mode'      => 'all'|'products'|'categories'|'tags',
	 *   'products'  => array ids (μόνο σε mode products),
	 *   'terms'     => array term ids (μόνο σε categories/tags),
	 * )
	 *
	 * Static cache ανά request (ίδιο selection = ίδια λίστα, canonical).
	 */
	public static function resolve_ids( array $sel ): array {

		static $cache = array();

		$mode = ( isset( $sel['mode'] ) && in_array( (string) $sel['mode'], self::MODES, true ) )
			? (string) $sel['mode']
			: self::MODE_ALL;

		$ids_raw = isset( $sel['products'] ) && is_array( $sel['products'] ) ? $sel['products'] : array();
		$tm_raw  = isset( $sel['terms'] ) && is_array( $sel['terms'] ) ? $sel['terms'] : array();

		// Canonical key: mode + sorted ids — ανεξαρτήτως σειράς κλικ.
		$ids_can = array();
		foreach ( $ids_raw as $v ) {
			$v = absint( $v );
			if ( $v > 0 ) {
				$ids_can[] = $v;
			}
		}
		sort( $ids_can );
		$tm_can = array();
		foreach ( $tm_raw as $v ) {
			$v = absint( $v );
			if ( $v > 0 ) {
				$tm_can[] = $v;
			}
		}
		sort( $tm_can );

		$key = $mode . ':' . implode( ',', $ids_can ) . ':' . implode( ',', $tm_can );
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}

		switch ( $mode ) {
			case self::MODE_PRODUCTS:
				// ΔΕΝ wc_get_products εδώ — τα IDs έχουν ήδη meaning
				// (admin picked). Gate: υπάρχει το post και είναι product.
				$ids = array();
				foreach ( $ids_can as $pid ) {
					$post = get_post( $pid );
					if ( $post instanceof WP_Post && 'product' === $post->post_type ) {
						$ids[] = $pid;
					}
				}
				break;

			case self::MODE_CATEGORIES:
			case self::MODE_TAGS:
				$ids = self::ids_by_terms( $mode, $tm_can );
				break;

			case self::MODE_ALL:
			default:
				$ids = self::ids_all();
				break;
		}

		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		sort( $ids );

		$cache[ $key ] = $ids;
		return $ids;
	}

	/** Όλα τα δημοσιευμένα προϊόντα (parent μόνο, όχι variations). */
	private static function ids_all(): array {
		$products = wc_get_products(
			array(
				'status'  => 'publish',
				'limit'   => -1,
				'type'    => array_values( array_diff( array_keys( wc_get_product_types() ), array( 'variation' ) ) ),
				'return'  => 'ids',
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);
		return is_array( $products ) ? $products : array();
	}

	/** Προϊόντα ανά category/tag term IDs (wc_get_PRODUCTS canonical). */
	private static function ids_by_terms( string $mode, array $term_ids ): array {

		if ( empty( $term_ids ) ) {
			return array(); // Κενή επιλογή όρων = τίποτα (ΟΧΙ «όλα» — fail-safe).
		}

		$args = array(
			'status'  => 'publish',
			'limit'   => -1,
			'return'  => 'ids',
			'orderby' => 'ID',
			'order'   => 'ASC',
		);

		// wc_get_products(): τα 'category'/'tag' δέχονται SLUGS, όχι term IDs
		// → μετατροπή ID → slug (άγνωστα IDs αγνοούνται).
		$taxonomy = ( self::MODE_CATEGORIES === $mode ) ? 'product_cat' : 'product_tag';
		$slugs    = array();
		foreach ( $term_ids as $tid ) {
			$term = get_term( (int) $tid, $taxonomy );
			if ( $term instanceof WP_Term ) {
				$slugs[] = (string) $term->slug;
			}
		}
		if ( empty( $slugs ) ) {
			return array();
		}

		if ( self::MODE_CATEGORIES === $mode ) {
			$args['category'] = $slugs;
		} else {
			$args['tag'] = $slugs;
		}

		$products = wc_get_products( $args );
		return is_array( $products ) ? $products : array();
	}

	/**
	 * Οι ίδιοι οι όροι (για το field sf_term_description) — μόνο σε
	 * category/tag modes. Returns array {id, taxonomy, title}.
	 */
	public static function resolve_terms( array $sel ): array {

		$mode = ( isset( $sel['mode'] ) && in_array( (string) $sel['mode'], self::MODES, true ) )
			? (string) $sel['mode']
			: self::MODE_ALL;

		if ( self::MODE_CATEGORIES !== $mode && self::MODE_TAGS !== $mode ) {
			return array();
		}

		$tm_raw  = isset( $sel['terms'] ) && is_array( $sel['terms'] ) ? $sel['terms'] : array();
		$tm_can = array();
		foreach ( $tm_raw as $v ) {
			$v = absint( $v );
			if ( $v > 0 ) {
				$tm_can[] = $v;
			}
		}
		sort( $tm_can );

		if ( empty( $tm_can ) ) {
			return array();
		}

		$taxonomy = ( self::MODE_CATEGORIES === $mode ) ? 'product_cat' : 'product_tag';

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'include'    => $tm_can,
				'hide_empty' => false,
			)
		);

		$out = array();
		if ( is_array( $terms ) ) {
			foreach ( $terms as $t ) {
				if ( $t instanceof WP_Term ) {
					$out[] = array(
						'id'       => (int) $t->term_id,
						'taxonomy' => $taxonomy,
						'title'    => (string) $t->name,
					);
				}
			}
		}

		return $out;
	}

	/* =====================================================================
	 * Unit list + plan (dry run / apply από ένα code path)
	 * =================================================================== */

	/**
	 * Units = [product × selected product fields] + [term × term field].
	 * Ο πλήρης κατάλογος πριν το slicing (για totals/progress).
	 */
	private static function units( array $sel, array $fields ): array {

		$units = array();

		$field_defs = self::fields();
		$prod_fields = array();
		$want_terms  = false;

		foreach ( $fields as $f ) {
			if ( ! isset( $field_defs[ $f ] ) ) {
				continue;
			}
			if ( 'term' === $field_defs[ $f ]['kind'] ) {
				$want_terms = true;
			} else {
				$prod_fields[] = $f;
			}
		}

		if ( ! empty( $prod_fields ) ) {
			$ids = self::resolve_ids( $sel );
			foreach ( $ids as $pid ) {
				foreach ( $prod_fields as $f ) {
					$units[] = array( 'kind' => 'product', 'id' => $pid, 'field' => $f );
				}
			}
		}

		if ( $want_terms ) {
			foreach ( self::resolve_terms( $sel ) as $t ) {
				$units[] = array(
					'kind'     => 'term',
					'id'       => $t['id'],
					'taxonomy' => $t['taxonomy'],
					'field'    => self::F_TERM_DESC,
				);
			}
		}

		return $units;
	}

	/**
	 * Run plan — ΜΙΑ υλοποίηση για preview / dry run / apply.
	 *
	 * @param array $sel      Selection (mode/products/terms).
	 * @param array $fields   Validated field IDs (SF_Targets::valid_fields).
	 * @param array $rules    Validated rule IDs (SF_Admin_UI validates πριν καλέσει).
	 * @param array $excluded Φράσεις exclusion list.
	 * @param array $args     {offset:int, limit:int|null, apply:bool}
	 *                        limit null = ΟΛΑ τα units (το UI δεν το χρησιμοποιεί —
	 *                        preview/dry run/apply τρέχουν πάντα σε slices).
	 *
	 * @return array {
	 *   total_units: int,          // πλήθος όλων των units (progress bar)
	 *   offset: int, limit: int|null,
	 *   applied_units: int,        // units που επεξεργάστηκαν στο slice
	 *   changed: int,             // entries with changed=true στο slice
	 *   entries: array[],          // ανά unit {kind,id,title,field,field_label,before,after,changed,error?}
	 * }
	 */
	public static function plan( array $sel, array $fields, array $rules, array $excluded, array $args = array() ): array {

		$offset = isset( $args['offset'] ) ? max( 0, absint( $args['offset'] ) ) : 0;
		$limit  = array_key_exists( 'limit', $args ) ? $args['limit'] : self::BATCH;
		if ( null !== $limit ) {
			$limit = max( 1, absint( $limit ) );
		}
		$apply  = ! empty( $args['apply'] );

		$all   = self::units( $sel, $fields );
		$total = count( $all );
		$slice = ( null === $limit )
			? array_slice( $all, $offset )
			: array_slice( $all, $offset, $limit );

		$changed = 0;
		$entries = array();

		foreach ( $slice as $unit ) {

			$entry = ( 'term' === $unit['kind'] )
				? self::process_term_unit( $unit, $rules, $excluded, $apply )
				: self::process_product_unit( $unit, $rules, $excluded, $apply );

			if ( $entry['changed'] ) {
				$changed++;
			}
			$entries[] = $entry;
		}

		return array(
			'total_units'   => $total,
			'offset'        => $offset,
			'limit'         => $limit,
			'applied_units' => count( $slice ),
			'changed'       => $changed,
			'entries'       => $entries,
		);
	}

	/* =====================================================================
	 * Per-unit processing (product / term)
	 * =================================================================== */

	private static function process_product_unit( array $unit, array $rules, array $excluded, bool $apply ): array {

		$field_def = self::fields()[ $unit['field'] ];

		$entry = array(
			'kind'        => 'product',
			'id'          => (int) $unit['id'],
			'title'       => '',
			'field'       => $unit['field'],
			'field_label' => $field_def['label'],
			'before'      => '',
			'after'       => '',
			'changed'     => false,
		);

		$product = wc_get_product( (int) $unit['id'] );
		if ( ! $product instanceof WC_Product ) {
			$entry['error'] = __( 'Το προϊόν δεν βρέθηκε.', 'smart-formatter' );
			return $entry;
		}

		$entry['title'] = (string) $product->get_name();

		switch ( $unit['field'] ) {

			case self::F_SHORT_DESC:
				$old            = (string) $product->get_short_description();
				$new            = SF_Engine::transform( $old, $rules, $excluded );
				$entry['before'] = $old;
				$entry['after']  = $new;
				if ( $new !== $old && $apply ) {
					$product->set_short_description( $new );
					$product->save();
				}
				$entry['changed'] = ( $new !== $old );
				break;

			case self::F_LONG_DESC:
				$old            = (string) $product->get_description();
				$new            = SF_Engine::transform( $old, $rules, $excluded );
				$entry['before'] = $old;
				$entry['after']  = $new;
				if ( $new !== $old && $apply ) {
					$product->set_description( $new );
					$product->save();
				}
				$entry['changed'] = ( $new !== $old );
				break;

			case self::F_PURCHASE:
				$old            = (string) $product->get_purchase_note();
				$new            = SF_Engine::transform( $old, $rules, $excluded );
				$entry['before'] = $old;
				$entry['after']  = $new;
				if ( $new !== $old && $apply ) {
					$product->set_purchase_note( $new );
					$product->save();
				}
				$entry['changed'] = ( $new !== $old );
				break;

			case self::F_ATTRIBUTES:
				$attrs   = $product->get_attributes();
				$before  = array();
				$after   = array();
				$touched = false;

				foreach ( $attrs as $akey => $attr ) {
					if ( ! $attr instanceof WC_Product_Attribute ) {
						continue;
					}
					// Global (taxonomy) attribute → SKIP με ρητό λόγο.
					// Τα values είναι shared terms σε όλο το κατάστημα.
					if ( '' !== (string) $attr->get_taxonomy() ) {
						$before[ $akey ] = null;
						$after[ $akey ]  = null;
						continue;
					}

					$options = $attr->get_options();
					if ( ! is_array( $options ) ) {
						continue;
					}

					$opts_before = $options;
					$opts_after   = array();
					foreach ( $options as $ov ) {
						$opts_after[] = is_string( $ov )
							? SF_Engine::transform( $ov, $rules, $excluded )
							: $ov;
					}

					$before[ $akey ] = $opts_before;
					$after[ $akey ]  = $opts_after;

					if ( $opts_after !== $opts_before ) {
						$touched = true;
						if ( $apply ) {
							$attr->set_options( $opts_after );
						}
					}
				}

				$entry['before'] = wp_json_encode( $before, JSON_UNESCAPED_UNICODE );
				$entry['after']  = wp_json_encode( $after, JSON_UNESCAPED_UNICODE );

				if ( $touched && $apply ) {
					$product->set_attributes( $attrs );
					$product->save();
				}
				$entry['changed'] = $touched;
				break;
		}

		return $entry;
	}

	private static function process_term_unit( array $unit, array $rules, array $excluded, bool $apply ): array {

		$field_def = self::fields()[ self::F_TERM_DESC ];

		$entry = array(
			'kind'        => 'term',
			'id'          => (int) $unit['id'],
			'title'       => '',
			'field'       => self::F_TERM_DESC,
			'field_label' => $field_def['label'],
			'before'      => '',
			'after'       => '',
			'changed'     => false,
		);

		$term = get_term( (int) $unit['id'], (string) $unit['taxonomy'] );
		if ( ! $term instanceof WP_Term || is_wp_error( $term ) ) {
			$entry['error'] = __( 'Ο όρος δεν βρέθηκε.', 'smart-formatter' );
			return $entry;
		}

		$entry['title'] = (string) $term->name;

		$old            = (string) $term->description;
		$new            = SF_Engine::transform( $old, $rules, $excluded );
		$entry['before'] = $old;
		$entry['after']  = $new;

		if ( $new !== $old && $apply ) {
			$res = wp_update_term(
				(int) $unit['id'],
				(string) $unit['taxonomy'],
				array( 'description' => $new )
			);
			if ( is_wp_error( $res ) ) {
				$entry['error'] = sprintf(
					/* translators: %s: μήνυμα σφάλματος */
					__( 'Αποτυχία ενημέρωσης όρου: %s', 'smart-formatter' ),
					$res->get_error_message()
				);
				return $entry;
			}
		}

		$entry['changed'] = ( $new !== $old );
		return $entry;
	}

}