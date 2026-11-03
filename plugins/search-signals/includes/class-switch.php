<?php
/**
 * SSG_Switch — «Switch over» and «Switch back», each in one request, so
 * that no visitor request falls between two SEO layers or none:
 *  over: deactivate the other SEO plugin (never delete it, never touch its
 *        data) → Search Signals live → purge the page caches.
 *  back: activate the other plugin again → Search Signals back to the mode
 *        it had → purge the page caches.
 * Admin only (manage_options + activate_plugins, nonce, confirmation).
 * The option ssg_switch remembers what was switched.
 */

defined( 'ABSPATH' ) || exit;

final class SSG_Switch {

	const OPT = 'ssg_switch';

	public static function state(): array {
		$s = get_option( self::OPT, array() );
		return is_array( $s ) ? $s : array();
	}

	/** '' when «Switch over» may run, else the reason (Greek, for the admin). */
	public static function blocked(): string {
		if ( Search_Signals::killed() ) {
			return __( 'Το SSG_DISABLE είναι ενεργό στο wp-config.php.', 'search-signals' );
		}
		$other = SSG_Detect::other();
		if ( '' === $other ) {
			return __( 'Δεν τρέχει άλλο SEO plugin: διάλεξε απλώς «Ενεργό» στη λειτουργία.', 'search-signals' );
		}
		if ( '' === SSG_Detect::file_of( $other ) || ! is_plugin_active( SSG_Detect::file_of( $other ) ) ) {
			/* translators: %s: plugin name */
			return sprintf( __( 'Το %s τρέχει από άλλο αρχείο ή ως must-use plugin: απενεργοποίησέ το από τη λίστα των plugins.', 'search-signals' ), $other );
		}
		if ( ! SSG_Settings::any_module() ) {
			return __( 'Άνοιξε πρώτα τα modules που θέλεις (Επισκόπηση).', 'search-signals' );
		}
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return __( 'Χρειάζεται το δικαίωμα ενεργοποίησης plugins.', 'search-signals' );
		}
		return '';
	}

	/** @return string '' on success, or the reason. */
	public static function over(): string {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$why = self::blocked();
		if ( '' !== $why ) {
			return $why;
		}
		$other = SSG_Detect::other();
		$file  = SSG_Detect::file_of( $other );
		$s     = SSG_Settings::get();
		update_option(
			self::OPT,
			array(
				'time'      => time(),
				'other'     => $other,
				'file'      => $file,
				'prev_mode' => $s['mode'],
				'user'      => get_current_user_id(),
			),
			false
		);
		deactivate_plugins( $file, false, false );
		$s['mode'] = 'live';
		SSG_Settings::save( $s );
		SSG_Detect::reset();
		Noxpress_Core::purge_page_cache();
		return '';
	}

	/** @return string '' on success, or the reason. */
	public static function back(): string {
		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$st = self::state();
		if ( empty( $st['file'] ) || ! is_string( $st['file'] ) || ! in_array( $st['file'], array_column( SSG_Detect::KNOWN, 1 ), true ) ) {
			return __( 'Δεν υπάρχει μετάβαση για επιστροφή.', 'search-signals' );
		}
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return __( 'Χρειάζεται το δικαίωμα ενεργοποίησης plugins.', 'search-signals' );
		}
		$r = activate_plugin( $st['file'], '', false, false );
		if ( is_wp_error( $r ) ) {
			return $r->get_error_message();
		}
		$s         = SSG_Settings::get();
		$s['mode'] = isset( $st['prev_mode'] ) && in_array( $st['prev_mode'], array( 'off', 'test' ), true ) ? $st['prev_mode'] : 'test';
		SSG_Settings::save( $s );
		delete_option( self::OPT );
		Noxpress_Core::purge_page_cache();
		return '';
	}
}
