<?php
/**
 * GLP_Hooks — automatic conversion of new slugs and file names.
 *
 *  - sanitize_title (priority 9, before WordPress's own dashes at 10):
 *    only in the 'save' context, i.e. when a slug is built (posts, pages,
 *    products, terms, users, attachments). Lookups ('query' context) are
 *    left alone, so existing Greek slugs keep opening.
 *  - sanitize_taxonomy_name: WooCommerce finds an attribute's taxonomy
 *    by passing its name through sanitize_title(). For an attribute created
 *    with a Greek name before this plugin, the Greek (encoded) name is kept
 *    when only that one exists.
 *  - sanitize_file_name (only with the "files" setting): new uploads and
 *    every other file name WordPress builds.
 *
 * Existing slugs and files never change here; that is GLP_Bulk's job.
 */

defined( 'ABSPATH' ) || exit;

final class GLP_Hooks {

	/** True while the bulk undo writes old Greek slugs back. */
	public static $paused = false;

	public static function init(): void {
		add_filter( 'sanitize_title', array( __CLASS__, 'title' ), 9, 3 );
		add_filter( 'get_terms_args', array( __CLASS__, 'term_slugs' ), 10, 2 );
		add_filter( 'sanitize_file_name', array( __CLASS__, 'file' ), 10, 2 );
		add_filter( 'sanitize_taxonomy_name', array( __CLASS__, 'taxonomy_name' ), 10, 2 );
	}

	/** sanitize_title filter. */
	public static function title( $title, $raw = '', $context = 'save' ) {
		if ( self::$paused || 'save' !== $context || ! is_string( $title ) || ! GLP_Converter::has_greek( $title ) ) {
			return $title;
		}
		$s = GLP_Settings::get();
		if ( ! $s['slugs'] ) {
			return $title;
		}
		return glp_convert( $title, 'slug' );
	}

	/**
	 * get_terms_args filter. WordPress looks terms up by slug through
	 * sanitize_title() in the 'save' context (term archives, get_term_by(),
	 * term_exists()), so a Greek slug would be converted before the lookup
	 * and an old Greek slug never found. The Greek (encoded) form is added
	 * next to it: old Greek terms keep working, converted ones are found by
	 * their old address (GLP_Redirects then sends a 301 to the new one).
	 */
	public static function term_slugs( $args, $taxonomies = array() ) {
		if ( ! is_array( $args ) || empty( $args['slug'] ) ) {
			return $args;
		}
		$slugs = (array) $args['slug'];
		$add   = array();
		foreach ( $slugs as $slug ) {
			if ( is_string( $slug ) && GLP_Converter::has_greek( $slug ) ) {
				$add[] = sanitize_title_with_dashes( $slug, '', 'save' );
			}
		}
		if ( $add ) {
			$args['slug'] = array_values( array_unique( array_merge( $slugs, $add ) ) );
		}
		return $args;
	}

	/** sanitize_file_name filter. */
	public static function file( $filename, $raw = '' ) {
		if ( self::$paused || ! is_string( $filename ) || ! GLP_Converter::has_greek( $filename ) ) {
			return $filename;
		}
		$s = GLP_Settings::get();
		if ( ! $s['files'] ) {
			return $filename;
		}
		return glp_convert( $filename, 'file' );
	}

	/**
	 * sanitize_taxonomy_name filter (wc_sanitize_taxonomy_name()): keep the
	 * Greek name of an attribute created before the plugin, when the Latin
	 * one does not exist.
	 *
	 * @param string $name     Sanitized name (already converted by title()).
	 * @param string $original Name as given.
	 */
	public static function taxonomy_name( $name, $original = '' ) {
		static $busy = false;
		if ( $busy || ! is_string( $name ) || ! is_string( $original ) || ! GLP_Converter::has_greek( urldecode( $original ) )
			|| ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return $name;
		}
		// What WooCommerce made of the name without this plugin: raw Greek.
		$legacy = urldecode( sanitize_title_with_dashes( urldecode( $original ), '', 'save' ) );
		if ( $legacy === $name ) {
			return $name;
		}
		$busy  = true;
		$names = wp_list_pluck( (array) wc_get_attribute_taxonomies(), 'attribute_name' ); // Cached by WooCommerce.
		$busy  = false;
		if ( in_array( $legacy, $names, true ) && ! in_array( $name, $names, true ) ) {
			return $legacy;
		}
		return $name;
	}
}
