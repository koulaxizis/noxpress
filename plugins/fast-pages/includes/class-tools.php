<?php
/**
 * FPG_Tools — Tools tab: settings from WP Rocket, database cleanup.
 *
 *  import_rocket()  reads the option wp_rocket_settings once (never
 *                   changes it, Bible §14.17 "replacing a third-party
 *                   plugin") and maps what Fast Pages has. WP Rocket's
 *                   separate mobile cache and "never expires" are not
 *                   copied: one cache and a 10-hour life are the
 *                   approved defaults; the report says so.
 *  db_counts()      post revisions and expired transients, read only
 *  db_cleanup()     deletes them (revisions through WordPress, at most
 *                   CLEAN_MAX per run; expired transients with core's
 *                   delete_expired_transients())
 */

defined( 'ABSPATH' ) || exit;

final class FPG_Tools {

	const CLEAN_MAX = 500;

	/** WP Rocket's settings ('' when there are none). */
	public static function rocket(): array {
		$r = get_option( 'wp_rocket_settings', array() );
		return is_array( $r ) ? $r : array();
	}

	/**
	 * Copies WP Rocket's settings. Returns array( changes, notes ): the
	 * settings changes (already saved) and lines for the report.
	 */
	public static function import_rocket(): array {
		$r = self::rocket();
		if ( ! $r ) {
			return array( array(), array( __( 'Δεν βρέθηκαν ρυθμίσεις του WP Rocket.', 'fast-pages' ) ) );
		}
		$on    = static function ( $k ) use ( $r ) {
			return ! empty( $r[ $k ] ) && '0' !== (string) $r[ $k ];
		};
		$lines = static function ( $k ) use ( $r ) {
			$v = $r[ $k ] ?? array();
			return implode( "\n", array_map( 'strval', is_array( $v ) ? $v : explode( "\n", (string) $v ) ) );
		};
		$c     = array(
			'minify_css'  => $on( 'minify_css' ),
			'minify_js'   => $on( 'minify_js' ),
			'lazy_img'    => $on( 'lazyload' ),
			'lazy_iframe' => $on( 'lazyload_iframes' ),
			'lazy_bg'     => $on( 'lazyload_css_bg_img' ),
			'youtube'     => $on( 'lazyload_youtube' ),
			'dims'        => $on( 'image_dimensions' ),
			'preload'     => $on( 'manual_preload' ),
			'hover'       => $on( 'preload_links' ) ? 'prefetch' : FPG_Settings::get()['hover'],
		);
		$map   = array(
			'exclude_urls'    => 'cache_reject_uri',
			'exclude_cookies' => 'cache_reject_cookies',
			'preload_exclude' => 'preload_excluded_uri',
			'lazy_exclude'    => 'exclude_lazyload',
		);
		foreach ( $map as $ours => $theirs ) {
			$v = trim( $lines( $theirs ) );
			if ( '' !== $v ) {
				// WP Rocket lines are regex-like ("/shop/(.*)"): ours use *.
				$c[ $ours ] = str_replace( array( '(.*)', '.*' ), '*', $v );
			}
		}
		$min = trim( $lines( 'exclude_css' ) . "\n" . $lines( 'exclude_js' ) );
		if ( '' !== $min ) {
			$c['minify_exclude'] = str_replace( array( '(.*)', '.*' ), '*', $min );
		}
		FPG_Settings::update( $c );
		FPG_Setup::set_state( array( 'imported' => time() ) );

		$notes = array( __( 'Οι ρυθμίσεις του WP Rocket αντιγράφηκαν. Το WP Rocket δεν άλλαξε.', 'fast-pages' ) );
		if ( $on( 'cache_mobile' ) && $on( 'do_caching_mobile_files' ) ) {
			$notes[] = __( 'Το WP Rocket κρατούσε ξεχωριστή cache για κινητά· εδώ υπάρχει μία για όλους (το αλλάζεις στην καρτέλα Cache).', 'fast-pages' );
		}
		if ( isset( $r['purge_cron_interval'] ) && 0 === (int) $r['purge_cron_interval'] ) {
			$notes[] = __( 'Στο WP Rocket η cache δεν έληγε ποτέ· εδώ λήγει μετά από λίγες ώρες (καρτέλα Cache), ώστε οι φόρμες να μη μένουν με παλιό nonce.', 'fast-pages' );
		}
		foreach ( array(
			'remove_unused_css' => __( 'Αφαίρεση αχρησιμοποίητου CSS', 'fast-pages' ),
			'async_css'         => __( 'Critical CSS', 'fast-pages' ),
			'delay_js'          => __( 'Καθυστέρηση JavaScript', 'fast-pages' ),
			'defer_all_js'      => __( 'Defer JavaScript', 'fast-pages' ),
			'cdn'               => __( 'CDN', 'fast-pages' ),
			'minify_google_fonts' => __( 'Google Fonts', 'fast-pages' ),
		) as $k => $label ) {
			if ( $on( $k ) ) {
				/* translators: %s: a WP Rocket feature */
				$notes[] = sprintf( __( 'Το «%s» του WP Rocket δεν υπάρχει στο Fast Pages και έμεινε έξω.', 'fast-pages' ), $label );
			}
		}
		return array( $c, $notes );
	}

	/* =====================================================================
	 * Database
	 * =================================================================== */

	public static function db_counts(): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- counts for the Tools tab.
		$rev = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" );
		$exp = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE ( option_name LIKE %s OR option_name LIKE %s ) AND option_value < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				$wpdb->esc_like( '_site_transient_timeout_' ) . '%',
				time()
			)
		);
		// phpcs:enable
		return array(
			'revisions'  => $rev,
			'transients' => $exp,
		);
	}

	/** Deletes revisions and expired transients. Returns before / after. */
	public static function db_cleanup(): array {
		global $wpdb;
		$before = self::db_counts();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- ids only; deletion goes through WordPress.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' ORDER BY ID ASC LIMIT %d", self::CLEAN_MAX ) );
		foreach ( (array) $ids as $id ) {
			wp_delete_post_revision( (int) $id );
		}
		delete_expired_transients( true );
		return array(
			'before' => $before,
			'after'  => self::db_counts(),
		);
	}
}
