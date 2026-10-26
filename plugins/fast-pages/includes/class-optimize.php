<?php
/**
 * FPG_Optimize — changes to the HTML of a page the cache stores, once,
 * when the file is written (FPG_Cache::finish). Pure string work, wrapped
 * in try / catch by the caller: any error = the page as it was.
 *
 *  minify   local .css / .js files (not .min.*), one minified copy per
 *           file in cache/fast-pages/min/ (MatthiasMullie\Minify, §10),
 *           never concatenated. A file that fails gets a .fail mark and
 *           is left alone. JavaScript that finds its own folder at run
 *           time (webpack chunks, currentScript, ES modules) is skipped,
 *           since a copy in another folder would load the wrong files.
 *  lazy     loading="lazy" on <img> / <iframe> without a loading
 *           attribute, except the first N images (LCP) and fetchpriority
 *           high. WordPress already does this for content images.
 *  dims     width / height from the file itself (getimagesize) on local
 *           images that have neither, at most MAX_DIMS per page.
 *  bg       inline style background images load when the element is
 *           near the screen (class fpg-lazy-bg + a small inline script).
 *  youtube  YouTube iframes become a thumbnail inside a <button>; the
 *           player loads on click (Bible §14.31).
 *
 * <script>, <style>, <noscript>, <textarea>, <template> and comments are
 * set aside first, so nothing inside them changes.
 */

defined( 'ABSPATH' ) || exit;

final class FPG_Optimize {

	const MAX_DIMS = 40;
	const MAX_MIN  = 600000;

	/** Set-aside blocks of the page being processed. */
	private static $kept = array();

	/** What the page needs at the end: 'bg', 'yt'. */
	private static $need = array();

	public static function run( string $html ): string {
		$s = FPG_Settings::get();
		if ( ! ( $s['minify_css'] || $s['minify_js'] || $s['lazy_img'] || $s['lazy_iframe'] || $s['lazy_bg'] || $s['youtube'] || $s['dims'] ) ) {
			return $html;
		}
		self::$need = array();
		if ( $s['minify_css'] || $s['minify_js'] ) {
			$html = self::minify( $html, $s );
		}
		self::$kept = array();
		$html       = self::cb(
			'#<(script|style|noscript|textarea|template)\b[^>]*>.*?</\1\s*>|<!--.*?-->#is',
			static function ( $m ) {
				self::$kept[] = $m[0];
				return "\x1AFPG" . ( count( self::$kept ) - 1 ) . "\x1A";
			},
			$html
		);
		if ( $s['lazy_img'] || $s['dims'] ) {
			$html = self::images( $html, $s );
		}
		if ( $s['youtube'] || $s['lazy_iframe'] ) {
			$html = self::iframes( $html, $s );
		}
		if ( $s['lazy_bg'] ) {
			$html = self::backgrounds( $html, $s );
		}
		$html       = self::cb(
			"#\x1AFPG([0-9]+)\x1A#",
			static function ( $m ) {
				return self::$kept[ (int) $m[1] ] ?? '';
			},
			$html
		);
		self::$kept = array();
		return self::assets( $html );
	}

	/** preg_replace_callback that throws instead of returning null (PCRE limits). */
	private static function cb( string $re, callable $fn, string $subject ): string {
		$out = preg_replace_callback( $re, $fn, $subject );
		if ( null === $out ) {
			throw new \RuntimeException( 'pcre' );
		}
		return $out;
	}

	/** A visitor text in the site's language (Bible §14.17), with a filter. */
	private static function text( string $key, string $arg = '' ): string {
		$el   = 0 === strpos( strtolower( (string) get_locale() ), 'el' );
		$all  = array(
			'play'       => $el ? 'Αναπαραγωγή: %s' : 'Play: %s',
			'play_video' => $el ? 'Αναπαραγωγή βίντεο' : 'Play video',
		);
		$text = (string) apply_filters( 'fpg_text', $all[ $key ] ?? '', $key, $el ? 'el' : 'en' );
		return '' !== $arg ? sprintf( $text, $arg ) : $text;
	}

	/* =====================================================================
	 * Attributes
	 * =================================================================== */

	/** Value of an attribute in a tag, or null. */
	public static function attr( string $tag, string $name ) {
		if ( preg_match( '/\s' . preg_quote( $name, '/' ) . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i', $tag, $m ) ) {
			$v = isset( $m[3] ) && '' !== $m[3] ? $m[3] : ( isset( $m[2] ) && '' !== $m[2] ? $m[2] : $m[1] );
			return html_entity_decode( $v, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		if ( preg_match( '/\s' . preg_quote( $name, '/' ) . '(?=[\s>\/])/i', $tag ) ) {
			return '';
		}
		return null;
	}

	/** Adds attributes before the end of an opening tag. */
	private static function add_attrs( string $tag, array $attrs ): string {
		$add = '';
		foreach ( $attrs as $k => $v ) {
			$add .= ' ' . $k . '="' . esc_attr( (string) $v ) . '"';
		}
		return (string) preg_replace( '#\s*(/?)>$#', $add . '$1>', $tag, 1 );
	}

	/** Replaces (or adds) the value of one attribute. */
	private static function set_attr( string $tag, string $name, string $value ): string {
		$re = '/(\s' . preg_quote( $name, '/' ) . '\s*=\s*)(?:"[^"]*"|\'[^\']*\'|[^\s"\'>]+)/i';
		if ( preg_match( $re, $tag ) ) {
			return self::cb(
				$re,
				static function ( $m ) use ( $value ) {
					return $m[1] . '"' . esc_attr( $value ) . '"';
				},
				$tag,
				1
			);
		}
		return self::add_attrs( $tag, array( $name => $value ) );
	}

	/** The tag matches one of the exclusion lines (in src, class, …). */
	private static function excluded( string $tag, string $key ): bool {
		foreach ( FPG_Settings::lines( $key ) as $line ) {
			if ( '' !== $line && false !== stripos( $tag, $line ) ) {
				return true;
			}
		}
		return false;
	}

	/* =====================================================================
	 * Local files
	 * =================================================================== */

	/** The file of a local URL ('' when not local or missing). */
	public static function local_path( string $url ): string {
		$url = html_entity_decode( trim( $url ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$url = preg_replace( '/[?#].*$/', '', $url );
		if ( '' === $url || 0 === strpos( $url, 'data:' ) ) {
			return '';
		}
		if ( 0 === strpos( $url, '//' ) ) {
			$url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
		} elseif ( '/' === $url[0] ) {
			$url = untrailingslashit( (string) preg_replace( '#^(https?://[^/]+).*$#', '$1', site_url() ) ) . $url;
		} elseif ( ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}
		$strip = static function ( $u ) {
			return (string) preg_replace( '#^https?:#i', '', untrailingslashit( $u ) );
		};
		$plain = $strip( $url );
		$file  = '';
		$roots = array(
			array( $strip( content_url() ), WP_CONTENT_DIR ),
			array( $strip( site_url() ), untrailingslashit( ABSPATH ) ),
		);
		foreach ( $roots as $r ) {
			if ( 0 === strpos( $plain, $r[0] . '/' ) ) {
				$file = $r[1] . rawurldecode( substr( $plain, strlen( $r[0] ) ) );
				break;
			}
		}
		if ( '' === $file ) {
			return '';
		}
		$real = realpath( $file );
		if ( false === $real || ! is_file( $real ) ) {
			return '';
		}
		$base = array( realpath( WP_CONTENT_DIR ), realpath( ABSPATH ) );
		foreach ( $base as $b ) {
			if ( $b && 0 === strpos( $real, rtrim( $b, '/' ) . '/' ) ) {
				return $real;
			}
		}
		return '';
	}

	/* =====================================================================
	 * Minify
	 * =================================================================== */

	private static function minify( string $html, array $s ): string {
		if ( $s['minify_css'] ) {
			$html = self::cb(
				'#<link\b[^>]*>#i',
				static function ( $m ) {
					$tag = $m[0];
					$rel = strtolower( (string) self::attr( $tag, 'rel' ) );
					$href = (string) self::attr( $tag, 'href' );
					if ( 'stylesheet' !== $rel || '' === $href ) {
						return $tag;
					}
					$new = self::min_file( $href, 'css' );
					return '' === $new ? $tag : self::set_attr( $tag, 'href', $new );
				},
				$html
			);
		}
		if ( $s['minify_js'] ) {
			$html = self::cb(
				'#<script\b[^>]*\ssrc\s*=[^>]*>#i',
				static function ( $m ) {
					$tag  = $m[0];
					$type = strtolower( (string) self::attr( $tag, 'type' ) );
					$src  = (string) self::attr( $tag, 'src' );
					if ( '' === $src || ( '' !== $type && ! in_array( $type, array( 'text/javascript', 'application/javascript' ), true ) ) ) {
						return $tag;
					}
					$new = self::min_file( $src, 'js' );
					return '' === $new ? $tag : self::set_attr( $tag, 'src', $new );
				},
				$html
			);
		}
		return $html;
	}

	/** URL of the minified copy of a local file ('' = keep the original). */
	private static function min_file( string $url, string $ext ): string {
		if ( self::excluded( $url, 'minify_exclude' ) ) {
			return '';
		}
		$path = self::local_path( $url );
		if ( '' === $path || strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) !== $ext || preg_match( '/[.-]min\.' . $ext . '$/i', $path ) ) {
			return '';
		}
		if ( 0 === strpos( $path, FPG_Files::dir() ) ) {
			return '';
		}
		$size = (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( $size <= 0 || $size > self::MAX_MIN ) {
			return '';
		}
		$hash = md5( $path . '|' . (int) @filemtime( $path ) . '|' . $size . '|' . FPG_VERSION ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$dir  = FPG_Files::min_dir();
		$out  = $dir . '/' . $hash . '.' . $ext;
		$new  = FPG_Files::min_url() . '/' . $hash . '.' . $ext;
		if ( is_file( $out ) ) {
			return $new;
		}
		if ( is_file( $dir . '/' . $hash . '.fail' ) ) {
			return '';
		}
		$code = (string) @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		$min  = '';
		try {
			if ( 'js' === $ext && preg_match( '/currentScript|__webpack_require__|webpackChunk|\bimport\s*\(|^\s*(import|export)\s/m', $code ) ) {
				$min = '';
			} else {
				$min = self::minify_code( $code, $ext, $url );
			}
		} catch ( \Throwable $e ) {
			$min = '';
		}
		// Nothing gained (already minified): keep the original. CSS is
		// compared with its absolute-url form, which is what the copy holds.
		$ref = 'css' === $ext ? self::absolute_css( $code, $url ) : $code;
		if ( '' === trim( $min ) || strlen( $min ) >= strlen( $ref ) ) {
			FPG_Files::write( $dir . '/' . $hash . '.fail', '' );
			return '';
		}
		return FPG_Files::write( $out, $min ) ? $new : '';
	}

	/** Minified code. CSS urls become absolute (the copy lives elsewhere). */
	private static function minify_code( string $code, string $ext, string $src ): string {
		FPG_Vendor::load();
		if ( 'css' === $ext ) {
			$code = self::absolute_css( $code, $src );
			$m    = new \MatthiasMullie\Minify\CSS();
			$m->setImportExtensions( array() );
			$m->setMaxImportSize( 0 );
			$m->add( $code );
			return (string) $m->minify();
		}
		$m = new \MatthiasMullie\Minify\JS();
		$m->add( $code );
		return (string) $m->minify() . "\n";
	}

	/** Relative url() / @import paths of a stylesheet, made absolute. */
	public static function absolute_css( string $css, string $src ): string {
		$src  = html_entity_decode( (string) preg_replace( '/[?#].*$/', '', $src ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$base = substr( $src, 0, (int) strrpos( $src, '/' ) + 1 );
		$fix  = static function ( $path ) use ( $base ) {
			$path = trim( $path );
			if ( '' === $path || preg_match( '#^(data:|https?:|//|/|\#|about:|blob:|var\()#i', $path ) ) {
				return $path;
			}
			$abs = $base . $path;
			// Resolve ./ and ../ segments.
			$parts = explode( '/', (string) preg_replace( '#^(https?:)?//[^/]+#i', '', $abs ) );
			$host  = substr( $abs, 0, strlen( $abs ) - strlen( implode( '/', $parts ) ) );
			$out   = array();
			foreach ( $parts as $p ) {
				if ( '..' === $p ) {
					if ( count( $out ) > 1 ) {
						array_pop( $out );
					}
				} elseif ( '.' !== $p ) {
					$out[] = $p;
				}
			}
			return $host . implode( '/', $out );
		};
		$css = self::cb(
			'/url\(\s*(["\']?)(.*?)\1\s*\)/i',
			static function ( $m ) use ( $fix ) {
				return 'url(' . $m[1] . $fix( $m[2] ) . $m[1] . ')';
			},
			$css
		);
		return self::cb(
			'/@import\s+(["\'])([^"\']+)\1/i',
			static function ( $m ) use ( $fix ) {
				return '@import ' . $m[1] . $fix( $m[2] ) . $m[1];
			},
			$css
		);
	}

	/* =====================================================================
	 * Images
	 * =================================================================== */

	private static function images( string $html, array $s ): string {
		$n    = 0;
		$dims = 0;
		return self::cb(
			'#<img\b[^>]*>#i',
			static function ( $m ) use ( $s, &$n, &$dims ) {
				$tag = $m[0];
				++$n;
				$src = (string) self::attr( $tag, 'src' );
				if ( $s['dims'] && $dims < self::MAX_DIMS && null === self::attr( $tag, 'width' ) && null === self::attr( $tag, 'height' ) && '' !== $src && ! preg_match( '/\.svg(\?|#|$)/i', $src ) ) {
					$path = self::local_path( $src );
					if ( '' !== $path ) {
						++$dims;
						$size = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
						if ( is_array( $size ) && $size[0] > 0 && $size[1] > 0 ) {
							$tag = self::add_attrs(
								$tag,
								array(
									'width'  => (int) $size[0],
									'height' => (int) $size[1],
								)
							);
						}
					}
				}
				if ( $s['lazy_img'] && $n > (int) $s['lazy_skip'] && self::may_lazy( $tag ) ) {
					$tag = self::add_attrs( $tag, array( 'loading' => 'lazy' ) );
				}
				return $tag;
			},
			$html
		);
	}

	private static function may_lazy( string $tag ): bool {
		if ( null !== self::attr( $tag, 'loading' ) || null !== self::attr( $tag, 'data-no-lazy' ) ) {
			return false;
		}
		if ( 'high' === strtolower( (string) self::attr( $tag, 'fetchpriority' ) ) ) {
			return false;
		}
		if ( preg_match( '/(^|\s)(skip-lazy|no-lazy|fpg-no-lazy)(\s|$)/', (string) self::attr( $tag, 'class' ) ) ) {
			return false;
		}
		if ( 0 === strpos( (string) self::attr( $tag, 'src' ), 'data:' ) ) {
			return false;
		}
		return ! self::excluded( $tag, 'lazy_exclude' );
	}

	/* =====================================================================
	 * Iframes
	 * =================================================================== */

	private static function iframes( string $html, array $s ): string {
		return self::cb(
			'#<iframe\b[^>]*>\s*</iframe\s*>#i',
			static function ( $m ) use ( $s ) {
				$tag  = $m[0];
				$open = (string) preg_replace( '#>\s*</iframe\s*>$#i', '>', $tag );
				$src  = (string) self::attr( $open, 'src' );
				if ( $s['youtube'] && ! self::excluded( $open, 'lazy_exclude' ) && false === strpos( (string) self::attr( $open, 'class' ), 'fpg-no-facade' ) ) {
					$facade = self::youtube( $open, $src );
					if ( '' !== $facade ) {
						return $facade;
					}
				}
				if ( $s['lazy_iframe'] && '' !== $src && self::may_lazy( $open ) ) {
					return self::add_attrs( $open, array( 'loading' => 'lazy' ) ) . '</iframe>';
				}
				return $tag;
			},
			$html
		);
	}

	/** The button that stands in for a YouTube iframe ('' = not YouTube). */
	private static function youtube( string $tag, string $src ): string {
		if ( ! preg_match( '#^(?:https?:)?//(?:www\.)?(?:youtube\.com|youtube-nocookie\.com)/embed/([A-Za-z0-9_-]{11})(?:\?([^\#]*))?$#i', $src, $m ) ) {
			return '';
		}
		$id   = $m[1];
		$keep = array();
		parse_str( isset( $m[2] ) ? html_entity_decode( $m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '', $q );
		foreach ( array( 'start', 'end', 'rel', 'list', 'loop', 'playlist', 'cc_load_policy', 'hl', 'controls', 'modestbranding' ) as $k ) {
			if ( isset( $q[ $k ] ) && is_scalar( $q[ $k ] ) && preg_match( '/^[A-Za-z0-9_,-]{1,64}$/', (string) $q[ $k ] ) ) {
				$keep[ $k ] = (string) $q[ $k ];
			}
		}
		$play  = 'https://www.youtube-nocookie.com/embed/' . $id . '?' . http_build_query( array_merge( array( 'autoplay' => '1' ), $keep ) );
		$title = trim( (string) self::attr( $tag, 'title' ) );
		$w     = (int) self::attr( $tag, 'width' );
		$h     = (int) self::attr( $tag, 'height' );
		$style = '';
		if ( $w > 0 && $h > 0 ) {
			$style = 'aspect-ratio:' . $w . '/' . $h . ';max-width:' . $w . 'px';
		}
		$class = trim( 'fpg-yt ' . (string) self::attr( $tag, 'class' ) );
		$label = '' !== $title ? self::text( 'play', $title ) : self::text( 'play_video' );

		self::$need['yt'] = true;
		return '<button type="button" class="' . esc_attr( $class ) . '" data-src="' . esc_url( $play ) . '" data-title="' . esc_attr( $title ) . '"'
			. ( $w > 0 ? ' data-w="' . $w . '"' : '' ) . ( $h > 0 ? ' data-h="' . $h . '"' : '' )
			. ' aria-label="' . esc_attr( $label ) . '"' . ( '' !== $style ? ' style="' . esc_attr( $style ) . '"' : '' ) . '>'
			. '<img src="' . esc_url( 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg' ) . '" alt="" loading="lazy" decoding="async" width="480" height="360">'
			. '<span class="fpg-yt-play" aria-hidden="true"></span></button>';
	}

	/* =====================================================================
	 * Background images
	 * =================================================================== */

	private static function backgrounds( string $html, array $s ): string {
		return self::cb(
			'#<(?!img\b|iframe\b|html\b|body\b|head\b)[a-z][a-z0-9-]*\b[^>]*\sstyle\s*=\s*(["\'])[^"\']*url\s*\([^>]*>#i',
			static function ( $m ) {
				$tag   = $m[0];
				$style = (string) self::attr( $tag, 'style' );
				if ( ! preg_match( '/background(-image)?\s*:[^;]*url\s*\(/i', $style ) || preg_match( '/url\s*\(\s*["\']?data:/i', $style ) ) {
					return $tag;
				}
				$class = (string) self::attr( $tag, 'class' );
				if ( preg_match( '/(^|\s)(skip-lazy|no-lazy|fpg-no-lazy)(\s|$)/', $class ) || self::excluded( $tag, 'lazy_exclude' ) ) {
					return $tag;
				}
				self::$need['bg'] = true;
				return self::set_attr( $tag, 'class', trim( $class . ' fpg-lazy-bg' ) );
			},
			$html
		);
	}

	/* =====================================================================
	 * CSS and script for the page (only what it uses, Bible §14.9)
	 * =================================================================== */

	private static function assets( string $html ): string {
		if ( ! self::$need ) {
			return $html;
		}
		$head = '';
		$css  = '';
		if ( false === stripos( $html, '</head>' ) || false === stripos( $html, '</body>' ) ) {
			return $html; // Without both, a hidden background could stay hidden.
		}
		if ( isset( self::$need['bg'] ) ) {
			$head .= '<script>document.documentElement.className+=" fpg-js";</script>';
			$css  .= '.fpg-js .fpg-lazy-bg{background-image:none!important}';
		}
		if ( isset( self::$need['yt'] ) ) {
			$css .= '.fpg-yt{position:relative;display:block;width:100%;aspect-ratio:16/9;margin:0;padding:0;border:0;background:#000;cursor:pointer;overflow:hidden}'
				. '.fpg-yt img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;margin:0}'
				. '.fpg-yt-play{position:absolute;left:50%;top:50%;width:68px;height:48px;margin:-24px 0 0 -34px;border-radius:12px;background:rgba(23,23,23,.8)}'
				. '.fpg-yt:hover .fpg-yt-play,.fpg-yt:focus .fpg-yt-play{background:#f00}'
				. '.fpg-yt-play:before{content:"";position:absolute;left:27px;top:14px;border-style:solid;border-width:10px 0 10px 18px;border-color:transparent transparent transparent #fff}'
				. '.fpg-yt-frame{display:block;width:100%;aspect-ratio:16/9;border:0}';
		}
		$head .= '<style id="fpg-css">' . $css . '</style>';
		$js    = (string) @file_get_contents( FPG_PATH . 'assets/front.js' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		$foot  = '' !== $js ? '<script id="fpg-js">' . trim( $js ) . '</script>' : '';
		if ( '' === $foot ) {
			return $html;
		}
		$at   = (int) stripos( $html, '</head>' );
		$html = substr( $html, 0, $at ) . $head . substr( $html, $at );
		$pos  = (int) strripos( $html, '</body>' );
		return substr( $html, 0, $pos ) . $foot . substr( $html, $pos );
	}
}
