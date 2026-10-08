<?php
/**
 * TP_Replace — "replace" mode.
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
 * Text fixes and other page-level rules live in TP_Page.
 */

defined( 'ABSPATH' ) || exit;

final class TP_Replace {

	/** > 0 while a replaced area is drawn (forces the standard card). */
	private static $std_depth = 0;

	/** Whole-request decision (computed once on 'wp'). */
	private static $on = false;

	public static function init(): void {
		add_action( 'wp', array( __CLASS__, 'boot' ), 20 );
	}

	public static function boot(): void {
		try {
			if ( ! TP_Runtime::request_ok() || ! TP_Runtime::applies() ) {
				return;
			}
			if ( ! TP_Settings::has_active_areas() ) {
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
		return '' !== $file && '' !== TP_Areas::theme_relative( $file );
	}

	/* =====================================================================
	 * Archive
	 * =================================================================== */

	public static function template_include( $template ) {
		if ( ! self::$on || ! is_string( $template ) ) {
			return $template;
		}
		try {
			if ( ! TP_Areas::is_product_archive() ) {
				return $template;
			}

			if ( 'replace' === TP_Settings::mode( 'shop' ) && self::is_theme_file( $template ) ) {
				$wc = TP_Areas::wc_template( 'archive-product.php' );
				if ( '' !== $wc ) {
					$template = $wc;
					++self::$std_depth; // Whole request: standard cards in the archive loop.
				}
			}
		} catch ( \Throwable $e ) {
			return $template;
		}
		return $template;
	}

	/* =====================================================================
	 * Related / up-sells / cross-sells / content-product
	 * =================================================================== */

	public static function wc_get_template( $template, $template_name = '', $args = array(), $template_path = '', $default_path = '' ) {
		if ( ! self::$on || ! is_string( $template ) ) {
			return $template;
		}
		try {
			$area = TP_Areas::area_for_template( (string) $template_name );
			if ( '' === $area || 'replace' !== TP_Settings::mode( $area ) || ! self::is_theme_file( $template ) ) {
				return $template;
			}
			$wc = TP_Areas::wc_template( (string) $template_name );
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
			$wc = TP_Areas::wc_template( 'content-product.php' );
			return '' !== $wc ? $wc : $template;
		} catch ( \Throwable $e ) {
			return $template;
		}
	}

	public static function before_part( $template_name ): void {
		$area = TP_Areas::area_for_template( (string) $template_name );
		if ( '' !== $area && 'replace' === TP_Settings::mode( $area ) ) {
			++self::$std_depth;
		}
	}

	public static function after_part( $template_name ): void {
		$area = TP_Areas::area_for_template( (string) $template_name );
		if ( '' !== $area && 'replace' === TP_Settings::mode( $area ) && self::$std_depth > 0 ) {
			--self::$std_depth;
		}
	}
}
