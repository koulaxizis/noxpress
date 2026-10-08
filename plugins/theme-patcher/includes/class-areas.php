<?php
/**
 * TP_Areas — where a product loop is drawn, and which fixes it accepts.
 *
 * Built-in areas:
 *  - shop        main query on product archives (shop, product taxonomies,
 *                product search). Modes: off / inject / replace.
 *  - related     single-product/related.php  (off / inject / replace)
 *  - upsells     single-product/up-sells.php (off / inject / replace)
 *  - crosssells  cart/cross-sells.php        (off / inject / replace)
 *
 * File areas ("file:<theme-relative path>"): any theme file that runs its
 * own product WP_Query (home sections, template parts…). There is no
 * WooCommerce template to fall back to, so they accept off / inject only.
 */

defined( 'ABSPATH' ) || exit;

final class TP_Areas {

	const FILE_PREFIX = 'file:';

	/** id => [label msgid, WooCommerce template name or '' ]. */
	public static function builtin(): array {
		return array(
			'shop'       => array(
				'label'    => 'Κατάστημα, κατηγορίες & αναζήτηση προϊόντων',
				'template' => '',
			),
			'related'    => array(
				'label'    => 'Σχετικά προϊόντα',
				'template' => 'single-product/related.php',
			),
			'upsells'    => array(
				'label'    => 'Up-sells (σελίδα προϊόντος)',
				'template' => 'single-product/up-sells.php',
			),
			'crosssells' => array(
				'label'    => 'Cross-sells (καλάθι)',
				'template' => 'cart/cross-sells.php',
			),
		);
	}

	public static function is_file_area( string $id ): bool {
		return 0 === strpos( $id, self::FILE_PREFIX );
	}

	public static function file_of( string $id ): string {
		return self::is_file_area( $id ) ? substr( $id, strlen( self::FILE_PREFIX ) ) : '';
	}

	/** Allowed modes of an area. */
	public static function modes_for( string $id ): array {
		return self::is_file_area( $id ) ? array( 'off', 'inject' ) : TP_Settings::MODES;
	}

	/** Translated label of an area. */
	public static function label( string $id ): string {
		$b = self::builtin();
		if ( isset( $b[ $id ] ) ) {
			return __( $b[ $id ]['label'], 'theme-patcher' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- msgid from the registry.
		}
		/* translators: %s: theme-relative file path */
		return sprintf( __( 'Αρχείο θέματος: %s', 'theme-patcher' ), self::file_of( $id ) );
	}

	/** Built-in area id for a WooCommerce template name ('' when none). */
	public static function area_for_template( string $template_name ): string {
		foreach ( self::builtin() as $id => $def ) {
			if ( '' !== $def['template'] && $def['template'] === $template_name ) {
				return $id;
			}
		}
		return '';
	}

	/** Shop, product taxonomy or product search request. */
	public static function is_product_archive(): bool {
		if ( function_exists( 'is_shop' ) && ( is_shop() || is_product_taxonomy() ) ) {
			return true;
		}
		if ( is_search() ) {
			$pt = get_query_var( 'post_type' );
			return 'product' === $pt || ( is_array( $pt ) && array( 'product' ) === array_values( $pt ) );
		}
		return false;
	}

	/** Directories of the active theme (child first, then parent). */
	public static function theme_dirs(): array {
		$dirs = array( wp_normalize_path( get_stylesheet_directory() ) );
		$tpl  = wp_normalize_path( get_template_directory() );
		if ( ! in_array( $tpl, $dirs, true ) ) {
			$dirs[] = $tpl;
		}
		return $dirs;
	}

	/** Theme-relative path of an absolute file, or '' when outside the theme. */
	public static function theme_relative( string $file ): string {
		$file = wp_normalize_path( $file );
		foreach ( self::theme_dirs() as $dir ) {
			$dir = trailingslashit( $dir );
			if ( 0 === strpos( $file, $dir ) ) {
				return substr( $file, strlen( $dir ) );
			}
		}
		return '';
	}

	/** Absolute path of a theme-relative file (child first), '' when missing. */
	public static function theme_file( string $rel ): string {
		foreach ( self::theme_dirs() as $dir ) {
			$abs = trailingslashit( $dir ) . $rel;
			if ( is_file( $abs ) ) {
				return $abs;
			}
		}
		return '';
	}

	/** Absolute path of a WooCommerce core template, '' when missing. */
	public static function wc_template( string $template_name ): string {
		if ( ! function_exists( 'WC' ) ) {
			return '';
		}
		$abs = trailingslashit( WC()->plugin_path() ) . 'templates/' . $template_name;
		return is_file( $abs ) ? $abs : '';
	}
}
