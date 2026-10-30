<?php
/**
 * TFI_Importer — a Trade Feed record as a WooCommerce product.
 *
 *  import()   a new simple product, or an update when the title is
 *             already ours. A product with the same ISBN that is not ours
 *             is never duplicated: the result says "exists" and the admin
 *             can link it.
 *  update()   the sync. The commerce fields always follow the feed:
 *             regular price = the publisher's retail price (fixed book
 *             price; the sale price stays the shop's own and is never
 *             touched), availability (unless the shop counts its own
 *             stock for the product: then never touched), pre-order and
 *             release date, the trade price (meta, and the WooCommerce
 *             cost while it is still the one we wrote). Title,
 *             descriptions, cover, attributes and dimensions follow the
 *             feed only while they are still what the plugin wrote (a
 *             hash per field in _tfi_hash): a field edited by hand is
 *             locked and left as it is. Categories, brand, SKU / GTIN, tax
 *             class and status are set on import only.
 *  link()     marks an existing product (same ISBN) as this title: only
 *             the commerce fields follow the feed, its content stays.
 *  unlink()   removes every _tfi_* meta; the product stays as it is.
 *  gone()     a title the feed no longer lists: out of stock, or draft.
 *
 * Meta on products (all removed by unlink and by uninstall):
 *  _tfi_source (the publisher's host), _tfi_id (product id there),
 *  _tfi_isbn (digits), _tfi_synced, _tfi_hash, _tfi_locked,
 *  _tfi_attr_keys, _tfi_linked, _tfi_gone, _tfi_gone_status,
 *  _tfi_trade (trade price), _tfi_cost (the cost we wrote), _tfi_release,
 *  _tfi_por ('1' = the pre-order in Pre-Orders was set by us).
 * Attachments we download carry _tfi_img_src.
 */

defined( 'ABSPATH' ) || exit;

final class TFI_Importer {

	const MAX_IMAGE = 10485760; // 10 MB

	/** Ids changed during this request (for noxpress_products_changed). */
	private static $changed = array();

	/* =====================================================================
	 * Lookups
	 * =================================================================== */

	/**
	 * Our products by the publisher's product id: remote id => product id.
	 *
	 * @param int[] $ids Remote ids (empty = all of ours).
	 */
	public static function ours( array $ids = array() ): array {
		global $wpdb;
		$host = TFI_Settings::host();
		if ( '' === $host ) {
			return array();
		}
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		$sql = "SELECT s.post_id, i.meta_value AS rid FROM {$wpdb->postmeta} s
			 INNER JOIN {$wpdb->postmeta} i ON i.post_id = s.post_id AND i.meta_key = '_tfi_id'
			 INNER JOIN {$wpdb->posts} p ON p.ID = s.post_id
			 WHERE s.meta_key = '_tfi_source' AND s.meta_value = %s
			   AND p.post_type = 'product' AND p.post_status <> 'trash'";
		$args = array( $host );
		if ( $ids ) {
			$sql .= ' AND i.meta_value IN (' . implode( ',', array_fill( 0, count( $ids ), '%s' ) ) . ')';
			$args = array_merge( $args, array_map( 'strval', $ids ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY s.post_id ASC', $args ) );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			if ( ! isset( $out[ (int) $row->rid ] ) ) {
				$out[ (int) $row->rid ] = (int) $row->post_id;
			}
		}
		return $out;
	}

	/**
	 * Any product (ours or not) by ISBN in GTIN, SKU or our meta: digits => id.
	 *
	 * @param string[] $digits Codes, digits only.
	 */
	public static function isbn_map( array $digits ): array {
		global $wpdb;
		$digits = array_values( array_unique( array_filter( array_map( 'strval', $digits ) ) ) );
		if ( ! $digits ) {
			return array();
		}
		$in = implode( ',', array_fill( 0, count( $digits ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id, UPPER(REPLACE(REPLACE(pm.meta_value,'-',''),' ','')) AS d FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key IN ('_global_unique_id','_sku','_tfi_isbn')
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
	 * Where each record stands in this shop: remote id => { state: 'ours' |
	 * 'exists' | 'new', pid }.
	 */
	public static function lookup( array $records ): array {
		$ours = self::ours( array_column( $records, 'id' ) );
		$isbn = self::isbn_map( array_column( $records, 'isbn_digits' ) );
		$out  = array();
		foreach ( $records as $r ) {
			if ( isset( $ours[ $r['id'] ] ) ) {
				$out[ $r['id'] ] = array( 'state' => 'ours', 'pid' => $ours[ $r['id'] ] );
			} elseif ( '' !== $r['isbn_digits'] && isset( $isbn[ $r['isbn_digits'] ] ) ) {
				$out[ $r['id'] ] = array( 'state' => 'exists', 'pid' => $isbn[ $r['isbn_digits'] ] );
			} else {
				$out[ $r['id'] ] = array( 'state' => 'new', 'pid' => 0 );
			}
		}
		return $out;
	}

	/* =====================================================================
	 * Import, update, link
	 * =================================================================== */

	/**
	 * @return array { result: 'created' | 'updated' | 'unchanged' | 'exists' | 'error', pid, message? }
	 */
	public static function import( array $r ): array {
		$ours = self::ours( array( $r['id'] ) );
		if ( isset( $ours[ $r['id'] ] ) ) {
			return self::update( $ours[ $r['id'] ], $r );
		}
		if ( '' !== $r['isbn_digits'] ) {
			$m = self::isbn_map( array( $r['isbn_digits'] ) );
			if ( isset( $m[ $r['isbn_digits'] ] ) ) {
				return array(
					'result' => 'exists',
					'pid'    => $m[ $r['isbn_digits'] ],
				);
			}
		}
		try {
			$pid = self::create( $r );
		} catch ( \Throwable $e ) {
			TFI_Log::add( 'error', 0, $r['isbn'], $r['title'], array(), $e->getMessage() );
			return array(
				'result'  => 'error',
				'pid'     => 0,
				'message' => $e->getMessage(),
			);
		}
		TFI_Log::add( 'import', $pid, $r['isbn'], $r['title'] );
		return array(
			'result' => 'created',
			'pid'    => $pid,
		);
	}

	/** Creates the product, every field written and hashed. */
	private static function create( array $r ): int {
		$s = TFI_Settings::get();
		$p = new WC_Product_Simple();
		$p->set_status( $s['status'] );
		$p->set_catalog_visibility( 'visible' );
		$p->set_tax_class( self::tax_class_for( $r['vat'] ) );
		self::write( $p, $r, TFI_Settings::SYNC_FIELDS );
		self::commerce( $p, $r );
		self::codes( $p, $r );
		$p->set_category_ids( self::category_ids( $r ) );
		$pid = (int) $p->save();
		if ( $pid <= 0 ) {
			throw new RuntimeException( 'The product could not be saved.' );
		}

		update_post_meta( $pid, '_tfi_source', TFI_Settings::host() );
		update_post_meta( $pid, '_tfi_id', (string) $r['id'] );
		update_post_meta( $pid, '_tfi_isbn', $r['isbn_digits'] );
		update_post_meta( $pid, '_tfi_synced', time() );

		if ( $s['brand'] && '' !== $r['publisher'] && taxonomy_exists( 'product_brand' ) ) {
			$term = self::term( $r['publisher'], 'product_brand' );
			if ( $term ) {
				wp_set_object_terms( $pid, array( $term ), 'product_brand' );
			}
		}

		$p = wc_get_product( $pid );
		if ( $p ) {
			if ( self::write_image( $p, $r ) ) {
				$p->save();
			}
			self::store_hashes( $p, TFI_Settings::SYNC_FIELDS );
		}
		self::$changed[] = $pid;
		return $pid;
	}

	/**
	 * The sync of one product of ours.
	 *
	 * @param bool $force Take over every field again (locks cleared).
	 * @return array { result: 'updated' | 'unchanged' | 'error', pid, fields?, locked? }
	 */
	public static function update( int $pid, array $r, bool $force = false ): array {
		$p = wc_get_product( $pid );
		if ( ! $p || 'simple' !== $p->get_type() ) {
			return array(
				'result'  => 'error',
				'pid'     => $pid,
				'message' => 'Not a simple product.',
			);
		}
		$was_gone    = '1' === (string) get_post_meta( $pid, '_tfi_gone', true );
		$gone_status = (string) get_post_meta( $pid, '_tfi_gone_status', true );
		try {
			$before = self::snapshot( $p );
			$linked = '1' === (string) $p->get_meta( '_tfi_linked', true, 'edit' );
			$fields = array();
			$locked = array();
			if ( ! $linked || $force ) {
				$hash = $force ? array() : self::hashes( $pid );
				foreach ( TFI_Settings::SYNC_FIELDS as $f ) {
					if ( $force || ! isset( $hash[ $f ] ) || hash_equals( $hash[ $f ], self::hash_of( $p, $f ) ) ) {
						$fields[] = $f;
					} else {
						$locked[] = $f;
					}
				}
				$writable = array_diff( $fields, array( 'image' ) );
				self::write( $p, $r, $writable );
				if ( in_array( 'image', $fields, true ) ) {
					self::write_image( $p, $r );
				}
			}
			self::commerce( $p, $r );
			$p->delete_meta_data( '_tfi_gone' );
			$p->delete_meta_data( '_tfi_gone_status' );
			if ( $force ) {
				$p->delete_meta_data( '_tfi_linked' );
			}
			$changed = self::snapshot( $p ) !== $before || $was_gone;
			if ( $changed ) {
				$p->save();
			}
			if ( $was_gone && 'draft' === $gone_status && 'draft' === get_post_status( $pid ) ) {
				// It went to draft when it left the feed: back as it was.
				wp_update_post(
					array(
						'ID'          => $pid,
						'post_status' => TFI_Settings::get()['status'],
					)
				);
			}
			update_post_meta( $pid, '_tfi_synced', time() );
			if ( ! $linked || $force ) {
				// Fields we wrote get their new hash (from the product as saved,
				// after WordPress's own filters); locked ones keep the old one.
				$keep  = $force ? array() : array_intersect_key( self::hashes( $pid ), array_flip( $locked ) );
				$saved = $changed ? wc_get_product( $pid ) : $p;
				self::store_hashes( $saved ? $saved : $p, $fields, $keep );
			}
		} catch ( \Throwable $e ) {
			TFI_Log::add( 'error', $pid, $r['isbn'], $r['title'], array(), $e->getMessage() );
			return array(
				'result'  => 'error',
				'pid'     => $pid,
				'message' => $e->getMessage(),
			);
		}

		$prev_locked = (string) get_post_meta( $pid, '_tfi_locked', true );
		if ( $locked && implode( ',', $locked ) !== $prev_locked ) {
			TFI_Log::add( 'locked', $pid, $r['isbn'], $r['title'], $locked );
		}
		if ( $locked ) {
			update_post_meta( $pid, '_tfi_locked', implode( ',', $locked ) );
		} else {
			delete_post_meta( $pid, '_tfi_locked' );
		}

		if ( $changed ) {
			self::$changed[] = $pid;
			TFI_Log::add( $was_gone ? 'back' : 'update', $pid, $r['isbn'], $r['title'] );
		}
		return array(
			'result' => $changed ? 'updated' : 'unchanged',
			'pid'    => $pid,
			'locked' => $locked,
		);
	}

	/** Links an existing product to this title: commerce fields follow the feed from now on. */
	public static function link( int $pid, array $r ): bool {
		$p = wc_get_product( $pid );
		if ( ! $p || 'simple' !== $p->get_type() || null !== tfi_get_source( $pid ) ) {
			return false;
		}
		update_post_meta( $pid, '_tfi_source', TFI_Settings::host() );
		update_post_meta( $pid, '_tfi_id', (string) $r['id'] );
		update_post_meta( $pid, '_tfi_isbn', $r['isbn_digits'] );
		update_post_meta( $pid, '_tfi_linked', '1' );
		TFI_Log::add( 'link', $pid, $r['isbn'], $r['title'] );
		self::update( $pid, $r );
		return true;
	}

	/** Removes every _tfi_* meta; the product stays as it is. */
	public static function unlink( int $pid ): void {
		$isbn  = (string) get_post_meta( $pid, '_tfi_isbn', true );
		$title = (string) get_the_title( $pid );
		foreach ( self::meta_keys() as $k ) {
			delete_post_meta( $pid, $k );
		}
		TFI_Log::add( 'unlink', $pid, $isbn, $title );
	}

	public static function meta_keys(): array {
		return array( '_tfi_source', '_tfi_id', '_tfi_isbn', '_tfi_synced', '_tfi_hash', '_tfi_locked', '_tfi_attr_keys', '_tfi_linked', '_tfi_gone', '_tfi_gone_status', '_tfi_trade', '_tfi_cost', '_tfi_release', '_tfi_por' );
	}

	/** A title the feed no longer lists: out of stock or draft (setting), once. */
	public static function gone( int $pid ): void {
		if ( '1' === (string) get_post_meta( $pid, '_tfi_gone', true ) ) {
			return;
		}
		$p = wc_get_product( $pid );
		if ( ! $p ) {
			return;
		}
		$how = TFI_Settings::get()['gone'];
		if ( 'draft' === $how && 'publish' === $p->get_status() ) {
			$p->set_status( 'draft' );
		} elseif ( ! $p->managing_stock() ) {
			$p->set_stock_status( 'outofstock' );
		}
		$p->update_meta_data( '_tfi_gone', '1' );
		$p->update_meta_data( '_tfi_gone_status', $how );
		$p->save();
		self::$changed[] = $pid;
		TFI_Log::add( 'gone', $pid, (string) $p->get_meta( '_tfi_isbn', true ), $p->get_name( 'edit' ), array(), $how );
	}

	/** The ids changed in this request (and forgets them). */
	public static function take_changed(): array {
		$ids           = array_values( array_unique( array_map( 'intval', self::$changed ) ) );
		self::$changed = array();
		return $ids;
	}

	/* =====================================================================
	 * Writing
	 * =================================================================== */

	/** The content fields of $fields (image excepted) on the product object. */
	private static function write( WC_Product $p, array $r, array $fields ): void {
		foreach ( $fields as $f ) {
			switch ( $f ) {
				case 'title':
					$p->set_name( $r['title'] );
					break;
				case 'desc':
					$p->set_description( wp_kses_post( $r['desc'] ) );
					break;
				case 'short':
					$p->set_short_description( wp_kses_post( $r['short'] ) );
					break;
				case 'dims':
					$p->set_length( $r['length'] );
					$p->set_width( $r['width'] );
					$p->set_height( $r['height'] );
					$p->set_weight( $r['weight'] );
					break;
				case 'attrs':
					self::write_attributes( $p, $r );
					break;
			}
		}
	}

	/** Price, availability, pre-order, trade price and cost: always from the feed. */
	private static function commerce( WC_Product $p, array $r ): void {
		$retail = self::local_price( $r['retail'], $r );
		if ( '' !== $retail ) {
			$p->set_regular_price( $retail );
		}

		$por = class_exists( 'POR_Product' ) && method_exists( 'POR_Product', 'set' );
		if ( $r['preorder'] ) {
			$p->update_meta_data( '_tfi_release', $r['release'] );
			if ( $por ) {
				POR_Product::set( $p, true, $r['release'] );
				$p->update_meta_data( '_tfi_por', '1' );
			}
			if ( ! $p->managing_stock() ) {
				$p->set_stock_status( $por ? 'instock' : 'onbackorder' );
			}
		} else {
			$p->delete_meta_data( '_tfi_release' );
			if ( $por && '1' === (string) $p->get_meta( '_tfi_por', true, 'edit' ) ) {
				POR_Product::set( $p, false, '' );
			}
			$p->delete_meta_data( '_tfi_por' );
			if ( ! $p->managing_stock() ) {
				$p->set_stock_status( 'preorder' === $r['status'] ? 'onbackorder' : $r['status'] );
			}
		}

		if ( '' !== $r['trade'] ) {
			$p->update_meta_data( '_tfi_trade', $r['trade'] ); // As the publisher gives it (its VAT rule).
		} else {
			$p->delete_meta_data( '_tfi_trade' );
		}
		self::cost( $p, self::local_price( $r['trade'], $r ) );
	}

	/**
	 * A price of the publisher in this shop's VAT rule: the publisher gives
	 * prices with VAT (as shoppers pay them) and the shop enters them
	 * without VAT, or the other way round, at the publisher's rate.
	 */
	private static function local_price( string $price, array $r ): string {
		if ( '' === $price || null === $r['vat'] || ! wc_tax_enabled() ) {
			return $price;
		}
		$shop_incl = wc_prices_include_tax();
		if ( $r['incl'] === $shop_incl ) {
			return $price;
		}
		$f = (float) $price;
		$f = $r['incl'] ? $f / ( 1 + $r['vat'] / 100 ) : $f * ( 1 + $r['vat'] / 100 );
		return wc_format_decimal( $f, wc_get_price_decimals() + 2 );
	}

	/**
	 * The shop's tax class with the publisher's VAT rate at the shop's base
	 * address ('' = standard). Used when the setting is left on "standard"
	 * and the standard rate is not the publisher's (books are 6% in Greece).
	 */
	private static function tax_class_for( ?float $vat ): string {
		$chosen = TFI_Settings::get()['tax_class'];
		if ( '' !== $chosen || null === $vat || ! wc_tax_enabled() ) {
			return $chosen;
		}
		foreach ( array_merge( array( '' ), WC_Tax::get_tax_class_slugs() ) as $slug ) {
			foreach ( (array) WC_Tax::get_base_tax_rates( $slug ) as $rate ) {
				if ( isset( $rate['rate'] ) && abs( (float) $rate['rate'] - $vat ) < 0.001 ) {
					return (string) $slug;
				}
			}
		}
		return '';
	}

	/** The trade price as the WooCommerce cost of goods (WC 9.5+, feature on), while the cost is still ours. */
	private static function cost( WC_Product $p, string $trade ): void {
		if ( ! TFI_Settings::get()['cost'] || '' === $trade || ! self::cogs_on() ) {
			return;
		}
		try {
			$now  = (string) $p->get_cogs_value();
			$ours = (string) $p->get_meta( '_tfi_cost', true, 'edit' );
			if ( '' === $now || '0' === $now || (float) $now === (float) $ours ) {
				$p->set_cogs_value( (float) $trade );
				$p->update_meta_data( '_tfi_cost', $trade );
			}
		} catch ( \Throwable $e ) {
			unset( $e ); // The cost is a convenience: it never stops a sync.
		}
	}

	/** True when this store has the WooCommerce cost of goods field switched on. */
	public static function cogs_on(): bool {
		$c = 'Automattic\\WooCommerce\\Internal\\CostOfGoodsSold\\CostOfGoodsSoldController';
		if ( ! function_exists( 'wc_get_container' ) || ! class_exists( $c ) || ! method_exists( 'WC_Product', 'set_cogs_value' ) ) {
			return false;
		}
		try {
			return (bool) wc_get_container()->get( $c )->feature_is_enabled();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/** SKU and GTIN from the ISBN, each only when no other product has it (import only). */
	private static function codes( WC_Product $p, array $r ): void {
		$d = $r['isbn_digits'];
		if ( '' === $d ) {
			return;
		}
		if ( '' === $p->get_sku( 'edit' ) && ! wc_get_product_id_by_sku( $d ) ) {
			try {
				$p->set_sku( $d );
			} catch ( \Throwable $e ) {
				unset( $e ); // Invalid or duplicate SKU: the product is still imported.
			}
		}
		if ( method_exists( $p, 'set_global_unique_id' ) ) {
			try {
				$p->set_global_unique_id( $d );
			} catch ( \Throwable $e ) {
				unset( $e ); // Duplicate or invalid GTIN: kept in _tfi_isbn only.
			}
		}
	}

	/** Local categories of a record: the mapped ones, else the default. */
	private static function category_ids( array $r ): array {
		$s   = TFI_Settings::get();
		$out = array();
		foreach ( $r['cats'] as $c ) {
			if ( isset( $s['cat_map'][ $c ] ) ) {
				$out[] = (int) $s['cat_map'][ $c ];
			}
		}
		if ( ! $out ) {
			$def = $s['cat'] > 0 ? $s['cat'] : (int) get_option( 'default_product_cat', 0 );
			if ( $def > 0 ) {
				$out[] = $def;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** Term id by name in a taxonomy, created when missing (0 on failure). */
	private static function term( string $name, string $tax ): int {
		$t = get_term_by( 'name', $name, $tax );
		if ( $t instanceof WP_Term ) {
			return (int) $t->term_id;
		}
		$new = wp_insert_term( $name, $tax );
		if ( is_wp_error( $new ) ) {
			$id = $new->get_error_data( 'term_exists' );
			return is_numeric( $id ) ? (int) $id : 0;
		}
		return isset( $new['term_id'] ) ? (int) $new['term_id'] : 0;
	}

	/**
	 * Contributors and the other attributes of the feed as product
	 * attributes. A role or attribute whose taxonomy (pa_<key>) exists in
	 * this store becomes a global attribute with its terms; anything else a
	 * custom attribute. Attributes the plugin did not write stay.
	 */
	private static function write_attributes( WC_Product $p, array $r ): void {
		$items = array();
		foreach ( $r['contributors'] as $c ) {
			$items[ 'pa_' . $c['key'] ] = array( $c['role'], $c['names'] );
		}
		foreach ( $r['attributes'] as $a ) {
			$key = 0 === strpos( $a['slug'], 'pa_' ) ? $a['slug'] : 'pa_' . $a['slug'];
			if ( ! isset( $items[ $key ] ) ) {
				$items[ $key ] = array( $a['name'], $a['values'] );
			}
		}

		$all  = $p->get_attributes( 'edit' );
		$prev = json_decode( (string) $p->get_meta( '_tfi_attr_keys', true, 'edit' ), true );
		foreach ( is_array( $prev ) ? $prev : array() as $k ) {
			unset( $all[ $k ] );
		}
		$keys = array();
		$pos  = count( $all );
		foreach ( $items as $tax => $it ) {
			$attr = new WC_Product_Attribute();
			if ( taxonomy_exists( $tax ) ) {
				$ids = array();
				foreach ( $it[1] as $v ) {
					$t = self::term( $v, $tax );
					if ( $t ) {
						$ids[] = $t;
					}
				}
				if ( ! $ids ) {
					continue;
				}
				$attr->set_id( (int) wc_attribute_taxonomy_id_by_name( $tax ) );
				$attr->set_name( $tax );
				$attr->set_options( $ids );
				$key = $tax;
			} else {
				$attr->set_id( 0 );
				$attr->set_name( $it[0] );
				$attr->set_options( $it[1] );
				$key = sanitize_title( $it[0] );
			}
			if ( isset( $all[ $key ] ) ) {
				continue; // The shop's own attribute with the same name wins.
			}
			$attr->set_position( $pos++ );
			$attr->set_visible( true );
			$attr->set_variation( false );
			$all[ $key ] = $attr;
			$keys[]      = $key;
		}
		$p->set_attributes( $all );
		$p->update_meta_data( '_tfi_attr_keys', wp_json_encode( $keys ) );
	}

	/** The cover: downloaded when its address is new. True when the image changed. */
	private static function write_image( WC_Product $p, array $r ): bool {
		if ( '' === $r['image'] ) {
			return false;
		}
		$cur = (int) $p->get_image_id( 'edit' );
		if ( $cur && (string) get_post_meta( $cur, '_tfi_img_src', true ) === $r['image'] ) {
			return false;
		}
		$att = self::sideload( $r['image'], (int) $p->get_id(), $r );
		if ( $att <= 0 || $att === $cur ) {
			return false;
		}
		$p->set_image_id( $att );
		return true;
	}

	private static function sideload( string $url, int $pid, array $r ): int {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Same source already in the library: reuse it.
		$found = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'meta_key'       => '_tfi_img_src', // phpcs:ignore WordPress.DB.SlowDBQuery -- one indexed lookup.
				'meta_value'     => $url, // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'         => 'ids',
				'posts_per_page' => 1,
			)
		);
		if ( $found ) {
			return (int) $found[0];
		}

		$tmp = download_url( $url, 15 );
		if ( is_wp_error( $tmp ) ) {
			TFI_Log::add( 'error', $pid, $r['isbn'], $r['title'], array( 'image' ), $tmp->get_error_message() );
			return 0;
		}
		$size = (int) @filesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$mime = function_exists( 'wp_get_image_mime' ) ? (string) wp_get_image_mime( $tmp ) : '';
		$exts = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
		);
		if ( $size <= 0 || $size > self::MAX_IMAGE || ! isset( $exts[ $mime ] ) ) {
			wp_delete_file( $tmp );
			TFI_Log::add( 'error', $pid, $r['isbn'], $r['title'], array( 'image' ), __( 'Το εξώφυλλο δεν είναι εικόνα JPEG, PNG, GIF ή WebP έως 10 MB.', 'trade-feed-import' ) );
			return 0;
		}
		$name = function_exists( 'glp_convert' ) ? glp_convert( $r['title'], 'slug' ) : remove_accents( $r['title'] );
		$base = sanitize_title( $name );
		if ( '' === $base || false !== strpos( $base, '%' ) ) {
			$base = 'book' . ( '' !== $r['isbn_digits'] ? '-' . $r['isbn_digits'] : '' );
		}
		$file = array(
			'name'     => sanitize_file_name( rtrim( substr( $base, 0, 120 ), '-' ) . '.' . $exts[ $mime ] ),
			'tmp_name' => $tmp,
		);
		$att  = media_handle_sideload( $file, $pid, $r['title'] );
		if ( is_wp_error( $att ) ) {
			wp_delete_file( $tmp );
			TFI_Log::add( 'error', $pid, $r['isbn'], $r['title'], array( 'image' ), $att->get_error_message() );
			return 0;
		}
		update_post_meta( (int) $att, '_wp_attachment_image_alt', $r['title'] );
		update_post_meta( (int) $att, '_tfi_img_src', $url );
		return (int) $att;
	}

	/* =====================================================================
	 * Field hashes (locks)
	 * =================================================================== */

	/** Everything the sync can change, to tell whether a save is needed. */
	private static function snapshot( WC_Product $p ): string {
		$v = array();
		foreach ( TFI_Settings::SYNC_FIELDS as $f ) {
			$v[ $f ] = self::value_of( $p, $f );
		}
		$v['price']   = (string) $p->get_regular_price( 'edit' );
		$v['stock']   = (string) $p->get_stock_status( 'edit' );
		$v['trade']   = (string) $p->get_meta( '_tfi_trade', true, 'edit' );
		$v['release'] = (string) $p->get_meta( '_tfi_release', true, 'edit' );
		$v['por']     = array( (string) $p->get_meta( '_por_on', true, 'edit' ), (string) $p->get_meta( '_por_date', true, 'edit' ) );
		$v['cost']    = (string) $p->get_meta( '_tfi_cost', true, 'edit' );
		return md5( (string) wp_json_encode( $v ) );
	}

	/** The stored hashes: field => md5. */
	public static function hashes( int $pid ): array {
		$h   = json_decode( (string) get_post_meta( $pid, '_tfi_hash', true ), true );
		$out = array();
		foreach ( is_array( $h ) ? $h : array() as $k => $v ) {
			if ( in_array( $k, TFI_Settings::SYNC_FIELDS, true ) && is_string( $v ) ) {
				$out[ $k ] = $v;
			}
		}
		return $out;
	}

	/** Stores the hashes of the fields as they are on the product now. */
	private static function store_hashes( WC_Product $p, array $fields, array $keep = array() ): void {
		$h = $keep;
		foreach ( $fields as $f ) {
			if ( in_array( $f, TFI_Settings::SYNC_FIELDS, true ) ) {
				$h[ $f ] = self::hash_of( $p, $f );
			}
		}
		update_post_meta( $p->get_id(), '_tfi_hash', wp_json_encode( $h ) );
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

	/** The current value of a field on the product. */
	private static function value_of( WC_Product $p, string $f ) {
		switch ( $f ) {
			case 'title':
				return $p->get_name( 'edit' );
			case 'desc':
				return $p->get_description( 'edit' );
			case 'short':
				return $p->get_short_description( 'edit' );
			case 'dims':
				return array( (string) $p->get_length( 'edit' ), (string) $p->get_width( 'edit' ), (string) $p->get_height( 'edit' ), (string) $p->get_weight( 'edit' ) );
			case 'image':
				return (int) $p->get_image_id( 'edit' );
			case 'attrs':
				$keys = json_decode( (string) $p->get_meta( '_tfi_attr_keys', true, 'edit' ), true );
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

	/** Field labels for the admin. */
	public static function field_label( string $f ): string {
		$map = array(
			'title' => __( 'Τίτλος', 'trade-feed-import' ),
			'desc'  => __( 'Περιγραφή', 'trade-feed-import' ),
			'short' => __( 'Σύντομη περιγραφή', 'trade-feed-import' ),
			'image' => __( 'Εξώφυλλο', 'trade-feed-import' ),
			'attrs' => __( 'Ιδιότητες', 'trade-feed-import' ),
			'dims'  => __( 'Διαστάσεις και βάρος', 'trade-feed-import' ),
		);
		return isset( $map[ $f ] ) ? $map[ $f ] : $f;
	}
}
