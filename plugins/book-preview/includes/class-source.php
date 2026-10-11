<?php
/**
 * BPV_Source — which PDFs a product can use, and where they are on disk.
 *
 * A source key is "d:<download id>" (a downloadable file of the product or
 * of one of its variations) or "a:<attachment id>" (a PDF in the media
 * library). Only local files count: a downloadable file is resolved from
 * its URL when the URL points into this site (any scheme, with or without
 * "www."), or from an absolute path inside the WordPress folder. Remote
 * files are refused, because the plugin makes no external requests.
 *
 * The full PDF is only ever read here, on the server; its URL is never
 * printed on the front end.
 */

defined( 'ABSPATH' ) || exit;

final class BPV_Source {

	/**
	 * PDF downloads of a product and its variations.
	 *
	 * @return array<string, array{key:string, name:string, file:string, path:string, variation:int}> key => item
	 */
	public static function downloads( int $product_id ): array {
		$out = array();
		$p   = wc_get_product( $product_id );
		if ( ! $p ) {
			return $out;
		}
		$products = array( $p );
		if ( $p->is_type( 'variable' ) ) {
			foreach ( $p->get_children() as $vid ) {
				$v = wc_get_product( $vid );
				if ( $v ) {
					$products[] = $v;
				}
			}
		}
		foreach ( $products as $item ) {
			foreach ( $item->get_downloads( 'edit' ) as $id => $dl ) {
				$file = (string) $dl->get_file();
				$name = (string) $dl->get_name();
				if ( ! self::looks_pdf( $file ) && ! self::looks_pdf( $name ) ) {
					continue;
				}
				$key = 'd:' . sanitize_key( (string) $id );
				if ( isset( $out[ $key ] ) ) {
					continue;
				}
				$out[ $key ] = array(
					'key'       => $key,
					'name'      => '' !== $name ? $name : wp_basename( (string) wp_parse_url( $file, PHP_URL_PATH ) ),
					'file'      => $file,
					'path'      => self::local_path( $file ),
					'variation' => $item->is_type( 'variation' ) ? (int) $item->get_id() : 0,
				);
			}
		}
		return $out;
	}

	/** The first usable PDF of a product (bulk runs), or ''. */
	public static function first_key( int $product_id ): string {
		foreach ( self::downloads( $product_id ) as $key => $d ) {
			if ( '' !== $d['path'] ) {
				return $key;
			}
		}
		return '';
	}

	public static function valid_key( string $key ): bool {
		return (bool) preg_match( '/^(d:[a-z0-9_\-]{1,64}|a:\d{1,20})$/', $key );
	}

	/**
	 * Resolves a source key for a product.
	 *
	 * @return array{ok:bool, path:string, name:string, public:bool, error:string}
	 *         error: missing | remote | not_found | not_pdf
	 */
	public static function resolve( int $product_id, string $key ): array {
		$out = array(
			'ok'     => false,
			'path'   => '',
			'name'   => '',
			'public' => false,
			'error'  => 'missing',
		);
		if ( ! self::valid_key( $key ) ) {
			return $out;
		}
		if ( 0 === strpos( $key, 'a:' ) ) {
			$aid = (int) substr( $key, 2 );
			if ( 'attachment' !== get_post_type( $aid ) ) {
				return $out;
			}
			$path        = (string) get_attached_file( $aid );
			$out['name'] = wp_basename( $path );
			if ( 'application/pdf' !== get_post_mime_type( $aid ) && ! self::looks_pdf( $path ) ) {
				$out['error'] = 'not_pdf';
				return $out;
			}
			$path = self::inside_site( $path );
			if ( '' === $path ) {
				$out['error'] = 'not_found';
				return $out;
			}
			$out['ok']     = true;
			$out['path']   = $path;
			$out['public'] = true; // Media library files always have a public URL.
			$out['error']  = '';
			return $out;
		}
		$downloads = self::downloads( $product_id );
		if ( ! isset( $downloads[ $key ] ) ) {
			return $out;
		}
		$d           = $downloads[ $key ];
		$out['name'] = $d['name'];
		if ( '' === $d['path'] ) {
			$out['error'] = self::is_remote( $d['file'] ) ? 'remote' : 'not_found';
			return $out;
		}
		$out['ok']     = true;
		$out['path']   = $d['path'];
		$out['public'] = ! self::in_protected_folder( $d['path'] );
		$out['error']  = '';
		return $out;
	}

	private static function looks_pdf( string $s ): bool {
		$path = (string) wp_parse_url( $s, PHP_URL_PATH );
		return (bool) preg_match( '/\.pdf$/i', '' !== $path ? $path : $s );
	}

	private static function is_remote( string $file ): bool {
		$host = (string) wp_parse_url( $file, PHP_URL_HOST );
		return '' !== $host && ! self::same_host( $host );
	}

	private static function same_host( string $host ): bool {
		$strip = static function ( string $h ): string {
			$h = strtolower( $h );
			return 0 === strpos( $h, 'www.' ) ? substr( $h, 4 ) : $h;
		};
		foreach ( array( home_url(), site_url(), content_url() ) as $u ) {
			if ( $strip( (string) wp_parse_url( $u, PHP_URL_HOST ) ) === $strip( $host ) ) {
				return true;
			}
		}
		return false;
	}

	/** A local file path for a download's file (URL or path), or ''. */
	public static function local_path( string $file ): string {
		$file = trim( $file );
		if ( '' === $file || ! self::looks_pdf( $file ) ) {
			return '';
		}
		$host = (string) wp_parse_url( $file, PHP_URL_HOST );
		if ( '' === $host ) {
			// An absolute path, or a path relative to the site root.
			$path = 0 === strpos( $file, '/' ) && file_exists( $file ) ? $file : ABSPATH . ltrim( $file, '/' );
			return self::inside_site( $path );
		}
		if ( ! self::same_host( $host ) ) {
			return '';
		}
		$url_path = rawurldecode( (string) wp_parse_url( $file, PHP_URL_PATH ) );
		// Longest base first: uploads, wp-content, then the site root.
		$up    = wp_get_upload_dir();
		$bases = array(
			array( (string) wp_parse_url( $up['baseurl'], PHP_URL_PATH ), $up['basedir'] ),
			array( (string) wp_parse_url( content_url(), PHP_URL_PATH ), WP_CONTENT_DIR ),
			array( (string) wp_parse_url( site_url(), PHP_URL_PATH ), ABSPATH ),
		);
		foreach ( $bases as $b ) {
			$prefix = rtrim( $b[0], '/' ) . '/';
			if ( 0 === strpos( $url_path, $prefix ) ) {
				return self::inside_site( rtrim( $b[1], '/\\' ) . '/' . substr( $url_path, strlen( $prefix ) ) );
			}
		}
		return '';
	}

	/** The real path when the file exists inside the WordPress or wp-content folder, else ''. */
	private static function inside_site( string $path ): string {
		$real = realpath( $path );
		if ( false === $real || ! is_file( $real ) || ! is_readable( $real ) ) {
			return '';
		}
		foreach ( array( ABSPATH, WP_CONTENT_DIR, wp_get_upload_dir()['basedir'] ) as $root ) {
			$r = realpath( $root );
			if ( false !== $r && 0 === strpos( $real, rtrim( $r, '/\\' ) . DIRECTORY_SEPARATOR ) ) {
				return $real;
			}
		}
		return '';
	}

	/** WooCommerce's protected folder (uploads/woocommerce_uploads). */
	private static function in_protected_folder( string $path ): bool {
		$dir = realpath( wp_get_upload_dir()['basedir'] . '/woocommerce_uploads' );
		return false !== $dir && 0 === strpos( $path, $dir . DIRECTORY_SEPARATOR );
	}

	/** True when the web server is nginx (the .htaccess of woocommerce_uploads does nothing there). */
	public static function nginx(): bool {
		$sw = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( sanitize_text_field( wp_unslash( (string) $_SERVER['SERVER_SOFTWARE'] ) ) ) : '';
		return false !== strpos( $sw, 'nginx' );
	}
}
