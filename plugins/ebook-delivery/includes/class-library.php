<?php
/**
 * EBD_Library — «Η βιβλιοθήκη μου»: a My Account endpoint with the
 * customer's digital files, one card per title.
 *
 *  - Endpoint "library" (filter ebd_library_endpoint), added through
 *    WooCommerce's own query vars, so WooCommerce registers the rewrite
 *    rule and the page title; the rules are flushed once after activation.
 *  - The menu item sits before «Downloads» (or after «Orders»), and with
 *    the setting lib_replace WooCommerce's «Downloads» item is hidden (its
 *    address keeps working).
 *  - The data is WooCommerce's: wc_get_customer_available_downloads(), so
 *    every link is WooCommerce's download link (checks, limits and log
 *    stay its own) and the watermark applies to it as to any other.
 *  - Grouped by title (a variation under its product), with the cover,
 *    the purchase date, one button per file (its format, or its name when
 *    a title has several files of one format), downloads left and expiry.
 *  - Shown only when the mode is live, or test for shop managers (Bible
 *    §14.2). Visitor texts are store settings (Bible §14.17).
 *  - CSS only on this endpoint, scoped under .ebd-lib, theme colours and
 *    fonts (currentColor), buttons with the theme's button classes (§14.16).
 *  - A short line with a link to the library in the customer's order
 *    e-mails that carry downloads (setting email_link, live mode only).
 */

defined( 'ABSPATH' ) || exit;

final class EBD_Library {

	public static function init(): void {
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 99 );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu' ), 20 );
		add_filter( 'woocommerce_endpoint_' . self::endpoint() . '_title', array( __CLASS__, 'title' ), 20 );
		add_action( 'woocommerce_account_' . self::endpoint() . '_endpoint', array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'email' ), 30, 4 );
	}

	/** The endpoint slug. */
	public static function endpoint(): string {
		$e = sanitize_title( (string) apply_filters( 'ebd_library_endpoint', 'library' ) );
		return '' !== $e ? $e : 'library';
	}

	public static function on(): bool {
		return (bool) EBD_Settings::get()['library'] && EBD_Settings::active_for_user();
	}

	public static function query_vars( $vars ) {
		$vars                     = is_array( $vars ) ? $vars : array();
		$vars[ self::endpoint() ] = self::endpoint();
		return $vars;
	}

	public static function maybe_flush(): void {
		if ( get_option( 'ebd_flush' ) ) {
			delete_option( 'ebd_flush' );
			flush_rewrite_rules( false );
		}
	}

	public static function menu( $items ) {
		try {
			$items = is_array( $items ) ? $items : array();
			if ( ! self::on() ) {
				return $items;
			}
			$label = EBD_Settings::text( 'tab' );
			$out   = array();
			$done  = false;
			foreach ( $items as $k => $v ) {
				if ( 'downloads' === $k ) {
					$out[ self::endpoint() ] = $label;
					$done                    = true;
					if ( EBD_Settings::get()['lib_replace'] ) {
						continue;
					}
				}
				$out[ $k ] = $v;
				if ( ! $done && 'orders' === $k ) {
					$out[ self::endpoint() ] = $label;
					$done                    = true;
				}
			}
			if ( ! $done ) {
				$out[ self::endpoint() ] = $label;
			}
			return $out;
		} catch ( \Throwable $e ) {
			return $items;
		}
	}

	public static function title( $title ) {
		try {
			return self::on() ? EBD_Settings::text( 'tab' ) : $title;
		} catch ( \Throwable $e ) {
			return $title;
		}
	}

	public static function assets(): void {
		try {
			if ( self::on() && function_exists( 'is_account_page' ) && is_account_page() && is_wc_endpoint_url( self::endpoint() ) ) {
				wp_enqueue_style( 'ebd-library', EBD_URL . 'assets/library.css', array(), EBD_VERSION );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * The customer's titles.
	 *
	 * @return array<int, array{id:int, title:string, url:string, image:int, date:int, files:array}>
	 */
	public static function items( int $user_id ): array {
		$downloads = function_exists( 'wc_get_customer_available_downloads' ) ? wc_get_customer_available_downloads( $user_id ) : array();
		$titles    = array();
		$dates     = array();
		foreach ( (array) $downloads as $d ) {
			if ( ! is_array( $d ) || empty( $d['download_url'] ) ) {
				continue;
			}
			$pid     = (int) ( $d['product_id'] ?? 0 );
			$product = wc_get_product( $pid );
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$parent = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : $product;
			$parent = $parent instanceof WC_Product ? $parent : $product;
			$key    = $parent->get_id();
			$oid    = (int) ( $d['order_id'] ?? 0 );
			if ( ! isset( $dates[ $oid ] ) ) {
				$o             = wc_get_order( $oid );
				$c             = $o ? $o->get_date_created() : null;
				$dates[ $oid ] = $c ? $c->getTimestamp() : 0;
			}
			if ( ! isset( $titles[ $key ] ) ) {
				$titles[ $key ] = array(
					'id'    => $key,
					'title' => wp_strip_all_tags( $parent->get_name() ),
					'url'   => $parent->is_visible() ? (string) get_permalink( $key ) : '',
					'image' => (int) ( $product->get_image_id() ? $product->get_image_id() : $parent->get_image_id() ),
					'date'  => $dates[ $oid ],
					'files' => array(),
				);
			}
			$titles[ $key ]['date'] = min( array_filter( array( $titles[ $key ]['date'], $dates[ $oid ] ) ) ?: array( 0 ) );
			$fname                  = isset( $d['file']['file'] ) ? (string) $d['file']['file'] : '';
			$ext                    = strtolower( pathinfo( (string) wp_parse_url( $fname, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
			$titles[ $key ]['files'][] = array(
				'url'     => (string) $d['download_url'],
				'format'  => preg_match( '/^[a-z0-9]{1,8}$/', $ext ) ? strtoupper( $ext ) : '',
				'name'    => (string) ( $d['download_name'] ?? '' ),
				'left'    => isset( $d['downloads_remaining'] ) ? (string) $d['downloads_remaining'] : '',
				'expires' => ! empty( $d['access_expires'] ) ? (int) strtotime( (string) $d['access_expires'] ) : 0,
			);
		}
		// Newest purchase first.
		uasort(
			$titles,
			static function ( $a, $b ) {
				return $b['date'] <=> $a['date'];
			}
		);
		/**
		 * The titles of «Η βιβλιοθήκη μου».
		 *
		 * @param array $titles  See EBD_Library::items().
		 * @param int   $user_id Customer.
		 */
		return (array) apply_filters( 'ebd_library_items', array_values( $titles ), $user_id );
	}

	public static function render(): void {
		try {
			if ( ! self::on() || ! is_user_logged_in() ) {
				return;
			}
			$items  = self::items( get_current_user_id() );
			$btn    = 'button' . ( function_exists( 'wc_wp_theme_get_element_class_name' ) && wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' );
			$format = get_option( 'date_format' );
			echo '<div class="ebd-lib">';
			if ( ! $items ) {
				echo '<p class="ebd-lib-empty">' . esc_html( EBD_Settings::text( 'empty' ) ) . '</p>';
				$shop = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : '';
				if ( $shop ) {
					echo '<p><a class="' . esc_attr( $btn ) . '" href="' . esc_url( $shop ) . '">' . esc_html( EBD_Settings::text( 'browse' ) ) . '</a></p>';
				}
				echo '</div>';
				return;
			}
			echo '<div class="ebd-lib-grid">';
			foreach ( $items as $it ) {
				$formats = array_count_values( array_column( $it['files'], 'format' ) );
				echo '<div class="ebd-lib-item">';
				echo '<div class="ebd-lib-cover">';
				$img = $it['image'] ? wp_get_attachment_image( (int) $it['image'], 'woocommerce_thumbnail', false, array( 'alt' => $it['title'] ) ) : '';
				$img = '' !== $img ? $img : ( function_exists( 'wc_placeholder_img' ) ? wc_placeholder_img( 'woocommerce_thumbnail' ) : '' );
				if ( '' !== $it['url'] ) {
					echo '<a href="' . esc_url( $it['url'] ) . '">' . wp_kses_post( $img ) . '</a>';
				} else {
					echo wp_kses_post( $img );
				}
				echo '</div><div class="ebd-lib-body">';
				echo '<p class="ebd-lib-title">' . ( '' !== $it['url'] ? '<a href="' . esc_url( $it['url'] ) . '">' . esc_html( $it['title'] ) . '</a>' : esc_html( $it['title'] ) ) . '</p>';
				if ( $it['date'] ) {
					echo '<p class="ebd-lib-meta">' . esc_html( EBD_Settings::text( 'bought', array( 'date' => wp_date( $format, $it['date'] ) ) ) ) . '</p>';
				}
				echo '<div class="ebd-lib-files">';
				foreach ( $it['files'] as $f ) {
					$label = '' !== $f['format'] ? $f['format'] : $f['name'];
					if ( '' !== $f['format'] && ( $formats[ $f['format'] ] ?? 0 ) > 1 && '' !== $f['name'] ) {
						$label = $f['name'];
					}
					echo '<div class="ebd-lib-file">';
					echo '<a class="' . esc_attr( $btn ) . ' ebd-lib-btn" href="' . esc_url( $f['url'] ) . '" rel="nofollow">'
						. '<svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3v12m0 0l-5-5m5 5l5-5M4 21h16"/></svg> '
						. esc_html( '' !== $label ? $label : '↓' ) . '</a>';
					$notes = array();
					$notes[] = '' === $f['left'] ? EBD_Settings::text( 'unlimited' ) : EBD_Settings::text( 'left', array( 'n' => number_format_i18n( (int) $f['left'] ) ) );
					if ( $f['expires'] ) {
						$notes[] = EBD_Settings::text( 'expires', array( 'date' => wp_date( $format, $f['expires'] ) ) );
					}
					echo '<span class="ebd-lib-note">' . esc_html( implode( ' · ', array_filter( $notes ) ) ) . '</span>';
					echo '</div>';
				}
				echo '</div></div></div>';
			}
			echo '</div></div>';
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * The library line in the customer's order e-mails.
	 *
	 * @param WC_Order $order         Order.
	 * @param bool     $sent_to_admin Admin copy.
	 * @param bool     $plain_text    Plain text e-mail.
	 * @param WC_Email $email         E-mail.
	 */
	public static function email( $order, $sent_to_admin = false, $plain_text = false, $email = null ): void {
		try {
			$s = EBD_Settings::get();
			if ( $sent_to_admin || ! $s['email_link'] || ! $s['library'] || 'live' !== $s['mode'] || ! $order instanceof WC_Order ) {
				return;
			}
			$id = is_object( $email ) && isset( $email->id ) ? (string) $email->id : '';
			if ( ! in_array( $id, array( 'customer_completed_order', 'customer_processing_order', 'customer_invoice' ), true ) ) {
				return;
			}
			if ( ! $order->get_customer_id() || ! $order->has_downloadable_item() || ! $order->is_download_permitted() ) {
				return;
			}
			$url  = wc_get_account_endpoint_url( self::endpoint() );
			$text = EBD_Settings::text( 'email', array( 'tab' => EBD_Settings::text( 'tab' ) ) );
			if ( $plain_text ) {
				echo "\n" . esc_html( $text ) . "\n" . esc_url_raw( $url ) . "\n\n";
			} else {
				echo '<p style="margin:0 0 16px;">' . esc_html( $text ) . ' <a href="' . esc_url( $url ) . '">' . esc_html( EBD_Settings::text( 'tab' ) ) . '</a></p>';
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}
}
