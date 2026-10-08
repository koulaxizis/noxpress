<?php
/**
 * TP_Detector — reads (never writes) the active theme's files and tells
 * where product cards are drawn and what they are missing.
 *
 *  - WooCommerce overrides in <theme>/woocommerce/: archive, taxonomies,
 *    content-product, related, up-sells, cross-sells.
 *  - Any theme .php that runs a product WP_Query (home sections, template
 *    parts…) → suggested "file:" area.
 *
 * Analysis is token based (token_get_all), so commented-out code such as
 * "<?php //echo $product->get_price_html(); ?>" counts as absent.
 *
 * It only suggests; nothing is enabled automatically (Bible §14). Results
 * are cached in a transient keyed on theme + version + file mtimes.
 *
 * Fingerprints: SHA-1 of every theme file an active area depends on,
 * stored on save. A changed file suspends its area until the admin
 * confirms (checked on theme updates and at most every 12 hours from
 * wp-admin — never on front-end requests).
 */

defined( 'ABSPATH' ) || exit;

final class TP_Detector {

	const SCAN_TTL      = 43200; // 12 h.
	const CHECK_KEY     = 'tp_fp_check';
	const CHECK_TTL     = 43200;
	const MAX_FILES     = 600;
	const MAX_FILE_SIZE = 262144; // 256 KB.

	/** WooCommerce template overrides we analyse → area id they belong to. */
	const OVERRIDES = array(
		'woocommerce/archive-product.php'        => 'shop',
		'woocommerce/taxonomy-product-cat.php'   => 'shop',
		'woocommerce/taxonomy-product-tag.php'   => 'shop',
		'woocommerce/content-product.php'        => 'shop',
		'woocommerce/single-product/related.php' => 'related',
		'woocommerce/single-product/up-sells.php' => 'upsells',
		'woocommerce/cart/cross-sells.php'       => 'crosssells',
	);

	const STD_HOOK_NAMES = array(
		'woocommerce_before_shop_loop_item',
		'woocommerce_before_shop_loop_item_title',
		'woocommerce_shop_loop_item_title',
		'woocommerce_after_shop_loop_item_title',
		'woocommerce_after_shop_loop_item',
	);

	const PRICE_CALLS  = array( 'get_price_html', 'woocommerce_template_loop_price' );
	const BUTTON_CALLS = array( 'woocommerce_template_loop_add_to_cart', 'add_to_cart_url', 'woocommerce_template_single_add_to_cart' );

	public static function init(): void {
		add_action( 'upgrader_process_complete', array( __CLASS__, 'on_upgrade' ), 20, 2 );
		add_action( 'admin_init', array( __CLASS__, 'periodic_check' ) );
	}

	/* =====================================================================
	 * Scan
	 * =================================================================== */

	/**
	 * Rows: [ file, kind (T1|T2), area, flags{std,price,button,loop_ok,broken_loop}, suggest ].
	 *
	 * @param bool $fresh Ignore the cache.
	 */
	public static function scan( bool $fresh = false ): array {
		$key = 'tp_scan_' . md5( self::signature() );
		if ( ! $fresh ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$rows  = array();
		$seen  = array();
		$files = self::theme_php_files();

		// 1) WooCommerce overrides (T1).
		foreach ( self::OVERRIDES as $rel => $area ) {
			$abs = TP_Areas::theme_file( $rel );
			if ( '' === $abs ) {
				continue;
			}
			$seen[ $rel ] = true;
			$a            = self::analyse( $abs );
			$rows[]       = array(
				'file'    => $rel,
				'kind'    => 'T1',
				'area'    => $area,
				'flags'   => $a,
				'suggest' => self::suggest_override( $rel, $area, $a ),
			);
		}

		// 2) Theme files running a product WP_Query (T2).
		foreach ( $files as $rel => $abs ) {
			if ( isset( $seen[ $rel ] ) ) {
				continue;
			}
			$a = self::analyse( $abs );
			if ( ! $a['product_query'] ) {
				continue;
			}
			$rows[] = array(
				'file'    => $rel,
				'kind'    => 'T2',
				'area'    => TP_Areas::FILE_PREFIX . $rel,
				'flags'   => $a,
				'suggest' => ( $a['std'] || ( $a['price'] && $a['button'] ) ) ? 'off' : 'inject',
			);
		}

		set_transient( $key, $rows, self::SCAN_TTL );
		return $rows;
	}

	/** Cache key material: theme, version and every file's mtime + size. */
	private static function signature(): string {
		$theme = wp_get_theme();
		$sig   = $theme->get_stylesheet() . '|' . $theme->get( 'Version' );
		foreach ( self::theme_php_files() as $rel => $abs ) {
			$sig .= '|' . $rel . ':' . (int) @filemtime( $abs ) . ':' . (int) @filesize( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		return $sig;
	}

	/** rel path => abs path of the theme's PHP files (child overrides parent). */
	public static function theme_php_files(): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$out = array();
		foreach ( array_reverse( TP_Areas::theme_dirs() ) as $dir ) { // Parent first, child overrides.
			$dir = trailingslashit( $dir );
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			$it = new RecursiveIteratorIterator(
				new RecursiveCallbackFilterIterator(
					new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
					static function ( $f ) {
						$name = $f->getFilename();
						if ( $f->isDir() ) {
							return ! in_array( $name, array( 'node_modules', 'vendor', '.git', 'tests', 'languages', 'assets' ), true );
						}
						return '.php' === substr( $name, -4 );
					}
				)
			);
			foreach ( $it as $f ) {
				if ( count( $out ) >= self::MAX_FILES ) {
					break 2;
				}
				if ( $f->isLink() || $f->getSize() > self::MAX_FILE_SIZE ) {
					continue;
				}
				$abs = wp_normalize_path( $f->getPathname() );
				$rel = substr( $abs, strlen( wp_normalize_path( $dir ) ) );
				$out[ $rel ] = $abs;
			}
		}
		ksort( $out );
		$cache = $out;
		return $out;
	}

	/**
	 * Token analysis of one file.
	 *
	 * @return array{std:bool,price:bool,button:bool,loop_ok:bool,broken_loop:bool,product_query:bool,content_product:bool}
	 */
	public static function analyse( string $abs ): array {
		$out = array(
			'std'             => false, // Standard card (content-product part or card hooks).
			'price'           => false,
			'button'          => false,
			'loop_ok'         => false, // the_post / setup_postdata present.
			'broken_loop'     => false, // foreach over products + the_* tags, no setup.
			'product_query'   => false, // new WP_Query with 'product'.
			'content_product' => false,
		);

		$code = (string) @file_get_contents( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local theme file.
		if ( '' === $code ) {
			return $out;
		}

		$tokens = @token_get_all( $code ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$sig    = array(); // Significant tokens only: [ type, text ].
		foreach ( $tokens as $t ) {
			if ( is_array( $t ) ) {
				if ( in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_INLINE_HTML, T_OPEN_TAG, T_CLOSE_TAG ), true ) ) {
					continue;
				}
				$sig[] = array( $t[0], $t[1] );
			} else {
				$sig[] = array( 0, $t );
			}
		}

		$n            = count( $sig );
		$has_foreach  = false;
		$has_template = false; // the_title / the_permalink / the_post_thumbnail used.
		$has_new_q    = false;
		$has_product  = false;

		for ( $i = 0; $i < $n; $i++ ) {
			list( $type, $text ) = $sig[ $i ];
			$lower               = strtolower( $text );

			if ( T_FOREACH === $type ) {
				$has_foreach = true;
				continue;
			}
			if ( T_CONSTANT_ENCAPSED_STRING === $type ) {
				$val = strtolower( trim( $text, "'\"" ) );
				if ( 'product' === $val ) {
					$has_product = true;
				}
				if ( in_array( $val, self::STD_HOOK_NAMES, true ) ) {
					$out['std'] = true;
				}
				continue;
			}
			if ( T_NEW === $type && isset( $sig[ $i + 1 ] ) && 'wp_query' === strtolower( ltrim( $sig[ $i + 1 ][1], '\\' ) ) ) {
				$has_new_q = true;
				continue;
			}
			if ( T_STRING !== $type && ( ! defined( 'T_NAME_FULLY_QUALIFIED' ) || T_NAME_FULLY_QUALIFIED !== $type ) ) {
				continue;
			}
			$lower = ltrim( $lower, '\\' );
			$next  = isset( $sig[ $i + 1 ] ) ? $sig[ $i + 1 ][1] : '';
			if ( '(' !== $next ) {
				continue;
			}

			if ( in_array( $lower, self::PRICE_CALLS, true ) ) {
				$out['price'] = true;
			} elseif ( in_array( $lower, self::BUTTON_CALLS, true ) ) {
				$out['button'] = true;
			} elseif ( 'the_post' === $lower || 'setup_postdata' === $lower ) {
				$out['loop_ok'] = true;
			} elseif ( in_array( $lower, array( 'the_title', 'the_permalink', 'the_post_thumbnail' ), true ) ) {
				$has_template = true;
			} elseif ( 'wc_get_template_part' === $lower ) {
				// wc_get_template_part( 'content', 'product' ).
				$a1 = isset( $sig[ $i + 2 ] ) ? strtolower( trim( $sig[ $i + 2 ][1], "'\"" ) ) : '';
				$a2 = isset( $sig[ $i + 4 ] ) ? strtolower( trim( $sig[ $i + 4 ][1], "'\"" ) ) : '';
				if ( 'content' === $a1 && 'product' === $a2 ) {
					$out['content_product'] = true;
					$out['std']             = true;
				}
			} elseif ( 'wc_get_template' === $lower ) {
				$a1 = isset( $sig[ $i + 2 ] ) ? strtolower( trim( $sig[ $i + 2 ][1], "'\"" ) ) : '';
				if ( 'loop/price.php' === $a1 ) {
					$out['price'] = true;
				} elseif ( 'loop/add-to-cart.php' === $a1 ) {
					$out['button'] = true;
				}
			}
		}

		$out['product_query'] = $has_new_q && $has_product;
		$out['broken_loop']   = $has_foreach && $has_template && ! $out['loop_ok'] && ! $out['std'];
		return $out;
	}

	private static function suggest_override( string $rel, string $area, array $a ): string {
		if ( $a['std'] && ! $a['broken_loop'] ) {
			return 'off';
		}
		if ( 'woocommerce/content-product.php' === $rel ) {
			return 'replace';
		}
		if ( $a['broken_loop'] ) {
			return 'replace';
		}
		if ( $a['price'] && $a['button'] ) {
			return 'off';
		}
		return 'shop' === $area && $a['loop_ok'] ? 'inject' : ( $a['loop_ok'] ? 'inject' : 'replace' );
	}

	/* =====================================================================
	 * Fingerprints
	 * =================================================================== */

	/** Theme-relative files an area depends on (that exist). */
	public static function files_of_area( string $id ): array {
		$files = array();
		if ( TP_Areas::is_file_area( $id ) ) {
			$files[] = TP_Areas::file_of( $id );
		} else {
			foreach ( self::OVERRIDES as $rel => $area ) {
				if ( $area === $id ) {
					$files[] = $rel;
				}
			}
		}
		$out = array();
		foreach ( $files as $rel ) {
			if ( '' !== TP_Areas::theme_file( $rel ) ) {
				$out[] = $rel;
			}
		}
		return $out;
	}

	/** Fingerprints of every file used by inject areas of a theme block. */
	public static function fingerprints( array $theme ): array {
		$fp = array();
		foreach ( $theme['areas'] as $id => $a ) {
			if ( 'inject' !== $a['mode'] ) {
				continue; // Replace mode does not depend on the theme's markup.
			}
			foreach ( self::files_of_area( (string) $id ) as $rel ) {
				$abs = TP_Areas::theme_file( $rel );
				$h   = '' !== $abs ? @sha1_file( $abs ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
				if ( is_string( $h ) ) {
					$fp[ $rel ] = $h;
				}
			}
		}
		return $fp;
	}

	/**
	 * Compare stored fingerprints with the files; suspend inject areas whose
	 * files changed (or vanished). Returns the newly suspended area ids.
	 */
	public static function check(): array {
		$theme = TP_Settings::theme();
		if ( empty( $theme['fingerprints'] ) ) {
			return array();
		}
		$current = self::fingerprints( $theme );
		$new     = array();

		foreach ( $theme['areas'] as $id => $a ) {
			$id = (string) $id;
			if ( 'inject' !== $a['mode'] || in_array( $id, $theme['suspended'], true ) ) {
				continue;
			}
			$files = TP_Areas::is_file_area( $id ) ? array( TP_Areas::file_of( $id ) ) : array_keys( array_intersect( self::OVERRIDES, array( $id ) ) );
			foreach ( $files as $rel ) {
				$stored = isset( $theme['fingerprints'][ $rel ] ) ? $theme['fingerprints'][ $rel ] : null;
				$now    = isset( $current[ $rel ] ) ? $current[ $rel ] : null;
				if ( $stored !== $now ) {
					$new[] = $id;
					break;
				}
			}
		}

		if ( $new ) {
			$theme['suspended'] = array_values( array_unique( array_merge( $theme['suspended'], $new ) ) );
			TP_Settings::save_theme( $theme );
		}
		return $new;
	}

	/** Admin confirmed the changed files: refresh fingerprints, lift suspension. */
	public static function confirm( string $id ): void {
		$theme              = TP_Settings::theme();
		$theme['suspended'] = array_values( array_diff( $theme['suspended'], array( $id ) ) );
		$theme['fingerprints'] = self::fingerprints( $theme );
		TP_Settings::save_theme( $theme );
	}

	public static function on_upgrade( $upgrader, $extra = array() ): void {
		if ( is_array( $extra ) && isset( $extra['type'] ) && 'theme' === $extra['type'] ) {
			try {
				self::check();
				delete_transient( self::CHECK_KEY );
			} catch ( \Throwable $e ) {
				return;
			}
		}
	}

	public static function periodic_check(): void {
		if ( wp_doing_ajax() || ! current_user_can( 'manage_woocommerce' ) || false !== get_transient( self::CHECK_KEY ) ) {
			return;
		}
		set_transient( self::CHECK_KEY, 1, self::CHECK_TTL );
		try {
			self::check();
		} catch ( \Throwable $e ) {
			return;
		}
	}
}
