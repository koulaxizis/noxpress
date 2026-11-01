<?php
/**
 * RDR_Path — one way to write a path, shared by the rules, the log and
 * the admin page.
 *
 * A normalised path is decoded (μάρκα and %ce%bc%ce%ac… are the same),
 * relative to the site's home path, without slashes at the ends, without
 * query string or fragment, with single slashes, in lower case, at most
 * 500 characters. The home page is ''.
 */

defined( 'ABSPATH' ) || exit;

final class RDR_Path {

	const MAX = 500;

	/** Lower case, multibyte safe. */
	public static function lower( string $s ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	}

	/** The home path of the site, e.g. '' or 'shop' (decoded, no slashes). */
	public static function home(): string {
		static $home = null;
		if ( null === $home ) {
			$home = trim( rawurldecode( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ), '/' );
		}
		return $home;
	}

	/**
	 * Normalises a path or a URL of this site. A URL of another host gives
	 * null (it can never be an old address of this site).
	 */
	public static function norm( string $in ): ?string {
		$in = trim( $in );
		if ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $in ) || 0 === strpos( $in, '//' ) ) {
			$host = strtolower( (string) wp_parse_url( $in, PHP_URL_HOST ) );
			if ( '' === $host || ! self::is_own_host( $host ) ) {
				return null;
			}
			$in = (string) wp_parse_url( $in, PHP_URL_PATH );
		}
		$in = preg_replace( '/[?#].*$/s', '', $in );
		$in = rawurldecode( (string) $in );
		if ( function_exists( 'wp_check_invalid_utf8' ) ) {
			$in = wp_check_invalid_utf8( $in, true );
		}
		$in = str_replace( '\\', '/', $in );
		$in = (string) preg_replace( '#/{2,}#', '/', $in );
		$in = trim( $in, "/ \t\n\r\0\x0B" );

		$home = self::home();
		if ( '' !== $home ) {
			if ( $in === $home ) {
				$in = '';
			} elseif ( 0 === strpos( $in . '/', $home . '/' ) ) {
				$in = ltrim( substr( $in, strlen( $home ) ), '/' );
			}
		}
		$in = self::lower( $in );
		if ( function_exists( 'mb_substr' ) ) {
			$in = mb_substr( $in, 0, self::MAX, 'UTF-8' );
		} else {
			$in = substr( $in, 0, self::MAX );
		}
		return $in;
	}

	/** True for the site's host, with or without www. */
	public static function is_own_host( string $host ): bool {
		$own  = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$bare = static function ( string $h ): string {
			return 0 === strpos( $h, 'www.' ) ? substr( $h, 4 ) : $h;
		};
		return '' !== $own && $bare( $own ) === $bare( strtolower( $host ) );
	}

	/** Hash of a normalised path (index of the rules table). */
	public static function hash( string $norm ): string {
		return md5( $norm );
	}

	/** The normalised path of the current request, or null. */
	public static function request(): ?string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- decoded and normalised here, only compared.
		if ( '' === $uri ) {
			return null;
		}
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		return self::norm( $path );
	}

	/** The raw query string of the current request ('' when none). */
	public static function request_query(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- passed on to a URL we build, through add_query_arg rules below.
		$q   = (string) wp_parse_url( $uri, PHP_URL_QUERY );
		return $q;
	}

	/** URL of a path of this site, each segment encoded. */
	public static function url( string $path ): string {
		$path = trim( $path, '/' );
		if ( '' === $path ) {
			return home_url( '/' );
		}
		$segs = array_map( 'rawurlencode', explode( '/', $path ) );
		$rel  = implode( '/', $segs );
		// A file keeps its name; a page follows the permalink structure.
		return home_url( false !== strpos( (string) end( $segs ), '.' ) ? $rel : user_trailingslashit( $rel ) );
	}

	/**
	 * Matches $path against a glob of the bot list (* = anything).
	 * Both are lower case.
	 */
	public static function glob( string $pattern, string $path ): bool {
		$re = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '$#su';
		return 1 === preg_match( $re, $path );
	}
}
