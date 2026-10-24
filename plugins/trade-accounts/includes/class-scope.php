<?php
/**
 * TRA_Scope — which products are eligible for wholesale prices and the
 * Trade Feed, and what a product costs a trade account.
 *
 * Eligible = every condition holds (the product of a variation is its
 * parent, except for "physical", which the variation decides):
 *  - in one of the chosen categories or their subcategories (none chosen =
 *    nothing is eligible, so a fresh install changes no price);
 *  - of one of the chosen brands, when brands are chosen;
 *  - physical (not virtual, not downloadable), when that setting is on;
 *  - not a Book Feed title (bkf_get_source(), Bible §6): another
 *    publisher's book is never sold or listed at our trade terms;
 *  - not excluded by hand (product meta _tra_exclude = yes, a checkbox in
 *    the product's General tab).
 * Filter tra_eligible( bool, WC_Product ) for code.
 *
 * Trade price: discount off the regular price, and a lower sale price
 * wins ('regular', default), or discount off the price shown now
 * ('active'). Rounded to the shop's decimals. The feed and the shop use
 * the same function, so they never disagree.
 */

defined( 'ABSPATH' ) || exit;

final class TRA_Scope {

	const EXCLUDE = '_tra_exclude';

	/** @var array<int,bool> Per-request cache, by product id. */
	private static $cache = array();

	/** @var int[]|null Chosen categories with all their subcategories. */
	private static $cats = null;

	public static function init(): void {
		if ( is_admin() ) {
			add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'field' ) );
			add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_field' ) );
		}
		add_action( 'save_post_product', array( __CLASS__, 'forget' ) );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'forget' ) );
	}

	public static function forget(): void {
		self::$cache = array();
	}

	/** The chosen categories and every subcategory of them. */
	public static function cat_ids(): array {
		if ( null === self::$cats ) {
			$ids = array();
			foreach ( TRA_Settings::get()['cats'] as $id ) {
				$ids[] = (int) $id;
				$kids  = get_term_children( (int) $id, 'product_cat' );
				if ( is_array( $kids ) ) {
					foreach ( $kids as $k ) {
						$ids[] = (int) $k;
					}
				}
			}
			self::$cats = array_values( array_unique( $ids ) );
		}
		return self::$cats;
	}

	public static function eligible( $product ): bool {
		$product = is_numeric( $product ) ? wc_get_product( (int) $product ) : $product;
		if ( ! $product instanceof WC_Product ) {
			return false;
		}
		$id = (int) $product->get_id();
		if ( isset( self::$cache[ $id ] ) ) {
			return self::$cache[ $id ];
		}
		$ok = self::check( $product );
		/**
		 * Filters whether a product is eligible for wholesale prices and
		 * the Trade Feed.
		 *
		 * @param bool       $ok      Eligible by the settings.
		 * @param WC_Product $product Product or variation.
		 */
		$ok                 = (bool) apply_filters( 'tra_eligible', $ok, $product );
		self::$cache[ $id ] = $ok;
		return $ok;
	}

	private static function check( WC_Product $product ): bool {
		$s    = TRA_Settings::get();
		$main = $product->get_parent_id() ? (int) $product->get_parent_id() : (int) $product->get_id();

		if ( $s['physical'] && ( $product->is_virtual() || $product->is_downloadable() ) ) {
			return false;
		}
		if ( in_array( $product->get_type(), array( 'external', 'grouped' ), true ) ) {
			return false;
		}
		if ( 'yes' === get_post_meta( $main, self::EXCLUDE, true ) ) {
			return false;
		}
		if ( function_exists( 'bkf_get_source' ) && null !== bkf_get_source( $main ) ) {
			return false;
		}
		$cats = self::cat_ids();
		if ( empty( $cats ) || ! array_intersect( $cats, array_map( 'intval', wc_get_product_term_ids( $main, 'product_cat' ) ) ) ) {
			return false;
		}
		if ( ! empty( $s['brands'] ) ) {
			if ( ! taxonomy_exists( 'product_brand' ) || ! array_intersect( $s['brands'], array_map( 'intval', wc_get_product_term_ids( $main, 'product_brand' ) ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The trade price of a product for a discount (%), from the stored
	 * prices ('edit' context, so no price filter of this or another plugin
	 * runs twice). Null when the product has no price.
	 */
	public static function trade_price( WC_Product $product, float $discount ): ?float {
		$regular = (float) $product->get_regular_price( 'edit' );
		$sale    = $product->is_on_sale( 'edit' ) ? (float) $product->get_sale_price( 'edit' ) : 0.0;
		$active  = $sale > 0 ? $sale : $regular;
		if ( $active <= 0 && $regular <= 0 ) {
			return null;
		}
		$factor = 1 - ( TRA_Settings::percent( $discount ) / 100 );
		if ( 'active' === TRA_Settings::get()['base'] || $regular <= 0 ) {
			$price = $active * $factor;
		} else {
			$price = $regular * $factor;
			if ( $sale > 0 && $sale < $price ) {
				$price = $sale;
			}
		}
		return round( $price, wc_get_price_decimals() );
	}

	/* ---------------------------------------------------------------------
	 * Product screen: «Εκτός χονδρικής»
	 * ------------------------------------------------------------------- */

	public static function field(): void {
		global $product_object;
		$on = $product_object instanceof WC_Product && 'yes' === $product_object->get_meta( self::EXCLUDE );
		echo '<div class="options_group">';
		woocommerce_wp_checkbox(
			array(
				'id'          => 'tra_exclude',
				'value'       => $on ? 'yes' : 'no',
				'label'       => __( 'Εκτός χονδρικής', 'trade-accounts' ),
				'description' => __( 'Χωρίς τιμή χονδρικής και έξω από το Trade Feed, ακόμα κι αν η κατηγορία του είναι επιλέξιμη.', 'trade-accounts' ),
			)
		);
		echo '</div>';
	}

	public static function save_field( $product ): void {
		if ( ! $product instanceof WC_Product || ! current_user_can( 'edit_product', $product->get_id() ) ) {
			return;
		}
		// The product form's own nonce was checked by WooCommerce before this hook.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! empty( $_POST['tra_exclude'] ) ) {
			$product->update_meta_data( self::EXCLUDE, 'yes' );
		} else {
			$product->delete_meta_data( self::EXCLUDE );
		}
	}

	/* ---------------------------------------------------------------------
	 * Query of the eligible products (Trade Feed, admin counts)
	 * ------------------------------------------------------------------- */

	/**
	 * WP_Query args for the published, visible, eligible simple products.
	 * The filter tra_eligible cannot run inside a query; the feed checks
	 * every product with eligible() again before it lists it.
	 */
	public static function query_args(): array {
		$s    = TRA_Settings::get();
		$cats = TRA_Settings::get()['cats'];
		$tax  = array(
			'relation' => 'AND',
			array(
				'taxonomy'         => 'product_cat',
				'field'            => 'term_id',
				'terms'            => empty( $cats ) ? array( 0 ) : $cats,
				'include_children' => true,
			),
			array(
				'taxonomy' => 'product_type',
				'field'    => 'slug',
				'terms'    => array( 'simple' ),
			),
			array(
				'taxonomy' => 'product_visibility',
				'field'    => 'slug',
				'terms'    => array( 'exclude-from-catalog', 'exclude-from-search' ),
				'operator' => 'NOT IN',
			),
		);
		if ( ! empty( $s['brands'] ) && taxonomy_exists( 'product_brand' ) ) {
			$tax[] = array(
				'taxonomy' => 'product_brand',
				'field'    => 'term_id',
				'terms'    => $s['brands'],
			);
		}
		$meta = array(
			'relation' => 'AND',
			array(
				'key'     => self::EXCLUDE,
				'compare' => 'NOT EXISTS',
			),
		);
		if ( function_exists( 'bkf_get_source' ) ) {
			// Book Feed titles carry _bkf_source (its public marker, Bible §6
			// bkf_get_source()); a query cannot call the function.
			$meta[] = array(
				'key'     => '_bkf_source',
				'compare' => 'NOT EXISTS',
			);
		}
		if ( $s['physical'] ) {
			$meta[] = array(
				'key'     => '_virtual',
				'value'   => 'yes',
				'compare' => '!=',
			);
			$meta[] = array(
				'key'     => '_downloadable',
				'value'   => 'yes',
				'compare' => '!=',
			);
		}
		return array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'tax_query'      => $tax, // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_query'     => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery
			'no_found_rows'  => false,
			'ignore_sticky_posts' => true,
		);
	}

	/** How many products are eligible now (admin). */
	public static function count(): int {
		if ( empty( TRA_Settings::get()['cats'] ) ) {
			return 0;
		}
		$args                   = self::query_args();
		$args['fields']         = 'ids';
		$args['posts_per_page'] = 1;
		$q                      = new WP_Query( $args );
		return (int) $q->found_posts;
	}
}
