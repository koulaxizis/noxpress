<?php
/**
 * PBQ_Front — what the visitor sees, and the two REST routes.
 *
 *  - The calculator: shortcode [publishing_quote], block
 *    publishing-quote/calculator and a classic widget. The HTML holds the
 *    option labels and one key per option, never a price.
 *  - With JavaScript (assets/front.js): every change (after 400 ms) and
 *    «Calculate» post the choices to POST /wp-json/pbq/v1/quote and draw
 *    the answer (labels and rounded amounts) with textContent. «I'm
 *    interested» opens the contact form, which posts to
 *    POST /wp-json/pbq/v1/request.
 *  - Without JavaScript: «Calculate» posts the page (pbq_do=calc) and the
 *    same PHP draws the result; the contact form posts pbq_do=send, handled
 *    on template_redirect with a redirect after success (PRG). Answers to
 *    such posts are never cached (DONOTCACHEPAGE + nocache headers); the
 *    page itself can be.
 *  - Test mode: only managers see the calculator (a note says so); off or
 *    PBQ_DISABLE: nothing, and the routes answer 403.
 */

defined( 'ABSPATH' ) || exit;

final class PBQ_Front {

	const SHORTCODE = 'publishing_quote';
	const BLOCK     = 'publishing-quote/calculator';
	const NS        = 'pbq/v1';

	/** @var bool Assets queued in this request. */
	private static $assets = false;

	/** @var bool The #pbq anchor is printed. */
	private static $anchor = false;

	/** @var array|null Result of a no-JS «Send» in this request. */
	private static $sent = null;

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_block' ) );
		add_action( 'widgets_init', array( __CLASS__, 'register_widget' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );

		if ( Publishing_Quote::killed() ) {
			// The block and the widget stay registered (no "missing block"
			// in the editor) but render nothing.
			return;
		}

		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_assets' ) );
		add_action( 'template_redirect', array( __CLASS__, 'template_redirect' ) );
	}

	/* =====================================================================
	 * REST
	 * =================================================================== */

	public static function routes(): void {
		register_rest_route(
			self::NS,
			'/quote',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_quote' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/request',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_request' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	private static function lang_of( $v ): string {
		return 'el' === $v || 'en' === $v ? $v : PBQ_Settings::visitor_lang();
	}

	/** Params of a REST request (JSON body or form fields). */
	private static function params( WP_REST_Request $r ): array {
		$p = $r->get_json_params();
		if ( ! is_array( $p ) ) {
			$p = $r->get_body_params();
		}
		return is_array( $p ) ? $p : array();
	}

	private static function closed(): WP_Error {
		return new WP_Error( 'pbq_off', 'The calculator is not available.', array( 'status' => 403 ) );
	}

	private static function nocache( WP_REST_Response $res ): WP_REST_Response {
		$res->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$res->header( 'X-Robots-Tag', 'noindex' );
		return $res;
	}

	/** POST pbq/v1/quote { q: {choices}, lang } → the public view. */
	public static function rest_quote( WP_REST_Request $r ) {
		try {
			if ( Publishing_Quote::killed() || ! PBQ_Settings::visible() ) {
				return self::closed();
			}
			$p    = self::params( $r );
			$lang = self::lang_of( isset( $p['lang'] ) ? $p['lang'] : '' );
			$q    = isset( $p['q'] ) && is_array( $p['q'] ) ? $p['q'] : array();
			$view = PBQ_Calc::public_view( PBQ_Calc::quote( PBQ_Calc::input( $q ), $lang ) );
			return self::nocache( new WP_REST_Response( $view, 200 ) );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'pbq_error', 'Error.', array( 'status' => 500 ) );
		}
	}

	/** POST pbq/v1/request { q, lang, name, email, phone, book, msg, consent, website }. */
	public static function rest_request( WP_REST_Request $r ) {
		try {
			if ( Publishing_Quote::killed() || ! PBQ_Settings::visible() ) {
				return self::closed();
			}
			$p    = self::params( $r );
			$lang = self::lang_of( isset( $p['lang'] ) ? $p['lang'] : '' );
			$res  = PBQ_Requests::submit( $p, $lang );
			unset( $res['id'] );
			return self::nocache( new WP_REST_Response( $res, $res['ok'] ? 200 : 400 ) );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'pbq_error', 'Error.', array( 'status' => 500 ) );
		}
	}

	/* =====================================================================
	 * No-JS posts
	 * =================================================================== */

	private static function posted_do(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- routing; nonce checked below for logged-in users.
		return isset( $_POST['pbq_do'] ) && is_string( $_POST['pbq_do'] ) ? sanitize_key( wp_unslash( $_POST['pbq_do'] ) ) : '';
	}

	/** The posted choices (no-JS «Calculate» or «Send»). */
	private static function posted_q(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- read-only choices, whitelisted in PBQ_Calc::input().
		if ( isset( $_POST['q'] ) && is_array( $_POST['q'] ) ) {
			return wp_unslash( $_POST['q'] );
		}
		if ( isset( $_POST['pbq_q'] ) && is_string( $_POST['pbq_q'] ) ) {
			$q = json_decode( wp_unslash( $_POST['pbq_q'] ), true );
			return is_array( $q ) ? $q : array();
		}
		// phpcs:enable
		return array();
	}

	public static function template_redirect(): void {
		$do = self::posted_do();
		if ( '' === $do ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			if ( isset( $_GET['pbq_sent'] ) ) {
				self::no_cache_page();
			}
			return;
		}
		self::no_cache_page();
		if ( 'send' !== $do || ! PBQ_Settings::visible() ) {
			return;
		}
		$lang = self::lang_of( isset( $_POST['pbq_lang'] ) ? sanitize_key( wp_unslash( (string) $_POST['pbq_lang'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked below.
		if ( is_user_logged_in() && ( ! isset( $_POST['pbq_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['pbq_nonce'] ) ), 'pbq_send' ) ) ) {
			self::$sent = array(
				'ok'      => false,
				'message' => PBQ_Settings::text( 'err_generic', $lang ),
				'fields'  => array(),
			);
			return;
		}
		$raw = array( 'q' => self::posted_q() );
		foreach ( array( 'name', 'email', 'phone', 'book', 'msg', 'consent', 'website' ) as $k ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- sanitized in PBQ_Requests::submit().
			$raw[ $k ] = isset( $_POST[ 'pbq_' . $k ] ) && is_scalar( $_POST[ 'pbq_' . $k ] ) ? (string) wp_unslash( $_POST[ 'pbq_' . $k ] ) : '';
		}
		$res = PBQ_Requests::submit( $raw, $lang );
		if ( $res['ok'] ) {
			// Back to the page that holds the form: the queried post, else the
			// referer (same host only, wp_get_referer), else the home page.
			$here = is_singular() ? get_permalink( get_queried_object_id() ) : '';
			$back = $here ? $here : ( wp_get_referer() ? wp_get_referer() : home_url( '/' ) );
			$back = remove_query_arg( 'pbq_sent', $back );
			wp_safe_redirect( add_query_arg( 'pbq_sent', '1', $back ) . '#pbq' );
			exit;
		}
		self::$sent = $res;
	}

	private static function no_cache_page(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! headers_sent() ) {
			nocache_headers();
		}
	}

	/* =====================================================================
	 * Calculator
	 * =================================================================== */

	/** For managers only: why the calculator does not show. */
	private static function manager_hint(): string {
		if ( Publishing_Quote::killed() || ! is_user_logged_in() || ! current_user_can( Publishing_Quote::cap() ) ) {
			return '';
		}
		if ( 'off' !== PBQ_Settings::get()['mode'] ) {
			return '';
		}
		return '<p class="pbq-note"><small>' . esc_html__( 'Publishing Quote: ο υπολογιστής είναι κλειστός (λειτουργία «Ανενεργό»).', 'publishing-quote' ) . '</small></p>';
	}

	/** Theme button classes (Bible §14.16). */
	public static function button_class(): string {
		$c = 'button';
		if ( function_exists( 'wc_wp_theme_get_element_class_name' ) ) {
			$e = (string) wc_wp_theme_get_element_class_name( 'button' );
		} elseif ( function_exists( 'wp_theme_get_element_class_name' ) ) {
			$e = (string) wp_theme_get_element_class_name( 'button' );
		} else {
			$e = '';
		}
		return '' !== $e ? $c . ' ' . $e : $c;
	}

	/**
	 * The calculator HTML ('' when it does not show).
	 *
	 * @param array $args title (bool).
	 */
	public static function render( array $args = array() ): string {
		try {
			if ( Publishing_Quote::killed() || ! PBQ_Settings::visible() ) {
				return self::manager_hint();
			}
			$s     = PBQ_Settings::get();
			$lang  = PBQ_Settings::visitor_lang();
			$title = ! isset( $args['title'] ) || $args['title'];
			$uid   = 'pbq-' . wp_unique_id();
			$t     = static function ( string $k ) use ( $lang ): string {
				return PBQ_Settings::text( $k, $lang );
			};

			self::enqueue();

			$do     = self::posted_do();
			$posted = '' !== $do ? self::posted_q() : array();
			$in     = PBQ_Calc::input( $posted ? $posted : self::defaults() );
			$view   = $posted ? PBQ_Calc::public_view( PBQ_Calc::quote( $in, $lang ) ) : null;

			$attrs = ' data-rest="' . esc_url( rest_url( self::NS . '/' ) ) . '" data-lang="' . esc_attr( $lang ) . '"';
			if ( is_user_logged_in() ) {
				// Logged-in pages are not cached: the REST nonce keeps the
				// cookie user (test mode: managers only).
				$attrs .= ' data-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '"';
			}

			$h = '<div class="pbq" id="' . esc_attr( $uid ) . '"' . $attrs . '>';
			if ( ! self::$anchor ) { // Anchor for the no-JS redirect (first calculator only).
				self::$anchor = true;
				$h           .= '<span id="pbq" class="pbq-anchor"></span>';
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			if ( isset( $_GET['pbq_sent'] ) ) {
				$h .= '<p class="pbq-msg pbq-msg--ok" role="status">' . esc_html( $t( 'thanks' ) ) . '</p>';
			}
			if ( $title && '' !== $t( 'title' ) ) {
				$h .= '<p class="pbq-title"><strong>' . esc_html( $t( 'title' ) ) . '</strong></p>';
			}
			if ( '' !== $t( 'intro' ) ) {
				$h .= '<p class="pbq-intro">' . esc_html( $t( 'intro' ) ) . '</p>';
			}

			$h .= '<form class="pbq-form" method="post" action="#' . esc_attr( $uid ) . '" novalidate>';
			$h .= '<input type="hidden" name="pbq_do" value="calc" />';
			$h .= self::fields( $in, $lang, $uid, $view ? $view['fields'] : array() );
			$h .= '<p class="pbq-actions"><button type="submit" class="' . esc_attr( self::button_class() ) . '">' . esc_html( $t( 'calc' ) ) . '</button></p>';
			$h .= '</form>';

			$h .= '<div class="pbq-result" aria-live="polite">' . ( $view ? self::result( $view ) : '' ) . '</div>';
			$h .= self::contact( $in, $lang, $uid, $view );

			if ( 'test' === $s['mode'] ) {
				$h .= '<p class="pbq-note"><small>' . esc_html__( 'Λειτουργία δοκιμής: μόνο οι διαχειριστές βλέπουν τον υπολογιστή.', 'publishing-quote' ) . '</small></p>';
			}
			$h .= '</div>';
			return $h;
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/** Choices before the visitor touches anything. */
	private static function defaults(): array {
		return array(
			'edit'  => '1',
			'print' => '1',
		);
	}

	private static function opt_label( string $id, string $text, bool $err = false ): string {
		return '<label class="pbq-label' . ( $err ? ' is-error' : '' ) . '" for="' . esc_attr( $id ) . '">' . esc_html( $text ) . '</label>';
	}

	/** A select, or a hidden input when there is one option only. */
	private static function select( string $name, string $id, array $rows, string $cur, string $lang, string $label, bool $err, string $extra = '' ): string {
		if ( count( $rows ) <= 1 ) {
			$only = $rows ? (string) array_key_first( $rows ) : '';
			$data = 'q[binding]' === $name && $rows && ! empty( $rows[ $only ]['hard'] ) ? ' data-hard="1"' : '';
			return '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $only ) . '"' . $data . ( 'q[binding]' === $name ? ' data-pbq-binding="1"' : '' ) . ' />';
		}
		$h  = '<div class="pbq-field">' . self::opt_label( $id, $label, $err );
		$h .= '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $extra . '>';
		foreach ( $rows as $rid => $r ) {
			$data = 'q[binding]' === $name && ! empty( $r['hard'] ) ? ' data-hard="1"' : '';
			$h   .= '<option value="' . esc_attr( $rid ) . '"' . selected( $rid, $cur, false ) . $data . '>' . esc_html( PBQ_Settings::label( $r, $lang ) ) . '</option>';
		}
		return $h . '</select></div>';
	}

	private static function number( string $name, string $id, $value, int $min, int $max, string $label, bool $err, string $placeholder = '' ): string {
		return '<div class="pbq-field">' . self::opt_label( $id, $label, $err )
			. '<input type="number" inputmode="numeric" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" min="' . (int) $min . '" max="' . (int) $max . '" step="1"' . ( '' !== $placeholder ? ' placeholder="' . esc_attr( $placeholder ) . '"' : '' ) . ( $err ? ' aria-invalid="true"' : '' ) . ' /></div>';
	}

	private static function check( string $name, string $id, bool $on, string $label, string $extra = '' ): string {
		return '<label class="pbq-check" for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . checked( $on, true, false ) . $extra . ' /> <span>' . esc_html( $label ) . '</span></label>';
	}

	private static function radio( string $name, string $id, string $value, bool $on, string $label, string $extra = '' ): string {
		return '<label class="pbq-check" for="' . esc_attr( $id ) . '"><input type="radio" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . checked( $on, true, false ) . $extra . ' /> <span>' . esc_html( $label ) . '</span></label>';
	}

	/**
	 * The calculator fields (front end and the admin test bench).
	 *
	 * @param array $in     PBQ_Calc::input() values.
	 * @param array $errors Field keys to mark.
	 */
	public static function fields( array $in, string $lang, string $uid, array $errors = array() ): string {
		$s   = PBQ_Settings::get();
		$t   = static function ( string $k ) use ( $lang ): string {
			return PBQ_Settings::text( $k, $lang );
		};
		$err = array_flip( $errors );
		$id  = static function ( string $k ) use ( $uid ): string {
			return $uid . '-' . $k;
		};
		$h   = '';

		// The book.
		$h .= '<fieldset class="pbq-sec"><legend>' . esc_html( $t( 'sec_book' ) ) . '</legend>';
		$h .= self::select( 'q[size]', $id( 'size' ), PBQ_Settings::rows_on( 'sizes' ), $in['size'], $lang, $t( 'size' ), false );
		if ( 'words' === $s['ed_unit'] ) {
			$h .= self::number( 'q[words]', $id( 'words' ), $in['words'] > 0 ? $in['words'] : '', 1, (int) $s['ed_max'] * (int) $s['ed_wpp'], $t( 'ms_words' ), isset( $err['words'] ) );
		} else {
			$h .= self::number( 'q[pages]', $id( 'pages' ), $in['pages'] > 0 ? $in['pages'] : '', 1, (int) $s['ed_max'], $t( 'ms_pages' ), isset( $err['pages'] ) );
		}
		if ( $s['pr_on'] ) {
			$h .= '<div data-pbq-if="print">' . self::number( 'q[book]', $id( 'book' ), $in['book'] > 0 ? $in['book'] : '', 1, 5000, $t( 'book_pages' ), false ) . '</div>';
		}
		$h .= '</fieldset>';

		// Editing.
		if ( $s['ed_on'] ) {
			$h .= '<fieldset class="pbq-sec"><legend>' . esc_html( $t( 'l_editing' ) ) . '</legend>';
			$h .= self::check( 'q[edit]', $id( 'edit' ), $in['edit'], $t( 'want_edit' ), ' data-pbq-toggle="edit"' );
			$levels = PBQ_Settings::rows_on( 'levels' );
			if ( count( $levels ) > 1 ) {
				$h .= '<div data-pbq-if="edit">' . self::select( 'q[level]', $id( 'level' ), $levels, $in['level'], $lang, $t( 'level' ), false ) . '</div>';
			} else {
				$h .= self::select( 'q[level]', $id( 'level' ), $levels, $in['level'], $lang, $t( 'level' ), false );
			}
			$h .= '</fieldset>';
		}

		// Cover and illustrations.
		if ( $s['cv_on'] || $s['il_on'] ) {
			$h .= '<fieldset class="pbq-sec"><legend>' . esc_html( $t( 'sec_design' ) ) . '</legend>';
			if ( $s['cv_on'] ) {
				$h .= self::check( 'q[cover]', $id( 'cover' ), $in['cover'], $t( 'want_cover' ) );
			}
			if ( $s['il_on'] ) {
				$h .= self::check( 'q[illus]', $id( 'illus' ), $in['illus'], $t( 'want_illus' ), ' data-pbq-toggle="illus"' );
				$h .= '<div class="pbq-sub" data-pbq-if="illus">';
				$h .= self::number( 'q[icount]', $id( 'icount' ), $in['icount'] > 0 ? $in['icount'] : '', 1, (int) $s['il_max'], $t( 'illus_count' ), isset( $err['icount'] ) );
				$h .= '<div class="pbq-radios" role="radiogroup">';
				$h .= self::radio( 'q[icolor]', $id( 'icolor-bw' ), 'bw', 'color' !== $in['icolor'], $t( 'bw' ) );
				$h .= self::radio( 'q[icolor]', $id( 'icolor-c' ), 'color', 'color' === $in['icolor'], $t( 'colour' ) );
				$h .= '</div></div>';
			}
			$h .= '</fieldset>';
		}

		// Printing.
		if ( $s['pr_on'] ) {
			$h .= '<fieldset class="pbq-sec"><legend>' . esc_html( $t( 'sec_print' ) ) . '</legend>';
			$h .= self::check( 'q[print]', $id( 'print' ), $in['print'], $t( 'want_print' ), ' data-pbq-toggle="print"' );
			$h .= '<div class="pbq-sub" data-pbq-if="print">';
			$h .= self::number( 'q[run]', $id( 'run' ), $in['run'], (int) $s['pr_run_min'], (int) $s['pr_run_max'], $t( 'run' ), isset( $err['run'] ) );
			$h .= self::select( 'q[paper]', $id( 'paper' ), PBQ_Settings::rows_on( 'papers' ), $in['paper'], $lang, $t( 'paper' ), false );
			if ( $s['pr_color_on'] ) {
				$h .= '<div class="pbq-field"><span class="pbq-label">' . esc_html( $t( 'body' ) ) . '</span><div class="pbq-radios" role="radiogroup">';
				$h .= self::radio( 'q[body]', $id( 'body-bw' ), 'bw', 'color' !== $in['body'], $t( 'body_bw' ) );
				$h .= self::radio( 'q[body]', $id( 'body-c' ), 'color', 'color' === $in['body'], $t( 'body_colour' ) );
				$h .= '</div></div>';
			}
			$h .= self::select( 'q[binding]', $id( 'binding' ), PBQ_Settings::rows_on( 'bindings' ), $in['binding'], $lang, $t( 'binding' ), isset( $err['binding'] ), ' data-pbq-binding="1"' );

			$one  = array();
			$adds = array();
			foreach ( PBQ_Settings::rows_on( 'finishes' ) as $fid => $f ) {
				if ( 'one' === $f['kind'] ) {
					$one[ $fid ] = $f;
				} else {
					$adds[ $fid ] = $f;
				}
			}
			if ( $one || $adds ) {
				$h  .= '<div class="pbq-field"><span class="pbq-label">' . esc_html( $t( 'finish' ) ) . '</span>';
				$cur = '' !== $in['finish'] ? $in['finish'] : (string) array_key_first( $one );
				if ( $one ) {
					$h .= '<div class="pbq-radios" role="radiogroup">';
					foreach ( $one as $fid => $f ) {
						$h .= self::radio( 'q[finish]', $id( 'f-' . $fid ), $fid, $fid === $cur, PBQ_Settings::label( $f, $lang ), ! empty( $f['hard'] ) ? ' data-pbq-hard="1"' : '' );
					}
					$h .= '</div>';
				}
				foreach ( $adds as $fid => $f ) {
					$h .= '<div' . ( ! empty( $f['hard'] ) ? ' data-pbq-hard="1"' : '' ) . '>' . self::check( 'q[adds][]', $id( 'a-' . $fid ), in_array( $fid, $in['adds'], true ), PBQ_Settings::label( $f, $lang ) ) . '</div>';
				}
				$h .= '</div>';
			}
			$h .= '</div></fieldset>';
		}

		// Extras.
		$extras = PBQ_Settings::rows_on( 'extras' );
		if ( $extras ) {
			$h .= '<fieldset class="pbq-sec"><legend>' . esc_html( $t( 'sec_extra' ) ) . '</legend>';
			foreach ( $extras as $xid => $x ) {
				$desc = PBQ_Settings::label( $x, $lang, 'd_' );
				$cond = 'copy' === $x['kind'] ? ' data-pbq-if="print"' : '';
				$h   .= '<div class="pbq-extra"' . $cond . '>';
				if ( 'qty' === $x['kind'] ) {
					$q   = isset( $in['x'][ $xid ] ) ? (int) $in['x'][ $xid ] : 0;
					$h  .= self::number( 'q[x][' . $xid . ']', $id( 'x-' . $xid ), $q > 0 ? $q : '', 0, (int) $x['qmax'], PBQ_Settings::label( $x, $lang ) . ' (' . $t( 'qty' ) . ')', false, '0' );
				} else {
					$h .= self::check( 'q[x][' . $xid . ']', $id( 'x-' . $xid ), isset( $in['x'][ $xid ] ), PBQ_Settings::label( $x, $lang ) );
				}
				if ( '' !== $desc ) {
					$h .= '<small class="pbq-desc">' . esc_html( $desc ) . '</small>';
				}
				$h .= '</div>';
			}
			$h .= '</fieldset>';
		}

		return $h;
	}

	/** The result table (same markup as front.js draws). */
	public static function result( array $v ): string {
		$h = '';
		if ( $v['errors'] ) {
			foreach ( $v['errors'] as $e ) {
				$h .= '<p class="pbq-msg pbq-msg--err" role="alert">' . esc_html( $e ) . '</p>';
			}
			return $h;
		}
		if ( '' !== $v['empty'] ) {
			return '<p class="pbq-msg">' . esc_html( $v['empty'] ) . '</p>';
		}
		$row = static function ( array $r, string $cls = '' ): string {
			return '<tr' . ( '' !== $cls ? ' class="' . esc_attr( $cls ) . '"' : '' ) . '><th scope="row">' . esc_html( $r['label'] ) . '</th><td>' . esc_html( $r['amount'] ) . '</td></tr>';
		};
		$h .= '<p class="pbq-result__title"><strong>' . esc_html( $v['title'] ) . '</strong></p>';
		$h .= '<table class="pbq-table"><tbody>';
		foreach ( $v['lines'] as $l ) {
			$h .= $row( $l );
		}
		if ( $v['typeset'] ) {
			$h .= $row( $v['typeset'], 'is-included' );
		}
		$h .= '</tbody><tfoot>';
		if ( $v['net'] ) {
			$h .= $row( $v['net'], 'is-sub' );
		}
		foreach ( $v['vat'] as $x ) {
			$h .= $row( $x, 'is-sub' );
		}
		$h .= $row( $v['total'], 'is-total' );
		if ( $v['per_copy'] ) {
			$h .= $row( $v['per_copy'], 'is-sub' );
		}
		$h .= '</tfoot></table>';
		foreach ( $v['notes'] as $n ) {
			$h .= '<p class="pbq-note"><small>' . esc_html( $n ) . '</small></p>';
		}
		return $h;
	}

	/** «I'm interested» and the contact form, in a <details>. */
	private static function contact( array $in, string $lang, string $uid, ?array $view ): string {
		$t      = static function ( string $k ) use ( $lang ): string {
			return PBQ_Settings::text( $k, $lang );
		};
		$sent   = self::$sent;
		$err    = $sent && ! $sent['ok'] ? array_flip( $sent['fields'] ) : array();
		$ok     = $view && $view['ok'];
		$val    = static function ( string $k ) use ( $sent ): string {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- refilling the visitor's own form after an error.
			return $sent && isset( $_POST[ 'pbq_' . $k ] ) && is_scalar( $_POST[ 'pbq_' . $k ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ 'pbq_' . $k ] ) ) : '';
		};
		$id     = static function ( string $k ) use ( $uid ): string {
			return $uid . '-c-' . $k;
		};
		$field  = static function ( string $k, string $type, string $label, bool $req, int $max, string $auto ) use ( $id, $val, $err ): string {
			$e = isset( $err[ $k ] );
			return '<div class="pbq-field">' . '<label class="pbq-label' . ( $e ? ' is-error' : '' ) . '" for="' . esc_attr( $id( $k ) ) . '">' . esc_html( $label ) . '</label>'
				. '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id( $k ) ) . '" name="pbq_' . esc_attr( $k ) . '" value="' . esc_attr( $val( $k ) ) . '" maxlength="' . (int) $max . '"' . ( $req ? ' required' : '' ) . ( '' !== $auto ? ' autocomplete="' . esc_attr( $auto ) . '"' : '' ) . ( $e ? ' aria-invalid="true"' : '' ) . ' /></div>';
		};

		$h  = '<details class="pbq-interest"' . ( $ok || $sent ? '' : ' hidden' ) . ( $sent && ! $sent['ok'] ? ' open' : '' ) . '>';
		$h .= '<summary class="' . esc_attr( self::button_class() ) . '">' . esc_html( $t( 'interest' ) ) . '</summary>';
		$h .= '<form class="pbq-contact" method="post" action="#' . esc_attr( $uid ) . '" novalidate>';
		if ( $sent && ! $sent['ok'] ) {
			$h .= '<p class="pbq-msg pbq-msg--err" role="alert">' . esc_html( $sent['message'] ) . '</p>';
		}
		if ( '' !== $t( 'f_intro' ) ) {
			$h .= '<p class="pbq-intro">' . esc_html( $t( 'f_intro' ) ) . '</p>';
		}
		$h .= '<input type="hidden" name="pbq_do" value="send" />';
		$h .= '<input type="hidden" name="pbq_lang" value="' . esc_attr( $lang ) . '" />';
		$h .= '<input type="hidden" name="pbq_q" value="' . esc_attr( (string) wp_json_encode( $in ) ) . '" />';
		if ( is_user_logged_in() ) {
			$h .= wp_nonce_field( 'pbq_send', 'pbq_nonce', false, false );
		}
		$h .= $field( 'name', 'text', $t( 'f_name' ), true, 100, 'name' );
		$h .= $field( 'email', 'email', $t( 'f_email' ), true, 254, 'email' );
		$h .= $field( 'phone', 'tel', $t( 'f_phone' ), false, 40, 'tel' );
		$h .= $field( 'book', 'text', $t( 'f_book' ), false, 150, '' );
		$h .= '<div class="pbq-field"><label class="pbq-label" for="' . esc_attr( $id( 'msg' ) ) . '">' . esc_html( $t( 'f_msg' ) ) . '</label>'
			. '<textarea id="' . esc_attr( $id( 'msg' ) ) . '" name="pbq_msg" rows="4" maxlength="2000">' . esc_textarea( $val( 'msg' ) ) . '</textarea></div>';

		$consent = esc_html( $t( 'f_consent' ) );
		$privacy = PBQ_Settings::get()['privacy'] && function_exists( 'get_privacy_policy_url' ) ? (string) get_privacy_policy_url() : '';
		if ( '' !== $privacy ) {
			$consent .= ' <a href="' . esc_url( $privacy ) . '" target="_blank" rel="noopener">' . esc_html( $t( 'f_privacy' ) ) . '</a>';
		}
		$h .= '<label class="pbq-check' . ( isset( $err['consent'] ) ? ' is-error' : '' ) . '" for="' . esc_attr( $id( 'consent' ) ) . '"><input type="checkbox" id="' . esc_attr( $id( 'consent' ) ) . '" name="pbq_consent" value="1" required /> <span>' . $consent . '</span></label>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
		// Honeypot, hidden inline so a cache that strips our CSS keeps it hidden.
		$h .= '<div style="position:absolute;left:-5000px" aria-hidden="true"><input type="text" name="pbq_website" tabindex="-1" autocomplete="off" value="" /></div>';
		$h .= '<p class="pbq-actions"><button type="submit" class="' . esc_attr( self::button_class() ) . '">' . esc_html( $t( 'f_send' ) ) . '</button></p>';
		$h .= '</form></details>';
		return $h;
	}

	/**
	 * [publishing_quote title="yes|no"]
	 *
	 * @param array|string $atts Shortcode attributes.
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'title' => 'yes' ), is_array( $atts ) ? $atts : array(), self::SHORTCODE );
		return self::render( array( 'title' => in_array( strtolower( (string) $atts['title'] ), array( '1', 'yes', 'true', 'on', 'ναι' ), true ) ) );
	}

	/* =====================================================================
	 * Assets (Bible §14.9: only where the calculator shows)
	 * =================================================================== */

	public static function maybe_assets(): void {
		if ( ! PBQ_Settings::visible() ) {
			return;
		}
		$need = false;
		if ( is_singular() ) {
			$post = get_post();
			if ( $post instanceof WP_Post ) {
				$need = has_shortcode( $post->post_content, self::SHORTCODE ) || has_block( self::BLOCK, $post );
			}
		}
		if ( ! $need && is_active_widget( false, false, 'pbq_calculator', true ) ) {
			$need = true;
		}
		if ( $need ) {
			self::enqueue();
		}
	}

	private static function enqueue(): void {
		if ( self::$assets ) {
			return;
		}
		self::$assets = true;
		wp_enqueue_style( 'pbq-front', PBQ_URL . 'assets/front.css', array(), PBQ_VERSION );
		wp_enqueue_script( 'pbq-front', PBQ_URL . 'assets/front.js', array(), PBQ_VERSION, true );
	}

	/* =====================================================================
	 * Block and widget
	 * =================================================================== */

	public static function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		register_block_type(
			PBQ_PATH . 'blocks/calculator',
			array(
				'render_callback' => array( __CLASS__, 'render_block' ),
			)
		);
	}

	/** @param array $attrs Block attributes. */
	public static function render_block( $attrs ): string {
		if ( Publishing_Quote::killed() ) {
			return '';
		}
		$attrs = is_array( $attrs ) ? $attrs : array();
		$html  = self::render( array( 'title' => ! isset( $attrs['showTitle'] ) || (bool) $attrs['showTitle'] ) );
		if ( '' === $html ) {
			return '';
		}
		$wrap = function_exists( 'get_block_wrapper_attributes' ) ? get_block_wrapper_attributes() : 'class="wp-block-publishing-quote-calculator"';
		return '<div ' . $wrap . '>' . $html . '</div>';
	}

	public static function register_widget(): void {
		require_once PBQ_PATH . 'includes/class-widget.php';
		register_widget( 'PBQ_Widget' );
	}
}
