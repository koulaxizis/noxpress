<?php
/**
 * FRM_Front — what the visitor sees, and the two REST routes.
 *
 *  - A form: block forms/form, shortcode [nx_form id="12"], and, while
 *    Contact Form 7 is not active, [contact-form-7 id="…"] and
 *    [contact-form id="…"] for the forms imported from it (FRM_CF7), so
 *    theme settings and widgets that hold the old shortcode keep working
 *    (Chris 2026-11-16, Q8).
 *  - The markup is the same for every visitor (no nonce, no token), so a
 *    page cache keeps the page. Logged-in visitors get a REST nonce (their
 *    pages are not cached).
 *  - With JavaScript (assets/front.js): on the first touch it asks
 *    GET frm/v1/token, solves the proof of work in a Web Worker
 *    (assets/pow.js) while the visitor types, and «Send» posts JSON to
 *    POST frm/v1/submit. Answers are drawn with textContent.
 *  - Without JavaScript: «Send» posts the page (frm_do=send); the answer is
 *    the «Confirm sending» step (frm_do=confirm, sealed blob), and after an
 *    accepted send a redirect (PRG) to ?frm_sent={id}. Answers to such
 *    posts are never cached (DONOTCACHEPAGE + nocache headers).
 *  - Test mode: only managers see the forms (a note says so); off or
 *    FRM_DISABLE: nothing, and the routes answer 403.
 *  - CSS and JS load only on pages with a form (Bible §14.9).
 */

defined( 'ABSPATH' ) || exit;

final class FRM_Front {

	const SHORTCODE = 'nx_form';
	const BLOCK     = 'forms/form';
	const NS        = 'frm/v1';

	/** @var bool Assets queued in this request. */
	private static $assets = false;

	/** @var array form id => FRM_Submit result of a no-JS post in this request. */
	private static $results = array();

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_block' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'editor_data' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );

		if ( Noxpress_Forms::killed() ) {
			// The block stays registered (no "missing block" in the editor)
			// but renders nothing.
			return;
		}

		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
		// After every plugin had its init: Contact Form 7 registers its own.
		add_action( 'init', array( __CLASS__, 'compat_shortcodes' ), 99 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_assets' ) );
		add_action( 'template_redirect', array( __CLASS__, 'template_redirect' ) );
	}

	/* =====================================================================
	 * REST
	 * =================================================================== */

	public static function routes(): void {
		register_rest_route(
			self::NS,
			'/token',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_token' ),
				'permission_callback' => '__return_true', // Public (§22): a visitor's signed time token and puzzle; writes nothing.
				'args'                => array(
					'form' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/submit',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_submit' ),
				'permission_callback' => '__return_true', // Public (§22): a visitor's form; token, proof of work, limits and validation in FRM_Submit::run().
			)
		);
	}

	private static function nocache( WP_REST_Response $res ): WP_REST_Response {
		$res->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$res->header( 'X-Robots-Tag', 'noindex' );
		return $res;
	}

	private static function closed(): WP_Error {
		return new WP_Error( 'frm_off', 'The form is not available.', array( 'status' => 403 ) );
	}

	/** GET frm/v1/token?form=ID → { t, d, w }. */
	public static function rest_token( WP_REST_Request $r ) {
		try {
			$form = FRM_Forms::get( (int) $r->get_param( 'form' ) );
			if ( Noxpress_Forms::killed() || ! FRM_Settings::visible() || ! $form || ! $form['active'] ) {
				return self::closed();
			}
			return self::nocache( new WP_REST_Response( FRM_Spam::issue( $form ), 200 ) );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'frm_error', 'Error.', array( 'status' => 500 ) );
		}
	}

	/** POST frm/v1/submit { form, lang, fields, website, token, answer, page, product }. */
	public static function rest_submit( WP_REST_Request $r ) {
		try {
			if ( Noxpress_Forms::killed() || ! FRM_Settings::visible() ) {
				return self::closed();
			}
			$p = $r->get_json_params();
			if ( ! is_array( $p ) ) {
				$p = $r->get_body_params();
			}
			$p = is_array( $p ) ? $p : array();
			if ( strlen( (string) wp_json_encode( $p ) ) > 100000 ) {
				return new WP_Error( 'frm_big', 'Too large.', array( 'status' => 413 ) );
			}
			$res = FRM_Submit::run(
				isset( $p['form'] ) ? absint( $p['form'] ) : 0,
				array(
					'via'     => 'rest',
					'fields'  => isset( $p['fields'] ) && is_array( $p['fields'] ) ? $p['fields'] : array(),
					'website' => $p['website'] ?? '',
					'lang'    => isset( $p['lang'] ) && is_string( $p['lang'] ) ? $p['lang'] : '',
					'token'   => $p['token'] ?? '',
					'answer'  => isset( $p['answer'] ) && is_scalar( $p['answer'] ) ? (string) $p['answer'] : '',
					'page'    => $p['page'] ?? '',
					'product' => $p['product'] ?? 0,
				)
			);
			$out = array(
				'ok'      => (bool) $res['ok'],
				'message' => (string) $res['message'],
				'errors'  => (object) $res['errors'],
			);
			return self::nocache( new WP_REST_Response( $out, $res['ok'] ? 200 : 400 ) );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'frm_error', 'Error.', array( 'status' => 500 ) );
		}
	}

	/* =====================================================================
	 * No-JS posts
	 * =================================================================== */

	private static function post_str( string $k, int $max = 200 ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- visitor form; nonce checked for logged-in users in template_redirect().
		return isset( $_POST[ $k ] ) && is_scalar( $_POST[ $k ] ) ? mb_substr( (string) wp_unslash( $_POST[ $k ] ), 0, $max ) : '';
	}

	public static function template_redirect(): void {
		$do = sanitize_key( self::post_str( 'frm_do', 20 ) );
		if ( '' === $do ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			if ( isset( $_GET['frm_sent'] ) ) {
				self::no_cache_page();
			}
			return;
		}
		if ( ! in_array( $do, array( 'send', 'confirm' ), true ) ) {
			return;
		}
		self::no_cache_page();
		$id   = absint( self::post_str( 'frm_form', 20 ) );
		$lang = self::post_str( 'frm_lang', 5 );
		$lang = in_array( $lang, array( 'el', 'en' ), true ) ? $lang : FRM_Settings::visitor_lang();
		if ( $id <= 0 ) {
			return;
		}
		if ( is_user_logged_in() && ! wp_verify_nonce( sanitize_key( self::post_str( 'frm_nonce', 64 ) ), 'frm_send_' . $id ) ) {
			self::$results[ $id ] = array(
				'ok'      => false,
				'message' => FRM_Settings::text( 'err_expired', $lang ),
				'errors'  => array(),
				'state'   => 'error',
			);
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- visitor values, validated per field in FRM_Fields::validate().
		$fields = isset( $_POST['frm'] ) && is_array( $_POST['frm'] ) ? wp_unslash( $_POST['frm'] ) : array();
		$res    = FRM_Submit::run(
			$id,
			array(
				'via'     => 'confirm' === $do ? 'confirm' : 'nojs',
				'fields'  => $fields,
				'website' => self::post_str( 'website' ),
				'lang'    => $lang,
				'blob'    => self::post_str( 'frm_blob', 200000 ),
				'page'    => self::post_str( 'frm_page', 1000 ),
				'product' => absint( self::post_str( 'frm_product', 20 ) ),
			)
		);
		if ( $res['ok'] ) {
			$here = is_singular() ? get_permalink( get_queried_object_id() ) : '';
			$back = $here ? $here : ( wp_get_referer() ? wp_get_referer() : home_url( '/' ) );
			$back = remove_query_arg( 'frm_sent', $back );
			wp_safe_redirect( add_query_arg( 'frm_sent', $id, $back ) . '#frm-' . $id );
			exit;
		}
		self::$results[ $id ] = $res;
	}

	private static function no_cache_page(): void {
		Noxpress_Core::no_page_cache();
	}

	/* =====================================================================
	 * Rendering
	 * =================================================================== */

	/** For managers only: why a form does not show. */
	private static function manager_hint( string $why ): string {
		if ( Noxpress_Forms::killed() || ! is_user_logged_in() || ! current_user_can( Noxpress_Forms::cap() ) ) {
			return '';
		}
		return '<p class="frm-note-admin"><small>' . esc_html( $why ) . '</small></p>';
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

	private static function current_url(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '/';
		return FRM_Submit::clean_page( home_url( $uri ) );
	}

	/**
	 * A form's HTML ('' when it does not show).
	 *
	 * @param int $id Form id.
	 */
	public static function render( int $id ): string {
		try {
			if ( Noxpress_Forms::killed() ) {
				return '';
			}
			$form = FRM_Forms::get( $id );
			if ( ! $form ) {
				return self::manager_hint( __( 'Forms: η φόρμα δεν βρέθηκε.', 'forms' ) );
			}
			if ( ! FRM_Settings::visible() ) {
				return 'off' === FRM_Settings::get()['mode'] ? self::manager_hint( __( 'Forms: οι φόρμες είναι κλειστές (λειτουργία «Ανενεργό»).', 'forms' ) ) : '';
			}
			if ( ! $form['active'] ) {
				return self::manager_hint( __( 'Forms: η φόρμα είναι ανενεργή.', 'forms' ) );
			}
			$lang = FRM_Settings::visitor_lang();
			$uid  = 'frm-' . $id . '-' . wp_unique_id();
			self::enqueue();

			$res    = self::$results[ $id ] ?? null;
			$values = $res && isset( $res['values'] ) && is_array( $res['values'] ) ? $res['values'] : array();
			$errors = $res && isset( $res['errors'] ) && is_array( $res['errors'] ) ? $res['errors'] : array();
			$prod   = is_singular( 'product' ) ? (int) get_queried_object_id() : 0;

			$attrs = ' data-form="' . (int) $id . '" data-rest="' . esc_url( rest_url( self::NS . '/' ) ) . '" data-lang="' . esc_attr( $lang ) . '"'
				. ' data-pow="' . esc_url( FRM_URL . 'assets/pow.js?ver=' . FRM_VERSION ) . '"'
				. ' data-sending="' . esc_attr( FRM_Settings::text( 'sending', $lang ) ) . '"'
				. ' data-error="' . esc_attr( FRM_Settings::text( 'err_generic', $lang ) ) . '"'
				. ' data-fields-error="' . esc_attr( FRM_Settings::text( 'err_fields', $lang ) ) . '"';
			if ( is_user_logged_in() ) {
				// Logged-in pages are not cached: the REST nonce keeps the
				// cookie user (test mode: managers only).
				$attrs .= ' data-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '"';
			}

			$h  = '<div class="frm-root" id="' . esc_attr( $uid ) . '"' . $attrs . '>';
			$h .= '<span id="frm-' . (int) $id . '" class="frm-anchor"></span>';

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			if ( isset( $_GET['frm_sent'] ) && (int) $_GET['frm_sent'] === $id ) {
				$h .= '<p class="frm-msg frm-msg--ok" role="status">' . esc_html( FRM_Forms::text( $form, 'thanks', $lang ) ) . '</p>';
			}

			if ( $res && 'confirm' === $res['state'] ) {
				$h .= self::confirm_box( $form, $lang, (string) $res['blob'], $prod );
			} else {
				$h .= self::form_html( $form, $lang, $uid, $values, $errors, $res, $prod );
			}

			if ( 'test' === FRM_Settings::get()['mode'] ) {
				$h .= '<p class="frm-note-admin"><small>' . esc_html__( 'Λειτουργία δοκιμής: μόνο οι διαχειριστές βλέπουν τις φόρμες.', 'forms' ) . '</small></p>';
			}
			return $h . '</div>';
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	private static function hidden( string $name, string $value ): string {
		return '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
	}

	/** Honeypot (Bible §14.15): off screen, out of the tab order, hidden from screen readers. */
	private static function honeypot( string $uid ): string {
		return '<div class="frm-hp" aria-hidden="true"><label for="' . esc_attr( $uid ) . '-website">Website</label><input type="text" id="' . esc_attr( $uid ) . '-website" name="website" value="" tabindex="-1" autocomplete="off" /></div>';
	}

	private static function common_hidden( array $form, string $lang, int $prod ): string {
		$h  = self::hidden( 'frm_form', (string) $form['id'] );
		$h .= self::hidden( 'frm_lang', $lang );
		$h .= self::hidden( 'frm_page', self::current_url() );
		if ( $prod > 0 && FRM_Submit::has_product_field( $form ) ) {
			$h .= self::hidden( 'frm_product', (string) $prod );
		}
		if ( is_user_logged_in() ) {
			$h .= self::hidden( 'frm_nonce', wp_create_nonce( 'frm_send_' . $form['id'] ) );
		}
		return $h;
	}

	private static function form_html( array $form, string $lang, string $uid, array $values, array $errors, ?array $res, int $prod ): string {
		$h  = '<form class="frm-form" method="post" action="#frm-' . (int) $form['id'] . '" accept-charset="UTF-8">';
		$h .= self::hidden( 'frm_do', 'send' ) . self::common_hidden( $form, $lang, $prod );
		$h .= self::honeypot( $uid );
		$h .= '<div class="frm-fields">';
		foreach ( $form['fields'] as $f ) {
			$v   = $values[ $f['key'] ] ?? ( 'checkboxes' === $f['type'] ? array() : '' );
			$h  .= FRM_Fields::render( $f, $uid, $lang, $v, (string) ( $errors[ $f['key'] ] ?? '' ) );
		}
		$h .= '</div>';
		$h .= '<div class="frm-actions"><button type="submit" class="' . esc_attr( self::button_class() ) . '">' . esc_html( FRM_Forms::text( $form, 'submit', $lang ) ) . '</button></div>';
		$msg = $res && ! $res['ok'] ? (string) $res['message'] : '';
		$h  .= '<p class="frm-status' . ( '' !== $msg ? ' frm-msg frm-msg--error' : '' ) . '" role="status" aria-live="polite">' . esc_html( $msg ) . '</p>';
		return $h . '</form>';
	}

	/** The no-JS «Confirm sending» step: the values travel in the sealed blob. */
	private static function confirm_box( array $form, string $lang, string $blob, int $prod ): string {
		$h  = '<form class="frm-form frm-confirm" method="post" action="#frm-' . (int) $form['id'] . '" accept-charset="UTF-8">';
		$h .= '<p class="frm-confirm-title"><strong>' . esc_html( FRM_Settings::text( 'confirm_title', $lang ) ) . '</strong></p>';
		$h .= '<p>' . esc_html( FRM_Settings::text( 'confirm_text', $lang ) ) . '</p>';
		$h .= self::hidden( 'frm_do', 'confirm' ) . self::common_hidden( $form, $lang, $prod ) . self::hidden( 'frm_blob', $blob );
		$h .= self::honeypot( 'frm-c-' . (int) $form['id'] );
		$h .= '<div class="frm-actions"><button type="submit" class="' . esc_attr( self::button_class() ) . '">' . esc_html( FRM_Forms::text( $form, 'submit', $lang ) ) . '</button></div>';
		return $h . '</form>';
	}

	/* =====================================================================
	 * Shortcodes, block, assets
	 * =================================================================== */

	public static function shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'id' => 0 ), is_array( $atts ) ? $atts : array(), self::SHORTCODE );
		return self::render( absint( $atts['id'] ) );
	}

	/** [contact-form-7] / [contact-form] while Contact Form 7 is not active. */
	public static function compat_shortcodes(): void {
		if ( defined( 'WPCF7_VERSION' ) || class_exists( 'WPCF7' ) ) {
			return;
		}
		$map = get_option( 'frm_cf7_map', array() );
		if ( ! is_array( $map ) || ! $map ) {
			return;
		}
		foreach ( array( 'contact-form-7', 'contact-form' ) as $tag ) {
			if ( ! shortcode_exists( $tag ) ) {
				add_shortcode( $tag, array( __CLASS__, 'compat_shortcode' ) );
			}
		}
	}

	public static function compat_shortcode( $atts ): string {
		$atts = is_array( $atts ) ? $atts : array();
		$map  = get_option( 'frm_cf7_map', array() );
		$map  = is_array( $map ) ? $map : array();
		$key  = isset( $atts['id'] ) ? strtolower( preg_replace( '/[^a-z0-9]/i', '', (string) $atts['id'] ) ) : '';
		if ( '' !== $key && isset( $map[ $key ] ) ) {
			return self::render( (int) $map[ $key ] );
		}
		return self::manager_hint( __( 'Forms: αυτή η φόρμα του Contact Form 7 δεν έχει εισαχθεί (Noxpress → Forms → Εργαλεία).', 'forms' ) );
	}

	public static function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		register_block_type(
			FRM_PATH . 'blocks/form',
			array(
				'render_callback' => array( __CLASS__, 'render_block' ),
			)
		);
	}

	public static function render_block( $attrs ): string {
		if ( Noxpress_Forms::killed() ) {
			return '';
		}
		$attrs = is_array( $attrs ) ? $attrs : array();
		$id    = isset( $attrs['formId'] ) ? absint( $attrs['formId'] ) : 0;
		if ( $id <= 0 ) {
			return self::manager_hint( __( 'Forms: διάλεξε φόρμα στις ρυθμίσεις του block.', 'forms' ) );
		}
		$html = self::render( $id );
		if ( '' === $html ) {
			return '';
		}
		$wrap = function_exists( 'get_block_wrapper_attributes' ) ? get_block_wrapper_attributes() : 'class="wp-block-forms-form"';
		return '<div ' . $wrap . '>' . $html . '</div>';
	}

	/** The forms list for the block's select (editor only). */
	public static function editor_data(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$list = array();
		foreach ( FRM_Forms::choices() as $id => $title ) {
			$list[] = array(
				'value' => (int) $id,
				'label' => (string) $title,
			);
		}
		wp_add_inline_script( 'forms-form-editor-script', 'window.frmForms = ' . wp_json_encode( $list ) . ';', 'before' );
	}

	public static function maybe_assets(): void {
		if ( ! FRM_Settings::visible() ) {
			return;
		}
		if ( is_singular() ) {
			$post = get_post();
			if ( $post instanceof WP_Post && ( has_shortcode( $post->post_content, self::SHORTCODE ) || has_block( self::BLOCK, $post ) || has_shortcode( $post->post_content, 'contact-form-7' ) ) ) {
				self::enqueue();
			}
		}
		// Elsewhere (theme settings, widgets, page builders) render() queues
		// them: the script goes to the footer, the style follows it.
	}

	private static function enqueue(): void {
		if ( self::$assets ) {
			return;
		}
		self::$assets = true;
		wp_enqueue_style( 'frm-front', FRM_URL . 'assets/front.css', array(), FRM_VERSION );
		wp_enqueue_script( 'frm-front', FRM_URL . 'assets/front.js', array(), FRM_VERSION, true );
	}
}
