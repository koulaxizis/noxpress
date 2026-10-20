<?php
/**
 * BKF_Importer — a feed record as a WooCommerce product.
 *
 *  import()    a new product (simple, or external for "show only" rules),
 *              or an update when the title is already ours. A product
 *              with the same ISBN that is not ours is never duplicated:
 *              the result says "exists" and the admin can link it.
 *  update()    the sync. Price and availability follow the feed always;
 *              title, descriptions, cover, attributes, weight and
 *              dimensions, and tax class follow it only while they are
 *              still what the plugin wrote (a hash per field in
 *              _bkf_hash). A field edited by hand is locked and left as
 *              it is. Categories, tags, brand, SKU / GTIN and the slug
 *              are set on import only.
 *  link()      marks an existing product as this title: the feed changes
 *              nothing on it (not even the price) until "Update from the
 *              feed" (take_over) is used once.
 *  unlink()    removes every _bkf_* meta; the product stays as it is.
 *  gone()      a title that left the feed: out of stock, or draft.
 *
 * Meta on products (all removed by unlink and by uninstall):
 *  _bkf_source ('bookpoint'), _bkf_source_id, _bkf_isbn (digits),
 *  _bkf_synced, _bkf_updated, _bkf_hash, _bkf_attr_keys, _bkf_rel_isbn,
 *  _bkf_rule, _bkf_linked, _bkf_gone, _bkf_gone_status, _bkf_cover_src,
 *  _bkf_back_src, _bkf_feed_price, _bkf_ptype, _bkf_fixed_until,
 *  _bkf_locked, _bkf_img_fail (image addresses that failed once),
 *  _bkf_pub_url (this product's page on the publisher's site, 1.3.0),
 *  _bkf_pub_cover_src (the cover came from that page, 1.4.0),
 *  _bkf_pub_cover_no (a publisher cover that was not larger, 1.4.0),
 *  _bkf_pub_price / _bkf_pub_at / _bkf_pub_note (the publisher's own
 *  price, when it was read, why it was not used; BKF_Pub_Price),
 *  _bkf_pub_cat (the publisher subcategory added once, 1.5.0).
 * Publisher subcategories (1.5.0, setting pub_cat): one subcategory per
 * publisher under the chosen category, added to each title once (on
 * import, or at the next sync for titles imported before), so a
 * subcategory removed by hand stays removed. Term meta: bkf_publisher
 * (the name), bkf_logo (the image copied from the brand of the same
 * name; while the category still shows it, a new brand logo follows).
 * Price: the publisher's page when one is set up for the publisher and
 * its price is plausible, else the catalogue's.
 * Attachments we download carry _bkf_cover_src.
 */

defined( 'ABSPATH' ) || exit;

final class BKF_Importer {

	const SOURCE    = 'bookpoint';
	const MAX_IMAGE = 10485760; // 10 MB

	/** True when the last sideload() reused an attachment already in the library. */
	private static $reused = false;

	/** Ids changed during this request (for noxpress_products_changed). */
	private static $changed = array();

	/* =====================================================================
	 * Lookups
	 * =================================================================== */

	/** Our product for a feed id, 0 when none. */
	public static function find_by_source( string $id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- indexed meta lookup.
		$pid = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT pm.post_id FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = '_bkf_source_id' AND pm.meta_value = %s
				   AND p.post_type = 'product' AND p.post_status <> 'trash'
				 ORDER BY pm.post_id ASC LIMIT 1",
				$id
			)
		);
		return (int) $pid;
	}

	/** Any product (ours or not) with this ISBN in GTIN, SKU or our meta, 0 when none. */
	public static function find_by_isbn( string $digits ): int {
		if ( '' === $digits ) {
			return 0;
		}
		$m = self::isbn_map( array( $digits ) );
		return isset( $m[ $digits ] ) ? $m[ $digits ] : 0;
	}

	/**
	 * ISBN digits => product id for many ISBNs at once.
	 *
	 * @param string[] $digits ISBNs, digits only.
	 */
	public static function isbn_map( array $digits ): array {
		global $wpdb;
		$digits = array_values( array_unique( array_filter( array_map( 'strval', $digits ) ) ) );
		if ( ! $digits ) {
			return array();
		}
		$in = implode( ',', array_fill( 0, count( $digits ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders -- placeholders built above.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id, UPPER(REPLACE(REPLACE(pm.meta_value,'-',''),' ','')) AS d FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key IN ('_global_unique_id','_sku','_bkf_isbn')
				   AND p.post_type = 'product' AND p.post_status <> 'trash'
				   AND UPPER(REPLACE(REPLACE(pm.meta_value,'-',''),' ','')) IN ($in)
				 ORDER BY pm.post_id ASC",
				$digits
			)
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			if ( ! isset( $out[ $row->d ] ) ) {
				$out[ $row->d ] = (int) $row->post_id;
			}
		}
		return $out;
	}

	/**
	 * Feed id => { pid, ours } for search results.
	 *
	 * @param array[] $records Records.
	 */
	public static function lookup( array $records ): array {
		$out   = array();
		$isbns = array();
		foreach ( $records as $r ) {
			$pid = self::find_by_source( $r['id'] );
			if ( $pid ) {
				$out[ $r['id'] ] = array(
					'pid'  => $pid,
					'ours' => true,
				);
			} elseif ( '' !== $r['isbn_digits'] ) {
				$isbns[ $r['id'] ] = $r['isbn_digits'];
			}
		}
		$map = self::isbn_map( array_values( $isbns ) );
		foreach ( $isbns as $id => $d ) {
			if ( isset( $map[ $d ] ) ) {
				$out[ $id ] = array(
					'pid'  => $map[ $d ],
					'ours' => false,
				);
			}
		}
		return $out;
	}

	/* =====================================================================
	 * Import
	 * =================================================================== */

	/**
	 * Imports one record.
	 *
	 * @param array $r   Record (BKF_Record::from_doc).
	 * @param array $opt status (draft|publish), cat (term id), mode
	 *                   (sell|external), url, button, rule (id).
	 * @return array { result: imported|updated|exists|error, pid, msg }.
	 */
	public static function import( array $r, array $opt = array() ): array {
		$pid = self::find_by_source( $r['id'] );
		if ( $pid ) {
			$fields = self::update( $pid, $r );
			return array(
				'result' => 'updated',
				'pid'    => $pid,
				'msg'    => implode( ',', $fields ),
			);
		}
		$other = self::find_by_isbn( $r['isbn_digits'] );
		if ( $other ) {
			return array(
				'result' => 'exists',
				'pid'    => $other,
				'msg'    => '',
			);
		}

		try {
			$pid = self::create( $r, $opt );
		} catch ( \Throwable $e ) {
			BKF_Log::add( 'error', 0, $r['isbn'], $r['title'], array(), $e->getMessage() );
			return array(
				'result' => 'error',
				'pid'    => 0,
				'msg'    => $e->getMessage(),
			);
		}
		BKF_Log::add( 'import', $pid, $r['isbn'], $r['title'] );
		self::$changed[] = $pid;
		return array(
			'result' => 'imported',
			'pid'    => $pid,
			'msg'    => '',
		);
	}

	/** Creates the product, every field written and hashed. */
	private static function create( array $r, array $opt ): int {
		$r    = self::with_pub_price( $r, 0 );
		$s    = BKF_Settings::get();
		$mode = isset( $opt['mode'] ) && 'external' === $opt['mode'] && ! empty( $opt['url'] ) ? 'external' : 'sell';

		$p = 'external' === $mode ? new WC_Product_External() : new WC_Product_Simple();
		$status = isset( $opt['status'] ) && in_array( $opt['status'], array( 'draft', 'publish' ), true ) ? $opt['status'] : $s['status'];
		$p->set_status( $status );
		$p->set_catalog_visibility( 'visible' );

		if ( 'external' === $mode ) {
			$p->set_product_url( self::external_url( (string) $opt['url'], $r ) );
			if ( ! empty( $opt['button'] ) ) {
				$p->set_button_text( (string) $opt['button'] );
			}
		}

		// Identity (import only).
		$isbn_out = 'digits' === $s['isbn'] ? $r['isbn_digits'] : $r['isbn'];
		if ( '' !== $isbn_out ) {
			if ( method_exists( $p, 'set_global_unique_id' ) ) {
				try {
					$p->set_global_unique_id( $isbn_out );
				} catch ( \Throwable $e ) {
					// Already used by another product: leave it empty.
					unset( $e );
				}
			}
			if ( $s['sku'] ) {
				try {
					$p->set_sku( $isbn_out );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
		}

		$p->set_category_ids( self::category_ids( $r, (int) ( $opt['cat'] ?? 0 ) ) );

		$all = array_merge( array( 'price', 'stock' ), BKF_Settings::SYNC_FIELDS );
		self::write( $p, $r, $all, 'external' === $mode );
		$pid = $p->save();
		if ( ! $pid ) {
			throw new \RuntimeException( __( 'Το προϊόν δεν αποθηκεύτηκε.', 'book-feed' ) );
		}

		update_post_meta( $pid, '_bkf_source', self::SOURCE );
		update_post_meta( $pid, '_bkf_source_id', $r['id'] );
		if ( ! empty( $opt['rule'] ) ) {
			update_post_meta( $pid, '_bkf_rule', (string) $opt['rule'] );
		}
		self::write_meta( $pid, $r );
		self::note_pub_price( $pid, $r );

		// Cover after the first save (the attachment needs a parent).
		$img = self::write_images( $pid, $r );
		$p   = wc_get_product( $pid );
		if ( $img ) {
			$p->save();
		}

		self::brand( $pid, $r );
		self::publisher_cat( $pid, $r );
		self::tags( $pid, $r );
		self::store_hashes( $p, $all );
		return $pid;
	}

	/* =====================================================================
	 * Sync
	 * =================================================================== */

	/**
	 * Applies a record to our product.
	 *
	 * @param bool $force Write every field and take the product over
	 *                    (link → normal sync), as "Update from the feed".
	 * @return string[] Fields that changed.
	 */
	public static function update( int $pid, array $r, bool $force = false ): array {
		$p = wc_get_product( $pid );
		if ( ! $p || $p->is_type( 'variable' ) || $p->is_type( 'grouped' ) ) {
			return array();
		}
		$s      = BKF_Settings::get();
		$linked = '1' === (string) get_post_meta( $pid, '_bkf_linked', true );
		if ( $linked && ! $force ) {
			// A linked product: only the record meta (price guard data) follows.
			self::write_meta( $pid, $r );
			return array();
		}

		$r['pub_url'] = (string) get_post_meta( $pid, '_bkf_pub_url', true );

		$r        = self::with_pub_price( $r, $pid );
		$hash     = self::hashes( $pid );
		$external = $p->is_type( 'external' );
		$want     = $force ? array_merge( array( 'price', 'stock' ), BKF_Settings::SYNC_FIELDS ) : array_merge( array( 'price', 'stock' ), $s['fields'] );
		$fields   = array();
		$locked   = array();
		foreach ( $want as $f ) {
			if ( $force || in_array( $f, array( 'price', 'stock' ), true ) ) {
				$fields[] = $f;
				continue;
			}
			if ( ! isset( $hash[ $f ] ) ) {
				continue; // never written by us
			}
			if ( hash_equals( $hash[ $f ], self::hash_of( $p, $f ) ) ) {
				$fields[] = $f;
			} else {
				$locked[] = $f;
			}
		}

		$before = array();
		foreach ( $fields as $f ) {
			$before[ $f ] = self::value_of( $p, $f );
		}

		self::write( $p, $r, array_diff( $fields, array( 'image' ) ), $external );

		// Back in the feed after being marked gone.
		$back = '1' === (string) get_post_meta( $pid, '_bkf_gone', true );
		if ( $back ) {
			$old = (string) get_post_meta( $pid, '_bkf_gone_status', true );
			if ( 'draft' === $p->get_status() && in_array( $old, array( 'publish', 'pending', 'private' ), true ) ) {
				$p->set_status( $old );
			}
		}
		$p->save();

		if ( in_array( 'image', $fields, true ) && self::write_images( $pid, $r, $force ) ) {
			$p = wc_get_product( $pid );
			$p->save();
		} else {
			$p = wc_get_product( $pid );
		}

		if ( $back ) {
			delete_post_meta( $pid, '_bkf_gone' );
			delete_post_meta( $pid, '_bkf_gone_status' );
			BKF_Log::add( 'back', $pid, $r['isbn'], $r['title'] );
		}

		if ( $force ) {
			delete_post_meta( $pid, '_bkf_linked' );
			self::store_hashes( $p, $fields );
		} else {
			// Fields we wrote get their new hash; locked ones keep the old one.
			self::store_hashes( $p, array_diff( $fields, array( 'price', 'stock' ) ), $hash );
		}
		self::write_meta( $pid, $r );
		self::note_pub_price( $pid, $r );
		self::publisher_cat( $pid, $r );

		$changed = array();
		foreach ( $fields as $f ) {
			if ( self::value_of( $p, $f ) !== $before[ $f ] ) {
				$changed[] = $f;
			}
		}
		if ( $changed ) {
			BKF_Log::add( 'update', $pid, $r['isbn'], $r['title'], $changed );
			self::$changed[] = $pid;
		}
		$prev_locked = (string) get_post_meta( $pid, '_bkf_locked', true );
		if ( $locked && implode( ',', $locked ) !== $prev_locked ) {
			BKF_Log::add( 'locked', $pid, $r['isbn'], $r['title'], $locked );
		}
		if ( $locked ) {
			update_post_meta( $pid, '_bkf_locked', implode( ',', $locked ) );
		} else {
			delete_post_meta( $pid, '_bkf_locked' );
		}
		return $changed;
	}

	/** Marks an existing product as a feed title (nothing is written to it). */
	public static function link( int $pid, array $r ): bool {
		$p = wc_get_product( $pid );
		if ( ! $p || ( self::find_by_source( $r['id'] ) && self::find_by_source( $r['id'] ) !== $pid ) ) {
			return false;
		}
		update_post_meta( $pid, '_bkf_source', self::SOURCE );
		update_post_meta( $pid, '_bkf_source_id', $r['id'] );
		update_post_meta( $pid, '_bkf_linked', '1' );
		delete_post_meta( $pid, '_bkf_hash' );
		self::write_meta( $pid, $r );
		BKF_Log::add( 'link', $pid, $r['isbn'], $r['title'] );
		return true;
	}

	/** Removes every feed mark from a product. */
	public static function unlink( int $pid ): void {
		$title = get_the_title( $pid );
		$isbn  = (string) get_post_meta( $pid, '_bkf_isbn', true );
		foreach ( self::meta_keys() as $k ) {
			delete_post_meta( $pid, $k );
		}
		BKF_Log::add( 'unlink', $pid, $isbn, (string) $title );
	}

	/** Every product meta key of the plugin. */
	public static function meta_keys(): array {
		return array( '_bkf_source', '_bkf_source_id', '_bkf_isbn', '_bkf_synced', '_bkf_updated', '_bkf_hash', '_bkf_attr_keys', '_bkf_rel_isbn', '_bkf_rule', '_bkf_linked', '_bkf_gone', '_bkf_gone_status', '_bkf_cover_src', '_bkf_back_src', '_bkf_feed_price', '_bkf_ptype', '_bkf_fixed_until', '_bkf_locked', '_bkf_img_fail', '_bkf_pub_price', '_bkf_pub_at', '_bkf_pub_note', '_bkf_pub_url', '_bkf_pub_cover_src', '_bkf_pub_cover_no', '_bkf_pub_cat' );
	}

	/** A title that left the feed. */
	public static function gone( int $pid ): void {
		if ( '1' === (string) get_post_meta( $pid, '_bkf_gone', true ) ) {
			return;
		}
		$p = wc_get_product( $pid );
		if ( ! $p ) {
			return;
		}
		$s = BKF_Settings::get();
		update_post_meta( $pid, '_bkf_gone', '1' );
		if ( '1' !== (string) get_post_meta( $pid, '_bkf_linked', true ) ) {
			if ( 'draft' === $s['gone'] ) {
				update_post_meta( $pid, '_bkf_gone_status', $p->get_status() );
				$p->set_status( 'draft' );
			} elseif ( ! $p->is_type( 'external' ) && ! $p->get_manage_stock() ) {
				$p->set_stock_status( 'outofstock' );
			}
			$p->save();
			self::$changed[] = $pid;
		}
		BKF_Log::add( 'gone', $pid, (string) get_post_meta( $pid, '_bkf_isbn', true ), $p->get_name() );
	}

	/** Ids changed in this request; clears the list. */
	public static function take_changed(): array {
		$ids           = array_values( array_unique( array_map( 'intval', self::$changed ) ) );
		self::$changed = array();
		return $ids;
	}

	/* =====================================================================
	 * Field writers
	 * =================================================================== */

	/** Writes the given fields from the record (images excluded: write_images). */
	private static function write( WC_Product $p, array $r, array $fields, bool $external ): void {
		$s = BKF_Settings::get();
		foreach ( $fields as $f ) {
			switch ( $f ) {
				case 'title':
					$p->set_name( self::title( $r ) );
					break;
				case 'desc':
					if ( $s['descriptions'] ) {
						$p->set_description( wp_kses_post( $r['desc'] ) );
					}
					break;
				case 'short':
					if ( $s['descriptions'] ) {
						$p->set_short_description( '' === $r['short'] ? '' : wpautop( esc_html( $r['short'] ) ) );
					}
					break;
				case 'price':
					$price = self::price( $r );
					if ( null !== $price ) {
						$p->set_regular_price( $price );
					}
					break;
				case 'stock':
					if ( ! $external && ! $p->get_manage_stock() && '' !== $r['status'] ) {
						$p->set_stock_status( BKF_Settings::stock_for( $r['status'] ) );
					}
					break;
				case 'tax':
					if ( null !== $r['vat'] ) {
						$class = BKF_Settings::tax_class_for( (float) $r['vat'] );
						if ( null !== $class ) {
							$p->set_tax_class( $class );
						}
					}
					break;
				case 'dims':
					// No trailing zeros: "600 g", not "600.000" (Chris, 2026-10-19).
					if ( $r['weight'] > 0 ) {
						$p->set_weight( (string) wc_format_decimal( wc_get_weight( $r['weight'], get_option( 'woocommerce_weight_unit', 'kg' ), 'g' ), 3, true ) );
					}
					$unit = get_option( 'woocommerce_dimension_unit', 'cm' );
					if ( $r['height'] > 0 ) {
						$p->set_height( (string) wc_format_decimal( wc_get_dimension( $r['height'], $unit, 'cm' ), 2, true ) );
					}
					if ( $r['width'] > 0 ) {
						$p->set_width( (string) wc_format_decimal( wc_get_dimension( $r['width'], $unit, 'cm' ), 2, true ) );
					}
					break;
				case 'attrs':
					self::write_attributes( $p, $r );
					break;
			}
		}
	}

	/** Product title: the title, with the subtitle after ": " when set so. */
	public static function title( array $r ): string {
		$s = BKF_Settings::get();
		if ( 'title' === $s['subtitle'] && '' !== $r['subtitle'] ) {
			return $r['title'] . ': ' . $r['subtitle'];
		}
		return $r['title'];
	}

	/** Regular price as the store keeps it (the feed's price includes VAT), or null. */
	public static function price( array $r ): ?string {
		$gross = isset( $r['pub_price'] ) && null !== $r['pub_price'] ? $r['pub_price'] : $r['price'];
		if ( null === $gross || $gross <= 0 ) {
			return null;
		}
		$price = (float) $gross;
		if ( wc_tax_enabled() && ! wc_prices_include_tax() && null !== $r['vat'] && $r['vat'] > 0 ) {
			$price = $price / ( 1 + $r['vat'] / 100 );
		}
		return (string) wc_format_decimal( $price, wc_get_price_decimals() );
	}

	/**
	 * The record with pub_price (float|null) and pub_why: the publisher's
	 * price when one is set up, else null. When the page cannot be read
	 * now, a price read within the last 7 days still counts.
	 */
	private static function with_pub_price( array $r, int $pid ): array {
		$r['pub_price'] = null;
		$r['pub_why']   = '';
		$r['pub_cover'] = '';
		$r['pub_none']  = false;
		if ( '' === BKF_Pub_Price::template( $r ) ) {
			return $r;
		}
		$why = '';
		$p   = BKF_Pub_Price::price( $r, $why );
		$s   = BKF_Settings::get();
		if ( $s['images'] && $s['pub_cover'] ) {
			$r['pub_cover'] = BKF_Pub_Price::cover( $r );
		}
		$r['pub_none'] = BKF_Pub_Price::no_price( $r );
		// The last good price covers a page that could not be read or gave
		// an implausible price, not a page that answered without a price
		// for this book (1.4.2).
		if ( null === $p && $pid && ! $r['pub_none'] ) {
			$last = (string) get_post_meta( $pid, '_bkf_pub_price', true );
			$at   = (int) get_post_meta( $pid, '_bkf_pub_at', true );
			if ( is_numeric( $last ) && $at > time() - 7 * DAY_IN_SECONDS && BKF_Pub_Price::plausible( (float) $last, $r['price'] ) ) {
				$p = (float) $last;
			}
		}
		$r['pub_price'] = $p;
		$r['pub_why']   = $why;
		return $r;
	}

	/** Keeps the publisher's price on the product and writes the history when it changes. */
	private static function note_pub_price( int $pid, array $r ): void {
		if ( ! array_key_exists( 'pub_price', $r ) || '' === BKF_Pub_Price::template( $r ) ) {
			return;
		}
		$prev = (string) get_post_meta( $pid, '_bkf_pub_price', true );
		$note = (string) get_post_meta( $pid, '_bkf_pub_note', true );
		if ( null !== $r['pub_price'] ) {
			$now = (string) $r['pub_price'];
			if ( '' === $r['pub_why'] ) {
				update_post_meta( $pid, '_bkf_pub_at', time() );
			}
			if ( $now !== $prev && ( null === $r['price'] || abs( $r['pub_price'] - $r['price'] ) > 0.004 ) ) {
				/* translators: 1: catalogue price, 2: publisher price */
				BKF_Log::add( 'price', $pid, $r['isbn'], $r['title'], array( 'price' ), sprintf( __( 'Κατάλογος %1$s → εκδότης %2$s', 'book-feed' ), null === $r['price'] ? '—' : BKF_Pub_Price::money( (float) $r['price'] ), BKF_Pub_Price::money( (float) $now ) ) );
			}
			update_post_meta( $pid, '_bkf_pub_price', $now );
		} elseif ( ! empty( $r['pub_none'] ) ) {
			// The page answered without a price: the old one is not shown any more.
			delete_post_meta( $pid, '_bkf_pub_price' );
			delete_post_meta( $pid, '_bkf_pub_at' );
		}
		if ( '' !== $r['pub_why'] && $r['pub_why'] !== $note ) {
			BKF_Log::add( 'error', $pid, $r['isbn'], $r['title'], array( 'price' ), $r['pub_why'] );
		}
		update_post_meta( $pid, '_bkf_pub_note', $r['pub_why'] );
	}

	/** Categories at import: the rule's, the mapped ones, the default. */
	private static function category_ids( array $r, int $rule_cat ): array {
		if ( $rule_cat > 0 && term_exists( $rule_cat, 'product_cat' ) ) {
			return array( $rule_cat );
		}
		$s   = BKF_Settings::get();
		$ids = array();
		foreach ( array_merge( array( $r['cat_main'] ), $r['cats'] ) as $c ) {
			if ( isset( $s['cats'][ $c ] ) ) {
				$ids[] = (int) $s['cats'][ $c ];
			}
		}
		$ids = array_values( array_unique( $ids ) );
		if ( ! $ids && $s['default_cat'] ) {
			$ids = array( (int) $s['default_cat'] );
		}
		if ( $s['cat_parents'] ) {
			$all = array();
			foreach ( $ids as $id ) {
				$all = array_merge( $all, array_reverse( array_map( 'intval', get_ancestors( $id, 'product_cat', 'taxonomy' ) ) ), array( $id ) );
			}
			$ids = array_values( array_unique( $all ) );
		}
		return $ids;
	}

	/** The publisher as a brand (import only, when set so). */
	private static function brand( int $pid, array $r ): void {
		$s    = BKF_Settings::get();
		$name = self::publisher( $r );
		if ( 'brand' !== $s['targets']['publisher'] || '' === $name || ! taxonomy_exists( 'product_brand' ) ) {
			return;
		}
		$term = self::term( $name, 'product_brand' );
		if ( $term ) {
			wp_set_object_terms( $pid, array( $term ), 'product_brand', false );
		}
	}

	/** The publisher's subcategory (setting pub_cat), added once per title. */
	private static function publisher_cat( int $pid, array $r ): void {
		$s      = BKF_Settings::get();
		$parent = (int) $s['pub_cat'];
		$name   = self::publisher( $r );
		if ( $parent <= 0 || '' === $name || '' !== (string) get_post_meta( $pid, '_bkf_pub_cat', true ) || ! term_exists( $parent, 'product_cat' ) ) {
			return;
		}
		$cat = self::publisher_term( $name, $parent );
		if ( ! $cat ) {
			return;
		}
		$ids = array( $cat );
		if ( $s['cat_parents'] ) {
			$ids = array_merge( $ids, array_map( 'intval', get_ancestors( $cat, 'product_cat', 'taxonomy' ) ) );
		}
		$res = wp_set_object_terms( $pid, $ids, 'product_cat', true );
		if ( is_wp_error( $res ) ) {
			return;
		}
		update_post_meta( $pid, '_bkf_pub_cat', $cat );
		self::$changed[] = $pid;
	}

	/** The publisher's subcategory under $parent, created when missing; 0 on failure. */
	public static function publisher_term( string $name, int $parent ): int {
		$found = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'parent'     => $parent,
				'hide_empty' => false,
				'fields'     => 'ids',
				'number'     => 1,
				// meta_query, not meta_key: WooCommerce takes meta_key over for its category order.
				'meta_query' => array( array( 'key' => 'bkf_publisher', 'value' => $name ) ), // phpcs:ignore WordPress.DB.SlowDBQuery -- one subcategory per import.
			)
		);
		$id = ( is_array( $found ) && $found ) ? (int) $found[0] : 0;
		if ( ! $id ) {
			// One made by hand with the same name.
			$found = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'parent'     => $parent,
					'hide_empty' => false,
					'name'       => $name,
					'fields'     => 'ids',
					'number'     => 1,
				)
			);
			$id = ( is_array( $found ) && $found ) ? (int) $found[0] : 0;
		}
		if ( ! $id ) {
			$res = wp_insert_term( $name, 'product_cat', array( 'parent' => $parent ) );
			if ( is_wp_error( $res ) ) {
				$id = (int) $res->get_error_data( 'term_exists' );
			} else {
				$id = (int) $res['term_id'];
			}
		}
		if ( ! $id ) {
			return 0;
		}
		update_term_meta( $id, 'bkf_publisher', $name );
		$brand = taxonomy_exists( 'product_brand' ) ? get_term_by( 'name', $name, 'product_brand' ) : false;
		if ( $brand ) {
			self::logo_to_cat( $id, (int) get_term_meta( $brand->term_id, 'thumbnail_id', true ) );
		}
		return $id;
	}

	/**
	 * The brand's logo as the category image: when the category has none,
	 * or still shows the logo copied before (an image chosen by hand stays).
	 */
	private static function logo_to_cat( int $cat, int $att ): void {
		if ( $att <= 0 ) {
			return;
		}
		$now    = (int) get_term_meta( $cat, 'thumbnail_id', true );
		$copied = (int) get_term_meta( $cat, 'bkf_logo', true );
		if ( $now > 0 && $now !== $copied ) {
			return;
		}
		update_term_meta( $cat, 'thumbnail_id', $att );
		update_term_meta( $cat, 'bkf_logo', $att );
	}

	/** A brand's logo was set or changed: the publisher subcategories of that name follow. */
	public static function brand_logo( $meta_id, $term_id, $key, $value ): void {
		if ( 'thumbnail_id' !== $key ) {
			return;
		}
		$term = get_term( (int) $term_id );
		if ( ! $term instanceof WP_Term || 'product_brand' !== $term->taxonomy ) {
			return;
		}
		$cats = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'fields'     => 'ids',
				'meta_query' => array( array( 'key' => 'bkf_publisher', 'value' => $term->name ) ), // phpcs:ignore WordPress.DB.SlowDBQuery -- on a brand logo change only.
			)
		);
		$cats   = is_array( $cats ) ? array_map( 'intval', $cats ) : array();
		$parent = (int) BKF_Settings::get()['pub_cat'];
		if ( $parent > 0 ) {
			// A publisher subcategory made by hand, before the plugin met it.
			$same = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'parent'     => $parent,
					'name'       => $term->name,
					'hide_empty' => false,
					'fields'     => 'ids',
				)
			);
			$cats = array_unique( array_merge( $cats, is_array( $same ) ? array_map( 'intval', $same ) : array() ) );
		}
		foreach ( $cats as $cat ) {
			self::logo_to_cat( (int) $cat, (int) $value );
		}
	}

	/** The publisher's name as the store writes it. */
	public static function publisher( array $r ): string {
		return BKF_Settings::renamed( '' !== $r['pub_name'] ? $r['pub_name'] : $r['imprint'] );
	}

	/** Values through the rename table, empty and dropped ones out, no duplicates. */
	public static function renamed( array $values ): array {
		$out = array();
		foreach ( $values as $v ) {
			$v = BKF_Settings::renamed( (string) $v );
			if ( '' !== $v && ! in_array( $v, $out, true ) ) {
				$out[] = $v;
			}
		}
		return $out;
	}

	/** The feed's categories, main one first. */
	public static function genres( array $r ): array {
		return self::renamed( array_merge( array( $r['cat_main'] ), $r['cats'] ) );
	}

	/** Names of the authors: the contributors whose role reads "author", else those of the first role. */
	public static function authors( array $r ): array {
		$by_role = array();
		foreach ( $r['contribs'] as $c ) {
			$by_role[ $c['role'] ][] = $c['name'];
		}
		foreach ( $by_role as $role => $names ) {
			if ( preg_match( '/συγγραφ|author|writer/iu', (string) $role ) ) {
				return $names;
			}
		}
		return $by_role ? reset( $by_role ) : array();
	}

	/** Product tags on import (when set so). */
	private static function tags( int $pid, array $r ): void {
		$s = BKF_Settings::get();
		if ( '' === $s['tags'] ) {
			return;
		}
		$names = 'cats' === $s['tags'] ? self::genres( $r ) : self::renamed( array_merge( array( $r['class_main'] ), $r['class'] ) );
		$ids   = array();
		foreach ( array_slice( $names, 0, $s['tags_max'] ) as $name ) {
			$id = self::term( $name, 'product_tag' );
			if ( $id ) {
				$ids[] = $id;
			}
		}
		if ( $ids ) {
			wp_set_object_terms( $pid, $ids, 'product_tag', false );
		}
	}

	/** An image template filled in from the record. */
	public static function fill( string $tpl, array $r ): string {
		$vals = array(
			$r['title'],
			$r['subtitle'],
			implode( ', ', self::authors( $r ) ),
			self::publisher( $r ),
			$r['isbn'],
		);
		$out = str_replace( BKF_Settings::IMG_TAGS, $vals, $tpl );
		// Separators left over by empty placeholders.
		$out = preg_replace( '/\s*(«\s*»|"\s*")\s*/u', ' ', $out );
		$out = preg_replace( '/(\s*[,\-]\s*){2,}/u', '$1', $out );
		$out = preg_replace( '/\s+([.,;:!?»)])/u', '$1', preg_replace( '/\s+/u', ' ', $out ) );
		return trim( $out, " ,-\t" );
	}

	/** Greek in Latin letters for file names (when Greeklish Permalinks is not there). */
	public static function latin( string $text ): string {
		$text = BKF_Client::fold( $text );
		$text = strtr(
			$text,
			array(
				'ου' => 'ou',
				'αυ' => 'av',
				'ευ' => 'ev',
				'μπ' => 'mp',
				'ντ' => 'nt',
				'γκ' => 'gk',
				'α'  => 'a',
				'β'  => 'v',
				'γ'  => 'g',
				'δ'  => 'd',
				'ε'  => 'e',
				'ζ'  => 'z',
				'η'  => 'i',
				'θ'  => 'th',
				'ι'  => 'i',
				'κ'  => 'k',
				'λ'  => 'l',
				'μ'  => 'm',
				'ν'  => 'n',
				'ξ'  => 'x',
				'ο'  => 'o',
				'π'  => 'p',
				'ρ'  => 'r',
				'σ'  => 's',
				'ς'  => 's',
				'τ'  => 't',
				'υ'  => 'y',
				'φ'  => 'f',
				'χ'  => 'ch',
				'ψ'  => 'ps',
				'ω'  => 'o',
			)
		);
		return remove_accents( $text );
	}

		/** Term id by name in a taxonomy, created when missing; 0 on failure. */
	private static function term( string $name, string $tax ): int {
		$t = get_term_by( 'name', $name, $tax );
		if ( $t ) {
			return (int) $t->term_id;
		}
		$res = wp_insert_term( $name, $tax );
		if ( is_wp_error( $res ) ) {
			$id = $res->get_error_data( 'term_exists' );
			return $id ? (int) $id : 0;
		}
		return (int) $res['term_id'];
	}

	/** Labels of local attributes, in the store's language. */
	private static function label( string $key ): string {
		$el     = 0 === strpos( strtolower( get_locale() ), 'el' );
		$labels = array(
			'publisher' => array( 'Εκδότης', 'Publisher' ),
			'year'      => array( 'Έτος έκδοσης', 'Publication year' ),
			'pages'     => array( 'Σελίδες', 'Pages' ),
			'language'  => array( 'Γλώσσα', 'Language' ),
			'series'    => array( 'Σειρά', 'Series' ),
			'form'      => array( 'Μορφή', 'Format' ),
			'isbn'      => array( 'ISBN', 'ISBN' ),
			'kind'      => array( 'Είδος', 'Kind' ),
			'genre'     => array( 'Κατηγορία', 'Category' ),
			'subtitle'  => array( 'Υπότιτλος', 'Subtitle' ),
			'contrib'   => array( 'Συντελεστές', 'Contributors' ),
		);
		return isset( $labels[ $key ] ) ? $labels[ $key ][ $el ? 0 : 1 ] : $key;
	}

	/** Our attribute items: target => { label, values[] } (merged per target). */
	public static function attribute_items( array $r ): array {
		$s     = BKF_Settings::get();
		$t     = $s['targets'];
		$items = array();
		$add   = static function ( string $target, string $label, array $values ) use ( &$items ) {
			$values = array_values( array_filter( array_map( 'strval', $values ), 'strlen' ) );
			if ( '' === $target || 'brand' === $target || ! $values ) {
				return;
			}
			$key = 'local' === $target ? 'local:' . $label : $target;
			if ( ! isset( $items[ $key ] ) ) {
				$items[ $key ] = array(
					'target' => $target,
					'label'  => $label,
					'values' => array(),
				);
			}
			$items[ $key ]['values'] = array_values( array_unique( array_merge( $items[ $key ]['values'], $values ) ) );
		};

		// ISBN first, then contributors in the feed's order of roles.
		$add( $t['isbn'], self::label( 'isbn' ), array( $r['isbn'] ) );
		$by_role = array();
		foreach ( $r['contribs'] as $c ) {
			$by_role[ $c['role'] ][] = $c['name'];
		}
		foreach ( $by_role as $role => $names ) {
			$role = (string) $role;
			$add( BKF_Settings::role_target( $role ), '' === $role ? self::label( 'contrib' ) : $role, $names );
		}
		if ( 'attr' === $s['subtitle'] ) {
			$add( $t['subtitle'], self::label( 'subtitle' ), array( $r['subtitle'] ) );
		}
		$add( $t['genre'], self::label( 'genre' ), self::genres( $r ) );
		$add( $t['kind'], self::label( 'kind' ), self::renamed( array( $r['kind'] ) ) );
		$add( $t['year'], self::label( 'year' ), array( $r['year'] > 0 ? (string) $r['year'] : '' ) );
		$add( $t['pages'], self::label( 'pages' ), array( $r['pages'] > 0 ? (string) $r['pages'] : '' ) );
		$add( $t['form'], self::label( 'form' ), self::renamed( array( $r['form'] ) ) );
		$add( $t['language'], self::label( 'language' ), self::renamed( $r['language'] ) );
		$series = self::renamed( array( $r['series'] ) );
		$add( $t['series'], self::label( 'series' ), $series ? array( $series[0] . ( '' !== $r['series_no'] ? ' #' . $r['series_no'] : '' ) ) : array() );
		$add( $t['publisher'], self::label( 'publisher' ), array( self::publisher( $r ) ) );
		return self::ordered( $items );
	}

	/**
	 * Items in the order the settings give, by the name the attribute has
	 * on the product; unlisted ones keep their place after the listed.
	 */
	private static function ordered( array $items ): array {
		$order = array();
		foreach ( BKF_Settings::get()['order'] as $i => $name ) {
			$order[ self::fold( $name ) ] = $i;
		}
		if ( ! $order ) {
			return $items;
		}
		$n    = count( $order );
		$keys = array();
		$i    = 0;
		foreach ( $items as $k => $item ) {
			$name       = 'local' === $item['target'] ? $item['label'] : wc_attribute_label( $item['target'] );
			$f          = self::fold( $name );
			$keys[ $k ] = array( isset( $order[ $f ] ) ? $order[ $f ] : $n, $i++ );
		}
		uksort(
			$items,
			static function ( $a, $b ) use ( $keys ) {
				return $keys[ $a ] <=> $keys[ $b ];
			}
		);
		return $items;
	}

	/** Lower case, no accents (Greek too): for comparing names typed by hand. */
	private static function fold( string $s ): string {
		return remove_accents( BKF_Client::fold( trim( $s ) ) );
	}

	/** Replaces our attributes on the product, keeping every other one. */
	private static function write_attributes( WC_Product $p, array $r ): void {
		$pid   = $p->get_id();
		$ours  = $pid ? json_decode( (string) get_post_meta( $pid, '_bkf_attr_keys', true ), true ) : array();
		$ours  = is_array( $ours ) ? $ours : array();
		$attrs = $p->get_attributes();
		foreach ( $ours as $k ) {
			unset( $attrs[ $k ] );
		}

		$keys     = array();
		$position = count( $attrs );
		foreach ( self::attribute_items( $r ) as $item ) {
			$a = new WC_Product_Attribute();
			if ( 'local' === $item['target'] ) {
				$key = sanitize_title( $item['label'] );
				if ( isset( $attrs[ $key ] ) ) {
					continue; // a local attribute of the same name that is not ours
				}
				$a->set_id( 0 );
				$a->set_name( $item['label'] );
				$a->set_options( $item['values'] );
			} else {
				$key = $item['target'];
				$tid = wc_attribute_taxonomy_id_by_name( $key );
				if ( ! $tid || ! taxonomy_exists( $key ) ) {
					continue;
				}
				$terms = array();
				foreach ( $item['values'] as $v ) {
					$id = self::term( $v, $key );
					if ( $id ) {
						$terms[] = $id;
					}
				}
				if ( ! $terms ) {
					continue;
				}
				$a->set_id( $tid );
				$a->set_name( $key );
				$a->set_options( $terms );
			}
			$a->set_position( $position++ );
			$a->set_visible( true );
			$a->set_variation( false );
			$attrs[ $key ] = $a;
			$keys[]        = $key;
		}
		$p->set_attributes( $attrs );
		$p->update_meta_data( '_bkf_attr_keys', wp_json_encode( $keys ) );
	}

	/** Record data kept as meta (not shown, not locked). */
	private static function write_meta( int $pid, array $r ): void {
		update_post_meta( $pid, '_bkf_isbn', $r['isbn_digits'] );
		update_post_meta( $pid, '_bkf_synced', time() );
		update_post_meta( $pid, '_bkf_updated', (int) $r['updated'] );
		update_post_meta( $pid, '_bkf_rel_isbn', wp_json_encode( $r['rel_isbn'] ) );
		update_post_meta( $pid, '_bkf_feed_price', null === $r['price'] ? '' : (string) $r['price'] );
		update_post_meta( $pid, '_bkf_ptype', $r['ptype'] );
		update_post_meta( $pid, '_bkf_fixed_until', $r['valid_until'] );
		update_post_meta( $pid, '_bkf_pub_status', $r['status'] );
	}

	/** The URL of an external product from a template ({isbn}, {isbn13}, {title}). */
	public static function external_url( string $tpl, array $r ): string {
		$url = strtr(
			$tpl,
			array(
				'{isbn}'   => rawurlencode( $r['isbn'] ),
				'{isbn13}' => rawurlencode( $r['isbn_digits'] ),
				'{title}'  => rawurlencode( $r['title'] ),
			)
		);
		return esc_url_raw( $url, array( 'http', 'https' ) );
	}

	/* =====================================================================
	 * Images
	 * =================================================================== */

	/**
	 * Cover (and back cover) into the media library. True when the
	 * product's image ids changed.
	 */
	private static function write_images( int $pid, array $r, bool $force = false ): bool {
		$s = BKF_Settings::get();
		if ( ! $s['images'] ) {
			return false;
		}
		$p       = wc_get_product( $pid );
		$changed = false;
		// Addresses that failed once are not downloaded again on every
		// sync (one error in the history, not one a day), unless forced.
		$failed  = $force ? array() : array_filter( explode( "\n", (string) get_post_meta( $pid, '_bkf_img_fail', true ) ) );
		$fail    = array();

		// A cover taken from the publisher's page stays until a full update.
		$from_pub = '' !== (string) get_post_meta( $pid, '_bkf_pub_cover_src', true ) && $p->get_image_id();
		if ( '' !== $r['cover'] && ! in_array( $r['cover'], $failed, true ) && ( $force || ! $from_pub ) && ( $force || (string) get_post_meta( $pid, '_bkf_cover_src', true ) !== $r['cover'] || ! $p->get_image_id() ) ) {
			$att = self::sideload( $r['cover'], $pid, $r, '' );
			if ( ! $att ) {
				$fail[] = $r['cover'];
			} else {
				$p->set_image_id( $att );
				update_post_meta( $pid, '_bkf_cover_src', $r['cover'] );
				delete_post_meta( $pid, '_bkf_pub_cover_src' );
				$changed = true;
			}
		}
		if ( self::pub_cover( $pid, $p, $r, $force ) ) {
			$changed = true;
		}
		if ( $s['back_cover'] && '' !== $r['back'] && ! in_array( $r['back'], $failed, true ) && ( $force || (string) get_post_meta( $pid, '_bkf_back_src', true ) !== $r['back'] ) ) {
			$att = self::sideload( $r['back'], $pid, $r, '-back' );
			if ( ! $att ) {
				$fail[] = $r['back'];
			} else {
				$gallery = array_values( array_diff( $p->get_gallery_image_ids(), array( $att ) ) );
				array_unshift( $gallery, $att );
				$p->set_gallery_image_ids( $gallery );
				update_post_meta( $pid, '_bkf_back_src', $r['back'] );
				$changed = true;
			}
		}
		$keep = array_values( array_intersect( $failed, array( $r['cover'], $r['back'] ) ) );
		$fail = array_values( array_unique( array_merge( $keep, $fail ) ) );
		if ( $fail ) {
			update_post_meta( $pid, '_bkf_img_fail', implode( "\n", $fail ) );
		} else {
			delete_post_meta( $pid, '_bkf_img_fail' );
		}
		if ( $changed ) {
			$p->save();
		}
		return $changed;
	}

	/**
	 * The cover from the publisher's page (1.4.0), only when it has more
	 * pixels than the product's current image. A smaller one is deleted
	 * again and its address remembered, so it is not downloaded daily.
	 */
	private static function pub_cover( int $pid, WC_Product $p, array $r, bool $force ): bool {
		$url = isset( $r['pub_cover'] ) && is_string( $r['pub_cover'] ) ? $r['pub_cover'] : '';
		if ( '' === $url ) {
			return false;
		}
		$cur = (int) $p->get_image_id();
		if ( ! $force && $cur && ( $url === (string) get_post_meta( $pid, '_bkf_pub_cover_src', true ) || $url === (string) get_post_meta( $pid, '_bkf_pub_cover_no', true ) ) ) {
			return false;
		}
		self::$reused = false;
		$att          = self::sideload( $url, $pid, $r, '' );
		if ( ! $att ) {
			update_post_meta( $pid, '_bkf_pub_cover_no', $url );
			return false;
		}
		if ( $att === $cur ) {
			update_post_meta( $pid, '_bkf_pub_cover_src', $url );
			return false;
		}
		if ( self::pixels( $att ) > self::pixels( $cur ) ) {
			$p->set_image_id( $att );
			update_post_meta( $pid, '_bkf_pub_cover_src', $url );
			delete_post_meta( $pid, '_bkf_pub_cover_no' );
			return true;
		}
		if ( ! self::$reused ) {
			wp_delete_attachment( $att, true );
		}
		update_post_meta( $pid, '_bkf_pub_cover_no', $url );
		return false;
	}

	/** Width × height of an attachment image (0 when unknown). */
	private static function pixels( int $att ): int {
		if ( ! $att ) {
			return 0;
		}
		$m = wp_get_attachment_metadata( $att );
		if ( is_array( $m ) && ! empty( $m['width'] ) && ! empty( $m['height'] ) ) {
			return (int) $m['width'] * (int) $m['height'];
		}
		$file = get_attached_file( $att );
		$size = $file && is_readable( $file ) ? @getimagesize( $file ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return is_array( $size ) ? (int) $size[0] * (int) $size[1] : 0;
	}

	/** Downloads one image; attachment id or 0 (logged). */
	private static function sideload( string $url, int $pid, array $r, string $suffix ): int {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Same source already in the library: reuse it.
		$found = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'meta_key'       => '_bkf_cover_src', // phpcs:ignore WordPress.DB.SlowDBQuery -- one indexed lookup.
				'meta_value'     => $url, // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'         => 'ids',
				'posts_per_page' => 1,
			)
		);
		if ( $found ) {
			self::$reused = true;
			return (int) $found[0];
		}

		$tmp = download_url( $url, 10 );
		if ( is_wp_error( $tmp ) ) {
			BKF_Log::add( 'error', $pid, $r['isbn'], $r['title'], array( 'image' ), $tmp->get_error_message() );
			return 0;
		}
		$size = (int) @filesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$mime = function_exists( 'wp_get_image_mime' ) ? wp_get_image_mime( $tmp ) : '';
		$exts = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
		);
		if ( $size <= 0 || $size > self::MAX_IMAGE || ! isset( $exts[ $mime ] ) ) {
			wp_delete_file( $tmp );
			BKF_Log::add( 'error', $pid, $r['isbn'], $r['title'], array( 'image' ), __( 'Το εξώφυλλο δεν είναι εικόνα JPEG, PNG, GIF ή WebP έως 10 MB.', 'book-feed' ) );
			return 0;
		}

		$s    = BKF_Settings::get();
		$name = self::fill( $s['img_name'], $r );
		$base = function_exists( 'glp_convert' ) ? glp_convert( $name, 'slug' ) : self::latin( $name );
		$base = sanitize_title( $base );
		if ( '' === $base || false !== strpos( $base, '%' ) ) {
			$base = 'book' . ( '' !== $r['isbn_digits'] ? '-' . $r['isbn_digits'] : '' );
		}
		$base = rtrim( substr( $base, 0, 120 ), '-' ) . $suffix;

		$file = array(
			'name'     => sanitize_file_name( $base . '.' . $exts[ $mime ] ),
			'tmp_name' => $tmp,
		);
		$att  = media_handle_sideload( $file, $pid, $r['title'] );
		if ( is_wp_error( $att ) ) {
			wp_delete_file( $tmp );
			BKF_Log::add( 'error', $pid, $r['isbn'], $r['title'], array( 'image' ), $att->get_error_message() );
			return 0;
		}
		$alt = self::fill( $s['img_alt'], $r );
		update_post_meta( $att, '_wp_attachment_image_alt', '' !== $alt ? $alt : $r['title'] );
		update_post_meta( $att, '_bkf_cover_src', $url );
		return (int) $att;
	}

	/* =====================================================================
	 * Field hashes (locks)
	 * =================================================================== */

	/** The stored hashes: field => md5. */
	public static function hashes( int $pid ): array {
		$h = json_decode( (string) get_post_meta( $pid, '_bkf_hash', true ), true );
		$out = array();
		foreach ( is_array( $h ) ? $h : array() as $k => $v ) {
			if ( in_array( $k, BKF_Settings::SYNC_FIELDS, true ) && is_string( $v ) ) {
				$out[ $k ] = $v;
			}
		}
		return $out;
	}

	/** Stores the hashes of the fields as they are on the product now. */
	private static function store_hashes( WC_Product $p, array $fields, array $keep = array() ): void {
		$h = $keep;
		foreach ( $fields as $f ) {
			if ( in_array( $f, BKF_Settings::SYNC_FIELDS, true ) ) {
				$h[ $f ] = self::hash_of( $p, $f );
			}
		}
		update_post_meta( $p->get_id(), '_bkf_hash', wp_json_encode( $h ) );
	}

	/** Fields that were edited by hand (locked), from the stored hashes. */
	public static function locked_fields( int $pid ): array {
		$p = wc_get_product( $pid );
		if ( ! $p ) {
			return array();
		}
		$out = array();
		foreach ( self::hashes( $pid ) as $f => $h ) {
			if ( ! hash_equals( $h, self::hash_of( $p, $f ) ) ) {
				$out[] = $f;
			}
		}
		return $out;
	}

	public static function hash_of( WC_Product $p, string $f ): string {
		return md5( (string) wp_json_encode( self::value_of( $p, $f ) ) );
	}

	/** The current value of a field on the product (for hashes and change detection). */
	public static function value_of( WC_Product $p, string $f ) {
		switch ( $f ) {
			case 'title':
				return $p->get_name( 'edit' );
			case 'desc':
				return $p->get_description( 'edit' );
			case 'short':
				return $p->get_short_description( 'edit' );
			case 'price':
				return (string) $p->get_regular_price( 'edit' );
			case 'stock':
				return (string) $p->get_stock_status( 'edit' );
			case 'tax':
				return (string) $p->get_tax_class( 'edit' );
			case 'dims':
				return array( (string) $p->get_weight( 'edit' ), (string) $p->get_height( 'edit' ), (string) $p->get_width( 'edit' ) );
			case 'image':
				return (int) $p->get_image_id( 'edit' );
			case 'attrs':
				$keys = json_decode( (string) $p->get_meta( '_bkf_attr_keys', true, 'edit' ), true );
				$out  = array();
				$all  = $p->get_attributes( 'edit' );
				foreach ( is_array( $keys ) ? $keys : array() as $k ) {
					if ( isset( $all[ $k ] ) && $all[ $k ] instanceof WC_Product_Attribute ) {
						$opts = array_map( 'strval', $all[ $k ]->get_options() );
						sort( $opts );
						$out[ $k ] = $opts;
					} else {
						$out[ $k ] = null;
					}
				}
				return $out;
		}
		return null;
	}
}
