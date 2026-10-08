<?php
/**
 * LF_Replace — "replace" mode and archive text fixes.
 *
 * Replace mode swaps a theme override for WooCommerce's own template, so
 * the area gets the standard card with every hook (price, button, rating,
 * third-party additions) and a correct loop:
 *  - shop        template_include (priority 99, after WooCommerce's
 *                loader at 10) → templates/archive-product.php
 *  - related / upsells / crosssells
 *                wc_get_template → WooCommerce's template of that name
 *  - content-product.php
 *                wc_get_template_part → WooCommerce's card, while a
 *                replaced area is being drawn (otherwise a theme card
 *                override would come back inside the standard template)
 * Only theme files are swapped: if the located file is not inside the
 * active theme (or its parent), nothing happens.
 *
 * Text fixes: exact find → replace pairs (replacement: WooCommerce page
 * title or custom text) applied to the HTML of product archive templates
 * through a small wrapper template. No pairs → no wrapper at all.
 */

defined( 'ABSPATH' ) || exit;

final class LF_Replace {

	const HANDLER = 'LF_Replace::text_fixes';

	/** > 0 while a replaced area is drawn (forces the standard card). */
	private static $std_depth = 0;

	/** Template wrapped for text fixes (read by templates/archive-wrapper.php). */
	private static $wrapped = '';

	/** Whole-request decision (computed once on 'wp'). */
	private static $on = false;

	public static function init(): void {
		add_action( 'wp', array( __CLASS__, 'boot' ), 20 );
	}

	public static function boot(): void {
		try {
			if ( ! LF_Runtime::request_ok() || ! LF_Runtime::applies() ) {
				return;
			}
			$theme = LF_Settings::theme();
			if ( ! LF_Settings::has_active_areas() && empty( $theme['text_fixes'] ) ) {
				return;
			}
			self::$on = true;

			add_filter( 'template_include', array( __CLASS__, 'template_include' ), 99 );
			add_filter( 'wc_get_template', array( __CLASS__, 'wc_get_template' ), 99, 5 );
			add_filter( 'wc_get_template_part', array( __CLASS__, 'wc_get_template_part' ), 99, 3 );
			add_action( 'woocommerce_before_template_part', array( __CLASS__, 'before_part' ), 0, 1 );
			add_action( 'woocommerce_after_template_part', array( __CLASS__, 'after_part' ), 1000, 1 );
		} catch ( \Throwable $e ) {
			self::$on = false;
		}
	}

	/** The theme's file → true when $file lives in the active theme. */
	private static function is_theme_file( string $file ): bool {
		return '' !== $file && '' !== LF_Areas::theme_relative( $file );
	}

	/* =====================================================================
	 * Archive
	 * =================================================================== */

	public static function template_include( $template ) {
		if ( ! self::$on || ! is_string( $template ) ) {
			return $template;
		}
		try {
			if ( ! LF_Areas::is_product_archive() ) {
				return $template;
			}

			if ( 'replace' === LF_Settings::mode( 'shop' ) && self::is_theme_file( $template ) ) {
				$wc = LF_Areas::wc_template( 'archive-product.php' );
				if ( '' !== $wc ) {
					$template = $wc;
					++self::$std_depth; // Whole request: standard cards in the archive loop.
				}
			}

			$theme = LF_Settings::theme();
			if ( ! empty( $theme['text_fixes'] ) ) {
				self::$wrapped = $template;
				return LF_PATH . 'templates/archive-wrapper.php';
			}
		} catch ( \Throwable $e ) {
			return $template;
		}
		return $template;
	}

	/** Template the wrapper must include (always a real file). */
	public static function wrapped(): string {
		return self::$wrapped;
	}

	/** Output handler of the wrapper buffer — pure string replacement. */
	public static function text_fixes( $buffer, $phase = 0 ) {
		if ( ! is_string( $buffer ) || '' === $buffer ) {
			return $buffer;
		}
		try {
			$theme = LF_Settings::theme();
			foreach ( $theme['text_fixes'] as $fix ) {
				if ( false === strpos( $buffer, $fix['find'] ) ) {
					continue;
				}
				$replace = 'custom' === $fix['replace'] ? esc_html( $fix['custom'] ) : self::wc_title();
				$buffer  = self::replace_text( $buffer, $fix['find'], $replace );
			}
		} catch ( \Throwable $e ) {
			return $buffer;
		}
		return $buffer;
	}

	/**
	 * Replace $find; when $find is an element like "<h1>Shop</h1>", only
	 * its inner text is replaced so the theme markup stays.
	 */
	private static function replace_text( string $buffer, string $find, string $replace ): string {
		if ( 1 === preg_match( '#^(<([a-zA-Z][a-zA-Z0-9]*)\b[^>]*>)(.*)(</\2\s*>)$#s', $find, $m ) ) {
			$replace = $m[1] . $replace . $m[4];
		}
		return str_replace( $find, $replace, $buffer );
	}

	private static function wc_title(): string {
		if ( function_exists( 'woocommerce_page_title' ) ) {
			return esc_html( wp_strip_all_tags( (string) woocommerce_page_title( false ) ) );
		}
		return '';
	}

	/* =====================================================================
	 * Related / up-sells / cross-sells / content-product
	 * =================================================================== */

	public static function wc_get_template( $template, $template_name = '', $args = array(), $template_path = '', $default_path = '' ) {
		if ( ! self::$on || ! is_string( $template ) ) {
			return $template;
		}
		try {
			$area = LF_Areas::area_for_template( (string) $template_name );
			if ( '' === $area || 'replace' !== LF_Settings::mode( $area ) || ! self::is_theme_file( $template ) ) {
				return $template;
			}
			$wc = LF_Areas::wc_template( (string) $template_name );
			return '' !== $wc ? $wc : $template;
		} catch ( \Throwable $e ) {
			return $template;
		}
	}

	public static function wc_get_template_part( $template, $slug = '', $name = '' ) {
		if ( ! self::$on || self::$std_depth <= 0 || 'content' !== $slug || 'product' !== $name || ! is_string( $template ) ) {
			return $template;
		}
		try {
			if ( ! self::is_theme_file( $template ) ) {
				return $template;
			}
			$wc = LF_Areas::wc_template( 'content-product.php' );
			return '' !== $wc ? $wc : $template;
		} catch ( \Throwable $e ) {
			return $template;
		}
	}

	public static function before_part( $template_name ): void {
		$area = LF_Areas::area_for_template( (string) $template_name );
		if ( '' !== $area && 'replace' === LF_Settings::mode( $area ) ) {
			++self::$std_depth;
		}
	}

	public static function after_part( $template_name ): void {
		$area = LF_Areas::area_for_template( (string) $template_name );
		if ( '' !== $area && 'replace' === LF_Settings::mode( $area ) && self::$std_depth > 0 ) {
			--self::$std_depth;
		}
	}
}
