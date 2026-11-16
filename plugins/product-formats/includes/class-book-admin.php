<?php
/**
 * PFM_Book_Admin — tab "Βιβλία" (1.3.0): book details settings and the
 * "Pages from the description" tool.
 *
 * The tool reads the description and short description of every product
 * (any status but trash), finds "176 σελίδες", "40 εικονογραφημένες
 * σελίδες", "έκδοση 40 σελίδων", "320 pages", and shows a preview: one
 * row per product with the number found (or the choices when the text has
 * more than one number), the text around it and the current value. Only
 * the checked rows are written, at most 100 per click, through WooCommerce
 * CRUD (a global "Σελίδες" attribute term, not used for variations).
 * Products that already have a value are never changed. Every write is
 * recorded (option pfm_pages_run), so "Undo" removes exactly what the tool
 * added, unless someone changed the value since.
 *
 * Routes: POST through PFM_Admin_UI::route() (nonce + capability checked
 * there), PRG. The preview lives in the transient pfm_pages_{uid} (1 day).
 */

defined( 'ABSPATH' ) || exit;

final class PFM_Book_Admin {

	const OPT_RUN   = 'pfm_pages_run';
	const PREVIEW   = 'pfm_pages_';
	const MAX_SCAN  = 5000;
	const PER_APPLY = 100;
	const SHOWN     = 300;

	/* =====================================================================
	 * Extraction
	 * =================================================================== */

	/**
	 * Distinct page counts written in a text, in order of appearance.
	 *
	 * @return array{0: int[], 1: string} Numbers and the text around the first one.
	 */
	public static function find( string $html ): array {
		$text = html_entity_decode( wp_strip_all_tags( str_replace( array( '<br', '</li>', '</p>' ), array( ' <br', ' </li>', ' </p>' ), $html ) ), ENT_QUOTES, 'UTF-8' );
		$text = (string) preg_replace( '/\s+/u', ' ', $text );
		$re   = '/(?<![\d.,])(\d{1,4})\s+(?:[\p{L}\-]{2,30}\s+){0,1}(?:σελίδ(?:ες|ων)|σελιδ(?:ες|ων)|pages)(?!\p{L})/iu';
		if ( ! preg_match_all( $re, $text, $m, PREG_OFFSET_CAPTURE ) ) {
			return array( array(), '' );
		}
		$nums = array();
		foreach ( $m[1] as $hit ) {
			$n = (int) $hit[0];
			if ( $n >= 4 && $n <= 5000 && ! in_array( $n, $nums, true ) ) {
				$nums[] = $n;
			}
		}
		$ctx = '';
		if ( $nums ) {
			// Byte offset of the first hit → character offset, for a clean cut of Greek text.
			$cpos = self::mlen( substr( $text, 0, (int) $m[0][0][1] ) );
			$from = max( 0, $cpos - 60 );
			$ctx  = trim( self::msub( $text, $from, ( $cpos - $from ) + self::mlen( (string) $m[0][0][0] ) + 20 ) );
		}
		return array( $nums, $ctx );
	}

	private static function mlen( string $s ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : strlen( $s );
	}

	private static function msub( string $s, int $from, int $len ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $s, $from, $len, 'UTF-8' ) : substr( $s, $from, $len );
	}

	/* =====================================================================
	 * Scan / preview
	 * =================================================================== */

	private static function preview_key(): string {
		return self::PREVIEW . get_current_user_id();
	}

	public static function preview(): ?array {
		$raw = get_transient( self::preview_key() );
		return is_array( $raw ) && isset( $raw['rows'], $raw['tax'] ) ? $raw : null;
	}

	/** Reads the catalogue and stores the preview. */
	public static function scan( string $tax ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin-only read, one query, no cache wanted.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status NOT IN ('trash','auto-draft','inherit')
				 AND ( post_content LIKE %s OR post_excerpt LIKE %s OR post_content LIKE %s OR post_content LIKE %s OR post_excerpt LIKE %s )
				 ORDER BY ID ASC LIMIT %d",
				'%' . $wpdb->esc_like( 'σελίδ' ) . '%',
				'%' . $wpdb->esc_like( 'σελίδ' ) . '%',
				'%' . $wpdb->esc_like( 'σελιδ' ) . '%',
				'%' . $wpdb->esc_like( 'pages' ) . '%',
				'%' . $wpdb->esc_like( 'pages' ) . '%',
				self::MAX_SCAN
			)
		);
		$rows  = array();
		$stats = array(
			'found'     => 0,
			'ambiguous' => 0,
			'has'       => 0,
			'none'      => 0,
		);
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			$id   = (int) $id;
			$post = get_post( $id );
			if ( ! $post ) {
				continue;
			}
			list( $nums, $ctx ) = self::find( (string) $post->post_content . ' ' . (string) $post->post_excerpt );
			if ( ! $nums ) {
				++$stats['none'];
				continue;
			}
			$current = self::current_value( $id, $tax );
			if ( '' !== $current ) {
				++$stats['has'];
			} elseif ( count( $nums ) > 1 ) {
				++$stats['ambiguous'];
			} else {
				++$stats['found'];
			}
			$rows[] = array(
				'id'      => $id,
				'nums'    => $nums,
				'ctx'     => PFM_Settings::clip( $ctx, 200 ),
				'current' => $current,
			);
		}
		$preview = array(
			'tax'   => $tax,
			'time'  => time(),
			'rows'  => $rows,
			'stats' => $stats,
		);
		set_transient( self::preview_key(), $preview, DAY_IN_SECONDS );
		return $preview;
	}

	/** Current value of the attribute on a product ('' = none). */
	public static function current_value( int $id, string $tax ): string {
		if ( '' === $tax || ! taxonomy_exists( $tax ) ) {
			return '';
		}
		$terms = wp_get_object_terms( $id, $tax, array( 'fields' => 'names' ) );
		return is_array( $terms ) && $terms ? implode( ', ', array_map( 'strval', $terms ) ) : '';
	}

	/* =====================================================================
	 * Apply / undo
	 * =================================================================== */

	/**
	 * Writes up to PER_APPLY of the chosen rows.
	 *
	 * @param array $choices product id => page count (from the form).
	 * @return array{0: int, 1: int, 2: int} Written, skipped, left in the preview.
	 */
	public static function apply( array $choices ): array {
		$preview = self::preview();
		if ( ! $preview ) {
			return array( 0, 0, 0 );
		}
		$tax = (string) $preview['tax'];
		if ( ! taxonomy_exists( $tax ) ) {
			return array( 0, count( $choices ), 0 );
		}
		$allowed = array();
		foreach ( $preview['rows'] as $r ) {
			if ( '' === $r['current'] ) {
				$allowed[ (int) $r['id'] ] = array_map( 'intval', $r['nums'] );
			}
		}
		$run = get_option( self::OPT_RUN, array() );
		if ( ! is_array( $run ) || ( $run['tax'] ?? '' ) !== $tax || ! isset( $run['items'] ) || ! is_array( $run['items'] ) ) {
			$run = array(
				'tax'   => $tax,
				'time'  => time(),
				'items' => array(),
			);
		}
		$done    = 0;
		$skipped = 0;
		$written = array();
		foreach ( $choices as $id => $n ) {
			$id = absint( $id );
			$n  = absint( $n );
			if ( $done >= self::PER_APPLY ) {
				break;
			}
			if ( ! isset( $allowed[ $id ] ) || ! in_array( $n, $allowed[ $id ], true ) ) {
				++$skipped;
				continue;
			}
			$term_id = self::write( $id, $tax, $n );
			if ( $term_id ) {
				$run['items'][ $id ] = $term_id;
				$written[]           = $id;
				++$done;
			} else {
				++$skipped;
			}
		}
		$run['time'] = time();
		update_option( self::OPT_RUN, $run, false );

		// The written rows leave the preview; the next click continues.
		$preview['rows'] = array_values(
			array_filter(
				$preview['rows'],
				static function ( $r ) use ( $written ) {
					return ! in_array( (int) $r['id'], $written, true );
				}
			)
		);
		set_transient( self::preview_key(), $preview, DAY_IN_SECONDS );
		if ( $written ) {
			do_action( 'noxpress_products_changed', $written );
			PFM_Works::purge( $written );
		}
		$left = 0;
		foreach ( $preview['rows'] as $r ) {
			if ( '' === $r['current'] ) {
				++$left;
			}
		}
		return array( $done, $skipped, $left );
	}

	/** Adds the page count to one product (never over an existing value). Term id or 0. */
	private static function write( int $id, string $tax, int $n ): int {
		$p = wc_get_product( $id );
		if ( ! $p || $p->is_type( 'variation' ) || '' !== self::current_value( $id, $tax ) ) {
			return 0;
		}
		$name = (string) $n;
		$t    = term_exists( $name, $tax );
		if ( ! $t ) {
			$t = wp_insert_term( $name, $tax );
		}
		if ( is_wp_error( $t ) || ! is_array( $t ) ) {
			return 0;
		}
		$term_id = (int) $t['term_id'];
		$attrs   = $p->get_attributes( 'edit' );
		$a       = new WC_Product_Attribute();
		$a->set_id( (int) wc_attribute_taxonomy_id_by_name( $tax ) );
		$a->set_name( $tax );
		$a->set_options( array( $term_id ) );
		$a->set_position( count( $attrs ) );
		$a->set_visible( true );
		$a->set_variation( false );
		$attrs[ $tax ] = $a;
		$p->set_attributes( $attrs );
		$p->save();
		return $term_id;
	}

	/** @return array{0: int, 1: int} Removed, kept (changed since). */
	public static function undo(): array {
		$run = get_option( self::OPT_RUN, array() );
		if ( ! is_array( $run ) || empty( $run['items'] ) || ! is_array( $run['items'] ) ) {
			return array( 0, 0 );
		}
		$tax     = (string) ( $run['tax'] ?? '' );
		$removed = 0;
		$kept    = 0;
		$changed = array();
		foreach ( $run['items'] as $id => $term_id ) {
			$id = (int) $id;
			$p  = wc_get_product( $id );
			if ( ! $p || ! taxonomy_exists( $tax ) ) {
				++$kept;
				continue;
			}
			$attrs = $p->get_attributes( 'edit' );
			if ( ! isset( $attrs[ $tax ] ) || array_map( 'intval', (array) $attrs[ $tax ]->get_options() ) !== array( (int) $term_id ) ) {
				++$kept; // Someone changed it since: leave it.
				continue;
			}
			unset( $attrs[ $tax ] );
			$p->set_attributes( $attrs );
			$p->save();
			wp_remove_object_terms( $id, (int) $term_id, $tax );
			$changed[] = $id;
			++$removed;
		}
		delete_option( self::OPT_RUN );
		delete_transient( self::preview_key() );
		if ( $changed ) {
			do_action( 'noxpress_products_changed', $changed );
			PFM_Works::purge( $changed );
		}
		return array( $removed, $kept );
	}

	/** Creates the global attribute "Σελίδες" (slug selides) and maps it. */
	public static function create_attribute(): string {
		$slug = 'selides';
		$id   = (int) wc_attribute_taxonomy_id_by_name( 'pa_' . $slug );
		if ( ! $id ) {
			$id = wc_create_attribute(
				array(
					'name'         => 'Σελίδες',
					'slug'         => $slug,
					'type'         => 'select',
					'order_by'     => 'name_num',
					'has_archives' => false,
				)
			);
			if ( is_wp_error( $id ) ) {
				return '';
			}
		}
		$s                   = PFM_Book::get();
		$s['attrs']['pages'] = 'pa_' . $slug;
		PFM_Book::save( $s );
		return 'pa_' . $slug;
	}

	/* =====================================================================
	 * Routes (called from PFM_Admin_UI::route(), nonce + capability done)
	 * =================================================================== */

	/** @return array Messages. */
	public static function handle( string $action ): array {
		switch ( $action ) {
			case 'save_book':
				$attrs = self::post_array( 'pfm_book_attrs' );
				$known = self::attribute_choices();
				foreach ( $attrs as $k => $v ) {
					if ( ! isset( $known[ (string) $v ] ) ) {
						$attrs[ $k ] = '';
					}
				}
				PFM_Book::save(
					array(
						'schema'    => '1' === self::post( 'pfm_book_schema' ),
						'add_type'  => '1' === self::post( 'pfm_book_add_type' ),
						'attrs'     => $attrs,
						'hardcover' => self::post( 'pfm_book_hardcover' ),
						'table'     => '1' === self::post( 'pfm_book_table' ),
						'position'  => self::post( 'pfm_book_position' ),
						'title_el'  => self::post( 'pfm_book_title_el' ),
						'title_en'  => self::post( 'pfm_book_title_en' ),
						'rows'      => self::post_array( 'pfm_book_rows' ),
					)
				);
				Noxpress_Core::purge_page_cache();
				return array( self::msg( 'success', __( 'Οι ρυθμίσεις βιβλίων αποθηκεύτηκαν.', 'product-formats' ) ) );

			case 'pages_attr':
				$tax = self::create_attribute();
				return array(
					'' !== $tax
						? self::msg( 'success', __( 'Το χαρακτηριστικό «Σελίδες» είναι έτοιμο και συνδέθηκε με τις σελίδες.', 'product-formats' ) )
						: self::msg( 'error', __( 'Το χαρακτηριστικό «Σελίδες» δεν δημιουργήθηκε.', 'product-formats' ) ),
				);

			case 'pages_scan':
				$tax = PFM_Book::get()['attrs']['pages'];
				if ( '' === $tax || ! taxonomy_exists( $tax ) ) {
					return array( self::msg( 'error', __( 'Διάλεξε πρώτα το χαρακτηριστικό των σελίδων (ή δημιούργησέ το).', 'product-formats' ) ) );
				}
				$pv = self::scan( $tax );
				return array(
					self::msg(
						'info',
						sprintf(
							/* translators: 1: products with one number, 2: with several numbers, 3: with a value already */
							__( 'Βρέθηκαν σελίδες σε %1$d προϊόντα, %2$d με περισσότερους από έναν αριθμούς, %3$d έχουν ήδη τιμή. Τίποτα δεν γράφτηκε ακόμα.', 'product-formats' ),
							(int) $pv['stats']['found'],
							(int) $pv['stats']['ambiguous'],
							(int) $pv['stats']['has']
						)
					),
				);

			case 'pages_apply':
				$pick    = self::post_array( 'pfm_pages_pick' );
				$num     = self::post_array( 'pfm_pages_num' );
				$choices = array();
				foreach ( $pick as $id ) {
					$id = absint( $id );
					if ( $id && isset( $num[ $id ] ) ) {
						$choices[ $id ] = absint( $num[ $id ] );
					}
				}
				if ( ! $choices ) {
					return array( self::msg( 'error', __( 'Δεν τσεκαρίστηκε κανένα προϊόν.', 'product-formats' ) ) );
				}
				list( $done, $skipped, $left ) = self::apply( $choices );
				$msgs = array(
					self::msg(
						$done ? 'success' : 'warning',
						sprintf(
							/* translators: 1: products written, 2: products skipped */
							__( 'Γράφτηκαν σελίδες σε %1$d προϊόντα, %2$d παραλείφθηκαν.', 'product-formats' ),
							$done,
							$skipped
						)
					),
				);
				if ( $left && count( $choices ) > self::PER_APPLY ) {
					/* translators: %d: products per click */
					$msgs[] = self::msg( 'info', sprintf( __( 'Γράφονται έως %d προϊόντα ανά κλικ: πάτα ξανά «Γράψε τα τσεκαρισμένα» για τα υπόλοιπα.', 'product-formats' ), self::PER_APPLY ) );
				}
				return $msgs;

			case 'pages_undo':
				list( $removed, $kept ) = self::undo();
				return array(
					self::msg(
						'success',
						sprintf(
							/* translators: 1: products restored, 2: products left as they are */
							__( 'Αναίρεση: αφαιρέθηκαν οι σελίδες από %1$d προϊόντα· %2$d έμειναν ως έχουν γιατί άλλαξαν στο μεταξύ.', 'product-formats' ),
							$removed,
							$kept
						)
					),
				);

			case 'pages_clear':
				delete_transient( self::preview_key() );
				return array( self::msg( 'success', __( 'Η προεπισκόπηση σβήστηκε.', 'product-formats' ) ) );
		}
		return array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'product-formats' ) ) );
	}

	private static function msg( string $type, string $text ): array {
		return array(
			'type' => $type,
			'text' => $text,
		);
	}

	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in PFM_Admin_UI::route().
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
	}

	private static function post_array( string $key ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in PFM_Admin_UI::route(), sanitized by the callers.
		return isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array();
	}

	/** Global attributes: taxonomy => label. */
	public static function attribute_choices(): array {
		$out = array();
		foreach ( wc_get_attribute_taxonomies() as $a ) {
			$out[ 'pa_' . (string) $a->attribute_name ] = (string) $a->attribute_label;
		}
		return $out;
	}

	/* =====================================================================
	 * Tab
	 * =================================================================== */

	public static function render( callable $nonce_field ): void {
		$s       = PFM_Book::get();
		$choices = self::attribute_choices();
		$sugg    = PFM_Book::suggest_attrs();
		$never   = false === get_option( PFM_Book::OPT, false );
		$labels  = array(
			'pages'   => __( 'Σελίδες', 'product-formats' ),
			'year'    => __( 'Έτος έκδοσης', 'product-formats' ),
			'lang'    => __( 'Γλώσσα', 'product-formats' ),
			'edition' => __( 'Έκδοση', 'product-formats' ),
			'series'  => __( 'Σειρά', 'product-formats' ),
			'binding' => __( 'Δέσιμο', 'product-formats' ),
		);
		$row_labels = array(
			'format'     => __( 'Μορφή', 'product-formats' ),
			'isbn'       => 'ISBN',
			'pages'      => __( 'Σελίδες', 'product-formats' ),
			'year'       => __( 'Έτος έκδοσης', 'product-formats' ),
			'binding'    => __( 'Δέσιμο', 'product-formats' ),
			'dimensions' => __( 'Διαστάσεις', 'product-formats' ),
			'lang'       => __( 'Γλώσσα', 'product-formats' ),
			'edition'    => __( 'Έκδοση', 'product-formats' ),
			'series'     => __( 'Σειρά', 'product-formats' ),
		);
		$positions = array(
			'after_meta'  => __( 'Κάτω από τα στοιχεία προϊόντος (κωδικός, κατηγορίες)', 'product-formats' ),
			'before_tabs' => __( 'Πάνω από τα tabs της περιγραφής', 'product-formats' ),
			'manual'      => __( 'Μόνο με το shortcode [nox_book_details]', 'product-formats' ),
		);
		$base = admin_url( 'admin.php?page=' . PFM_Admin_UI::SLUG_MAIN . '&tab=book' );
		?>
		<h2 class="pfm-h2"><?php esc_html_e( 'Στοιχεία βιβλίου', 'product-formats' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Τα στοιχεία έρχονται από χαρακτηριστικά που έχει ήδη το κατάστημα. Στο schema μπαίνουν μέσα στο Product που τυπώνει ήδη η σελίδα (WooCommerce, Search Signals ή Rank Math, μαζί με το Featured Creators), ποτέ δεύτερο. Με τη λειτουργία δοκιμής του Product Formats, μόνο οι διαχειριστές τα βλέπουν.', 'product-formats' ); ?></p>
		<form method="post" action="<?php echo esc_url( $base ); ?>" class="pfm-zone">
			<?php $nonce_field(); ?>
			<input type="hidden" name="pfm_action" value="save_book">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Χαρακτηριστικά', 'product-formats' ); ?></th>
					<td>
						<?php foreach ( $labels as $f => $label ) : ?>
							<?php $cur = '' !== $s['attrs'][ $f ] ? $s['attrs'][ $f ] : ( $never ? $sugg[ $f ] : '' ); ?>
							<p>
								<label>
									<span class="pfm-book-label"><?php echo esc_html( $label ); ?></span>
									<select name="pfm_book_attrs[<?php echo esc_attr( $f ); ?>]">
										<option value=""><?php esc_html_e( '— κανένα —', 'product-formats' ); ?></option>
										<?php foreach ( $choices as $tax => $alabel ) : ?>
											<option value="<?php echo esc_attr( $tax ); ?>" <?php selected( $cur, $tax ); ?>><?php echo esc_html( $alabel . ' (' . $tax . ')' ); ?></option>
										<?php endforeach; ?>
									</select>
								</label>
								<?php if ( '' === $s['attrs'][ $f ] && '' !== $sugg[ $f ] && $never ) : ?>
									<span class="pfm-muted"><?php esc_html_e( 'πρόταση από το όνομα', 'product-formats' ); ?></span>
								<?php endif; ?>
							</p>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'Από τις σελίδες και το έτος κρατιέται ο πρώτος αριθμός της τιμής.', 'product-formats' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="pfm_book_hardcover"><?php esc_html_e( 'Λέξεις για σκληρόδετο', 'product-formats' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" id="pfm_book_hardcover" name="pfm_book_hardcover" value="<?php echo esc_attr( implode( ', ', $s['hardcover'] ) ); ?>">
						<p class="description"><?php esc_html_e( 'Όταν το δέσιμο περιέχει μία από αυτές, το έντυπο είναι Hardcover· αλλιώς Paperback. Χωρίς δέσιμο, το bookFormat του εντύπου παραλείπεται.', 'product-formats' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Schema', 'product-formats' ); ?></th>
					<td>
						<p><label><input type="checkbox" name="pfm_book_schema" value="1" <?php checked( $s['schema'] ); ?>> <?php esc_html_e( 'Πρόσθεσε στοιχεία βιβλίου στο schema (σελίδες, έτος, γλώσσα, έκδοση, σειρά, μορφή, ISBN, οι άλλες μορφές του έργου)', 'product-formats' ); ?></label></p>
						<p><label><input type="checkbox" name="pfm_book_add_type" value="1" <?php checked( $s['add_type'] ); ?>> <?php esc_html_e( 'Κάνε Book τα προϊόντα με μορφή Έντυπο ή Ηλεκτρονικό βιβλίο και Audiobook τα ηχητικά, όταν δεν έχουν ήδη τύπο έργου', 'product-formats' ); ?></label></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Πίνακας «Στοιχεία έκδοσης»', 'product-formats' ); ?></th>
					<td>
						<p><label><input type="checkbox" name="pfm_book_table" value="1" <?php checked( $s['table'] ); ?>> <?php esc_html_e( 'Δείξε τον πίνακα στη σελίδα του προϊόντος (μόνο τα συμπληρωμένα στοιχεία)', 'product-formats' ); ?></label></p>
						<p>
							<select name="pfm_book_position" aria-label="<?php esc_attr_e( 'Θέση πίνακα', 'product-formats' ); ?>">
								<?php foreach ( $positions as $k => $l ) : ?>
									<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $s['position'], $k ); ?>><?php echo esc_html( $l ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
						<p>
							<input type="text" name="pfm_book_title_el" value="<?php echo esc_attr( $s['title_el'] ); ?>" placeholder="<?php esc_attr_e( 'Στοιχεία έκδοσης', 'product-formats' ); ?>" aria-label="<?php esc_attr_e( 'Τίτλος (ελληνικά)', 'product-formats' ); ?>">
							<input type="text" name="pfm_book_title_en" value="<?php echo esc_attr( $s['title_en'] ); ?>" placeholder="<?php echo esc_attr( PFM_Lang::english( 'Στοιχεία έκδοσης' ) ); ?>" aria-label="<?php esc_attr_e( 'Τίτλος (αγγλικά)', 'product-formats' ); ?>">
						</p>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Γραμμές', 'product-formats' ); ?></legend>
							<?php foreach ( $row_labels as $k => $l ) : ?>
								<label class="pfm-book-row"><input type="checkbox" name="pfm_book_rows[]" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( $k, $s['rows'], true ) ); ?>> <?php echo esc_html( $l ); ?></label>
							<?php endforeach; ?>
						</fieldset>
					</td>
				</tr>
			</table>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'product-formats' ); ?></button></p>
		</form>

		<?php self::render_tool( $nonce_field, $base, $s ); ?>
		<?php
	}

	private static function render_tool( callable $nonce_field, string $base, array $s ): void {
		$tax     = $s['attrs']['pages'];
		$preview = self::preview();
		$run     = get_option( self::OPT_RUN, array() );
		$n_run   = is_array( $run ) && isset( $run['items'] ) && is_array( $run['items'] ) ? count( $run['items'] ) : 0;
		?>
		<h2 class="pfm-h2"><?php esc_html_e( 'Σελίδες από την περιγραφή', 'product-formats' ); ?></h2>
		<div class="pfm-zone">
			<p class="description"><?php esc_html_e( 'Βρίσκει φράσεις όπως «σε 176 σελίδες» ή «40 εικονογραφημένες σελίδες» στην περιγραφή και τη σύντομη περιγραφή, και γράφει τον αριθμό στο χαρακτηριστικό των σελίδων. Πρώτα δείχνει προεπισκόπηση· γράφει μόνο τα τσεκαρισμένα, ποτέ πάνω σε τιμή που υπάρχει, και η αναίρεση σβήνει μόνο ό,τι πρόσθεσε.', 'product-formats' ); ?></p>
			<?php if ( '' === $tax || ! taxonomy_exists( $tax ) ) : ?>
				<form method="post" action="<?php echo esc_url( $base ); ?>">
					<?php $nonce_field(); ?>
					<input type="hidden" name="pfm_action" value="pages_attr">
					<p><?php esc_html_e( 'Δεν έχει οριστεί χαρακτηριστικό για τις σελίδες. Διάλεξε ένα πιο πάνω ή φτιάξε νέο:', 'product-formats' ); ?>
						<button type="submit" class="button"><?php esc_html_e( 'Δημιούργησε το χαρακτηριστικό «Σελίδες»', 'product-formats' ); ?></button></p>
				</form>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( $base ); ?>" class="pfm-inline-form">
					<?php $nonce_field(); ?>
					<input type="hidden" name="pfm_action" value="pages_scan">
					<button type="submit" class="button"><?php echo esc_html( $preview ? __( 'Νέα σάρωση', 'product-formats' ) : __( 'Σάρωση (προεπισκόπηση)', 'product-formats' ) ); ?></button>
				</form>
				<?php if ( $n_run ) : ?>
					<form method="post" action="<?php echo esc_url( $base ); ?>" class="pfm-inline-form">
						<?php $nonce_field(); ?>
						<input type="hidden" name="pfm_action" value="pages_undo">
						<button type="submit" class="button" data-pfm-confirm="<?php esc_attr_e( 'Να αφαιρεθούν οι σελίδες που έγραψε το εργαλείο;', 'product-formats' ); ?>">
							<?php
							/* translators: %d: number of products */
							echo esc_html( sprintf( __( 'Αναίρεση (%d προϊόντα)', 'product-formats' ), $n_run ) );
							?>
						</button>
					</form>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( $preview && $preview['tax'] === $tax ) : ?>
				<?php self::render_preview( $nonce_field, $base, $preview ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_preview( callable $nonce_field, string $base, array $preview ): void {
		$rows = array_slice( $preview['rows'], 0, self::SHOWN );
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'Δεν έμεινε τίποτα για να γραφτεί.', 'product-formats' ) . '</p>';
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( $base ); ?>">
			<?php $nonce_field(); ?>
			<input type="hidden" name="pfm_action" value="pages_apply">
			<?php if ( count( $preview['rows'] ) > self::SHOWN ) : ?>
				<p class="pfm-muted">
					<?php
					/* translators: 1: rows shown, 2: rows in total */
					echo esc_html( sprintf( __( 'Φαίνονται %1$d από %2$d. Τα υπόλοιπα έρχονται μετά την εγγραφή αυτών.', 'product-formats' ), self::SHOWN, count( $preview['rows'] ) ) );
					?>
				</p>
			<?php endif; ?>
			<table class="widefat striped pfm-table">
				<thead>
					<tr>
						<th class="check-column"><input type="checkbox" data-pfm-checkall="input[name='pfm_pages_pick[]']" aria-label="<?php esc_attr_e( 'Όλα', 'product-formats' ); ?>"></th>
						<th><?php esc_html_e( 'Προϊόν', 'product-formats' ); ?></th>
						<th><?php esc_html_e( 'Σελίδες', 'product-formats' ); ?></th>
						<th><?php esc_html_e( 'Κείμενο', 'product-formats' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $r ) : ?>
						<?php
						$id    = (int) $r['id'];
						$has   = '' !== $r['current'];
						$multi = count( $r['nums'] ) > 1;
						?>
						<tr>
							<td class="check-column">
								<?php if ( ! $has ) : ?>
									<input type="checkbox" name="pfm_pages_pick[]" value="<?php echo (int) $id; ?>" <?php checked( ! $multi ); ?> aria-label="<?php echo esc_attr( get_the_title( $id ) ); ?>">
								<?php endif; ?>
							</td>
							<td><a href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>"><?php echo esc_html( get_the_title( $id ) ); ?></a> <span class="pfm-muted">#<?php echo (int) $id; ?></span></td>
							<td>
								<?php if ( $has ) : ?>
									<?php
									/* translators: %s: the value the product has */
									echo esc_html( sprintf( __( 'έχει ήδη: %s', 'product-formats' ), $r['current'] ) );
									?>
								<?php elseif ( $multi ) : ?>
									<select name="pfm_pages_num[<?php echo (int) $id; ?>]" aria-label="<?php esc_attr_e( 'Σελίδες', 'product-formats' ); ?>">
										<?php foreach ( $r['nums'] as $n ) : ?>
											<option value="<?php echo (int) $n; ?>"><?php echo (int) $n; ?></option>
										<?php endforeach; ?>
									</select>
									<span class="pfm-muted"><?php esc_html_e( 'περισσότεροι από ένας αριθμοί', 'product-formats' ); ?></span>
								<?php else : ?>
									<strong><?php echo (int) $r['nums'][0]; ?></strong>
									<input type="hidden" name="pfm_pages_num[<?php echo (int) $id; ?>]" value="<?php echo (int) $r['nums'][0]; ?>">
								<?php endif; ?>
							</td>
							<td class="pfm-muted">…<?php echo esc_html( $r['ctx'] ); ?>…</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Γράψε τα τσεκαρισμένα', 'product-formats' ); ?></button>
				<?php
				/* translators: %d: products per click */
				echo '<span class="pfm-muted"> ' . esc_html( sprintf( __( 'έως %d ανά κλικ', 'product-formats' ), self::PER_APPLY ) ) . '</span>';
				?>
			</p>
		</form>
		<form method="post" action="<?php echo esc_url( $base ); ?>">
			<?php $nonce_field(); ?>
			<input type="hidden" name="pfm_action" value="pages_clear">
			<button type="submit" class="button-link"><?php esc_html_e( 'Σβήσε την προεπισκόπηση', 'product-formats' ); ?></button>
		</form>
		<?php
	}
}
