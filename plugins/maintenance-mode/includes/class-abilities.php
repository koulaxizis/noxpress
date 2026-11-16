<?php
/**
 * MMD_Abilities — Maintenance Mode in the WordPress Abilities API, for an
 * AI or a remote tool that runs abilities (e.g. Site Bridge's ability_run),
 * with the same capability as the settings page (manage_options):
 *
 *  maintenance-mode/status  state, mode, start / end, who passes (read)
 *  maintenance-mode/set     turns it on or off, with mode and optional
 *                           start / end; purges the page cache like the
 *                           settings page does
 *
 * Registered only on WordPress 6.9+, where the Abilities API exists. Site
 * Bridge adds its own level and write window on top.
 */

defined( 'ABSPATH' ) || exit;

final class MMD_Abilities {

	const CAT = 'noxpress';

	public static function init(): void {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ) );
	}

	/** The shared "noxpress" category, once for every Noxpress plugin. */
	public static function category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( self::CAT ) ) {
			return;
		}
		wp_register_ability_category(
			self::CAT,
			array(
				'label'       => 'Noxpress',
				'description' => 'Operations of the Noxpress plugins.',
			)
		);
	}

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		$defs = array(
			'status' => array(
				'label'       => 'Maintenance Mode: status',
				'description' => 'Whether visitors see the site now. state: off | scheduled (on, start in the future) | on (visitors meet the page) | ended (on, end time passed: the site is open). mode: maintenance (HTTP 503 + Retry-After) | coming_soon (200 + noindex, robots.txt Disallow). start / end as ISO 8601 (empty = none). Who passes: roles, number of IP ranges, active preview links. disabled: MMD_DISABLE is set in wp-config.php.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'ann'         => array( true, false, true ),
				'run'         => array( __CLASS__, 'status' ),
			),
			'set'    => array(
				'label'       => 'Maintenance Mode: turn on or off',
				'description' => 'Turns the maintenance / coming-soon page on or off for visitors (administrators and the chosen roles always see the site). on: true / false. mode: maintenance (default; for a short break of a live site) or coming_soon (a site not launched yet). start / end: optional ISO 8601 times or unix seconds; end in the past is refused; omitted = keep the saved value; empty string = none. The page cache is purged. Returns the new status.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'on'    => array( 'type' => 'boolean' ),
						'mode'  => array(
							'type' => 'string',
							'enum' => array( 'maintenance', 'coming_soon' ),
						),
						'start' => array(
							'type'      => array( 'string', 'integer' ),
							'maxLength' => 40,
						),
						'end'   => array(
							'type'      => array( 'string', 'integer' ),
							'maxLength' => 40,
						),
					),
					'required'             => array( 'on' ),
					'additionalProperties' => false,
				),
				'ann'         => array( false, false, true ),
				'run'         => array( __CLASS__, 'set' ),
			),
		);
		foreach ( $defs as $name => $d ) {
			wp_register_ability(
				'maintenance-mode/' . $name,
				array(
					'label'               => $d['label'],
					'description'         => $d['description'],
					'category'            => self::CAT,
					'input_schema'        => $d['input'],
					'execute_callback'    => $d['run'],
					'permission_callback' => array( __CLASS__, 'allowed' ),
					'meta'                => array(
						'annotations'  => array(
							'readonly'    => $d['ann'][0],
							'destructive' => $d['ann'][1],
							'idempotent'  => $d['ann'][2],
						),
						'show_in_rest' => false,
					),
				)
			);
		}
	}

	public static function allowed(): bool {
		return current_user_can( Maintenance_Mode::CAP );
	}

	public static function status( $in = null ): array {
		$st    = mmd_status();
		$s     = MMD_Settings::get();
		$links = 0;
		foreach ( MMD_Access::links() as $l ) {
			if ( $l['expires'] > time() ) {
				++$links;
			}
		}
		return array(
			'state'         => $st['state'],
			'active'        => $st['active'],
			'mode'          => $st['mode'],
			'start'         => $s['start'] ? wp_date( 'c', $s['start'] ) : '',
			'end'           => $s['end'] ? wp_date( 'c', $s['end'] ) : '',
			'roles'         => $s['roles'],
			'ip_ranges'     => count( $s['ips'] ),
			'preview_links' => $links,
			'rest_closed'   => $s['rest_close'],
			'disabled'      => Maintenance_Mode::killed(),
		);
	}

	/** @return array|WP_Error */
	public static function set( $in = null ) {
		$in = is_array( $in ) ? $in : array();
		$s  = MMD_Settings::get();

		$s['on'] = ! empty( $in['on'] );
		if ( isset( $in['mode'] ) ) {
			$s['mode'] = 'coming_soon' === $in['mode'] ? 'coming_soon' : 'maintenance';
		}
		foreach ( array( 'start', 'end' ) as $k ) {
			if ( ! array_key_exists( $k, $in ) ) {
				continue;
			}
			$t = self::time( $in[ $k ] );
			if ( null === $t ) {
				/* translators: %s: start or end */
				return new WP_Error( 'mmd_bad_time', sprintf( 'Unreadable %s time.', $k ) );
			}
			$s[ $k ] = $t;
		}
		if ( $s['on'] && $s['end'] && $s['end'] <= time() ) {
			return new WP_Error( 'mmd_end_past', 'The end time is in the past.' );
		}
		if ( $s['start'] && $s['end'] && $s['end'] <= $s['start'] ) {
			return new WP_Error( 'mmd_end_before_start', 'The end time must come after the start time.' );
		}
		MMD_Settings::save( $s );
		Maintenance_Mode::purge();
		Maintenance_Mode::schedule();
		return self::status();
	}

	/** Unix time from ISO 8601 / unix seconds; 0 for empty; null when unreadable. */
	private static function time( $v ): ?int {
		if ( '' === $v || null === $v || 0 === $v ) {
			return 0;
		}
		if ( is_int( $v ) || ( is_string( $v ) && ctype_digit( $v ) ) ) {
			return (int) $v;
		}
		try {
			$d = new DateTimeImmutable( (string) $v, wp_timezone() );
			return $d->getTimestamp();
		} catch ( \Throwable $e ) {
			return null;
		}
	}
}
