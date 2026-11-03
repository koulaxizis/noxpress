<?php
/**
 * ORC_Popup — part of the module "newsletter": the sign-up form in a
 * popup (nl_popup, off by default; this file loads only when it is on).
 *
 *  - The same form as the block (ORC_Newsletter::form), inside a <dialog>
 *    printed in wp_footer. The HTML is the same for every visitor, so page
 *    caches are fine; it stays hidden until assets/popup.js opens it.
 *  - Opens after nl_pop_delay seconds or when the visitor has scrolled
 *    nl_pop_scroll % of the page (0 = time only), whichever comes first,
 *    at most once in nl_pop_days days. The visitor's browser remembers
 *    when it showed (localStorage "orc_pop", no cookie, nothing on the
 *    server); after a sign-up it does not show again. Without
 *    localStorage it never shows, so it cannot nag on every page.
 *  - Never on the cart, the checkout or the account pages, on a page
 *    that already holds the form, in the customizer, in feeds or embeds.
 *    Filter orc_popup_show lets other code hide it.
 *  - ?orc_popup=1 in the address opens it at once, for testing (test mode
 *    still limits it to managers).
 *
 * Front-end rules (Bible §14): mode and ORC_DISABLE through
 * ORC_Newsletter::form_ready(), try/catch with no output on error, no
 * database write, CSS and JS only on the pages where it can open.
 */

defined( 'ABSPATH' ) || exit;

final class ORC_Popup {

	/** @var bool The popup belongs to this page (decided in wp_enqueue_scripts). */
	private static $on = false;

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
		add_action( 'wp_footer', array( __CLASS__, 'render' ), 20 );
	}

	/** True when this page may carry the popup. */
	private static function here(): bool {
		if ( ! ORC_Newsletter::form_ready() || ! ORC_Settings::get()['nl_popup'] ) {
			return false;
		}
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		if ( is_feed() || is_embed() || is_customize_preview() ) {
			return false;
		}
		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
			return false;
		}
		if ( is_singular() ) {
			$post = get_post();
			if ( $post instanceof WP_Post && ( has_shortcode( $post->post_content, ORC_Newsletter::SHORTCODE ) || has_block( 'oros-connect/newsletter', $post ) ) ) {
				return false;
			}
		}
		/**
		 * Whether the newsletter popup may open on this page.
		 *
		 * @param bool $show True by default.
		 */
		return (bool) apply_filters( 'orc_popup_show', true );
	}

	public static function enqueue(): void {
		try {
			if ( ! self::here() ) {
				return;
			}
			self::$on = true;
			wp_enqueue_style( 'orc-front', ORC_URL . 'assets/front.css', array(), ORC_VERSION );
			wp_enqueue_script(
				'orc-popup',
				ORC_URL . 'assets/popup.js',
				array(),
				ORC_VERSION,
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
		} catch ( \Throwable $e ) {
			self::$on = false;
		}
	}

	public static function render(): void {
		if ( ! self::$on ) {
			return;
		}
		try {
			$form = ORC_Newsletter::form(
				array(
					'title' => false,
					'intro' => false,
				)
			);
			if ( '' === $form ) {
				return;
			}
			$s     = ORC_Settings::get();
			$lang  = ORC_Settings::visitor_lang();
			$title = ORC_Settings::text( 'pop_title', $lang );
			$intro = ORC_Settings::text( 'pop_intro', $lang );
			$title = '' !== $title ? $title : ORC_Settings::text( 'title', $lang );
			$intro = '' !== $intro ? $intro : ORC_Settings::text( 'intro', $lang );
			$close = ORC_Settings::text( 'close', $lang );

			$h  = '<dialog class="orc-pop" id="orc-pop" hidden';
			$h .= '' !== $title ? ' aria-labelledby="orc-pop-t"' : ' aria-label="Newsletter"';
			$h .= ' data-delay="' . (int) $s['nl_pop_delay'] . '" data-scroll="' . (int) $s['nl_pop_scroll'] . '" data-days="' . (int) $s['nl_pop_days'] . '">';
			$h .= '<div class="orc-pop__in">';
			$h .= '<button type="button" class="orc-pop__x" data-orc-close aria-label="' . esc_attr( '' !== $close ? $close : 'Close' ) . '">&times;</button>';
			if ( '' !== $title ) {
				$h .= '<p class="orc-pop__title" id="orc-pop-t"><strong>' . esc_html( $title ) . '</strong></p>';
			}
			if ( '' !== $intro ) {
				$h .= '<p class="orc-pop__intro">' . esc_html( $intro ) . '</p>';
			}
			$h .= $form;
			$h .= '</div></dialog>';
			echo $h; // phpcs:ignore WordPress.Security.EscapeOutput -- every part escaped above or in ORC_Newsletter::form().
		} catch ( \Throwable $e ) {
			return;
		}
	}
}
