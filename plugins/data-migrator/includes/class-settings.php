<?php
/**
 * NM_Settings — the nm_settings option (autoload off), strict validation.
 *
 *  storage     absolute folder for backups ('' = chosen on first use)
 *  part_mb     split archives into parts of this size (0 = one file)
 *  exclude     user exclusion patterns, one per line
 *  keep        keep the backups when the plugin is deleted (default no)
 *  sched_quota size limit of all scheduled backups in GB (0 = none)
 *  sched_mail  email the admin about scheduled runs: fail | all | none
 */

defined( 'ABSPATH' ) || exit;

final class NM_Settings {

	const OPTION = 'nm_settings';

	/** Allowed part sizes in MB. */
	const PARTS = array( 0, 100, 250, 500, 1024, 2048 );

	public static function defaults(): array {
		return array(
			'storage' => '',
			'part_mb' => 0,
			'exclude' => '',
			'keep'        => false,
			'sched_quota' => 5,
			'sched_mail'  => 'fail',
		);
	}

	public static function get(): array {
		$v = get_option( self::OPTION, array() );
		$v = is_array( $v ) ? $v : array();
		return self::clean( array_merge( self::defaults(), $v ) );
	}

	public static function save( array $v ): void {
		update_option( self::OPTION, self::clean( $v ), false );
	}

	public static function clean( array $v ): array {
		$d   = self::defaults();
		$out = array();

		$s              = isset( $v['storage'] ) && is_string( $v['storage'] ) ? trim( $v['storage'] ) : '';
		$out['storage'] = ( '' !== $s && path_is_absolute( $s ) && false === strpos( $s, '..' ) ) ? NM_Util::norm( $s ) : '';

		$p              = isset( $v['part_mb'] ) ? (int) $v['part_mb'] : 0;
		$out['part_mb'] = in_array( $p, self::PARTS, true ) ? $p : $d['part_mb'];

		$lines = array();
		$ex    = isset( $v['exclude'] ) && is_string( $v['exclude'] ) ? $v['exclude'] : '';
		foreach ( preg_split( '/\R/', $ex ) as $line ) {
			$line = trim( (string) $line );
			if ( '' !== $line && strlen( $line ) <= 200 && ! preg_match( '/[\x00-\x1f]/', $line ) ) {
				$lines[] = $line;
			}
		}
		$out['exclude'] = implode( "\n", array_slice( array_unique( $lines ), 0, 100 ) );
		$out['keep']    = ! empty( $v['keep'] );

		$out['sched_quota'] = max( 0, min( 10000, (int) ( $v['sched_quota'] ?? $d['sched_quota'] ) ) );
		$out['sched_mail']  = in_array( $v['sched_mail'] ?? '', array( 'fail', 'all', 'none' ), true ) ? $v['sched_mail'] : $d['sched_mail'];
		return $out;
	}

	/** Exclusion patterns as a list. */
	public static function patterns(): array {
		$s = self::get();
		return '' === $s['exclude'] ? array() : explode( "\n", $s['exclude'] );
	}
}
