<?php
/**
 * WSH_Settings — the wsh_settings option, strict validation with
 * whitelists (Bible §11), visitor texts (Bible §14.17).
 *
 * wsh_settings (autoload: read on every front-end request with a button):
 *  - mode           'off' (default) | 'test' (shop managers only) | 'live'
 *  - guests         'allow' (list in the browser) | 'login' (sign in first)
 *  - loop           { enabled, position: before | after | image }
 *  - single         { enabled, position: before | after }
 *  - style          'icon_text' | 'icon' | 'text'
 *  - toast          small notice after a click, with "View list"
 *  - page_id        the list page ([nox_wishlist])
 *  - layout         'table' | 'cards'
 *  - cols           { image, price, stock, date, qty, cart, remove }
 *  - price_drop     "The price dropped by …" (signed-in customers)
 *  - bulk           bulk actions (all / selected to cart, empty list)
 *  - remove_after   remove an item once it is added to the cart from the list
 *  - account        tab in My Account; endpoint: its URL slug
 *  - menu           theme menu location for the counter ('' = none)
 *  - count_zero     show the counter at 0
 *  - share          share links
 *  - lists          named lists for signed-in customers (1.1.0)
 *  - alert_price    email alerts: price drops (1.1.0; the email itself is
 *                   on / off in WooCommerce → Settings → Emails)
 *  - alert_drop     smallest drop that sends an alert, in % (1–90)
 *  - alert_stock    email alerts: back in stock
 *  - lang, t_*      visitor texts
 *  - delete_data    delete the customers' lists on uninstall
 */

defined( 'ABSPATH' ) || exit;

final class WSH_Settings {

	const OPT = 'wsh_settings';

	/** Columns of the table layout (the product name is always there). */
	const COLS = array( 'image', 'price', 'stock', 'date', 'qty', 'cart', 'remove' );

	/** Text keys => max length. */
	const TEXTS = array(
		't_add'        => 60,
		't_added'      => 60,
		't_toast_add'  => 120,
		't_toast_rem'  => 120,
		't_view'       => 60,
		't_title'      => 60,
		't_empty'      => 120,
		't_login'      => 120,
		't_drop'       => 120,
		't_product'    => 40,
		't_price'      => 40,
		't_stock'      => 40,
		't_date'       => 40,
		't_qty'        => 40,
		't_cart'       => 40,
		't_options'    => 40,
		't_remove'     => 40,
		't_all'        => 60,
		't_selected'   => 60,
		't_clear'      => 60,
		't_confirm'    => 120,
		't_share'      => 60,
		't_copy'       => 60,
		't_copied'     => 60,
		't_share_new'  => 60,
		't_share_off'  => 60,
		't_skipped'    => 160,
		't_shared'     => 60,
		't_default'    => 60,
		't_new_list'   => 60,
		't_list_name'  => 60,
		't_create'     => 40,
		't_rename'     => 40,
		't_del_list'   => 60,
		't_conf_list'  => 120,
		't_move'       => 40,
		't_alerts'     => 200,
		't_save'       => 40,
		't_unsub'      => 160,
		't_mail_intro' => 200,
		't_mail_drop'  => 160,
		't_mail_stock' => 160,
		't_mail_off'   => 80,
	);

	/** @var array|null Per-request cache. */
	private static $cache = null;

	public static function defaults(): array {
		return array(
			'mode'         => 'off',
			'guests'       => 'allow',
			'loop'         => array(
				'enabled'  => true,
				'position' => 'after',
			),
			'single'       => array(
				'enabled'  => true,
				'position' => 'after',
			),
			'style'        => 'icon_text',
			'toast'        => true,
			'page_id'      => 0,
			'layout'       => 'table',
			'cols'         => array(
				'image'  => true,
				'price'  => true,
				'stock'  => true,
				'date'   => false,
				'qty'    => true,
				'cart'   => true,
				'remove' => true,
			),
			'price_drop'   => true,
			'bulk'         => true,
			'remove_after' => false,
			'account'      => true,
			'endpoint'     => 'wishlist',
			'menu'         => '',
			'count_zero'   => false,
			'share'        => true,
			'lists'        => false,
			'alert_price'  => true,
			'alert_drop'   => 5,
			'alert_stock'  => true,
			'lang'         => 'auto',
			't_add'        => array(
				'el' => 'Λίστα επιθυμιών',
				'en' => 'Add to wishlist',
			),
			't_added'      => array(
				'el' => 'Στη λίστα σας',
				'en' => 'In your wishlist',
			),
			't_toast_add'  => array(
				'el' => 'Το {product_name} προστέθηκε στη λίστα σας.',
				'en' => '{product_name} was added to your wishlist.',
			),
			't_toast_rem'  => array(
				'el' => 'Το {product_name} αφαιρέθηκε από τη λίστα σας.',
				'en' => '{product_name} was removed from your wishlist.',
			),
			't_view'       => array(
				'el' => 'Προβολή λίστας',
				'en' => 'View wishlist',
			),
			't_title'      => array(
				'el' => 'Λίστα επιθυμιών',
				'en' => 'Wishlist',
			),
			't_empty'      => array(
				'el' => 'Η λίστα επιθυμιών είναι άδεια.',
				'en' => 'Your wishlist is empty.',
			),
			't_login'      => array(
				'el' => 'Συνδεθείτε για να κρατήσετε λίστα επιθυμιών.',
				'en' => 'Sign in to keep a wishlist.',
			),
			't_drop'       => array(
				'el' => 'Η τιμή έπεσε κατά {amount} από τότε που το προσθέσατε.',
				'en' => 'The price dropped by {amount} since you added it.',
			),
			't_product'    => array(
				'el' => 'Προϊόν',
				'en' => 'Product',
			),
			't_price'      => array(
				'el' => 'Τιμή',
				'en' => 'Price',
			),
			't_stock'      => array(
				'el' => 'Διαθεσιμότητα',
				'en' => 'Availability',
			),
			't_date'       => array(
				'el' => 'Προστέθηκε',
				'en' => 'Added',
			),
			't_qty'        => array(
				'el' => 'Ποσότητα',
				'en' => 'Quantity',
			),
			't_cart'       => array(
				'el' => 'Στο καλάθι',
				'en' => 'Add to cart',
			),
			't_options'    => array(
				'el' => 'Επιλογές',
				'en' => 'Select options',
			),
			't_remove'     => array(
				'el' => 'Αφαίρεση',
				'en' => 'Remove',
			),
			't_all'        => array(
				'el' => 'Όλα στο καλάθι',
				'en' => 'Add all to cart',
			),
			't_selected'   => array(
				'el' => 'Επιλεγμένα στο καλάθι',
				'en' => 'Add selected to cart',
			),
			't_clear'      => array(
				'el' => 'Άδειασμα λίστας',
				'en' => 'Empty wishlist',
			),
			't_confirm'    => array(
				'el' => 'Να αδειάσει η λίστα επιθυμιών;',
				'en' => 'Empty your wishlist?',
			),
			't_share'      => array(
				'el' => 'Κοινοποίηση',
				'en' => 'Share',
			),
			't_copy'       => array(
				'el' => 'Αντιγραφή συνδέσμου',
				'en' => 'Copy link',
			),
			't_copied'     => array(
				'el' => 'Ο σύνδεσμος αντιγράφηκε.',
				'en' => 'Link copied.',
			),
			't_share_new'  => array(
				'el' => 'Νέος σύνδεσμος',
				'en' => 'New link',
			),
			't_share_off'  => array(
				'el' => 'Διακοπή κοινοποίησης',
				'en' => 'Stop sharing',
			),
			't_skipped'    => array(
				'el' => 'Κάποια προϊόντα δεν μπήκαν στο καλάθι, γιατί δεν είναι διαθέσιμα ή θέλουν επιλογές.',
				'en' => 'Some products were not added to the cart because they are unavailable or need options.',
			),
			't_shared'     => array(
				'el' => 'Κοινή λίστα επιθυμιών',
				'en' => 'Shared wishlist',
			),
			't_default'    => array(
				'el' => 'Η λίστα μου',
				'en' => 'My wishlist',
			),
			't_new_list'   => array(
				'el' => 'Νέα λίστα',
				'en' => 'New list',
			),
			't_list_name'  => array(
				'el' => 'Όνομα λίστας',
				'en' => 'List name',
			),
			't_create'     => array(
				'el' => 'Δημιουργία',
				'en' => 'Create',
			),
			't_rename'     => array(
				'el' => 'Μετονομασία',
				'en' => 'Rename',
			),
			't_del_list'   => array(
				'el' => 'Διαγραφή λίστας',
				'en' => 'Delete list',
			),
			't_conf_list'  => array(
				'el' => 'Να διαγραφεί η λίστα μαζί με τα προϊόντα της;',
				'en' => 'Delete this list and its products?',
			),
			't_move'       => array(
				'el' => 'Μετακίνηση',
				'en' => 'Move',
			),
			't_alerts'     => array(
				'el' => 'Στείλτε μου email όταν πέφτει η τιμή ή όταν ξαναμπαίνει σε απόθεμα ένα προϊόν της λίστας μου.',
				'en' => 'Email me when a product in my wishlist drops in price or is back in stock.',
			),
			't_save'       => array(
				'el' => 'Αποθήκευση',
				'en' => 'Save',
			),
			't_unsub'      => array(
				'el' => 'Δεν θα λαμβάνετε πια emails για τη λίστα επιθυμιών σας.',
				'en' => 'You will no longer receive emails about your wishlist.',
			),
			't_mail_intro' => array(
				'el' => 'Νέα για προϊόντα της λίστας επιθυμιών σας:',
				'en' => 'News about products in your wishlist:',
			),
			't_mail_drop'  => array(
				'el' => 'Η τιμή έπεσε από {old} σε {new}.',
				'en' => 'The price dropped from {old} to {new}.',
			),
			't_mail_stock' => array(
				'el' => 'Είναι ξανά σε απόθεμα.',
				'en' => 'It is back in stock.',
			),
			't_mail_off'   => array(
				'el' => 'Διακοπή αυτών των emails',
				'en' => 'Stop these emails',
			),
			'delete_data'  => false,
		);
	}

	public static function get(): array {
		if ( null === self::$cache ) {
			$raw         = get_option( self::OPT, array() );
			self::$cache = self::sanitize( is_array( $raw ) ? $raw : array() );
		}
		return self::$cache;
	}

	public static function save( array $s ): void {
		$old         = self::get();
		$s           = self::sanitize( $s );
		self::$cache = $s;
		update_option( self::OPT, $s, true );
		// A new My Account slug needs the rewrite rules again (next request).
		if ( $old['endpoint'] !== $s['endpoint'] || $old['account'] !== $s['account'] ) {
			update_option( 'wsh_flush', 1, true );
		}
	}

	/** Drops the per-request cache (tests, imports). */
	public static function flush(): void {
		self::$cache = null;
	}

	/** Whitelist validation: unknown keys are dropped, bad values fall back to the defaults. */
	public static function sanitize( array $raw ): array {
		$d = self::defaults();
		$s = array();

		$s['mode']   = self::pick( $raw, 'mode', array( 'off', 'test', 'live' ), $d['mode'] );
		$s['guests'] = self::pick( $raw, 'guests', array( 'allow', 'login' ), $d['guests'] );
		$s['style']  = self::pick( $raw, 'style', array( 'icon_text', 'icon', 'text' ), $d['style'] );
		$s['layout'] = self::pick( $raw, 'layout', array( 'table', 'cards' ), $d['layout'] );
		$s['lang']   = self::pick( $raw, 'lang', array( 'auto', 'el', 'en' ), $d['lang'] );

		$loop      = isset( $raw['loop'] ) && is_array( $raw['loop'] ) ? $raw['loop'] : array();
		$s['loop'] = array(
			'enabled'  => isset( $loop['enabled'] ) ? (bool) $loop['enabled'] : $d['loop']['enabled'],
			'position' => self::pick( $loop, 'position', array( 'before', 'after', 'image' ), $d['loop']['position'] ),
		);
		$single      = isset( $raw['single'] ) && is_array( $raw['single'] ) ? $raw['single'] : array();
		$s['single'] = array(
			'enabled'  => isset( $single['enabled'] ) ? (bool) $single['enabled'] : $d['single']['enabled'],
			'position' => self::pick( $single, 'position', array( 'before', 'after' ), $d['single']['position'] ),
		);

		$s['page_id'] = isset( $raw['page_id'] ) && is_numeric( $raw['page_id'] ) ? max( 0, (int) $raw['page_id'] ) : 0;

		$cols      = isset( $raw['cols'] ) && is_array( $raw['cols'] ) ? $raw['cols'] : array();
		$s['cols'] = array();
		foreach ( self::COLS as $c ) {
			$s['cols'][ $c ] = isset( $cols[ $c ] ) ? (bool) $cols[ $c ] : $d['cols'][ $c ];
		}

		foreach ( array( 'toast', 'price_drop', 'bulk', 'remove_after', 'account', 'count_zero', 'share', 'lists', 'alert_price', 'alert_stock', 'delete_data' ) as $k ) {
			$s[ $k ] = isset( $raw[ $k ] ) ? (bool) $raw[ $k ] : $d[ $k ];
		}
		$s['alert_drop'] = isset( $raw['alert_drop'] ) && is_numeric( $raw['alert_drop'] ) ? max( 1, min( 90, (int) $raw['alert_drop'] ) ) : $d['alert_drop'];

		$ep            = isset( $raw['endpoint'] ) && is_string( $raw['endpoint'] ) ? sanitize_title( $raw['endpoint'] ) : '';
		$s['endpoint'] = ( '' !== $ep && strlen( $ep ) <= 40 && 1 === preg_match( '/^[a-z0-9\-]+$/', $ep ) ) ? $ep : $d['endpoint'];

		$menu      = isset( $raw['menu'] ) && is_string( $raw['menu'] ) ? $raw['menu'] : '';
		$s['menu'] = 1 === preg_match( '/^[A-Za-z0-9_\-]{1,64}$/', $menu ) ? $menu : '';

		foreach ( self::TEXTS as $key => $len ) {
			$s[ $key ] = $d[ $key ];
			if ( isset( $raw[ $key ] ) && is_array( $raw[ $key ] ) ) {
				foreach ( array( 'el', 'en' ) as $l ) {
					$v = isset( $raw[ $key ][ $l ] ) && is_string( $raw[ $key ][ $l ] ) ? self::line( $raw[ $key ][ $l ], $len ) : '';
					// Empty Greek = the default; empty English = shows the Greek.
					if ( 'el' === $l && '' === $v ) {
						continue;
					}
					$s[ $key ][ $l ] = $v;
				}
			}
		}

		return $s;
	}

	/** One line of plain text, at most $len characters. */
	public static function line( string $v, int $len ): string {
		return mb_substr( trim( sanitize_text_field( $v ) ), 0, $len );
	}

	/** One of the allowed string values, else the default. */
	private static function pick( array $raw, string $key, array $allowed, string $default ): string {
		return isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) && in_array( $raw[ $key ], $allowed, true ) ? $raw[ $key ] : $default;
	}

	/* =====================================================================
	 * Readers
	 * =================================================================== */

	/**
	 * The effective mode: 'off' | 'test' | 'live'. Another wishlist plugin
	 * active turns 'live' into 'test', so visitors never see two buttons
	 * (Bible §14.23).
	 */
	public static function mode(): string {
		$mode = self::get()['mode'];
		if ( 'live' === $mode && class_exists( 'WSH_Import' ) && WSH_Import::other_active() ) {
			return 'test';
		}
		return $mode;
	}

	/** True when the current visitor may see the wishlist (mode + test mode). */
	public static function visible(): bool {
		$mode = self::mode();
		if ( 'live' === $mode ) {
			return true;
		}
		return 'test' === $mode && current_user_can( 'manage_woocommerce' );
	}

	/** The list page id when it is a published page, else 0. */
	public static function page_id(): int {
		$id = (int) self::get()['page_id'];
		return ( $id && 'publish' === get_post_status( $id ) && 'page' === get_post_type( $id ) ) ? $id : 0;
	}

	/** URL of the list (page, else the My Account tab, else ''). */
	public static function list_url(): string {
		$id = self::page_id();
		if ( $id ) {
			return (string) get_permalink( $id );
		}
		$s = self::get();
		if ( $s['account'] && function_exists( 'wc_get_account_endpoint_url' ) ) {
			return (string) wc_get_account_endpoint_url( $s['endpoint'] );
		}
		return '';
	}

	/** Visitor language for the texts: 'el' | 'en' (Bible §14.17). */
	public static function lang(): string {
		$choice = self::get()['lang'];
		if ( 'el' === $choice || 'en' === $choice ) {
			return $choice;
		}
		return 0 === strpos( strtolower( (string) get_locale() ), 'el' ) ? 'el' : 'en';
	}

	/**
	 * A visitor text in the visitor language (empty English → Greek).
	 * Placeholders ({product_name}, {amount}) are filled by the caller.
	 *
	 * @param string $key A key of TEXTS.
	 */
	public static function text( string $key ): string {
		$s    = self::get();
		$lang = self::lang();
		$pair = isset( $s[ $key ] ) && is_array( $s[ $key ] ) ? $s[ $key ] : array(
			'el' => '',
			'en' => '',
		);
		$text = ( 'en' === $lang && '' !== $pair['en'] ) ? $pair['en'] : $pair['el'];
		/**
		 * Filters a visitor text of Wish Shelf (e.g. for WPML).
		 *
		 * @param string $text Text.
		 * @param string $key  Text key (t_add, t_added, t_title, …).
		 * @param string $lang 'el' | 'en'.
		 */
		return (string) apply_filters( 'wsh_text', $text, $key, $lang );
	}
}
