<?php
/**
 * EBD_Delivery — the WooCommerce download hooks.
 *
 *  - woocommerce_download_product_filepath (priority 50): for a local PDF
 *    or EPUB of a product that is not switched off, returns the stamped
 *    copy for this download permission (made now, or from the cache). It
 *    runs after WooCommerce checked the link, the order key and the e-mail
 *    and before it checks limits and expiry; an exhausted or expired
 *    permission is skipped here so that no copy is made for a download
 *    WooCommerce is about to refuse. Any failure → the original path and a
 *    line in the issue log.
 *  - woocommerce_file_download_method: «Force downloads» for our copies.
 *  - woocommerce_file_download_filename: the original file's name for a
 *    copy (never our hex name), or the clean name «{author} - {title}.ext»
 *    when that setting is on (for every file of the product, stamped or
 *    not).
 *
 * The visible line and the Ex libris page are written in the visitor
 * language of the store (EBD_Settings::visitor_lang()).
 */

defined( 'ABSPATH' ) || exit;

final class EBD_Delivery {

	/** @var array|null The download of this request: product, download, original name. */
	private static $current = null;

	public static function init(): void {
		add_filter( 'woocommerce_download_product_filepath', array( __CLASS__, 'filepath' ), 50, 5 );
		add_filter( 'woocommerce_file_download_method', array( __CLASS__, 'method' ), 50, 3 );
		add_filter( 'woocommerce_file_download_filename', array( __CLASS__, 'filename' ), 50, 2 );
	}

	/**
	 * @param string                $file_path File path or URL.
	 * @param string                $email     Buyer's e-mail.
	 * @param WC_Order|false        $order     Order.
	 * @param WC_Product            $product   Product.
	 * @param WC_Customer_Download  $download  Permission.
	 * @return string
	 */
	public static function filepath( $file_path, $email = '', $order = false, $product = null, $download = null ) {
		$orig = is_string( $file_path ) ? $file_path : '';
		try {
			if ( ! $product instanceof WC_Product || ! $download instanceof WC_Customer_Download ) {
				return $file_path;
			}
			$files    = $product->get_downloads();
			$did      = (string) $download->get_download_id();
			$dl_name  = isset( $files[ $did ] ) ? (string) $files[ $did ]->get_name() : '';
			$basename = self::basename_of( $orig );

			self::$current = array(
				'product'  => $product,
				'name'     => $dl_name,
				'basename' => $basename,
				'count'    => count( $files ),
				'stamped'  => '',
			);

			if ( 'off' === EBD_Settings::mode() || ! EBD_Settings::active_for_user() || ! $order instanceof WC_Order ) {
				return $file_path;
			}
			$s   = EBD_Settings::get();
			$ext = strtolower( pathinfo( $basename, PATHINFO_EXTENSION ) );
			if ( ! ( ( 'pdf' === $ext && $s['pdf'] ) || ( 'epub' === $ext && $s['epub'] ) ) ) {
				return $file_path;
			}
			if ( EBD_Limits::is_off( $product ) ) {
				return $file_path;
			}
			/**
			 * Whether this download gets a watermark.
			 *
			 * @param bool                 $stamp    True.
			 * @param WC_Product           $product  Product.
			 * @param WC_Customer_Download $download Permission.
			 * @param WC_Order             $order    Order.
			 */
			if ( ! apply_filters( 'ebd_should_stamp', true, $product, $download, $order ) ) {
				return $file_path;
			}

			// A download WooCommerce is about to refuse needs no copy.
			$left = $download->get_downloads_remaining();
			if ( '' !== (string) $left && (int) $left <= 0 ) {
				return $file_path;
			}
			$exp = $download->get_access_expires();
			if ( $exp && $exp->getTimestamp() < time() ) {
				return $file_path;
			}

			$parsed = WC_Download_Handler::parse_file_path( $orig );
			if ( ! empty( $parsed['remote_file'] ) ) {
				EBD_Store::issue( $product->get_id(), $basename, 'remote' );
				return $file_path;
			}
			$src = realpath( (string) $parsed['file_path'] );
			if ( false === $src || ! is_file( $src ) || ! is_readable( $src ) ) {
				return $file_path; // WooCommerce answers «file not found» itself.
			}
			$size = (int) filesize( $src );
			if ( $size > (int) $s['max_mb'] * 1048576 ) {
				EBD_Store::issue( $product->get_id(), $basename, 'too_big' );
				return $file_path;
			}
			if ( ! EBD_Store::ensure_dir() ) {
				EBD_Store::issue( $product->get_id(), $basename, 'folder' );
				return $file_path;
			}

			$ctx  = self::context( $order, $download );
			$lang = EBD_Settings::visitor_lang();
			$key  = implode( '|', array( $src, $size, (int) filemtime( $src ), md5( wp_json_encode( $ctx ) . wp_json_encode( $s ) ), EBD_VERSION, $lang ) );
			$name = EBD_Store::name_for( (int) $download->get_id(), $key, $ext );
			$path = EBD_Store::path( $name );
			if ( '' === $path ) {
				return $file_path;
			}
			if ( ! is_file( $path ) ) {
				if ( ! EBD_Store::room_for( $size ) ) {
					EBD_Store::issue( $product->get_id(), $basename, 'disk' );
					return $file_path;
				}
				$reason = self::make( $src, $path, $ext, $ctx, $lang );
				if ( '' !== $reason ) {
					EBD_Store::issue( $product->get_id(), $basename, $reason );
					return $file_path;
				}
				/**
				 * After a stamped copy is made.
				 *
				 * @param string $path    The copy.
				 * @param array  $context name, email, order, date, site, code.
				 */
				do_action( 'ebd_stamped', $path, $ctx );
			}
			self::$current['stamped'] = $path;
			return $path;
		} catch ( \Throwable $e ) {
			if ( $product instanceof WC_Product ) {
				EBD_Store::issue( $product->get_id(), self::basename_of( $orig ), 'error' );
			}
			return $file_path;
		}
	}

	/** Makes the copy; '' on success, else a reason of EBD_Store::reasons(). */
	private static function make( string $src, string $dst, string $ext, array $ctx, string $lang ): string {
		Ebook_Delivery::load_engine();
		if ( function_exists( 'wc_set_time_limit' ) ) {
			wc_set_time_limit( 0 );
		}
		$tmp = EBD_Store::tmp_for( basename( $dst ) );
		if ( '' === $tmp ) {
			return 'folder';
		}
		$vars = array(
			'name'  => $ctx['display'],
			'order' => $ctx['order'],
			'date'  => $ctx['date'],
			'site'  => $ctx['site'],
			'code'  => $ctx['code'],
		);
		$line = EBD_Settings::text( 'line', $vars, $lang );
		/**
		 * The visible line of a download.
		 *
		 * @param string $line Text.
		 * @param array  $ctx  name, display, email, order, date, site, code.
		 */
		$line = (string) apply_filters( 'ebd_line_text', $line, $ctx );
		try {
			if ( 'pdf' === $ext ) {
				EBD_Pdf_Stamp::stamp(
					$src,
					$tmp,
					array(
						'text' => $line,
						'code' => $ctx['code'],
						'name' => $ctx['name'],
						'size' => (int) EBD_Settings::get()['size'],
					)
				);
			} else {
				EBD_Epub::stamp(
					$src,
					$tmp,
					array(
						'text'    => $line,
						'code'    => $ctx['code'],
						'name'    => $ctx['name'],
						'lang'    => $lang,
						'heading' => EBD_Settings::text( 'ex_title', $vars, $lang ),
						'rows'    => array_values(
							array_filter(
								array(
									EBD_Settings::text( 'ex_owner', $vars, $lang ),
									EBD_Settings::text( 'ex_order', $vars, $lang ),
									$ctx['site'],
									EBD_Settings::text( 'ex_note', $vars, $lang ),
								)
							)
						),
					)
				);
			}
		} catch ( EBD_Pdf_Error $e ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			return isset( EBD_Store::reasons()[ $e->reason ] ) ? $e->reason : 'damaged';
		} catch ( EBD_Epub_Error $e ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			return isset( EBD_Store::reasons()[ $e->reason ] ) ? $e->reason : 'damaged';
		} catch ( \Throwable $e ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			return 'error';
		}
		if ( ! @rename( $tmp, $dst ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			return 'io';
		}
		return '';
	}

	/**
	 * What goes into a copy.
	 *
	 * @return array{name:string, display:string, email:string, order:string, date:string, site:string, code:string}
	 */
	public static function context( WC_Order $order, WC_Customer_Download $download ): array {
		$first = trim( (string) $order->get_billing_first_name() );
		$last  = trim( (string) $order->get_billing_last_name() );
		$email = (string) $download->get_user_email();
		$full  = trim( $first . ' ' . $last );
		if ( '' === $full ) {
			$full = self::mask_email( $email );
		}
		$display = $full;
		if ( 'initial' === EBD_Settings::get()['name_style'] && '' !== $first && '' !== $last ) {
			$display = $first . ' ' . mb_substr( $last, 0, 1 ) . '.';
		}
		$created = $order->get_date_created();
		return array(
			'name'    => $full,
			'display' => $display,
			'email'   => $email,
			'order'   => (string) $order->get_order_number(),
			'date'    => $created ? (string) wc_format_datetime( $created ) : '',
			'site'    => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'code'    => EBD_Code::make( (int) $order->get_id(), (int) $download->get_product_id(), (string) $download->get_download_id() ),
		);
	}

	/** k***@example.gr */
	public static function mask_email( string $email ): string {
		$at = strpos( $email, '@' );
		if ( false === $at || $at < 1 ) {
			return '';
		}
		return mb_substr( $email, 0, 1 ) . '***' . substr( $email, $at );
	}

	/** «Force downloads» for our copies. */
	public static function method( $method, $product_id = 0, $file_path = '' ) {
		try {
			if ( is_string( $file_path ) && '' !== $file_path && EBD_Store::is_ours( $file_path ) ) {
				return 'force';
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		return $method;
	}

	/** The name the buyer sees. */
	public static function filename( $filename, $product_id = 0 ) {
		try {
			$cur = self::$current;
			if ( ! is_array( $cur ) || ! $cur['product'] instanceof WC_Product || (int) $cur['product']->get_id() !== (int) $product_id ) {
				return $filename;
			}
			$out = is_string( $filename ) ? $filename : '';
			if ( '' !== $cur['stamped'] && 1 === preg_match( EBD_Store::FILE_RE, (string) $out ) ) {
				$out = $cur['basename'];
			}
			if ( EBD_Settings::get()['clean_names'] && EBD_Settings::active_for_user() ) {
				$clean = self::clean_name( $cur['product'], $cur['name'], (int) $cur['count'], strtolower( pathinfo( $cur['basename'], PATHINFO_EXTENSION ) ) );
				if ( '' !== $clean ) {
					$out = $clean;
				}
			}
			return self::safe_filename( $out );
		} catch ( \Throwable $e ) {
			return $filename;
		}
	}

	/** «{author} - {title}[ - {file name}].ext», '' when nothing usable. */
	public static function clean_name( WC_Product $product, string $dl_name, int $files, string $ext ): string {
		if ( '' === $ext || ! preg_match( '/^[a-z0-9]{1,8}$/', $ext ) ) {
			return '';
		}
		$parent = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : $product;
		$parent = $parent instanceof WC_Product ? $parent : $product;
		$title  = wp_strip_all_tags( $product->get_name() );
		$tax    = (string) EBD_Settings::get()['author_tax'];
		$author = '';
		if ( '' !== $tax && taxonomy_exists( $tax ) ) {
			$terms = get_the_terms( $parent->get_id(), $tax );
			if ( is_array( $terms ) ) {
				$author = implode( ', ', wp_list_pluck( $terms, 'name' ) );
			}
		}
		$parts = array_filter( array( $author, $title ) );
		// Several files of the same product: the file's own name tells them apart.
		$label = trim( (string) preg_replace( '/\.[a-z0-9]{1,8}$/i', '', $dl_name ) );
		if ( $files > 1 && '' !== $label && ! preg_match( '/^-|-[a-z0-9]{6}$/i', $label ) ) {
			$parts[] = $label;
		}
		$name = implode( ' - ', $parts );
		/**
		 * The clean download name, without the extension.
		 *
		 * @param string     $name    Name.
		 * @param WC_Product $product Product.
		 * @param string     $ext     Extension.
		 */
		$name = (string) apply_filters( 'ebd_download_name', $name, $product, $ext );
		return '' === trim( $name ) ? '' : $name . '.' . $ext;
	}

	/** A file name safe for the Content-Disposition header (no quotes, controls or path parts). */
	public static function safe_filename( string $name ): string {
		$name = wp_specialchars_decode( $name, ENT_QUOTES );
		$name = (string) preg_replace( '/[\x00-\x1F\x7F"\\\\\/:*?<>|;]+/u', ' ', $name );
		$name = trim( (string) preg_replace( '/\s+/u', ' ', $name ), " .\t" );
		if ( mb_strlen( $name ) > 150 ) {
			$ext  = pathinfo( $name, PATHINFO_EXTENSION );
			$name = mb_substr( $name, 0, 140 ) . ( '' !== $ext ? '.' . $ext : '' );
		}
		return '' === $name ? 'download' : $name;
	}

	/** Basename of a path or URL, without the query. */
	private static function basename_of( string $path ): string {
		$p = (string) wp_parse_url( $path, PHP_URL_PATH );
		$p = '' !== $p ? $p : $path;
		return rawurldecode( basename( $p ) );
	}
}
