<?php
/**
 * WSH_Account — the "Wishlist" tab in My Account (endpoint, default
 * "wishlist"), the shortcodes, the blocks, the privacy tools and the
 * privacy policy text.
 */

defined( 'ABSPATH' ) || exit;

final class WSH_Account {

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'endpoint' ) );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu' ), 20 );
		add_filter( 'woocommerce_endpoint_' . WSH_Settings::get()['endpoint'] . '_title', array( __CLASS__, 'title' ) );
		add_action( 'woocommerce_account_' . WSH_Settings::get()['endpoint'] . '_endpoint', array( 'WSH_List', 'account_tab' ) );
	}

	/**
	 * Registers the endpoint with WooCommerce's own mask (EP_ROOT only when
	 * My Account is the front page, so a page called "wishlist" keeps
	 * working); flushes the rules once after a slug change.
	 */
	public static function endpoint(): void {
		$s = WSH_Settings::get();
		if ( $s['account'] ) {
			add_rewrite_endpoint( $s['endpoint'], function_exists( 'WC' ) && WC()->query ? WC()->query->get_endpoints_mask() : EP_PAGES );
		}
		if ( get_option( 'wsh_flush' ) ) {
			delete_option( 'wsh_flush' );
			flush_rewrite_rules( false );
		}
	}

	/**
	 * @param array $vars WooCommerce query vars.
	 */
	public static function query_vars( $vars ) {
		$s = WSH_Settings::get();
		if ( is_array( $vars ) && $s['account'] ) {
			$vars[ $s['endpoint'] ] = $s['endpoint'];
		}
		return $vars;
	}

	/**
	 * The tab, before "Log out".
	 *
	 * @param array $items Menu items.
	 */
	public static function menu( $items ) {
		try {
			$s = WSH_Settings::get();
			if ( ! is_array( $items ) || ! $s['account'] || ! WSH_Front::active() ) {
				return $items;
			}
			$label = WSH_Settings::text( 't_title' );
			$out   = array();
			foreach ( $items as $k => $v ) {
				if ( 'customer-logout' === $k ) {
					$out[ $s['endpoint'] ] = $label;
				}
				$out[ $k ] = $v;
			}
			if ( ! isset( $out[ $s['endpoint'] ] ) ) {
				$out[ $s['endpoint'] ] = $label;
			}
			return $out;
		} catch ( \Throwable $e ) {
			return $items;
		}
	}

	/**
	 * @param string $title Endpoint title.
	 */
	public static function title( $title ) {
		return WSH_Settings::text( 't_title' );
	}

	/* =====================================================================
	 * Shortcodes and blocks (registered always, Bible §14.21: they print
	 * nothing while the plugin is off, and only to managers in test mode)
	 * =================================================================== */

	public static function shortcodes(): void {
		add_shortcode( 'nox_wishlist', array( __CLASS__, 'sc_list' ) );
		add_shortcode( 'nox_wishlist_button', array( __CLASS__, 'sc_button' ) );
		add_shortcode( 'nox_wishlist_count', array( __CLASS__, 'sc_count' ) );
		add_action( 'init', array( __CLASS__, 'blocks' ) );
	}

	public static function sc_list(): string {
		if ( Wish_Shelf::killed() || ! class_exists( 'WooCommerce' ) ) {
			return '';
		}
		return WSH_List::shortcode();
	}

	/**
	 * @param array|string $atts { id }.
	 */
	public static function sc_button( $atts = array() ): string {
		if ( Wish_Shelf::killed() || ! class_exists( 'WooCommerce' ) ) {
			return '';
		}
		try {
			if ( ! WSH_Front::active() ) {
				return '';
			}
			$atts = shortcode_atts( array( 'id' => 0 ), is_array( $atts ) ? $atts : array(), 'nox_wishlist_button' );
			$id   = (int) $atts['id'];
			if ( ! $id ) {
				global $product;
				$id = $product instanceof WC_Product ? (int) $product->get_id() : (int) get_the_ID();
			}
			return $id ? WSH_Front::button( $id, is_product() && get_queried_object_id() === $id ? 'single' : 'short' ) : '';
		} catch ( \Throwable $e ) {
			WSH_Front::fail();
			return '';
		}
	}

	public static function sc_count(): string {
		if ( Wish_Shelf::killed() || ! class_exists( 'WooCommerce' ) ) {
			return '';
		}
		try {
			return WSH_Front::active() ? WSH_Front::counter() : '';
		} catch ( \Throwable $e ) {
			WSH_Front::fail();
			return '';
		}
	}

	/** Three dynamic blocks; the editor shows a placeholder (the content is per visitor). */
	public static function blocks(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		wp_register_script( 'wsh-blocks', WSH_URL . 'assets/blocks.js', array( 'wp-blocks', 'wp-element', 'wp-block-editor' ), WSH_VERSION, true );
		wp_localize_script(
			'wsh-blocks',
			'wshBlocks',
			array(
				'list'   => __( 'Λίστα επιθυμιών', 'wish-shelf' ),
				'button' => __( 'Κουμπί λίστας επιθυμιών', 'wish-shelf' ),
				'count'  => __( 'Μετρητής λίστας επιθυμιών', 'wish-shelf' ),
				'hint'   => __( 'Εμφανίζεται στο site για κάθε επισκέπτη, όταν το Wish Shelf είναι ενεργό (Noxpress → Λίστα επιθυμιών).', 'wish-shelf' ),
			)
		);
		register_block_type(
			'noxpress/wishlist',
			array(
				'api_version'     => 3,
				'title'           => 'Wishlist',
				'category'        => 'woocommerce',
				'editor_script'   => 'wsh-blocks',
				'render_callback' => array( __CLASS__, 'sc_list' ),
				'supports'        => array( 'html' => false ),
			)
		);
		register_block_type(
			'noxpress/wishlist-button',
			array(
				'api_version'     => 3,
				'title'           => 'Wishlist button',
				'category'        => 'woocommerce',
				'editor_script'   => 'wsh-blocks',
				'uses_context'    => array( 'postId', 'postType' ),
				'render_callback' => array( __CLASS__, 'block_button' ),
				'supports'        => array( 'html' => false ),
			)
		);
		register_block_type(
			'noxpress/wishlist-count',
			array(
				'api_version'     => 3,
				'title'           => 'Wishlist counter',
				'category'        => 'woocommerce',
				'editor_script'   => 'wsh-blocks',
				'render_callback' => array( __CLASS__, 'sc_count' ),
				'supports'        => array( 'html' => false ),
			)
		);
	}

	/**
	 * The button block: the product of the template (context) or the page.
	 *
	 * @param array    $attrs   Attributes.
	 * @param string   $content Content.
	 * @param WP_Block $block   Block.
	 */
	public static function block_button( $attrs, $content = '', $block = null ): string {
		$id = ( $block instanceof WP_Block && isset( $block->context['postId'] ) ) ? (int) $block->context['postId'] : 0;
		return self::sc_button( array( 'id' => $id ) );
	}

	/* =====================================================================
	 * Privacy (WordPress's export / erase personal data tools)
	 * =================================================================== */

	public static function privacy(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ) );
		add_action( 'admin_init', array( __CLASS__, 'policy' ) );
	}

	/**
	 * @param array $list Exporters.
	 */
	public static function exporters( $list ) {
		$list['wish-shelf'] = array(
			'exporter_friendly_name' => 'Wish Shelf',
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $list;
	}

	/**
	 * @param array $list Erasers.
	 */
	public static function erasers( $list ) {
		$list['wish-shelf'] = array(
			'eraser_friendly_name' => 'Wish Shelf',
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $list;
	}

	/**
	 * @param string $email Email.
	 * @param int    $page  Page.
	 */
	public static function export( $email, $page = 1 ): array {
		$user = get_user_by( 'email', (string) $email );
		$data = array();
		if ( $user && (int) $page === 1 ) {
			foreach ( WSH_Store::items( (int) $user->ID ) as $it ) {
				$product = wc_get_product( $it['v'] ? $it['v'] : $it['p'] );
				$data[]  = array(
					'group_id'    => 'wish-shelf',
					'group_label' => 'Wishlist',
					'item_id'     => 'wsh-' . $it['p'] . '-' . $it['v'],
					'data'        => array(
						array(
							'name'  => 'Product',
							'value' => $product ? wp_strip_all_tags( $product->get_name() ) : '#' . $it['p'],
						),
						array(
							'name'  => 'Added',
							'value' => $it['added'] . ' UTC',
						),
					),
				);
			}
			foreach ( WSH_Store::lists( (int) $user->ID ) as $l ) {
				$row = array(
					array(
						'name'  => 'List',
						'value' => $l['name'],
					),
				);
				if ( '' !== $l['token'] ) {
					$row[] = array(
						'name'  => 'Share link',
						'value' => WSH_List::share_url( $l['token'] ),
					);
				}
				$data[] = array(
					'group_id'    => 'wish-shelf',
					'group_label' => 'Wishlist',
					'item_id'     => 'wsh-list-' . $l['id'],
					'data'        => $row,
				);
			}
			if ( WSH_Alerts::opted_in( (int) $user->ID ) ) {
				$data[] = array(
					'group_id'    => 'wish-shelf',
					'group_label' => 'Wishlist',
					'item_id'     => 'wsh-alerts',
					'data'        => array(
						array(
							'name'  => 'Email alerts',
							'value' => 'Yes',
						),
					),
				);
			}
			$token = WSH_Store::token( (int) $user->ID );
			if ( '' !== $token ) {
				$data[] = array(
					'group_id'    => 'wish-shelf',
					'group_label' => 'Wishlist',
					'item_id'     => 'wsh-share',
					'data'        => array(
						array(
							'name'  => 'Share link',
							'value' => WSH_List::share_url( $token ),
						),
					),
				);
			}
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * @param string $email Email.
	 * @param int    $page  Page.
	 */
	public static function erase( $email, $page = 1 ): array {
		$user    = get_user_by( 'email', (string) $email );
		$removed = false;
		if ( $user ) {
			$removed = WSH_Store::clear( (int) $user->ID ) > 0;
			if ( '' !== WSH_Store::token( (int) $user->ID ) ) {
				WSH_Store::token_off( (int) $user->ID );
				$removed = true;
			}
			foreach ( WSH_Store::lists( (int) $user->ID ) as $l ) {
				$removed = WSH_Store::list_delete( (int) $user->ID, $l['id'] ) || $removed;
			}
			if ( WSH_Alerts::opted_in( (int) $user->ID ) ) {
				WSH_Alerts::set_optin( (int) $user->ID, false );
				$removed = true;
			}
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/** A suggested paragraph for the privacy policy. */
	public static function policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			'Wish Shelf',
			wp_kses_post(
				'<p>' . __( 'Όταν προσθέτετε ένα προϊόν στη λίστα επιθυμιών χωρίς να έχετε συνδεθεί, η λίστα αποθηκεύεται μόνο στον browser σας (localStorage) και δεν στέλνεται στο κατάστημα. Όταν είστε συνδεδεμένοι, αποθηκεύουμε στον λογαριασμό σας τα προϊόντα της λίστας, την ημερομηνία και την τιμή τη στιγμή της προσθήκης, για να σας δείχνουμε αν έπεσε η τιμή. Αν κοινοποιήσετε τη λίστα, όποιος έχει τον σύνδεσμο βλέπει τα προϊόντα της, όχι το όνομά σας. Μπορείτε να αδειάσετε τη λίστα ή να σταματήσετε την κοινοποίηση όποτε θέλετε.', 'wish-shelf' ) . '</p>'
				. '<p>' . __( 'Αν ζητήσετε ειδοποιήσεις, σας στέλνουμε email στη διεύθυνση του λογαριασμού σας όταν ένα προϊόν της λίστας σας πέσει σε τιμή ή ξαναμπεί σε απόθεμα. Μπορείτε να τις σταματήσετε από τη λίστα σας ή από τον σύνδεσμο σε κάθε email.', 'wish-shelf' ) . '</p>'
			)
		);
	}
}
