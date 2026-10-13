<?php
/**
 * VPK_Front — swatches on the product page, assets, cart colour dot.
 *
 * Product page: woocommerce_dropdown_variation_attribute_options_html.
 * WooCommerce's <select> stays in the page (hidden by front.js once it
 * runs); the swatches are printed after it and only drive it, so prices,
 * images, stock and add to cart remain WooCommerce's own. Without
 * JavaScript the visitor sees the normal dropdown.
 *
 * Request guard (Bible §14.4): never in admin, REST, feeds, embeds, cron,
 * CLI or XML-RPC. Exceptions: admin-ajax / wc-ajax requests, for quick-view
 * popups that load the product form over AJAX, and the cart dot, which
 * also runs in WooCommerce's cart fragments (mini-cart).
 */

defined( 'ABSPATH' ) || exit;

final class VPK_Front {

	/** @var bool Set after an error: the plugin stops for the rest of the request. */
	private static $broken = false;

	/** @var bool Assets enqueued. */
	private static $assets = false;

	/** @var array Products whose map was printed (id => true). */
	private static $map_printed = array();

	public static function init(): void {
		add_filter( 'woocommerce_dropdown_variation_attribute_options_html', array( __CLASS__, 'dropdown_html' ), 100, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_assets' ), 20 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'cart_dot' ), 20, 2 );
	}

	/** True when swatches may render in this request. */
	public static function active(): bool {
		if ( self::$broken || Variation_Picker::killed() ) {
			return false;
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() ) {
			return false;
		}
		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}
		if ( did_action( 'parse_query' ) && ( is_feed() || is_embed() ) ) {
			return false;
		}
		return VPK_Settings::visible();
	}

	/** Called by any renderer on error: stop for the rest of the request. */
	public static function fail(): void {
		self::$broken = true;
	}

	/* =====================================================================
	 * Assets
	 * =================================================================== */

	public static function maybe_assets(): void {
		try {
			if ( ! self::active() ) {
				return;
			}
			$need = false;
			if ( is_product() ) {
				$product = wc_get_product( get_queried_object_id() );
				$need    = $product && $product->is_type( 'variable' );
				// Related / upsell cards with shop swatches.
				$need = $need || VPK_Settings::get()['shop']['enabled'];
			} elseif ( VPK_Settings::get()['shop']['enabled'] && ( is_shop() || is_product_taxonomy() || ( is_search() && 'product' === get_query_var( 'post_type' ) ) ) ) {
				$need = true;
			}
			if ( $need ) {
				self::assets();
			}
		} catch ( \Throwable $e ) {
			self::fail();
		}
	}

	/**
	 * Enqueues front.css and front.js once. Called from wp_enqueue_scripts,
	 * and from a renderer on pages the check above cannot foresee
	 * (shortcodes, quick views): WordPress then prints them in the footer.
	 */
	public static function assets(): void {
		if ( self::$assets ) {
			return;
		}
		self::$assets = true;
		$s            = VPK_Settings::get();

		wp_enqueue_style( 'vpk-front', VPK_URL . 'assets/front.css', array(), VPK_VERSION );
		wp_add_inline_style(
			'vpk-front',
			sprintf(
				'.vpk-root{--vpk-size:%1$dpx;--vpk-font:%2$dpx;--vpk-bw:%3$dpx;--vpk-bc:%4$s;--vpk-ac:%5$s;--vpk-shop:%6$dpx}',
				(int) $s['size'],
				(int) $s['font'],
				(int) $s['border'],
				esc_attr( $s['border_color'] ),
				esc_attr( $s['active_color'] ),
				(int) $s['shop']['size']
			)
		);

		wp_enqueue_script( 'vpk-front', VPK_URL . 'assets/front.js', array( 'jquery' ), VPK_VERSION, true );
		wp_localize_script( 'vpk-front', 'vpkCfg', self::js_config() );
	}

	/** The settings front.js needs (no secrets, nothing per user). */
	private static function js_config(): array {
		$s      = VPK_Settings::get();
		$format = get_woocommerce_price_format();
		return array(
			'unavail'  => $s['unavailable'],
			'oos'      => $s['oos'],
			'label'    => $s['selected_label'] ? 1 : 0,
			'tip'      => $s['tooltip'],
			'auto'     => $s['auto_select'] ? 1 : 0,
			'deselect' => $s['deselect'] ? 1 : 0,
			'url'      => $s['url_sync'] ? 1 : 0,
			'diff'     => $s['price_diff'] ? 1 : 0,
			'low'      => (int) $s['low_stock'],
			'swap'     => $s['shop']['swap_image'] ? 1 : 0,
			't'        => array(
				'low'     => VPK_Settings::text( 't_low' ),
				'unavail' => VPK_Settings::text( 't_unavail' ),
				'oos'     => VPK_Settings::text( 't_oos' ),
			),
			'price'    => array(
				'dec'    => wc_get_price_decimals(),
				'ds'     => wc_get_price_decimal_separator(),
				'ts'     => wc_get_price_thousand_separator(),
				'format' => $format,
				'symbol' => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			),
		);
	}

	/* =====================================================================
	 * Product page
	 * =================================================================== */

	/**
	 * woocommerce_dropdown_variation_attribute_options_html.
	 *
	 * @param mixed $html WooCommerce's <select>.
	 * @param mixed $args wc_dropdown_variation_attribute_options() args.
	 */
	public static function dropdown_html( $html, $args ) {
		if ( ! is_string( $html ) || ! is_array( $args ) || ! self::active() ) {
			return $html;
		}
		try {
			$out = self::render( $html, $args );
			return null === $out ? $html : $out;
		} catch ( \Throwable $e ) {
			self::fail();
			return $html;
		}
	}

	/**
	 * The select plus swatches, or null to leave WooCommerce's output alone.
	 *
	 * @param string $html Select.
	 * @param array  $args Args.
	 */
	private static function render( string $html, array $args ): ?string {
		$product   = isset( $args['product'] ) ? $args['product'] : null;
		$attribute = isset( $args['attribute'] ) && is_string( $args['attribute'] ) ? $args['attribute'] : '';
		if ( ! $product instanceof WC_Product || '' === $attribute || false === strpos( $html, '<select' ) ) {
			return null;
		}
		$pid  = (int) $product->get_id();
		$type = VPK_Swatch::type( $pid, $attribute );
		$key  = 'attribute_' . sanitize_title( $attribute );

		$all_attrs = $product->get_variation_attributes();
		$names     = array_keys( $all_attrs );
		$is_last   = end( $names ) === $attribute;
		$guide     = VPK_Guide::button( $product, $attribute, $is_last );

		if ( 'select' === $type ) {
			if ( '' === $guide ) {
				return null;
			}
			self::assets();
			return '<div class="vpk-root vpk-wrap vpk-wrap--select">' . $html . $guide . '</div>';
		}

		$options = isset( $args['options'] ) && is_array( $args['options'] ) && $args['options'] ? $args['options'] : ( isset( $all_attrs[ $attribute ] ) ? (array) $all_attrs[ $attribute ] : array() );
		if ( ! $options ) {
			return null;
		}
		$selected = isset( $args['selected'] ) && is_scalar( $args['selected'] ) ? (string) $args['selected'] : '';

		$map    = VPK_Map::get( $product );
		$images = ( $map && 'variation_image' === $type ) ? VPK_Map::images( $map, $key ) : array();
		$items  = self::items( $product, $attribute, $options );
		if ( ! $items ) {
			return null;
		}

		$look  = VPK_Swatch::look( $attribute );
		$s     = VPK_Settings::get();
		$label = wc_attribute_label( $attribute, $product );

		$list = '';
		$tab0 = '';
		foreach ( $items as $it ) {
			if ( $it['value'] === $selected || ( '' !== $selected && sanitize_title( $selected ) === $it['value'] ) ) {
				$tab0 = $it['value'];
			}
		}
		if ( '' === $tab0 ) {
			$tab0 = $items[0]['value'];
		}
		foreach ( $items as $it ) {
			$list .= self::swatch( $it, $type, $images, $selected, $tab0 === $it['value'], $s['tooltip'] );
		}

		$attrs = sprintf(
			' data-vpk-attr="%1$s" data-vpk-type="%2$s"',
			esc_attr( $key ),
			esc_attr( $type )
		);

		// Products over the AJAX threshold: WooCommerce prints no variation
		// list, so the map tells front.js which combinations exist.
		$threshold = (int) apply_filters( 'woocommerce_ajax_variation_threshold', 30, $product );
		if ( $map && count( $product->get_children() ) > $threshold && ! isset( self::$map_printed[ $pid ] ) ) {
			self::$map_printed[ $pid ] = true;
			$attrs                    .= ' data-vpk-map="' . esc_attr( (string) wp_json_encode( $map ) ) . '"';
		}

		$size_style = (int) $look['size'] !== (int) $s['size'] ? ' style="--vpk-size:' . (int) $look['size'] . 'px"' : '';

		self::assets();

		return '<div class="vpk-root vpk-wrap"' . $attrs . '>'
			. $html
			. '<div class="vpk-list vpk-list--' . esc_attr( $type ) . ' vpk-shape--' . esc_attr( $look['shape'] ) . '" role="radiogroup" aria-label="' . esc_attr( wp_strip_all_tags( $label ) ) . '"' . $size_style . '>'
			. $list
			. '</div>'
			. $guide
			. '</div>';
	}

	/**
	 * The values of an attribute in WooCommerce's order: { value, name, look }.
	 *
	 * @param WC_Product $product   Product.
	 * @param string     $attribute Attribute name.
	 * @param array      $options   Option values.
	 */
	public static function items( WC_Product $product, string $attribute, array $options ): array {
		$pid   = (int) $product->get_id();
		$items = array();
		if ( taxonomy_exists( $attribute ) ) {
			$terms = wc_get_product_terms( $pid, $attribute, array( 'fields' => 'all' ) );
			foreach ( $terms as $term ) {
				if ( ! in_array( $term->slug, $options, true ) ) {
					continue;
				}
				$items[] = array(
					'value' => (string) $term->slug,
					'name'  => (string) apply_filters( 'woocommerce_variation_option_name', $term->name, $term, $attribute, $product ),
					'look'  => VPK_Swatch::value( $pid, $attribute, (string) $term->slug, $term ),
				);
			}
		} else {
			foreach ( $options as $option ) {
				$option  = (string) $option;
				$items[] = array(
					'value' => $option,
					'name'  => (string) apply_filters( 'woocommerce_variation_option_name', $option, null, $attribute, $product ),
					'look'  => VPK_Swatch::value( $pid, $attribute, $option ),
				);
			}
		}
		return $items;
	}

	/**
	 * One swatch button.
	 *
	 * @param array  $it       Item { value, name, look }.
	 * @param string $type     Swatch type.
	 * @param array  $images   value => variation image id (variation_image type).
	 * @param string $selected Selected value.
	 * @param bool   $tab      In the tab order (roving tabindex).
	 * @param string $tooltip  Tooltip setting.
	 */
	private static function swatch( array $it, string $type, array $images, string $selected, bool $tab, string $tooltip ): string {
		$look  = $it['look'];
		$name  = wp_strip_all_tags( $it['name'] );
		$short = '' !== $look['label'] ? $look['label'] : $name;
		$img   = 0;
		$kind  = 'label';

		switch ( $type ) {
			case 'color':
				if ( '' !== $look['color'] ) {
					$kind = 'color';
				} elseif ( $look['image'] ) {
					$kind = 'image';
					$img  = $look['image'];
				}
				break;
			case 'image':
				if ( $look['image'] ) {
					$kind = 'image';
					$img  = $look['image'];
				} elseif ( '' !== $look['color'] ) {
					$kind = 'color';
				}
				break;
			case 'variation_image':
				if ( isset( $images[ $it['value'] ] ) ) {
					$kind = 'image';
					$img  = (int) $images[ $it['value'] ];
				} elseif ( $look['image'] ) {
					$kind = 'image';
					$img  = $look['image'];
				} elseif ( '' !== $look['color'] ) {
					$kind = 'color';
				}
				break;
			case 'radio':
				$kind = 'radio';
				break;
		}

		$inner = '';
		$src   = '';
		if ( 'color' === $kind ) {
			$bg    = '' !== $look['color2']
				? 'linear-gradient(135deg,' . $look['color'] . ' 50%,' . $look['color2'] . ' 50%)'
				: $look['color'];
			$inner = '<span class="vpk-sw__color" style="background:' . esc_attr( $bg ) . '"></span>';
		} elseif ( 'image' === $kind ) {
			$src   = (string) wp_get_attachment_image_url( $img, 'thumbnail' );
			$inner = '' !== $src
				? '<img class="vpk-sw__img" src="' . esc_url( $src ) . '" alt="" loading="lazy" decoding="async" />'
				: '<span class="vpk-sw__label">' . esc_html( $short ) . '</span>';
			if ( '' === $src ) {
				$kind = 'label';
			}
		} elseif ( 'radio' === $kind ) {
			$inner = '<span class="vpk-sw__dot" aria-hidden="true"></span><span class="vpk-sw__label">' . esc_html( $name ) . '</span>';
		} else {
			$inner = '<span class="vpk-sw__label">' . esc_html( $short ) . '</span>';
		}

		$on   = '' !== $selected && ( $selected === $it['value'] || sanitize_title( $selected ) === $it['value'] );
		$data = '';
		if ( 'none' !== $tooltip ) {
			$data .= ' data-vpk-tip="' . esc_attr( $name ) . '"';
			if ( 'image' === $tooltip ) {
				$tip_img = $img ? (string) wp_get_attachment_image_url( $img, 'woocommerce_thumbnail' ) : '';
				if ( '' !== $tip_img ) {
					$data .= ' data-vpk-img="' . esc_url( $tip_img ) . '"';
				}
			}
		}

		return '<button type="button" class="vpk-sw vpk-sw--' . esc_attr( $kind ) . ( $on ? ' vpk-is-on' : '' ) . '" role="radio"'
			. ' aria-checked="' . ( $on ? 'true' : 'false' ) . '" tabindex="' . ( $tab ? '0' : '-1' ) . '"'
			. ' aria-label="' . esc_attr( $name ) . '" data-value="' . esc_attr( $it['value'] ) . '" data-name="' . esc_attr( $name ) . '"' . $data . '>'
			. $inner
			. '</button>';
	}

	/* =====================================================================
	 * Cart colour dot (P10)
	 * =================================================================== */

	/**
	 * woocommerce_get_item_data: a colour dot before a colour value.
	 *
	 * @param mixed $item_data Rows { key, value[, display] }.
	 * @param mixed $cart_item Cart item.
	 */
	public static function cart_dot( $item_data, $cart_item ) {
		if ( ! is_array( $item_data ) || ! $item_data || ! is_array( $cart_item ) || empty( $cart_item['variation'] ) || ! is_array( $cart_item['variation'] ) ) {
			return $item_data;
		}
		if ( ! VPK_Settings::get()['cart_dot'] || ! self::active() ) {
			return $item_data;
		}
		try {
			$pid = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
			foreach ( $cart_item['variation'] as $name => $value ) {
				if ( ! is_string( $name ) || 0 !== strpos( $name, 'attribute_pa_' ) || '' === (string) $value ) {
					continue;
				}
				$tax = wc_attribute_taxonomy_name( str_replace( 'attribute_pa_', '', urldecode( $name ) ) );
				if ( ! taxonomy_exists( $tax ) || 'color' !== VPK_Swatch::type( $pid, $tax ) ) {
					continue;
				}
				$term = get_term_by( 'slug', (string) $value, $tax );
				if ( ! $term instanceof WP_Term ) {
					continue;
				}
				$look = VPK_Swatch::value( $pid, $tax, (string) $value, $term );
				if ( '' === $look['color'] ) {
					continue;
				}
				$label = wc_attribute_label( $tax );
				foreach ( $item_data as $i => $row ) {
					if ( ! is_array( $row ) || ! isset( $row['key'], $row['value'] ) || $row['key'] !== $label || (string) $row['value'] !== $term->name || isset( $row['display'] ) ) {
						continue;
					}
					$bg = '' !== $look['color2'] ? 'linear-gradient(135deg,' . $look['color'] . ' 50%,' . $look['color2'] . ' 50%)' : $look['color'];
					// Inline styles only: the cart and mini-cart may load without our CSS.
					$item_data[ $i ]['display'] = '<span class="vpk-dot" style="display:inline-block;width:.85em;height:.85em;border-radius:50%;background:' . esc_attr( $bg ) . ';border:1px solid #b0b0b0;vertical-align:-0.1em;margin-right:.35em" aria-hidden="true"></span>' . esc_html( $term->name );
				}
			}
		} catch ( \Throwable $e ) {
			self::fail();
		}
		return $item_data;
	}
}
