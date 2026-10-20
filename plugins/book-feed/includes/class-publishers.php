<?php
/**
 * BKF_Publishers — [bkf_publishers]: the logos of the publisher
 * subcategories (setting pub_cat) that have titles, in a small strip
 * that drifts slowly sideways (1.6.0).
 *
 * Attributes: height (logo height in px, 24–120, default 40),
 * speed (seconds per round, 10–300, default 40), parent (product_cat
 * id, default the pub_cat setting). Each logo links to its
 * subcategory; a subcategory without an image shows its name.
 * The strip stops on hover and focus, and stands still (scrolls by
 * hand) for visitors who ask for reduced motion.
 */

defined( 'ABSPATH' ) || exit;

final class BKF_Publishers {

	/** Copies of the list in the strip, so a short list still fills it. */
	const MIN_ITEMS = 12;

	/** True once the styles are printed on this page. */
	private static $styled = false;

	public static function init(): void {
		add_shortcode( 'bkf_publishers', array( __CLASS__, 'render' ) );
	}

	/** The publisher subcategories with titles, by name. */
	public static function terms( int $parent ): array {
		if ( $parent <= 0 ) {
			return array();
		}
		// The ids from core's stored hierarchy, then the terms by id: a list
		// query by parent would be emptied by plugins that hide the parent
		// (Hide Catalog leaves lookups by id alone).
		$ids = get_term_children( $parent, 'product_cat' );
		if ( ! is_array( $ids ) || ! $ids ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'include'    => array_map( 'intval', $ids ),
				'hide_empty' => true,
				'orderby'    => 'name',
			)
		);
		if ( ! is_array( $terms ) ) {
			return array();
		}
		$out = array();
		foreach ( $terms as $t ) {
			if ( $t instanceof WP_Term && (int) $t->parent === $parent ) {
				$out[] = $t;
			}
		}
		return $out;
	}

	/** The shortcode. */
	public static function render( $atts ): string {
		$a      = shortcode_atts(
			array(
				'height' => 40,
				'speed'  => 40,
				'parent' => 0,
			),
			$atts,
			'bkf_publishers'
		);
		$height = max( 24, min( 120, (int) $a['height'] ) );
		$speed  = max( 10, min( 300, (int) $a['speed'] ) );
		$parent = (int) $a['parent'] > 0 ? (int) $a['parent'] : (int) BKF_Settings::get()['pub_cat'];
		$terms  = self::terms( $parent );
		if ( ! $terms ) {
			return '';
		}

		$items = '';
		foreach ( $terms as $t ) {
			$link = get_term_link( $t );
			if ( is_wp_error( $link ) ) {
				continue;
			}
			$img  = (int) get_term_meta( $t->term_id, 'thumbnail_id', true );
			$html = $img ? wp_get_attachment_image(
				$img,
				'medium',
				false,
				array(
					'alt'     => $t->name,
					'loading' => 'lazy',
					'class'   => 'bkf-pubs__logo',
				)
			) : '';
			if ( '' === $html ) {
				$html = '<span class="bkf-pubs__name">' . esc_html( $t->name ) . '</span>';
			}
			$items .= '<li class="bkf-pubs__item"><a href="' . esc_url( $link ) . '" title="' . esc_attr( $t->name ) . '">' . $html . '</a></li>';
		}
		if ( '' === $items ) {
			return '';
		}

		// One half of the track: the list repeated until it is long enough;
		// the second half repeats it for a seamless loop (hidden from screen
		// readers and keyboard).
		$copies = max( 1, (int) ceil( self::MIN_ITEMS / count( $terms ) ) );
		$half   = str_repeat( $items, $copies );
		$hidden = str_replace( '<a href=', '<a tabindex="-1" href=', $half );

		$out  = self::style();
		$out .= '<div class="bkf-pubs" style="--bkf-pubs-h:' . $height . 'px;--bkf-pubs-t:' . $speed . 's" role="region" aria-label="' . esc_attr__( 'Εκδοτικοί οίκοι', 'book-feed' ) . '">';
		$out .= '<ul class="bkf-pubs__track"><li class="bkf-pubs__half"><ul>' . $half . '</ul></li>';
		$out .= '<li class="bkf-pubs__half" aria-hidden="true"><ul>' . $hidden . '</ul></li></ul></div>';
		return $out;
	}

	/** Styles, once per page. */
	private static function style(): string {
		if ( self::$styled ) {
			return '';
		}
		self::$styled = true;
		return '<style id="bkf-pubs-css">'
			. '.bkf-pubs{overflow:hidden;margin:.5em 0 1.25em;-webkit-mask-image:linear-gradient(90deg,transparent,#000 8%,#000 92%,transparent);mask-image:linear-gradient(90deg,transparent,#000 8%,#000 92%,transparent)}'
			. '.bkf-pubs ul{list-style:none;margin:0;padding:0;display:flex;align-items:center}'
			. '.bkf-pubs__track{width:max-content;animation:bkf-pubs-run var(--bkf-pubs-t,40s) linear infinite}'
			. '.bkf-pubs:hover .bkf-pubs__track,.bkf-pubs:focus-within .bkf-pubs__track{animation-play-state:paused}'
			. '.bkf-pubs__item{flex:0 0 auto;margin:0 calc(var(--bkf-pubs-h,40px)*.9)}'
			. '.bkf-pubs__item a{display:flex;align-items:center;height:var(--bkf-pubs-h,40px);text-decoration:none;opacity:.55;filter:grayscale(1);transition:opacity .3s,filter .3s}'
			. '.bkf-pubs__item a:hover,.bkf-pubs__item a:focus{opacity:1;filter:none}'
			. '.bkf-pubs__logo{height:100%;width:auto;max-width:none;object-fit:contain}'
			. '.bkf-pubs__name{font-size:.85em;letter-spacing:.04em;white-space:nowrap;color:inherit}'
			. '@keyframes bkf-pubs-run{from{transform:translateX(0)}to{transform:translateX(-50%)}}'
			. '@media (prefers-reduced-motion:reduce){.bkf-pubs{overflow-x:auto;-webkit-mask-image:none;mask-image:none}.bkf-pubs__track{animation:none}.bkf-pubs__half[aria-hidden]{display:none}}'
			. '</style>';
	}
}
