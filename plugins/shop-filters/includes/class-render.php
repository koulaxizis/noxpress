<?php
/**
 * SHF_Render — filter set HTML, widget, shortcode, assets.
 *
 * Markup built to survive theme rules (requirement: no per-site CSS):
 *  - no ul / li, no input checkboxes and no icon fonts for the layout:
 *    lists are div[role=list], boxes and arrows are drawn with CSS or
 *    inline SVG, so rules like "#sidebar ul li { display: flex }" never
 *    reach them;
 *  - collapsible filters are <details> / <summary> (no JavaScript);
 *  - every option is a plain link with the full filter URL (rel nofollow),
 *    so filtering works without JavaScript and links can be shared;
 *  - colours and fonts are inherited from the theme.
 *
 * front.js only adds: category toggle buttons, the "Apply" button mode,
 * the price slider and immediate visual feedback.
 */

defined( 'ABSPATH' ) || exit;

final class SHF_Render {

	const SHORTCODE = 'shf_filters';

	/** One results anchor per page. */
	private static $anchor_done = false;

	public static function init_widget(): void {
		add_action(
			'widgets_init',
			static function () {
				register_widget( 'SHF_Widget' );
			}
		);
	}

	public static function init(): void {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'woocommerce_before_shop_loop', array( __CLASS__, 'anchor' ), 0 );
		add_action( 'woocommerce_no_products_found', array( __CLASS__, 'anchor' ), 0 );
		add_filter( 'noxpress_filters_present', array( __CLASS__, 'present' ) );
	}

	/** Public filter for other suite plugins: are the filters in use here? */
	public static function present( $present ): bool {
		$s = SHF_Settings::get();
		return (bool) $present || ( ! empty( $s['enabled'] ) && (bool) SHF_Settings::sets() );
	}

	/* =====================================================================
	 * Gating, assets, anchor
	 * =================================================================== */

	/** The set to show here ('auto' = by category), or null. */
	private static function current_set( string $set_id ): ?array {
		if ( ! SHF_Request::active() || ! SHF_Request::is_listing() ) {
			return null;
		}
		if ( '' !== $set_id && 'auto' !== $set_id ) {
			return SHF_Settings::set( $set_id );
		}
		return SHF_Settings::set_for_category( SHF_Request::current_cat() );
	}

	public static function assets(): void {
		try {
			// Listing pages only, and only when a set exists (a widget may show a fixed set).
			if ( ! SHF_Request::active() || ! SHF_Request::is_listing() || ! SHF_Settings::sets() ) {
				return;
			}
			wp_register_style( 'shf-front', SHF_URL . 'assets/front.css', array(), SHF_VERSION );
			wp_register_script( 'shf-front', SHF_URL . 'assets/front.js', array(), SHF_VERSION, true );
			// Widget placed: in the <head>. Otherwise (shortcode) render() enqueues
			// late, and pages without filters load nothing.
			if ( self::widget_placed() ) {
				self::enqueue();
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Is the widget in a sidebar of the current theme? is_active_widget() also
	 * counts sidebars the theme no longer registers. A block theme keeps the
	 * previous theme's sidebars registered (core, for the legacy widget block)
	 * but rarely prints them: there render() enqueues only when it outputs.
	 */
	private static function widget_placed(): bool {
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			return false;
		}
		foreach ( wp_get_sidebars_widgets() as $area => $ids ) {
			if ( 'wp_inactive_widgets' === $area || ! is_array( $ids ) || ! is_registered_sidebar( $area ) ) {
				continue;
			}
			foreach ( $ids as $id ) {
				if ( 0 === strpos( (string) $id, 'shf_filters-' ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private static function enqueue(): void {
		wp_enqueue_style( 'shf-front' );
		wp_enqueue_script( 'shf-front' );
	}

	/** Anchor at the top of the product list: filter links land here. */
	public static function anchor(): void {
		if ( self::$anchor_done || ! SHF_Request::active() || ! SHF_Settings::sets() ) {
			return;
		}
		self::$anchor_done = true;
		echo '<span id="' . esc_attr( SHF_Request::ANCHOR ) . '" class="shf-anchor"></span>';
	}

	/* =====================================================================
	 * Entry points
	 * =================================================================== */

	public static function shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'set' => 'auto' ), is_array( $atts ) ? $atts : array(), self::SHORTCODE );
		return self::render( sanitize_key( (string) $atts['set'] ) );
	}

	/** Full filter set HTML ('' when nothing should show). */
	public static function render( string $set_id = 'auto' ): string {
		try {
			$set = self::current_set( $set_id );
			if ( null === $set || ! $set['filters'] ) {
				return '';
			}
			$parts = array();
			foreach ( $set['filters'] as $f ) {
				if ( 'cat' === $f['type'] ) {
					$parts[] = self::filter_cat( $f );
				} elseif ( 'price' === $f['type'] ) {
					$parts[] = self::filter_price( $f );
				} elseif ( SHF_Settings::is_attribute( $f['attr'] ) ) {
					$parts[] = self::filter_attr( $f );
				}
			}
			$parts = array_filter( $parts );
			if ( ! $parts ) {
				return '';
			}
			$s = SHF_Settings::get();
			self::enqueue();
			return '<div class="shf-root" data-shf-apply="' . esc_attr( $s['apply'] ) . '" data-shf-base="' . esc_url( SHF_Request::base_url() ) . '"'
				. ' data-shf-apply-label="' . esc_attr__( 'Εφαρμογή', 'shop-filters' ) . '"'
				. ' data-shf-toggle-label="' . esc_attr__( 'Άνοιγμα ή κλείσιμο υποκατηγοριών', 'shop-filters' ) . '">'
				. self::active_bar()
				. implode( '', $parts )
				. '</div>';
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/* =====================================================================
	 * Active filters
	 * =================================================================== */

	private static function active_bar(): string {
		$chips = array();
		foreach ( SHF_Request::selected() as $attr => $tokens ) {
			$options = SHF_Groups::options( $attr );
			foreach ( $tokens as $tok ) {
				$label   = $options[ $tok ]['label'] ?? $tok;
				$chips[] = self::chip( $label, SHF_Request::url_toggle( $attr, $tok ) );
			}
		}
		$p = SHF_Request::price();
		if ( null !== $p ) {
			$label   = wp_strip_all_tags( wc_price( $p[0], array( 'decimals' => 0 ) ) ) . ' – ' . ( $p[1] >= PHP_INT_MAX ? '…' : wp_strip_all_tags( wc_price( $p[1], array( 'decimals' => 0 ) ) ) );
			$chips[] = self::chip( html_entity_decode( $label, ENT_QUOTES, 'UTF-8' ), SHF_Request::url_without_price() );
		}
		if ( ! $chips ) {
			return '';
		}
		return '<div class="shf-active" role="region" aria-label="' . esc_attr__( 'Ενεργά φίλτρα', 'shop-filters' ) . '">'
			. '<div class="shf-chips">' . implode( '', $chips ) . '</div>'
			. '<a class="shf-clear" rel="nofollow" href="' . esc_url( SHF_Request::url_clear() ) . '">' . esc_html__( 'Καθαρισμός όλων', 'shop-filters' ) . '</a>'
			. '</div>';
	}

	private static function chip( string $label, string $url ): string {
		/* translators: %s: filter value */
		$aria = sprintf( __( 'Αφαίρεση φίλτρου: %s', 'shop-filters' ), $label );
		return '<a class="shf-chip" rel="nofollow" href="' . esc_url( $url ) . '" aria-label="' . esc_attr( $aria ) . '">'
			. '<span class="shf-chip-text">' . esc_html( $label ) . '</span>'
			. '<span class="shf-x" aria-hidden="true"></span></a>';
	}

	/* =====================================================================
	 * Filter shells
	 * =================================================================== */

	private static function shell( string $kind, string $title, bool $open, string $body ): string {
		return '<details class="shf-filter shf-filter--' . esc_attr( $kind ) . '"' . ( $open ? ' open' : '' ) . '>'
			. '<summary class="shf-head"><span class="shf-title">' . esc_html( $title ) . '</span>' . self::chevron( 'shf-chev' ) . '</summary>'
			. '<div class="shf-body">' . $body . '</div>'
			. '</details>';
	}

	private static function chevron( string $class ): string {
		return '<svg class="' . esc_attr( $class ) . '" viewBox="0 0 12 12" width="12" height="12" aria-hidden="true" focusable="false"><path d="M2.5 4.5 6 8l3.5-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	}

	private static function count_html( int $n ): string {
		return '<span class="shf-count">' . esc_html( number_format_i18n( $n ) ) . '</span>';
	}

	/* =====================================================================
	 * Attribute filter
	 * =================================================================== */

	private static function filter_attr( array $f ): string {
		$attr    = $f['attr'];
		$options = SHF_Groups::options( $attr );
		if ( ! $options ) {
			return '';
		}
		$counts   = SHF_Index::attr_counts( $attr );
		$selected = SHF_Request::selected()[ $attr ] ?? array();
		$dim      = 'dim' === SHF_Settings::get()['empty'];
		$items    = array();
		foreach ( $options as $tok => $o ) {
			$tok = (string) $tok;
			$on  = in_array( $tok, $selected, true );
			$n   = $counts[ $tok ] ?? 0;
			if ( 0 === $n && ! $on && ! $dim ) {
				continue;
			}
			$inner = '<span class="shf-box" aria-hidden="true"></span><span class="shf-name">' . esc_html( $o['label'] ) . '</span>'
				. ( $f['counts'] ? self::count_html( $n ) : '' );
			if ( 0 === $n && ! $on ) {
				$items[] = '<div class="shf-item" role="listitem"><span class="shf-opt is-empty" role="checkbox" aria-checked="false" aria-disabled="true">' . $inner . '</span></div>';
				continue;
			}
			$items[] = '<div class="shf-item" role="listitem"><a class="shf-opt' . ( $on ? ' is-on' : '' ) . '" rel="nofollow" role="checkbox" aria-checked="' . ( $on ? 'true' : 'false' ) . '"'
				. ' href="' . esc_url( SHF_Request::url_toggle( $attr, $tok ) ) . '" data-shf-attr="' . esc_attr( $attr ) . '" data-shf-token="' . esc_attr( $tok ) . '">'
				. $inner . '</a></div>';
		}
		if ( ! $items ) {
			return '';
		}
		$title = '' !== $f['title'] ? $f['title'] : ( SHF_Settings::attributes()[ $attr ] ?? $attr );
		return self::shell( 'attr', $title, $f['open'] || (bool) $selected, '<div class="shf-list" role="list">' . implode( '', $items ) . '</div>' );
	}

	/* =====================================================================
	 * Category filter (tree)
	 * =================================================================== */

	private static function filter_cat( array $f ): string {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		if ( ! is_array( $terms ) || ! $terms ) {
			return '';
		}
		$children = array();
		$parents  = array();
		$names    = array();
		foreach ( $terms as $t ) {
			$id                         = (int) $t->term_id;
			$parents[ $id ]             = (int) $t->parent;
			$names[ $id ]               = (string) $t->name;
			$children[ (int) $t->parent ][] = $id;
		}
		$counts  = SHF_Index::cat_counts( $parents );
		$current = SHF_Request::current_cat();
		$open    = array();
		$t       = $current;
		$guard   = 0;
		while ( $t > 0 && $guard++ < 20 ) {
			$open[ $t ] = true;
			$t          = $parents[ $t ] ?? 0;
		}

		$tree = self::cat_level( 0, $children, $names, $counts, $current, $open, $f['counts'], 0 );
		if ( '' === $tree ) {
			return '';
		}
		$all = '';
		if ( $current > 0 ) {
			$all = '<div class="shf-item" role="listitem"><div class="shf-row"><a class="shf-opt shf-cat shf-all" href="' . esc_url( SHF_Request::url_category( 0 ) ) . '">'
				. '<span class="shf-name">' . esc_html__( 'Όλα τα προϊόντα', 'shop-filters' ) . '</span></a></div></div>';
		}
		$title = '' !== $f['title'] ? $f['title'] : __( 'Κατηγορίες', 'shop-filters' );
		return self::shell( 'cat', $title, $f['open'] || $current > 0, '<div class="shf-list shf-tree" role="list">' . $all . $tree . '</div>' );
	}

	private static function cat_level( int $parent, array $children, array $names, array $counts, int $current, array $open, bool $show_counts, int $depth ): string {
		if ( empty( $children[ $parent ] ) || $depth > 10 ) {
			return '';
		}
		$out = '';
		foreach ( $children[ $parent ] as $id ) {
			$n = $counts[ $id ] ?? 0;
			if ( 0 === $n && ! isset( $open[ $id ] ) ) {
				continue;
			}
			$sub     = self::cat_level( $id, $children, $names, $counts, $current, $open, $show_counts, $depth + 1 );
			$is_open = isset( $open[ $id ] );
			$cls     = 'shf-item shf-depth-' . min( $depth, 5 ) . ( '' !== $sub ? ' has-sub' : '' ) . ( $is_open ? ' is-open' : '' );
			$link    = '<a class="shf-opt shf-cat' . ( $id === $current ? ' is-on' : '' ) . '" href="' . esc_url( SHF_Request::url_category( $id ) ) . '"'
				. ( $id === $current ? ' aria-current="page"' : '' ) . '>'
				. '<span class="shf-name">' . esc_html( $names[ $id ] ) . '</span>' . ( $show_counts ? self::count_html( $n ) : '' ) . '</a>';
			$tog = '';
			if ( '' !== $sub ) {
				$tog = '<button type="button" class="shf-tog" aria-expanded="' . ( $is_open ? 'true' : 'false' ) . '" aria-controls="shf-sub-' . $id . '" hidden>'
					. self::chevron( 'shf-chev' ) . '</button>';
				$sub = '<div class="shf-sub" id="shf-sub-' . $id . '" role="list">' . $sub . '</div>';
			}
			$out .= '<div class="' . esc_attr( $cls ) . '" role="listitem"><div class="shf-row">' . $link . $tog . '</div>' . $sub . '</div>';
		}
		return $out;
	}

	/* =====================================================================
	 * Price filter
	 * =================================================================== */

	private static function filter_price( array $f ): string {
		$bounds = SHF_Index::price_bounds();
		if ( null === $bounds || $bounds[1] <= $bounds[0] ) {
			return '';
		}
		$p   = SHF_Request::price();
		$min = null === $p ? $bounds[0] : max( $bounds[0], min( $bounds[1], $p[0] ) );
		$max = null === $p ? $bounds[1] : max( $bounds[0], min( $bounds[1], $p[1] ) );
		if ( $min > $max ) {
			$min = $bounds[0];
			$max = $bounds[1];
		}

		// A GET form replaces the whole query string: keep the other parameters.
		$base   = SHF_Request::base_url();
		$action = strtok( $base, '?' );
		$hidden = '';
		$query  = (string) wp_parse_url( $base, PHP_URL_QUERY );
		parse_str( $query, $args );
		foreach ( (array) $args as $k => $v ) {
			if ( in_array( $k, array( 'min_price', 'max_price', 'paged', 'product-page' ), true ) || ! is_string( $v ) ) {
				continue;
			}
			$hidden .= '<input type="hidden" name="' . esc_attr( (string) $k ) . '" value="' . esc_attr( $v ) . '" />';
		}

		$cur  = get_woocommerce_currency_symbol();
		$lo   = SHF_Request::num( $bounds[0] );
		$hi   = SHF_Request::num( $bounds[1] );
		$body = '<form class="shf-price" method="get" action="' . esc_url( $action . '#' . SHF_Request::ANCHOR ) . '" data-shf-min="' . esc_attr( $lo ) . '" data-shf-max="' . esc_attr( $hi ) . '">'
			. $hidden
			. '<div class="shf-range" hidden>'
			. '<input type="range" class="shf-range-min" min="' . esc_attr( $lo ) . '" max="' . esc_attr( $hi ) . '" step="1" value="' . esc_attr( SHF_Request::num( $min ) ) . '" aria-label="' . esc_attr__( 'Ελάχιστη τιμή', 'shop-filters' ) . '" />'
			. '<input type="range" class="shf-range-max" min="' . esc_attr( $lo ) . '" max="' . esc_attr( $hi ) . '" step="1" value="' . esc_attr( SHF_Request::num( $max ) ) . '" aria-label="' . esc_attr__( 'Μέγιστη τιμή', 'shop-filters' ) . '" />'
			. '</div>'
			. '<div class="shf-price-fields">'
			. '<label class="shf-field"><span class="shf-field-label">' . esc_html__( 'Από', 'shop-filters' ) . ' (' . esc_html( html_entity_decode( $cur, ENT_QUOTES, 'UTF-8' ) ) . ')</span>'
			. '<input type="number" class="shf-num shf-num-min" name="min_price" inputmode="numeric" min="' . esc_attr( $lo ) . '" max="' . esc_attr( $hi ) . '" step="1" value="' . esc_attr( SHF_Request::num( $min ) ) . '" /></label>'
			. '<label class="shf-field"><span class="shf-field-label">' . esc_html__( 'Έως', 'shop-filters' ) . ' (' . esc_html( html_entity_decode( $cur, ENT_QUOTES, 'UTF-8' ) ) . ')</span>'
			. '<input type="number" class="shf-num shf-num-max" name="max_price" inputmode="numeric" min="' . esc_attr( $lo ) . '" max="' . esc_attr( $hi ) . '" step="1" value="' . esc_attr( SHF_Request::num( $max ) ) . '" /></label>'
			. '</div>'
			. '<button type="submit" class="shf-btn shf-price-go">' . esc_html__( 'Εφαρμογή', 'shop-filters' ) . '</button>'
			. '</form>';
		$title = '' !== $f['title'] ? $f['title'] : __( 'Τιμή', 'shop-filters' );
		return self::shell( 'price', $title, $f['open'] || null !== $p, $body );
	}
}

/**
 * Classic widget "Noxpress: Φίλτρα": shows the filter set of the current
 * page (by category) or a chosen set. Prints nothing (not even the theme's
 * widget wrapper) when there is nothing to show.
 */
final class SHF_Widget extends WP_Widget {

	public function __construct() {
		parent::__construct(
			'shf_filters',
			__( 'Noxpress: Φίλτρα', 'shop-filters' ),
			array(
				'classname'             => 'shf-widget',
				'description'           => __( 'Φίλτρα προϊόντων του Shop Filters (σετ φίλτρων ανά κατηγορία).', 'shop-filters' ),
				'customize_selective_refresh' => false,
			)
		);
	}

	/**
	 * @param array $args     Sidebar arguments.
	 * @param array $instance Widget settings.
	 */
	public function widget( $args, $instance ) {
		if ( Shop_Filters::killed() ) {
			return;
		}
		$html = SHF_Render::render( isset( $instance['set'] ) ? sanitize_key( (string) $instance['set'] ) : 'auto' );
		if ( '' === $html ) {
			return;
		}
		$title = isset( $instance['title'] ) ? (string) $instance['title'] : '';
		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput -- theme markup.
		if ( '' !== $title ) {
			echo $args['before_title'] . esc_html( apply_filters( 'widget_title', $title, $instance, $this->id_base ) ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput -- theme markup.
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in SHF_Render.
		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput -- theme markup.
	}

	/** @param array $instance Widget settings. */
	public function form( $instance ) {
		$title = isset( $instance['title'] ) ? (string) $instance['title'] : '';
		$set   = isset( $instance['set'] ) ? (string) $instance['set'] : 'auto';
		$sets  = SHF_Settings::sets();
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Τίτλος (προαιρετικός):', 'shop-filters' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'set' ) ); ?>"><?php esc_html_e( 'Σετ φίλτρων:', 'shop-filters' ); ?></label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'set' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'set' ) ); ?>">
				<option value="auto" <?php selected( $set, 'auto' ); ?>><?php esc_html_e( 'Αυτόματα, ανά κατηγορία', 'shop-filters' ); ?></option>
				<?php foreach ( $sets as $s ) : ?>
					<option value="<?php echo esc_attr( $s['id'] ); ?>" <?php selected( $set, $s['id'] ); ?>><?php echo esc_html( '' !== $s['name'] ? $s['name'] : $s['id'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php if ( ! $sets ) : ?>
			<p class="description"><?php esc_html_e( 'Δεν υπάρχει ακόμα σετ φίλτρων: φτιάξε ένα στο Noxpress → Shop Filters.', 'shop-filters' ); ?></p>
		<?php endif; ?>
		<?php
		return '';
	}

	/**
	 * @param array $new_instance New settings.
	 * @param array $old_instance Old settings.
	 */
	public function update( $new_instance, $old_instance ) {
		$set = isset( $new_instance['set'] ) ? sanitize_key( (string) $new_instance['set'] ) : 'auto';
		if ( 'auto' !== $set && null === SHF_Settings::set( $set ) ) {
			$set = 'auto';
		}
		return array(
			'title' => isset( $new_instance['title'] ) ? sanitize_text_field( (string) $new_instance['title'] ) : '',
			'set'   => $set,
		);
	}
}
