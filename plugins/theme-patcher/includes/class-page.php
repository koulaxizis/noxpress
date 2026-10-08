<?php
/**
 * TP_Page — page-level rules applied to the final HTML of a page.
 *
 *  - Text table: exact "theme text → my text" pairs, per scope (whole
 *    site / shop pages / home page / product pages). When the text found
 *    is a whole element (e.g. "<h1>Shop</h1>") only its inner text is
 *    replaced. Replacement: custom text (simple HTML allowed) or the
 *    WooCommerce page title.
 *  - Element removal: "tag.class", ".class" or "#id" (e.g. a theme credit).
 *  - Links to this site open in the same tab (target="_blank" removed).
 *  - Category images: the full-size file is swapped for a thumbnail and
 *    an empty alt gets the category name.
 *  - Images of the media library with an empty alt get their alt text.
 *  - Links and buttons without an accessible name get an aria-label
 *    derived from their icon, address or role (social networks, cart,
 *    search, menu, previous / next, back to top…).
 *  - CSS: area styles (slot, sale badge, title lines / size, hidden theme
 *    badge) and the theme's extra CSS, printed once in <head>.
 *
 * The page template runs inside templates/page-wrapper.php, whose buffer
 * handler (self::process) is pure string work — no ob_* calls, no
 * database writes. Any Throwable returns the HTML untouched.
 */

defined( 'ABSPATH' ) || exit;

final class TP_Page {

	const HANDLER = 'TP_Page::process';

	/** Max media-library lookups (attachment_url_to_postid) per page. */
	const MAX_LOOKUPS = 25;

	/** Template wrapped (read by templates/page-wrapper.php). */
	private static $wrapped = '';

	/** Texts of the current scope. */
	private static $texts = array();

	/** Page rules of the active theme. */
	private static $page = array();

	/** Counters for the page probe. */
	private static $stats = array(
		'texts'    => 0,
		'removed'  => 0,
		'same_tab' => 0,
		'cat_img'  => 0,
		'alt'      => 0,
		'aria'     => 0,
	);

	public static function init(): void {
		add_action( 'wp', array( __CLASS__, 'boot' ), 21 );
	}

	public static function boot(): void {
		try {
			if ( ! TP_Runtime::request_ok() || ! TP_Runtime::applies() || ! TP_Settings::has_anything() ) {
				return;
			}
			$theme      = TP_Settings::theme();
			self::$page = $theme['page'];

			foreach ( $theme['texts'] as $fix ) {
				if ( self::in_scope( $fix['scope'] ) ) {
					self::$texts[] = $fix;
				}
			}

			add_action( 'wp_head', array( __CLASS__, 'head_css' ), 99 );

			$p = self::$page;
			if ( self::$texts || $p['remove'] || $p['same_tab'] || $p['img_alt'] || $p['aria'] || '' !== $p['cat_img_size'] ) {
				add_filter( 'template_include', array( __CLASS__, 'template_include' ), 100 );
			}
		} catch ( \Throwable $e ) {
			self::$texts = array();
		}
	}

	public static function in_scope( string $scope ): bool {
		switch ( $scope ) {
			case 'shop':
				return TP_Areas::is_product_archive();
			case 'home':
				return is_front_page();
			case 'product':
				return function_exists( 'is_product' ) && is_product();
			default:
				return true;
		}
	}

	public static function template_include( $template ) {
		if ( ! is_string( $template ) || ! is_file( $template ) ) {
			return $template;
		}
		self::$wrapped = $template;
		return TP_PATH . 'templates/page-wrapper.php';
	}

	public static function wrapped(): string {
		return self::$wrapped;
	}

	public static function stats(): array {
		return self::$stats;
	}

	/* =====================================================================
	 * CSS (<head>)
	 * =================================================================== */

	public static function head_css(): void {
		try {
			$css   = '';
			$theme = TP_Settings::theme();
			foreach ( $theme['areas'] as $id => $cfg ) {
				$id = (string) $id;
				if ( 'inject' === TP_Settings::mode( $id ) && self::area_may_run( $id ) ) {
					$css .= TP_Render::css( $id, $cfg );
				}
			}
			if ( '' !== $theme['page']['css'] ) {
				$css .= $theme['page']['css'] . "\n";
			}
			if ( '' !== $css ) {
				echo '<style id="tp-css">' . "\n" . $css . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- validated CSS (no "<").
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/** Built-in areas only run on their own pages; theme file areas may run anywhere. */
	private static function area_may_run( string $id ): bool {
		switch ( $id ) {
			case 'shop':
				return TP_Areas::is_product_archive();
			case 'related':
			case 'upsells':
				return function_exists( 'is_product' ) && is_product();
			case 'crosssells':
				return function_exists( 'is_cart' ) && is_cart();
			default:
				return true;
		}
	}

	/* =====================================================================
	 * Buffer handler
	 * =================================================================== */

	/**
	 * @param string $buffer Page HTML.
	 * @param int    $phase  PHP_OUTPUT_HANDLER_* flags (unused).
	 */
	public static function process( $buffer, $phase = 0 ) {
		if ( ! is_string( $buffer ) || '' === $buffer || strlen( $buffer ) > TP_Runtime::MAX_BUFFER ) {
			return $buffer;
		}
		try {
			$out = $buffer;
			foreach ( self::$texts as $fix ) {
				$out = self::apply_text( $out, $fix );
			}

			// HTML rules work on <body> only.
			$start = stripos( $out, '<body' );
			if ( false === $start ) {
				return $out;
			}
			$head = substr( $out, 0, $start );
			$body = substr( $out, $start );

			$p = self::$page;
			foreach ( $p['remove'] as $sel ) {
				$body = self::remove_elements( $body, $sel );
			}
			if ( $p['same_tab'] ) {
				$body = self::same_tab( $body );
			}
			if ( $p['img_alt'] || '' !== $p['cat_img_size'] ) {
				$body = self::images( $body );
			}
			if ( $p['aria'] ) {
				$body = self::aria( $body );
			}
			return $head . $body;
		} catch ( \Throwable $e ) {
			return $buffer;
		}
	}

	/* ---------- Text table ---------- */

	private static function apply_text( string $html, array $fix ): string {
		if ( false === strpos( $html, $fix['find'] ) ) {
			return $html;
		}
		$replace = 'wc_title' === $fix['replace'] ? self::wc_title() : $fix['custom'];
		if ( 1 === preg_match( '#^(<([a-zA-Z][a-zA-Z0-9]*)\b[^>]*>)(.*)(</\2\s*>)$#s', $fix['find'], $m ) ) {
			$replace = $m[1] . $replace . $m[4];
		}
		$count = 0;
		$html  = str_replace( $fix['find'], $replace, $html, $count );
		self::$stats['texts'] += $count;
		return $html;
	}

	private static function wc_title(): string {
		if ( function_exists( 'woocommerce_page_title' ) && ( TP_Areas::is_product_archive() ) ) {
			return esc_html( wp_strip_all_tags( (string) woocommerce_page_title( false ) ) );
		}
		return esc_html( wp_strip_all_tags( (string) wp_get_document_title() ) );
	}

	/* ---------- Element removal ---------- */

	/** Remove every element matching "tag.class" / ".class" / "tag#id" / "#id" (":empty": only empty ones). */
	public static function remove_elements( string $html, string $sel ): string {
		if ( ! preg_match( '/^([a-z][a-z0-9]*)?([.#])([A-Za-z0-9_-]+)(:empty)?$/', $sel, $m ) ) {
			return $html;
		}
		$tag   = '' !== $m[1] ? $m[1] : '[a-zA-Z][a-zA-Z0-9]*';
		$kind  = $m[2];
		$name  = $m[3];
		$empty = ! empty( $m[4] );

		$offset = 0;
		while ( preg_match( '/<(' . $tag . ')\b([^>]*)>/i', $html, $o, PREG_OFFSET_CAPTURE, $offset ) ) {
			$pos   = $o[0][1];
			$attrs = self::attrs( $o[2][0] );
			$hit   = ( '.' === $kind && isset( $attrs['class'] ) && in_array( $name, preg_split( '/\s+/', trim( $attrs['class'] ) ), true ) )
				|| ( '#' === $kind && isset( $attrs['id'] ) && $attrs['id'] === $name );
			if ( ! $hit ) {
				$offset = $pos + strlen( $o[0][0] );
				continue;
			}
			$end = self::element_end( $html, strtolower( $o[1][0] ), $pos + strlen( $o[0][0] ), '/' === substr( rtrim( $o[2][0] ), -1 ) );
			if ( $end < 0 ) {
				$offset = $pos + strlen( $o[0][0] );
				continue;
			}
			if ( $empty && ! self::is_empty( substr( $html, $pos + strlen( $o[0][0] ), $end - $pos - strlen( $o[0][0] ) ) ) ) {
				$offset = $pos + strlen( $o[0][0] );
				continue;
			}
			$html = substr( $html, 0, $pos ) . substr( $html, $end );
			++self::$stats['removed'];
			$offset = $pos;
		}
		return $html;
	}

	/** No visible text and no image, icon or form control inside. */
	private static function is_empty( string $inner ): bool {
		if ( preg_match( '/<(img|svg|i|picture|video|iframe|input|button|select|canvas)\b/i', $inner ) ) {
			return false;
		}
		$text = html_entity_decode( wp_strip_all_tags( $inner ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return '' === trim( str_replace( "\xC2\xA0", ' ', $text ) );
	}

	/** End offset of an element whose opening tag ends at $from (nesting of the same tag counted). */
	private static function element_end( string $html, string $tag, int $from, bool $self_closing ): int {
		$void = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr' );
		if ( $self_closing || in_array( $tag, $void, true ) ) {
			return $from;
		}
		$depth = 1;
		$pos   = $from;
		while ( preg_match( '/<(\/?)' . preg_quote( $tag, '/' ) . '\b[^>]*>/i', $html, $t, PREG_OFFSET_CAPTURE, $pos ) ) {
			$depth += '/' === $t[1][0] ? -1 : 1;
			$pos    = $t[0][1] + strlen( $t[0][0] );
			if ( 0 === $depth ) {
				return $pos;
			}
		}
		return -1;
	}

	/* ---------- Links: same tab ---------- */

	private static function same_tab( string $html ): string {
		$home = wp_parse_url( home_url() );
		$host = isset( $home['host'] ) ? strtolower( $home['host'] ) : '';

		return (string) preg_replace_callback(
			'/<a\b([^>]*)>/i',
			static function ( $m ) use ( $host ) {
				if ( false === stripos( $m[1], 'target' ) ) {
					return $m[0];
				}
				$a = self::attrs( $m[1] );
				if ( ! isset( $a['target'] ) || '_blank' !== strtolower( $a['target'] ) || ! isset( $a['href'] ) ) {
					return $m[0];
				}
				$href = html_entity_decode( $a['href'], ENT_QUOTES );
				$h    = wp_parse_url( $href, PHP_URL_HOST );
				$internal = ( null === $h || false === $h ) ? ( '' !== $href && '#' !== $href[0] && 0 !== stripos( $href, 'javascript:' ) && 0 !== stripos( $href, 'mailto:' ) && 0 !== stripos( $href, 'tel:' ) ) : strtolower( (string) $h ) === $host;
				if ( ! $internal ) {
					return $m[0];
				}
				++self::$stats['same_tab'];
				return '<a' . preg_replace( '/\s+target\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $m[1] ) . '>';
			},
			$html
		);
	}

	/* ---------- Images ---------- */

	private static function images( string $html ): string {
		$p       = self::$page;
		$cats    = '' !== $p['cat_img_size'] || $p['img_alt'] ? TP_Categories::image_map() : array();
		$lookups = 0;

		return (string) preg_replace_callback(
			'/<img\b([^>]*)>/i',
			static function ( $m ) use ( $p, $cats, &$lookups ) {
				$a = self::attrs( $m[1] );
				if ( empty( $a['src'] ) ) {
					return $m[0];
				}
				$src     = html_entity_decode( $a['src'], ENT_QUOTES );
				$no_alt  = ! isset( $a['alt'] ) || '' === trim( $a['alt'] );
				$changes = array();

				if ( isset( $cats[ $src ] ) ) {
					list( $att_id, $term_name ) = $cats[ $src ];
					if ( '' !== $p['cat_img_size'] && TP_Settings::size_exists( $p['cat_img_size'] ) ) {
						$img = wp_get_attachment_image_src( $att_id, $p['cat_img_size'] );
						if ( is_array( $img ) && $img[0] !== $src ) {
							$changes['src']    = $img[0];
							$changes['width']  = (string) $img[1];
							$changes['height'] = (string) $img[2];
							if ( ! isset( $a['loading'] ) ) {
								$changes['loading'] = 'lazy';
							}
							if ( ! isset( $a['decoding'] ) ) {
								$changes['decoding'] = 'async';
							}
							++self::$stats['cat_img'];
						}
					}
					if ( $no_alt ) {
						$changes['alt'] = $term_name;
					}
				} elseif ( $no_alt && $p['img_alt'] && $lookups < self::MAX_LOOKUPS && false !== strpos( $src, '/wp-content/uploads/' ) ) {
					++$lookups;
					$id  = (int) attachment_url_to_postid( preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', $src ) );
					$alt = $id ? trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) : '';
					if ( '' === $alt && $id ) {
						$alt = trim( (string) get_the_title( $id ) );
					}
					if ( '' !== $alt ) {
						$changes['alt'] = $alt;
					}
				}

				if ( ! $changes ) {
					return $m[0];
				}
				if ( isset( $changes['alt'] ) ) {
					++self::$stats['alt'];
				}
				return '<img' . self::set_attrs( $m[1], $changes ) . '>';
			},
			$html
		);
	}

	/* ---------- Accessible names ---------- */

	private static function aria( string $html ): string {
		return (string) preg_replace_callback(
			'/<(a|button)\b([^>]*)>(.*?)<\/\1\s*>/is',
			static function ( $m ) {
				$attrs = $m[2];
				if ( preg_match( '/\saria-(label|labelledby)\s*=|\stitle\s*=\s*["\'][^"\']/i', $attrs ) ) {
					return $m[0];
				}
				$inner = $m[3];
				if ( '' !== trim( html_entity_decode( wp_strip_all_tags( $inner ), ENT_QUOTES ) ) ) {
					return $m[0]; // Has visible or screen-reader text.
				}
				if ( preg_match( '/<img\b[^>]*\balt\s*=\s*("[^"]+"|\'[^\']+\')/i', $inner ) || preg_match( '/<svg\b.*?<title>/is', $inner ) ) {
					return $m[0];
				}
				$label = self::guess_label( strtolower( $attrs . ' ' . $inner ), self::attrs( $attrs ) );
				if ( '' === $label ) {
					return $m[0];
				}
				++self::$stats['aria'];
				return '<' . $m[1] . $attrs . ' aria-label="' . esc_attr( $label ) . '">' . $inner . '</' . $m[1] . '>';
			},
			$html
		);
	}

	/** Accessible name from icon classes, address or role ('' when unknown). */
	private static function guess_label( string $hay, array $attrs ): string {
		$href = isset( $attrs['href'] ) ? strtolower( html_entity_decode( $attrs['href'], ENT_QUOTES ) ) : '';

		$networks = array(
			'facebook'  => 'Facebook',
			'instagram' => 'Instagram',
			'youtube'   => 'YouTube',
			'pinterest' => 'Pinterest',
			'linkedin'  => 'LinkedIn',
			'tiktok'    => 'TikTok',
			'whatsapp'  => 'WhatsApp',
			'viber'     => 'Viber',
			'telegram'  => 'Telegram',
			'messenger' => 'Messenger',
			'twitter'   => 'X (Twitter)',
			'x.com'     => 'X (Twitter)',
		);
		foreach ( $networks as $needle => $name ) {
			if ( false !== strpos( $hay, $needle ) ) {
				return $name;
			}
		}
		if ( 0 === strpos( $href, 'mailto:' ) ) {
			return __( 'Email', 'theme-patcher' );
		}
		if ( 0 === strpos( $href, 'tel:' ) ) {
			return __( 'Τηλέφωνο', 'theme-patcher' );
		}
		$cart = function_exists( 'wc_get_cart_url' ) ? strtolower( wc_get_cart_url() ) : '';
		if ( ( '' !== $cart && '' !== $href && $href === $cart ) || preg_match( '/\b(fa-)?(cart|basket|shopping-bag)\b/', $hay ) ) {
			return __( 'Καλάθι', 'theme-patcher' );
		}
		$acct = function_exists( 'wc_get_page_permalink' ) ? strtolower( wc_get_page_permalink( 'myaccount' ) ) : '';
		if ( ( '' !== $acct && '' !== $href && $href === $acct ) || preg_match( '/\b(fa-)?(user|account|login)\b/', $hay ) ) {
			return __( 'Ο λογαριασμός μου', 'theme-patcher' );
		}
		if ( preg_match( '/\b(fa-)?(heart|wishlist)\b/', $hay ) ) {
			return __( 'Λίστα επιθυμιών', 'theme-patcher' );
		}
		if ( preg_match( '/\b(fa-)?(search|magnifying-glass)\b/', $hay ) ) {
			return __( 'Αναζήτηση', 'theme-patcher' );
		}
		if ( preg_match( '/\b(fa-)?(angle-double-up|angle-up|arrow-up|chevron-up|return-to-top|scroll-top|back-to-top)\b/', $hay ) ) {
			return __( 'Επιστροφή στην αρχή', 'theme-patcher' );
		}
		if ( preg_match( '/\b(fa-)?(chevron-left|angle-left|arrow-left|prev|previous)\b/', $hay ) ) {
			return __( 'Προηγούμενο', 'theme-patcher' );
		}
		if ( preg_match( '/\b(fa-)?(chevron-right|angle-right|arrow-right|next)\b/', $hay ) ) {
			return __( 'Επόμενο', 'theme-patcher' );
		}
		if ( preg_match( '/\b(fa-)?(play|video)\b/', $hay ) ) {
			return __( 'Αναπαραγωγή βίντεο', 'theme-patcher' );
		}
		if ( preg_match( '/\b(fa-)?(times|xmark|close)\b/', $hay ) ) {
			return __( 'Κλείσιμο', 'theme-patcher' );
		}
		if ( preg_match( '/\b(fa-)?(bars|menu|hamburger|navbar-toggler|toggle)\b/', $hay ) ) {
			return __( 'Μενού', 'theme-patcher' );
		}
		if ( '' !== $href && untrailingslashit( $href ) === untrailingslashit( strtolower( home_url() ) ) ) {
			return __( 'Αρχική σελίδα', 'theme-patcher' );
		}
		return '';
	}

	/* ---------- Attribute helpers ---------- */

	/** Attributes of a tag (names lower-cased, values HTML as written). */
	public static function attrs( string $s ): array {
		$out = array();
		if ( preg_match_all( '/([^\s=\/>"\']+)(?:\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+)))?/', $s, $mm, PREG_SET_ORDER ) ) {
			foreach ( $mm as $a ) {
				$v = '';
				if ( isset( $a[5] ) && '' !== $a[5] ) {
					$v = $a[5];
				} elseif ( isset( $a[4] ) && '' !== $a[4] ) {
					$v = $a[4];
				} elseif ( isset( $a[3] ) ) {
					$v = $a[3];
				}
				$out[ strtolower( $a[1] ) ] = $v;
			}
		}
		return $out;
	}

	/** Set attribute values in a tag's attribute string (replace or append). */
	private static function set_attrs( string $s, array $changes ): string {
		foreach ( $changes as $name => $value ) {
			$quoted = '"' . esc_attr( $value ) . '"';
			$re     = '/(\s' . preg_quote( $name, '/' ) . ')\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i';
			if ( preg_match( $re, $s ) ) {
				$s = (string) preg_replace( $re, '$1=' . str_replace( '$', '\$', $quoted ), $s, 1 );
			} else {
				$tail = '';
				if ( preg_match( '/\s*\/\s*$/', $s, $t ) ) {
					$tail = $t[0];
					$s    = substr( $s, 0, -strlen( $tail ) );
				}
				$s .= ' ' . $name . '=' . $quoted . $tail;
			}
		}
		return $s;
	}
}
