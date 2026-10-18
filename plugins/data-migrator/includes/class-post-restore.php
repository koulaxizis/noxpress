<?php
/**
 * NM_Post_Restore — the steps that need WordPress itself, run once on
 * the first request after a restore (the runner leaves the nm_post_restore
 * option in the restored database):
 *
 *  wp_loaded (any request)    object cache flush, rewrite rules rebuilt
 *  admin_init (manage_options) WooCommerce: product lookup tables and the
 *                              attributes lookup table (queued in the
 *                              Action Scheduler by WooCommerce's own tools),
 *                              WooCommerce transients
 *
 * Plus an admin notice while a finished restore still keeps its undo data.
 */

defined( 'ABSPATH' ) || exit;

final class NM_Post_Restore {

	public static function init(): void {
		add_action( 'wp_loaded', array( __CLASS__, 'core' ), 1 );
		add_action( 'admin_init', array( __CLASS__, 'woo' ), 20 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	private static function pending(): ?array {
		$v = get_option( 'nm_post_restore', '' );
		$j = is_string( $v ) ? json_decode( $v, true ) : null;
		return is_array( $j ) ? $j : null;
	}

	public static function core(): void {
		$p = self::pending();
		if ( ! $p || ! empty( $p['core'] ) ) {
			return;
		}
		$p['core'] = time();
		update_option( 'nm_post_restore', wp_json_encode( $p ), true );
		wp_cache_flush();
		flush_rewrite_rules( false );
		NM_Util::log( 'post-restore: cache flushed, rewrite rules rebuilt' );
	}

	public static function woo(): void {
		$p = self::pending();
		if ( ! $p || ! current_user_can( 'manage_options' ) || wp_doing_ajax() ) {
			return;
		}
		// One request only.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$gone = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", 'nm_post_restore' ) );
		wp_cache_delete( 'nm_post_restore', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		if ( 1 !== (int) $gone ) {
			return;
		}
		$done = array();
		if ( class_exists( 'WooCommerce' ) ) {
			try {
				if ( function_exists( 'wc_delete_product_transients' ) ) {
					wc_delete_product_transients();
				}
				if ( function_exists( 'wc_delete_shop_order_transients' ) ) {
					wc_delete_shop_order_transients();
				}
				$done[] = 'transients';
				if ( class_exists( 'WC_REST_System_Status_Tools_Controller' ) ) {
					$tools = new WC_REST_System_Status_Tools_Controller();
					$list  = $tools->get_tools();
					foreach ( array( 'regenerate_product_lookup_tables', 'regenerate_product_attributes_lookup_table' ) as $tool ) {
						if ( isset( $list[ $tool ] ) ) {
							$tools->execute_tool( $tool );
							$done[] = $tool;
						}
					}
				} elseif ( function_exists( 'wc_update_product_lookup_tables' ) ) {
					wc_update_product_lookup_tables();
					$done[] = 'regenerate_product_lookup_tables';
				}
			} catch ( Throwable $e ) {
				NM_Util::log( 'post-restore WooCommerce: ' . $e->getMessage(), true );
			}
		}
		$st         = get_option( 'nm_state', array() );
		$st         = is_array( $st ) ? $st : array();
		$st['post'] = array(
			'time' => time(),
			'done' => $done,
			'job'  => (string) ( $p['job'] ?? '' ),
		);
		update_option( 'nm_state', $st, false );
		NM_Util::log( 'post-restore: ' . ( $done ? implode( ', ', $done ) : 'no WooCommerce' ) );
	}

	/** The last restore job, if it still keeps undo data. */
	public static function open_restore(): ?array {
		$st = get_option( 'nm_state', array() );
		$id = is_array( $st ) ? (string) ( $st['last'] ?? '' ) : '';
		if ( '' === $id ) {
			return null;
		}
		$job = NM_Storage::job( $id );
		return ( $job && 'restore' === $job['type'] && 'done' === $job['status'] ) ? $job : null;
	}

	public static function notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$job = self::open_restore();
		if ( ! $job ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		if ( 'nm-restore' === $page ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>'
			. esc_html( NM_Util::t( 'Data Migrator: το site επαναφέρθηκε από αντίγραφο. Έλεγξέ το και μετά πάτα «Οριστικοποίηση» (ή «Αναίρεση» αν κάτι δεν πάει καλά).' ) )
			. ' <a href="' . esc_url( admin_url( 'admin.php?page=nm-restore' ) ) . '">' . esc_html( NM_Util::t( 'Άνοιγμα' ) ) . '</a></p></div>';
	}
}
