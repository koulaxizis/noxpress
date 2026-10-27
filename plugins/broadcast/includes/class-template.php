<?php
/**
 * BRC_Template — placeholders in the per-source templates.
 *
 * {title} {url} {text} {price} {preorder} {excerpt} {category} {date}
 * {count} {list} {recipients} {site}. A placeholder a source does not
 * fill becomes empty; a line that only had placeholders and ends up empty
 * is dropped, so "{price}{preorder}" without a price leaves no blank line.
 */

defined( 'ABSPATH' ) || exit;

final class BRC_Template {

	const KEYS = array( 'title', 'url', 'text', 'price', 'preorder', 'excerpt', 'category', 'date', 'count', 'list', 'recipients', 'site' );

	/** Placeholders each source fills (for the templates screen). */
	public static function keys_for( string $src ): array {
		$map = array(
			'products'   => array( 'title', 'url', 'price', 'preorder', 'excerpt', 'category', 'site' ),
			'newsletter' => array( 'title', 'url', 'recipients', 'date', 'site' ),
			'youtube'    => array( 'title', 'url', 'date', 'site' ),
			'queue'      => array( 'text', 'url', 'site' ),
			'digest'     => array( 'count', 'list', 'url', 'site' ),
		);
		return isset( $map[ $src ] ) ? $map[ $src ] : array();
	}

	public static function render( string $tpl, array $vars ): string {
		$vars['site'] = isset( $vars['site'] ) ? $vars['site'] : wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$out          = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $tpl ) as $line ) {
			$had  = false;
			$line = preg_replace_callback(
				'/\{([a-z]+)\}/',
				static function ( $m ) use ( $vars, &$had ) {
					if ( ! in_array( $m[1], self::KEYS, true ) ) {
						return $m[0];
					}
					$had = true;
					return isset( $vars[ $m[1] ] ) && is_scalar( $vars[ $m[1] ] ) ? (string) $vars[ $m[1] ] : '';
				},
				$line
			);
			if ( $had && '' === trim( (string) $line ) ) {
				continue;
			}
			$out[] = rtrim( (string) $line );
		}
		$text = implode( "\n", $out );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );
		return trim( (string) $text );
	}
}
