<?php
/**
 * Plugin Name:          Gift Cards
 * Plugin URI:           https://noxpress.tech
 * Description:          Ψηφιακές δωροκάρτες ως προϊόν: σταθερά ή ελεύθερα ποσά, αποστολή στον παραλήπτη σε ημερομηνία με email και εκτυπώσιμο PDF, εξαργύρωση ως πληρωμή στο καλάθι και στο ταμείο (κλασικό και block), υπόλοιπο που μένει για την επόμενη αγορά και ημερολόγιο κάθε κίνησης.
 * Version:              1.0.2
 * Requires at least:    6.2
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 7.1
 * WC tested up to:      11.2
 * Author:               Christos Koulaxizis
 * Author URI:           https://koulaxizis.gr
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Donate URI:           https://ko-fi.com/koulaxizis
 * Update URI:           https://noxpress.tech/updates/gift-cards
 * Text Domain:          gift-cards
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter. Tier 1 (money): a full
 * security scan runs before every release (project rule 35).
 *
 * Dispatch map:
 *  - gift-cards.php  → bootstrap only (constants, requires, init wiring)
 *  - GFC_Lang        → EN dictionary + gettext filter (admin only: rs_lang)
 *  - GFC_Settings    → the gfc_settings option, strict validation, the
 *                      customer texts (el/en, filter gfc_text, Bible §14.17)
 *  - GFC_Schema      → tables gfc_cards, gfc_ledger, gfc_limits (dbDelta,
 *                      schema version checked on init)
 *  - GFC_Codes       → code generation (80 bit, Crockford base32), input
 *                      normalisation, HMAC (the only form stored)
 *  - GFC_Store       → cards and the append-only ledger: issue, atomic
 *                      debit/credit, per-order target amounts, invariants
 *  - GFC_Limiter     → attempt limits per IP, per session and per site
 *  - GFC_Product     → the product type gfc_gift_card, amounts, the form on
 *                      the product page, cart and order line data
 *  - GFC_Issue       → cards from paid orders, review, delivery, expiry,
 *                      voids on refunded purchases, daily checks
 *  - GFC_Redeem      → applying cards in the cart / checkout, the reduced
 *                      total, debit on order creation, restores, refunds
 *  - GFC_Blocks      → Cart / Checkout blocks: Store API data + update
 *                      callback, the small front-end script
 *  - GFC_Emails      → WooCommerce emails (card, sent, review, expiry)
 *  - GFC_PDF         → the printable card (own writer, as RS_PDF)
 *  - GFC_Account     → My account › Gift cards
 *  - GFC_Admin_UI    → menu, cards, new card, summary, settings, texts,
 *                      tools, the order meta box
 *  - GFC_CLI         → wp gift-cards … (read only)
 *
 * How a card pays (approved E2, "as a payment, not a coupon"): the amount
 * taken from a card is subtracted from the order total after taxes and
 * shipping (filters woocommerce_calculated_total for the cart and
 * woocommerce_order_after_calculate_totals for admin recalculations). The
 * line items, their VAT and the beneficiaries' splits stay exactly as
 * without a card. A negative fee line was the first idea; WooCommerce caps
 * negative fees at the total without tax and spreads tax over them, so a
 * card could not pay the VAT part and would lower the VAT, which is wrong
 * for a payment. The redemptions are kept in order meta (_gfc_cards) and
 * shown as their own rows in the totals, emails and admin.
 *
 * Front-end rules (Bible §14): nothing changes after activation until a
 * product of type "Gift card" is published; test mode limits redemption
 * to shop managers; GFC_DISABLE in wp-config.php stops selling and
 * redeeming (cards already on orders keep their rows and restores).
 *
 * Deviations from the Bible (with reason):
 *  - §14.4 / §14.5 (no AJAX of our own, no write on a visitor request):
 *    applying or removing a card is a visitor action without a page form,
 *    so it has two wc-ajax endpoints (gfc_apply, gfc_remove: POST + nonce,
 *    Wish Shelf exception) and a Store API update callback for the blocks
 *    (namespace gift-cards). They write the card ids into the WooCommerce
 *    session, as coupons do, and count the attempt in gfc_limits before
 *    the code is checked (Bible §11 limits on public forms). The debit is
 *    written when WooCommerce creates the order.
 *  - §12 (clean uninstall): cards, ledger and the HMAC key stay after
 *    uninstall (money the store owes); they go only when "Delete all gift
 *    card data" is confirmed with ΔΙΑΓΡΑΦΗ in Tools.
 *  - §14.3 (GFC_DISABLE stops every front-end hook): the hooks that keep an
 *    existing cart or order right (totals, restores) keep running.
 */

defined( 'ABSPATH' ) || exit;

define( 'GFC_VERSION', '1.0.2' );
define( 'GFC_FILE', __FILE__ );
define( 'GFC_PATH', plugin_dir_path( __FILE__ ) );
define( 'GFC_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16).
require_once GFC_PATH . 'includes/noxpress-core/loader.php';

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', GFC_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', GFC_FILE, true );
		}
	}
);

require_once GFC_PATH . 'includes/class-lang.php';

final class Gift_Cards {

	public static function init(): void {

		GFC_Lang::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'gift-cards', false, dirname( plugin_basename( GFC_FILE ) ) . '/languages' );
			}
		);

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_woo_notice' ) );
			return;
		}

		foreach ( array( 'settings', 'schema', 'codes', 'store', 'limiter', 'product', 'issue', 'redeem', 'blocks', 'emails', 'pdf', 'account' ) as $f ) {
			require_once GFC_PATH . 'includes/class-' . $f . '.php';
		}

		GFC_Schema::init();
		GFC_Product::init();
		GFC_Issue::init();
		GFC_Redeem::init();
		GFC_Blocks::init();
		GFC_Emails::init();
		GFC_Account::init();

		if ( is_admin() ) {
			require_once GFC_PATH . 'includes/class-admin-ui.php';
			GFC_Admin_UI::init();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once GFC_PATH . 'includes/class-cli.php';
			GFC_CLI::init();
		}
	}

	/** True when GFC_DISABLE is defined and truthy in wp-config.php. */
	public static function killed(): bool {
		return defined( 'GFC_DISABLE' ) && GFC_DISABLE;
	}

	public static function missing_woo_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'Το Gift Cards χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.', 'gift-cards' )
			. '</p></div>';
	}
}

/**
 * True when the product (or product id) is a gift card (public API, Bible §6).
 * Discount, wholesale-price and badge plugins of the suite skip these.
 *
 * @param int|WC_Product|null $product Product or id.
 */
function gfc_is_gift_card_product( $product ): bool {
	if ( ! class_exists( 'GFC_Product' ) ) {
		return false;
	}
	return GFC_Product::is_gift_card( $product );
}

/**
 * The gift-card amounts that paid an order (public API, Bible §6).
 *
 * @param int|WC_Order $order Order or id.
 * @return array<int,array{card:int,last4:string,amount:float,refunded:float}>
 */
function gfc_order_cards( $order ): array {
	if ( ! class_exists( 'GFC_Redeem' ) ) {
		return array();
	}
	return GFC_Redeem::order_cards( $order );
}

add_action( 'plugins_loaded', array( 'Gift_Cards', 'init' ), 20 );

register_activation_hook(
	__FILE__,
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		require_once GFC_PATH . 'includes/class-schema.php';
		GFC_Schema::install();
		update_option( 'gfc_flush_rewrite', 1, false );
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'gfc_daily' );
		}
		flush_rewrite_rules( false );
	}
);
