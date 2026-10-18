<?php
/**
 * NM_Replacer — search and replace that keeps serialized PHP data valid.
 *
 * - Plain text and JSON: one regex pass with every pair (longest first),
 *   so a new URL that contains the old one is never replaced twice.
 * - A search string that ends in a letter or digit only matches at a
 *   boundary: "https://shop.gr" does not touch "https://shop.gr.cy" or
 *   "https://shop.green.com", and "/public_html" does not touch
 *   "/public_html2".
 * - Serialized values are rewritten token by token: every s:N:"…";
 *   gets its new length, nested serialized strings are handled the same
 *   way, objects are never instantiated (no unserialize at all), array
 *   keys are left alone. If a value does not parse as serialized data,
 *   it is treated as plain text.
 */

defined( 'ABSPATH' ) || defined( 'NM_STANDALONE' ) || exit;

final class NM_Replacer {

	/** @var array from => to */
	private $map = array();
	/** @var string */
	private $regex = '';
	/** @var string[] cheap pre-check needles */
	private $needles = array();

	/** Replacements made since construction. */
	public $count = 0;

	public function __construct( array $pairs ) {
		foreach ( $pairs as $from => $to ) {
			$from = (string) $from;
			if ( '' !== $from && $from !== (string) $to ) {
				$this->map[ $from ] = (string) $to;
			}
		}
		if ( ! $this->map ) {
			return;
		}
		$keys = array_keys( $this->map );
		usort(
			$keys,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		$alts = array();
		foreach ( $keys as $k ) {
			$q = preg_quote( $k, '~' );
			if ( preg_match( '/[A-Za-z0-9]$/', $k ) ) {
				$q .= '(?![A-Za-z0-9_\-]|\.[A-Za-z0-9])';
			}
			$alts[] = $q;
		}
		$this->regex = '~(?:' . implode( '|', $alts ) . ')~';

		// Needles: the shortest distinctive part of each key.
		foreach ( $keys as $k ) {
			$n = self::needle( $k );
			if ( ! in_array( $n, $this->needles, true ) ) {
				$this->needles[] = $n;
			}
		}
	}

	public function active(): bool {
		return (bool) $this->map;
	}

	public function pairs(): array {
		return $this->map;
	}

	/** Host or last path part, without scheme or escaping. */
	private static function needle( string $k ): string {
		$k = str_replace( array( '\\/', '%2F', '%3A' ), array( '/', '/', ':' ), $k );
		$k = preg_replace( '#^[a-z]+:#i', '', $k );
		$k = ltrim( $k, '/' );
		$h = strtok( $k, '/' );
		$h = false === $h ? $k : $h;
		if ( 0 === strpos( $h, 'www.' ) ) {
			$h = substr( $h, 4 );
		}
		return '' === $h ? $k : $h;
	}

	/** True when $v may contain something to replace. */
	public function may_match( string $v ): bool {
		foreach ( $this->needles as $n ) {
			if ( false !== strpos( $v, $n ) ) {
				return true;
			}
		}
		return false;
	}

	/** Replace in one value (plain, JSON or serialized). */
	public function replace( string $v ): string {
		if ( ! $this->map || ! $this->may_match( $v ) ) {
			return $v;
		}
		if ( self::looks_serialized( $v ) ) {
			$p   = 0;
			$out = '';
			if ( $this->walk( $v, $p, $out, 0 ) && strlen( $v ) === $p ) {
				return $out;
			}
		}
		return $this->plain( $v );
	}

	private function plain( string $v ): string {
		$map = $this->map;
		$n   = 0;
		$out = preg_replace_callback(
			$this->regex,
			static function ( $m ) use ( $map ) {
				return $map[ $m[0] ];
			},
			$v,
			-1,
			$n
		);
		if ( null === $out ) {
			return $v;
		}
		$this->count += $n;
		return $out;
	}

	public static function looks_serialized( string $v ): bool {
		$v = rtrim( $v );
		if ( strlen( $v ) < 4 || ':' !== $v[1] ) {
			return false;
		}
		$c    = $v[0];
		$last = substr( $v, -1 );
		if ( 's' === $c || 'E' === $c ) {
			return '"' === substr( $v, -2, 1 ) && ';' === $last;
		}
		if ( 'a' === $c || 'O' === $c || 'C' === $c ) {
			return '}' === $last;
		}
		return false;
	}

	/**
	 * Copy one serialized value from $s at $p to $out, replacing inside
	 * strings. False when the input is not valid serialized data.
	 */
	private function walk( string $s, int &$p, string &$out, int $depth, bool $is_key = false ): bool {
		if ( $depth > 256 || ! isset( $s[ $p ] ) ) {
			return false;
		}
		$c = $s[ $p ];
		switch ( $c ) {
			case 'N':
				if ( 'N;' !== substr( $s, $p, 2 ) ) {
					return false;
				}
				$out .= 'N;';
				$p   += 2;
				return true;

			case 'b':
			case 'i':
			case 'd':
			case 'r':
			case 'R':
				$end = strpos( $s, ';', $p );
				if ( false === $end || ':' !== ( $s[ $p + 1 ] ?? '' ) ) {
					return false;
				}
				$out .= substr( $s, $p, $end - $p + 1 );
				$p    = $end + 1;
				return true;

			case 's':
				if ( ! preg_match( '/\Gs:(\d+):"/', $s, $m, 0, $p ) ) {
					return false;
				}
				$len   = (int) $m[1];
				$start = $p + strlen( $m[0] );
				if ( '";' !== substr( $s, $start + $len, 2 ) ) {
					return false;
				}
				$str = substr( $s, $start, $len );
				if ( ! $is_key && $this->may_match( $str ) ) {
					$str = $this->replace( $str );
				}
				$out .= 's:' . strlen( $str ) . ':"' . $str . '";';
				$p    = $start + $len + 2;
				return true;

			case 'E':
				if ( ! preg_match( '/\GE:(\d+):"/', $s, $m, 0, $p ) ) {
					return false;
				}
				$len   = (int) $m[1];
				$start = $p + strlen( $m[0] );
				if ( '";' !== substr( $s, $start + $len, 2 ) ) {
					return false;
				}
				$out .= substr( $s, $p, $start + $len + 2 - $p );
				$p    = $start + $len + 2;
				return true;

			case 'a':
				if ( ! preg_match( '/\Ga:(\d+):\{/', $s, $m, 0, $p ) ) {
					return false;
				}
				$out .= $m[0];
				$p   += strlen( $m[0] );
				return $this->walk_items( $s, $p, $out, $depth, (int) $m[1] );

			case 'O':
				if ( ! preg_match( '/\GO:(\d+):"/', $s, $m, 0, $p ) ) {
					return false;
				}
				$len   = (int) $m[1];
				$start = $p + strlen( $m[0] );
				if ( ! preg_match( '/\G":(\d+):\{/', $s, $m2, 0, $start + $len ) ) {
					return false;
				}
				$out .= substr( $s, $p, $start + $len - $p ) . $m2[0];
				$p    = $start + $len + strlen( $m2[0] );
				return $this->walk_items( $s, $p, $out, $depth, (int) $m2[1] );

			case 'C':
				// Custom serialization: opaque payload, copied as is.
				if ( ! preg_match( '/\GC:(\d+):"/', $s, $m, 0, $p ) ) {
					return false;
				}
				$len   = (int) $m[1];
				$start = $p + strlen( $m[0] );
				if ( ! preg_match( '/\G":(\d+):\{/', $s, $m2, 0, $start + $len ) ) {
					return false;
				}
				$dlen = (int) $m2[1];
				$dat  = $start + $len + strlen( $m2[0] );
				if ( '}' !== ( $s[ $dat + $dlen ] ?? '' ) ) {
					return false;
				}
				$out .= substr( $s, $p, $dat + $dlen + 1 - $p );
				$p    = $dat + $dlen + 1;
				return true;
		}
		return false;
	}

	private function walk_items( string $s, int &$p, string &$out, int $depth, int $n ): bool {
		for ( $i = 0; $i < $n; $i++ ) {
			if ( ! $this->walk( $s, $p, $out, $depth + 1, true ) ) {
				return false;
			}
			if ( ! $this->walk( $s, $p, $out, $depth + 1 ) ) {
				return false;
			}
		}
		if ( '}' !== ( $s[ $p ] ?? '' ) ) {
			return false;
		}
		$out .= '}';
		++$p;
		return true;
	}

	/* ---------------------------------------------------------------
	 * Pair builders
	 * ------------------------------------------------------------- */

	/**
	 * Every form of an old site URL that should become the new one:
	 * http and https, with and without www, protocol-relative, JSON
	 * escaped (\/) and URL-encoded.
	 */
	public static function url_pairs( string $old, string $new ): array {
		$old = rtrim( trim( $old ), '/' );
		$new = rtrim( trim( $new ), '/' );
		$o   = parse_url( $old ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		$n   = parse_url( $new ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		if ( empty( $o['host'] ) || empty( $n['host'] ) || $old === $new ) {
			return array();
		}
		$host  = strtolower( $o['host'] );
		$bare  = 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
		$port  = isset( $o['port'] ) ? ':' . $o['port'] : '';
		$path  = isset( $o['path'] ) ? rtrim( $o['path'], '/' ) : '';
		$nrel  = '//' . strtolower( $n['host'] ) . ( isset( $n['port'] ) ? ':' . $n['port'] : '' ) . ( isset( $n['path'] ) ? rtrim( $n['path'], '/' ) : '' );
		$hosts = array( $bare );
		if ( false === strpos( $bare, ':' ) && ! filter_var( $bare, FILTER_VALIDATE_IP ) && 'localhost' !== $bare ) {
			$hosts[] = 'www.' . $bare;
		}

		$pairs = array();
		foreach ( $hosts as $h ) {
			$rel = '//' . $h . $port . $path;
			foreach ( array( 'http:', 'https:' ) as $sch ) {
				$pairs[ $sch . $rel ] = $new;
			}
			$pairs[ $rel ] = $nrel;
		}
		$out = array();
		foreach ( $pairs as $from => $to ) {
			if ( $from === $to ) {
				continue;
			}
			$out[ $from ]                                    = $to;
			$out[ str_replace( '/', '\\/', $from ) ]         = str_replace( '/', '\\/', $to );
			$out[ str_replace( array( ':', '/' ), array( '%3A', '%2F' ), $from ) ] = str_replace( array( ':', '/' ), array( '%3A', '%2F' ), $to );
		}
		return $out;
	}

	/** An old absolute path → new one (plain and JSON escaped). */
	public static function path_pairs( string $old, string $new ): array {
		$old = rtrim( str_replace( '\\', '/', $old ), '/' );
		$new = rtrim( str_replace( '\\', '/', $new ), '/' );
		if ( '' === $old || $old === $new || strlen( $old ) < 4 ) {
			return array();
		}
		return array(
			$old                             => $new,
			str_replace( '/', '\\/', $old ) => str_replace( '/', '\\/', $new ),
		);
	}

	/**
	 * Local development URLs in a text: http(s)://localhost or
	 * 127.0.0.1, with port and first path part. base => count.
	 */
	public static function find_local( string $text, array &$found ): void {
		if ( false === strpos( $text, '://localhost' ) && false === strpos( $text, '://127.0.0.1' ) && false === strpos( $text, ':\\/\\/localhost' ) ) {
			return;
		}
		$text = str_replace( '\\/', '/', $text );
		if ( preg_match_all( '#https?://(?:localhost|127\.0\.0\.1)(?::\d{2,5})?(?:/[A-Za-z0-9._~-]+)?#', $text, $m ) ) {
			foreach ( $m[0] as $u ) {
				// "/wp-content" is not a site folder: the site is the root.
				$u           = rtrim( (string) preg_replace( '#/(wp-content|wp-includes|wp-admin|wp-json|index\.php)$#', '', $u ), '.' );
				$found[ $u ] = ( $found[ $u ] ?? 0 ) + 1;
			}
		}
	}
}
