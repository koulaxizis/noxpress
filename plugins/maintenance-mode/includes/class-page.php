<?php
/**
 * MMD_Page — the page visitors see while the site is closed.
 *
 * Own template (includes/template.php), never the theme: inline CSS, no
 * external fonts or requests, JavaScript only for the optional countdown.
 * Status: «Maintenance» 503 + Retry-After (until the end time, else the
 * hours setting); «Coming soon» 200 + noindex. Never cached.
 *
 * Visitor texts are settings of the site, not msgids (Bible §14.17):
 * Greek and English value, language Auto (site locale) / Greek / English,
 * an empty English text shows the Greek one, an empty Greek one the
 * default of the mode. Overrides:
 *  - filter mmd_page_text( array $t, string $mode, string $lang )
 *  - a file maintenance-mode.php in the (child) theme replaces our
 *    template; it gets $mmd (the same data as ours);
 *  - filter mmd_page_html( string $html, array $mmd ) for the final HTML.
 */

defined( 'ABSPATH' ) || exit;

final class MMD_Page {

	/** Language of the visitor page: 'el' | 'en'. */
	public static function lang( array $s ): string {
		if ( 'el' === $s['lang'] || 'en' === $s['lang'] ) {
			return $s['lang'];
		}
		return 0 === strpos( strtolower( (string) get_locale() ), 'el' ) ? 'el' : 'en';
	}

	/** Default title and text of a mode. */
	public static function defaults( string $mode, string $lang ): array {
		$d = array(
			'maintenance' => array(
				'el' => array( 'Γινόμαστε καλύτεροι', 'Κάνουμε μια σύντομη συντήρηση. Επιστρέφουμε σε λίγο!' ),
				'en' => array( 'We\'ll be back soon', 'We are doing some quick maintenance. Please check back shortly.' ),
			),
			'coming_soon' => array(
				'el' => array( 'Σύντομα κοντά σας', 'Το νέο μας site ετοιμάζεται. Τα λέμε πολύ σύντομα!' ),
				'en' => array( 'Coming soon', 'Our new site is on its way. See you very soon!' ),
			),
		);
		$m = isset( $d[ $mode ] ) ? $d[ $mode ] : $d['maintenance'];
		return array(
			'title' => $m[ $lang ][0],
			'text'  => $m[ $lang ][1],
		);
	}

	/** Fixed labels of the page. */
	private static function labels( string $lang ): array {
		return 'el' === $lang
			? array(
				'days'    => 'μέρες',
				'hours'   => 'ώρες',
				'minutes' => 'λεπτά',
				'seconds' => 'δευτ.',
				'contact' => 'Επικοινωνία',
				'preview' => 'Προεπισκόπηση: έτσι βλέπουν οι επισκέπτες τη σελίδα.',
			)
			: array(
				'days'    => 'days',
				'hours'   => 'hours',
				'minutes' => 'minutes',
				'seconds' => 'seconds',
				'contact' => 'Contact',
				'preview' => 'Preview: this is the page visitors see.',
			);
	}

	/** Everything the template needs. */
	public static function data( string $mode, bool $preview ): array {
		$s    = MMD_Settings::get();
		$lang = self::lang( $s );
		$def  = self::defaults( $mode, $lang );

		$title = 'en' === $lang && '' !== $s['title_en'] ? $s['title_en'] : $s['title_el'];
		$text  = 'en' === $lang && '' !== $s['text_en'] ? $s['text_en'] : $s['text_el'];
		$t     = array(
			'title' => '' !== $title ? $title : $def['title'],
			'text'  => '' !== $text ? $text : $def['text'],
		);
		$f = apply_filters( 'mmd_page_text', $t, $mode, $lang );
		if ( is_array( $f ) && isset( $f['title'], $f['text'] ) ) {
			$t = array(
				'title' => (string) $f['title'],
				'text'  => (string) $f['text'],
			);
		}

		$logo = '';
		if ( $s['logo'] ) {
			$img  = wp_get_attachment_image_src( $s['logo'], 'medium' );
			$logo = $img ? (string) $img[0] : '';
		}
		if ( '' === $logo && function_exists( 'get_site_icon_url' ) ) {
			$logo = (string) get_site_icon_url( 192 );
		}
		$bg_image = '';
		if ( $s['bg_image'] ) {
			$img      = wp_get_attachment_image_src( $s['bg_image'], 'large' );
			$bg_image = $img ? (string) $img[0] : '';
		}

		$links = array();
		foreach ( $s['links'] as $line ) {
			$p = array_map( 'trim', explode( '|', $line, 2 ) );
			if ( 2 === count( $p ) ) {
				$links[] = array(
					'label' => $p[0],
					'url'   => $p[1],
				);
			}
		}

		$now = time();
		return array(
			'mode'      => $mode,
			'lang'      => $lang,
			'title'     => $t['title'],
			'text'      => $t['text'],
			'site'      => (string) get_bloginfo( 'name' ),
			'logo'      => $logo,
			'bg'        => $s['bg'],
			'fg'        => $s['fg'],
			'accent'    => $s['accent'],
			'bg_image'  => $bg_image,
			'countdown' => $s['countdown'] && $s['end'] > $now ? (int) $s['end'] : 0,
			'email'     => $s['email'],
			'phone'     => $s['phone'],
			'links'     => $links,
			'labels'    => self::labels( $lang ),
			'preview'   => $preview,
			'charset'   => (string) get_option( 'blog_charset', 'UTF-8' ),
		);
	}

	/**
	 * Send the page (status, headers, HTML). The caller exits.
	 *
	 * @param string $mode    'maintenance' | 'coming_soon'.
	 * @param bool   $preview The settings page preview (status 200).
	 */
	public static function send( string $mode, bool $preview ): void {
		$mmd = self::data( $mode, $preview );
		if ( ! headers_sent() ) {
			if ( 'maintenance' === $mode && ! $preview ) {
				status_header( 503 );
				header( 'Retry-After: ' . MMD_Settings::retry_after( MMD_Settings::get(), time() ) );
			} else {
				status_header( 200 );
			}
			if ( 'coming_soon' === $mode || $preview ) {
				header( 'X-Robots-Tag: noindex, nofollow' );
			}
			header( 'Content-Type: text/html; charset=' . $mmd['charset'] );
			header( 'X-Maintenance-Mode: ' . ( $preview ? 'preview' : $mode ) );
			nocache_headers();
		}

		$file = locate_template( 'maintenance-mode.php' );
		ob_start();
		if ( '' !== $file ) {
			include $file;
		} else {
			include MMD_PATH . 'includes/template.php';
		}
		$html = (string) ob_get_clean();
		echo (string) apply_filters( 'mmd_page_html', $html, $mmd ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the template.
	}
}
