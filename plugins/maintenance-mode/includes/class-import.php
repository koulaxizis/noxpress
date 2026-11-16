<?php
/**
 * MMD_Import — settings from plugins this one replaces (Bible §14.17,
 * «Replacing a third-party plugin»). Reads their options, never changes
 * them, and never turns the switch on: the texts, colours, logo and mode
 * are copied, the rest stays as it was.
 *
 * Formats, read from the plugins' own code (2026-11-16):
 *  - SeedProd «Coming Soon Page & Maintenance Mode» 4.x–5.x
 *    (coming-soon/framework/get-settings.php, inc/config-settings.php):
 *    option seed_csp4_settings_content { status '0' off | '1' coming soon
 *    | '2' maintenance, logo URL, headline, description HTML } and
 *    seed_csp4_settings_design { bg_color, bg_image URL, text_color,
 *    link_color }. SeedProd 6+ keeps its page in its own builder: its
 *    page cannot be imported, the screen says so.
 *  - WP Maintenance 2.x (wp-maintenance/wp-maintenance.php): option
 *    wp_maintenance_settings { titre_maintenance, text_maintenance,
 *    color_bg, color_txt, image URL, b_image URL + b_enable_image,
 *    active_cpt + date_cpt_aa/mm/jj/hh/mn (countdown end) }.
 */

defined( 'ABSPATH' ) || exit;

final class MMD_Import {

	/** The plugins we import from (main files read from their code): file => name. */
	const OTHERS = array(
		'coming-soon/coming-soon.php'       => 'SeedProd Coming Soon',
		'wp-maintenance/wp-maintenance.php' => 'WP Maintenance',
	);

	/** Active plugins of the list above: names. */
	public static function active_others(): array {
		$active = (array) get_option( 'active_plugins', array() );
		$out    = array();
		foreach ( self::OTHERS as $file => $name ) {
			if ( in_array( $file, $active, true ) ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	/** Sources with settings we can read: key => name. */
	public static function sources(): array {
		$out = array();
		if ( is_array( get_option( 'seed_csp4_settings_content', null ) ) ) {
			$out['seedprod'] = 'SeedProd Coming Soon';
		}
		if ( is_array( get_option( 'wp_maintenance_settings', null ) ) ) {
			$out['wpm'] = 'WP Maintenance';
		}
		return $out;
	}

	/** SeedProd 6+ is active, but there is no 4.x/5.x option to read. */
	public static function seedprod_builder(): bool {
		$active = (array) get_option( 'active_plugins', array() );
		return in_array( 'coming-soon/coming-soon.php', $active, true ) && ! is_array( get_option( 'seed_csp4_settings_content', null ) );
	}

	/**
	 * What a source would give: our keys => values.
	 *
	 * @return array
	 */
	public static function read( string $source ): array {
		$out = array();
		if ( 'seedprod' === $source ) {
			$c = get_option( 'seed_csp4_settings_content', array() );
			$d = get_option( 'seed_csp4_settings_design', array() );
			$c = is_array( $c ) ? $c : array();
			$d = is_array( $d ) ? $d : array();
			if ( isset( $c['status'] ) && in_array( (string) $c['status'], array( '1', '2' ), true ) ) {
				$out['mode'] = '1' === (string) $c['status'] ? 'coming_soon' : 'maintenance';
			}
			self::put( $out, 'title_el', isset( $c['headline'] ) ? wp_strip_all_tags( (string) $c['headline'] ) : '' );
			self::put( $out, 'text_el', isset( $c['description'] ) ? wp_kses_post( (string) $c['description'] ) : '' );
			self::put( $out, 'logo', isset( $c['logo'] ) ? self::attachment( (string) $c['logo'] ) : 0 );
			self::put( $out, 'bg', isset( $d['bg_color'] ) ? (string) sanitize_hex_color( (string) $d['bg_color'] ) : '' );
			self::put( $out, 'fg', isset( $d['text_color'] ) ? (string) sanitize_hex_color( (string) $d['text_color'] ) : '' );
			self::put( $out, 'accent', isset( $d['link_color'] ) ? (string) sanitize_hex_color( (string) $d['link_color'] ) : '' );
			self::put( $out, 'bg_image', isset( $d['bg_image'] ) ? self::attachment( (string) $d['bg_image'] ) : 0 );
		} elseif ( 'wpm' === $source ) {
			$w = get_option( 'wp_maintenance_settings', array() );
			$w = is_array( $w ) ? $w : array();
			if ( (int) get_option( 'wp_maintenance_active', 0 ) === 1 ) {
				$out['mode'] = 'maintenance';
			}
			self::put( $out, 'title_el', isset( $w['titre_maintenance'] ) ? wp_strip_all_tags( stripslashes( (string) $w['titre_maintenance'] ) ) : '' );
			self::put( $out, 'text_el', isset( $w['text_maintenance'] ) ? wp_kses_post( stripslashes( (string) $w['text_maintenance'] ) ) : '' );
			self::put( $out, 'bg', isset( $w['color_bg'] ) ? (string) sanitize_hex_color( (string) $w['color_bg'] ) : '' );
			self::put( $out, 'fg', isset( $w['color_txt'] ) ? (string) sanitize_hex_color( (string) $w['color_txt'] ) : '' );
			self::put( $out, 'logo', isset( $w['image'] ) ? self::attachment( (string) $w['image'] ) : 0 );
			if ( ! empty( $w['b_enable_image'] ) && ! empty( $w['b_image'] ) ) {
				self::put( $out, 'bg_image', self::attachment( (string) $w['b_image'] ) );
			}
			if ( ! empty( $w['active_cpt'] ) && ! empty( $w['date_cpt_aa'] ) ) {
				$str = sprintf(
					'%04d-%02d-%02d %02d:%02d:00',
					(int) $w['date_cpt_aa'],
					isset( $w['date_cpt_mm'] ) ? (int) $w['date_cpt_mm'] : 1,
					isset( $w['date_cpt_jj'] ) ? (int) $w['date_cpt_jj'] : 1,
					isset( $w['date_cpt_hh'] ) ? (int) $w['date_cpt_hh'] : 0,
					isset( $w['date_cpt_mn'] ) ? (int) $w['date_cpt_mn'] : 0
				);
				$dt = date_create_immutable_from_format( 'Y-m-d H:i:s', $str, wp_timezone() );
				if ( $dt && $dt->getTimestamp() > time() ) {
					$out['end']       = $dt->getTimestamp();
					$out['countdown'] = true;
				}
			}
		}
		return $out;
	}

	/** Copy a source into our settings. @return string[] Keys copied. */
	public static function apply( string $source ): array {
		$in = self::read( $source );
		if ( ! $in ) {
			return array();
		}
		$s = MMD_Settings::get();
		foreach ( $in as $k => $v ) {
			$s[ $k ] = $v;
		}
		MMD_Settings::save( $s );
		return array_keys( $in );
	}

	private static function put( array &$out, string $key, $value ): void {
		if ( '' !== $value && 0 !== $value && null !== $value ) {
			$out[ $key ] = $value;
		}
	}

	/** Attachment id of a media library URL (0 when it is not one). */
	private static function attachment( string $url ): int {
		$url = esc_url_raw( $url );
		return '' !== $url ? (int) attachment_url_to_postid( $url ) : 0;
	}
}
