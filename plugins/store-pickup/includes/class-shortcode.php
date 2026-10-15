<?php
/**
 * PKP_Shortcode — [pkp_stores]: the active stores as a list (name, address,
 * hours / details, map link), so pages such as "Pickup options" or the FAQ
 * follow the settings.
 *
 *   [pkp_stores]                   every active store, in display order
 *   [pkp_stores ids="a1b2c3d4,…"]  only these store ids (Tools tab lists them)
 *
 * Front-end rules (Bible §14): prints nothing while the mode is "off", or
 * in test mode for visitors who are not shop managers, or with PKP_DISABLE
 * (the tag itself is still registered, so it never shows as raw text);
 * the same output for every visitor otherwise (page caches stay valid); no
 * database writes; try/catch. The texts follow the visitor language of the
 * plugin (Bible §14.17).
 */

defined( 'ABSPATH' ) || exit;

final class PKP_Shortcode {

	const TAG = 'pkp_stores';

	public static function init(): void {
		add_shortcode( self::TAG, array( __CLASS__, 'render' ) );
	}

	/**
	 * @param array|string $atts Shortcode attributes.
	 */
	public static function render( $atts = array() ): string {
		if ( Store_Pickup::killed() || ! PKP_Settings::visible() ) {
			return '';
		}
		try {
			$atts   = shortcode_atts( array( 'ids' => '' ), is_array( $atts ) ? $atts : array(), self::TAG );
			$stores = PKP_Settings::active_stores();
			$ids    = array_filter( array_map( 'sanitize_key', explode( ',', (string) $atts['ids'] ) ) );
			if ( $ids ) {
				$stores = array_values(
					array_filter(
						$stores,
						static function ( $st ) use ( $ids ) {
							return in_array( $st['id'], $ids, true );
						}
					)
				);
			}
			if ( ! $stores ) {
				return '';
			}
			self::style();
			$out = '<ul class="pkp-list">';
			foreach ( $stores as $st ) {
				$out .= '<li class="pkp-list__item">' . PKP_Checkout::store_html( $st, PKP_Settings::details( $st ), 'pkp-list' ) . '</li>';
			}
			return $out . '</ul>';
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug only.
				error_log( 'Store Pickup: ' . $e->getMessage() );
			}
			return '';
		}
	}

	/** Spacing only; the theme's typography is kept. Printed once. */
	private static function style(): void {
		if ( wp_style_is( 'pkp-list', 'enqueued' ) ) {
			return;
		}
		wp_register_style( 'pkp-list', false, array(), PKP_VERSION );
		wp_enqueue_style( 'pkp-list' );
		wp_add_inline_style(
			'pkp-list',
			'.pkp-list{list-style:none;margin:0 0 1.5em;padding:0}'
			. '.pkp-list .pkp-list__item{margin:0 0 1em;padding:0}'
			. '.pkp-list .pkp-list__name{display:block;font-weight:600}'
			. '.pkp-list .pkp-list__addr,.pkp-list .pkp-list__det{display:block}'
			. '.pkp-list .pkp-list__map{display:inline-block;margin-top:.15em}'
		);
	}
}
