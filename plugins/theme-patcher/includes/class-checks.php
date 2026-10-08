<?php
/**
 * TP_Checks — admin checks of the shop setup (read only).
 *
 *  - Filter widgets: on a classic theme, WooCommerce's block filters
 *    (price, attribute, stock, rating, active filters, product filters)
 *    placed as widgets often do not filter a theme-drawn product archive,
 *    while the classic widgets ("Filter Products by Price / Attribute /
 *    Rating", "Active Product Filters") do. The check lists block widgets with
 *    such blocks that sit in an active sidebar and shows a notice on the
 *    Widgets screen and on Theme Patcher's pages.
 */

defined( 'ABSPATH' ) || exit;

final class TP_Checks {

	/** Block names (prefix match) of WooCommerce filter blocks. */
	const FILTER_BLOCKS = array(
		'woocommerce/price-filter',
		'woocommerce/attribute-filter',
		'woocommerce/stock-filter',
		'woocommerce/rating-filter',
		'woocommerce/active-filters',
		'woocommerce/filter-wrapper',
		'woocommerce/product-filter',
	);

	public static function init(): void {
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/**
	 * Block widgets with WooCommerce filter blocks in active sidebars.
	 *
	 * @return array<int, array{sidebar:string, blocks:string[]}>
	 */
	public static function filter_widgets(): array {
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			return array(); // Block themes use these blocks natively.
		}
		$instances = get_option( 'widget_block', array() );
		$sidebars  = wp_get_sidebars_widgets();
		if ( ! is_array( $instances ) || ! is_array( $sidebars ) ) {
			return array();
		}
		$out = array();
		foreach ( $sidebars as $sidebar => $widgets ) {
			if ( 'wp_inactive_widgets' === $sidebar || ! is_array( $widgets ) ) {
				continue;
			}
			foreach ( $widgets as $widget_id ) {
				if ( ! preg_match( '/^block-(\d+)$/', (string) $widget_id, $m ) || empty( $instances[ (int) $m[1] ]['content'] ) ) {
					continue;
				}
				$content = (string) $instances[ (int) $m[1] ]['content'];
				$found   = array();
				if ( preg_match_all( '/<!--\s*wp:(woocommerce\/[a-z0-9-]+)/', $content, $bm ) ) {
					foreach ( array_unique( $bm[1] ) as $name ) {
						foreach ( self::FILTER_BLOCKS as $prefix ) {
							if ( 0 === strpos( $name, $prefix ) ) {
								$found[] = $name;
								break;
							}
						}
					}
				}
				if ( $found ) {
					$out[] = array(
						'sidebar' => self::sidebar_name( (string) $sidebar ),
						'blocks'  => $found,
					);
				}
			}
		}
		return $out;
	}

	private static function sidebar_name( string $id ): string {
		global $wp_registered_sidebars;
		return isset( $wp_registered_sidebars[ $id ]['name'] ) ? (string) $wp_registered_sidebars[ $id ]['name'] : $id;
	}

	/** Notice on the Widgets screen and on Theme Patcher's pages. */
	public static function notice(): void {
		try {
			if ( ! current_user_can( 'edit_theme_options' ) ) {
				return;
			}
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			$id     = $screen ? (string) $screen->id : '';
			if ( 'widgets' !== $id && false === strpos( $id, 'tp-' ) ) {
				return;
			}
			$found = self::filter_widgets();
			if ( ! $found ) {
				return;
			}
			$where = array();
			foreach ( $found as $f ) {
				$where[] = $f['sidebar'];
			}
			echo '<div class="notice notice-warning"><p><strong>Theme Patcher:</strong> '
				. esc_html(
					sprintf(
						/* translators: %s: widget area names */
						__( 'Βρέθηκαν φίλτρα WooCommerce σε μορφή block (%s). Σε κλασικά θέματα με δική τους σελίδα καταστήματος συχνά δεν φιλτράρουν τα προϊόντα. Αν δεν δουλεύουν, χρησιμοποίησε τα κλασικά widgets «Φιλτράρισμα προϊόντων κατά τιμή», «… κατά χαρακτηριστικό», «… κατά βαθμολογία» και «Ενεργά φίλτρα προϊόντων».', 'theme-patcher' ),
						implode( ', ', array_unique( $where ) )
					)
				)
				. '</p></div>';
		} catch ( \Throwable $e ) {
			return;
		}
	}
}
