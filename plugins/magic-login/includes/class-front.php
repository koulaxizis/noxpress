<?php
/**
 * MGL_Front — the visitor side.
 *
 *  - button()       «Στείλε μου σύνδεσμο σύνδεσης» at the end of
 *                   WooCommerce's login form (woocommerce_login_form_end:
 *                   My Account and the classic checkout). It submits the
 *                   same form (formnovalidate, so the empty password is
 *                   fine) with name mgl_request; WooCommerce's own login
 *                   handler waits for name="login" and ignores it.
 *  - handle_post()  wp_loaded, priority 5 (before WooCommerce, 20): the
 *                   request (mgl_request) and the confirmation
 *                   (mgl_confirm). The request answers with a WooCommerce
 *                   notice on the same page, like WooCommerce's own login
 *                   errors (no redirect: a guest may have no session yet,
 *                   and a notice would not survive one).
 *  - handle_link()  template_redirect: a page opened with ?mgl=… gets
 *                   no-cache, no-referrer and noindex headers; a logged-in
 *                   visitor goes on without the token.
 *  - confirm_box()  woocommerce_before_customer_login_form: the box with
 *                   the «Σύνδεση» button (POST, the token in the body, never
 *                   in the form's URL), or the "expired" notice.
 *
 * Nothing runs in mode "off", with MGL_DISABLE, for a logged-in visitor's
 * forms, or when the e-mail is switched off in WooCommerce.
 */

defined( 'ABSPATH' ) || exit;

final class MGL_Front {

	/** @var array|null The link of this request: array( 'key' => 'id-token', 'entry' => array|null ). */
	private static $link = null;

	/** @var bool Set after an error: the plugin stays out of the rest of the request (Bible §14.6). */
	private static $broken = false;

	public static function init(): void {
		if ( 'off' === MGL_Settings::mode() ) {
			return;
		}
		add_action( 'wp_loaded', array( __CLASS__, 'handle_post' ), 5 );
		add_action( 'template_redirect', array( __CLASS__, 'handle_link' ), 1 );
		add_action( 'woocommerce_login_form_end', array( __CLASS__, 'button' ) );
		add_action( 'woocommerce_before_customer_login_form', array( __CLASS__, 'confirm_box' ), 5 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
	}

	/** Logs an error once and stops the plugin for this request. */
	private static function fail( \Throwable $e ): void {
		self::$broken = true;
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $e->getMessage(), array( 'source' => 'magic-login' ) );
		}
	}

	/* =====================================================================
	 * Where the button shows
	 * =================================================================== */

	/** True when this login form gets the button. */
	private static function form_here(): bool {
		if ( self::$broken || is_user_logged_in() || ! MGL_Emails::enabled() ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only switch.
		if ( 'test' === MGL_Settings::mode() && ! isset( $_GET['mgl-test'] ) ) {
			return false;
		}
		$s = MGL_Settings::get();
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return (bool) $s['checkout'];
		}
		return function_exists( 'is_account_page' ) && is_account_page() && (bool) $s['account'];
	}

	/** Where the customer goes after the login: the checkout, or the account page they were on. */
	private static function return_url(): string {
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return wc_get_checkout_url();
		}
		$scheme = is_ssl() ? 'https://' : 'http://';
		$host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_HOST'] ) ) : '';
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
		return self::clean_url( $scheme . $host . $uri );
	}

	/** A local URL without our parameters, or the My Account page. */
	private static function clean_url( string $url ): string {
		$fallback = wc_get_page_permalink( 'myaccount' );
		$url      = remove_query_arg( array( 'mgl', 'mgl-test' ), $url );
		return (string) wp_validate_redirect( $url, $fallback );
	}

	/* =====================================================================
	 * Output
	 * =================================================================== */

	public static function button(): void {
		try {
			if ( ! self::form_here() ) {
				return;
			}
			$cls = 'button mgl-button' . ( function_exists( 'wc_wp_theme_get_element_class_name' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' );
			?>
			<div class="mgl-box">
				<?php wp_nonce_field( 'mgl_request', 'mgl_nonce', false ); ?>
				<input type="hidden" name="mgl_redirect" value="<?php echo esc_url( self::return_url() ); ?>" />
				<button type="submit" class="<?php echo esc_attr( trim( $cls ) ); ?>" name="mgl_request" value="1" formnovalidate="formnovalidate"><?php echo esc_html( MGL_Settings::text( 'button' ) ); ?></button>
				<p class="mgl-hint"><?php echo esc_html( MGL_Settings::text( 'hint' ) ); ?></p>
			</div>
			<?php
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	public static function confirm_box(): void {
		try {
			if ( self::$broken || null === self::$link ) {
				return;
			}
			if ( null === self::$link['entry'] ) {
				wc_add_notice( MGL_Settings::text( 'invalid' ), 'error' );
				return;
			}
			$parsed = MGL_Tokens::parse( self::$link['key'] );
			$user   = $parsed ? get_user_by( 'id', $parsed[0] ) : false;
			if ( ! $user instanceof WP_User ) {
				return;
			}
			$cls = 'button mgl-button' . ( function_exists( 'wc_wp_theme_get_element_class_name' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' );
			?>
			<form method="post" action="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>" class="mgl-confirm">
				<h2><?php echo esc_html( MGL_Settings::text( 'title' ) ); ?></h2>
				<p><?php echo esc_html( MGL_Settings::text( 'confirm', array( 'email' => MGL_Tokens::mask_email( (string) $user->user_email ) ) ) ); ?></p>
				<?php wp_nonce_field( 'mgl_confirm', 'mgl_cnonce', false ); ?>
				<input type="hidden" name="mgl_key" value="<?php echo esc_attr( self::$link['key'] ); ?>" />
				<button type="submit" class="<?php echo esc_attr( trim( $cls ) ); ?>" name="mgl_confirm" value="1"><?php echo esc_html( MGL_Settings::text( 'go' ) ); ?></button>
			</form>
			<?php
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/** True when this page shows the confirmation box (the login form is then hidden). */
	private static function confirming(): bool {
		return ! self::$broken && null !== self::$link && null !== self::$link['entry'];
	}

	public static function assets(): void {
		try {
			// Only where the button or the confirmation box actually shows.
			if ( ! self::form_here() && ! self::confirming() ) {
				return;
			}
			wp_register_style( 'mgl-front', false, array(), MGL_VERSION );
			wp_enqueue_style( 'mgl-front' );
			wp_add_inline_style(
				'mgl-front',
				'.mgl-box{margin:1.25em 0 0;padding-top:1em;border-top:1px solid rgba(127,127,127,.25)}'
				. '.mgl-box .mgl-button{margin:0}'
				. '.mgl-box .mgl-hint{margin:.5em 0 0;font-size:.9em;opacity:.8}'
				. '.mgl-confirm{max-width:480px;margin:0 auto 2em;padding:1.5em;border:1px solid rgba(127,127,127,.3);border-radius:6px;text-align:center}'
				. '.mgl-confirm h2{margin-top:0}'
				. '.mgl-confirming #customer_login,.mgl-confirming .woocommerce-form-login,.mgl-confirming .woocommerce-form-register{display:none!important}'
			);
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	public static function body_class( $classes ) {
		$classes = is_array( $classes ) ? $classes : array();
		if ( self::confirming() ) {
			$classes[] = 'mgl-confirming';
		}
		return $classes;
	}

	/* =====================================================================
	 * Requests
	 * =================================================================== */

	/** A posted scalar, unslashed (callers sanitize). */
	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by the callers.
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
	}

	public static function handle_post(): void {
		try {
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- the nonces are checked below.
			if ( isset( $_POST['mgl_request'] ) ) {
				self::request();
			} elseif ( isset( $_POST['mgl_confirm'] ) ) {
				self::confirm();
			}
			// phpcs:enable
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/** «Στείλε μου σύνδεσμο»: always the same message unless the form was empty, stale or over the limit. */
	private static function request(): void {
		if ( is_user_logged_in() || ! MGL_Emails::enabled() ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( self::post( 'mgl_nonce' ) ), 'mgl_request' ) ) {
			wc_add_notice( MGL_Settings::text( 'stale' ), 'error' );
			return;
		}
		$ident = trim( sanitize_text_field( self::post( 'username' ) ) );
		if ( '' === $ident ) {
			wc_add_notice( MGL_Settings::text( 'empty' ), 'error' );
			return;
		}
		if ( MGL_Tokens::over_limit( $ident ) ) {
			MGL_Tokens::log( 'limited' );
			wc_add_notice( MGL_Settings::text( 'limit' ), 'error' );
			return;
		}

		$sent_msg = MGL_Settings::text( 'sent' );
		$user     = MGL_Tokens::find_user( $ident );
		if ( null === $user ) {
			MGL_Tokens::log( 'unknown' );
			wc_add_notice( $sent_msg, 'success' );
			return;
		}
		if ( ! MGL_Tokens::allowed( $user ) ) {
			MGL_Tokens::log( 'staff', (int) $user->ID );
			wc_add_notice( $sent_msg, 'success' );
			return;
		}
		if ( 'test' === MGL_Settings::mode() && ! MGL_Tokens::is_tester( $user ) ) {
			MGL_Tokens::log( 'test_skip', (int) $user->ID );
			wc_add_notice( $sent_msg, 'success' );
			return;
		}

		$lang     = MGL_Settings::visitor_lang();
		$redirect = self::clean_url( esc_url_raw( self::post( 'mgl_redirect' ) ) );
		$token    = MGL_Tokens::issue( $user, $redirect, $lang );
		if ( MGL_Emails::send( $user, MGL_Tokens::link( (int) $user->ID, $token ), $lang ) ) {
			MGL_Tokens::log( 'sent', (int) $user->ID );
		} else {
			MGL_Tokens::forget_user( $user );
			MGL_Tokens::log( 'mail_fail', (int) $user->ID );
		}
		wc_add_notice( $sent_msg, 'success' );
	}

	/** «Σύνδεση» on the confirmation box: use the token up, log in, go back. */
	private static function confirm(): void {
		if ( is_user_logged_in() ) {
			wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
			exit;
		}
		if ( ! wp_verify_nonce( sanitize_key( self::post( 'mgl_cnonce' ) ), 'mgl_confirm' ) ) {
			wc_add_notice( MGL_Settings::text( 'stale' ), 'error' );
			return;
		}
		$parsed = MGL_Tokens::parse( self::post( 'mgl_key' ) );
		$entry  = $parsed ? MGL_Tokens::consume( $parsed[0], $parsed[1] ) : null;
		if ( null === $entry ) {
			MGL_Tokens::log( 'invalid', $parsed ? $parsed[0] : 0 );
			wc_add_notice( MGL_Settings::text( 'invalid' ), 'error' );
			return;
		}

		$user = get_user_by( 'id', $parsed[0] );
		if ( ! $user instanceof WP_User ) {
			return;
		}
		wp_set_current_user( (int) $user->ID );
		wp_set_auth_cookie( (int) $user->ID, (bool) MGL_Settings::get()['remember'], is_ssl() );
		/** WordPress's own login action (sessions, carts, other plugins). */
		do_action( 'wp_login', $user->user_login, $user );
		MGL_Tokens::log( 'used', (int) $user->ID );
		/**
		 * After a login by link.
		 *
		 * @param int $user_id User id.
		 */
		do_action( 'mgl_logged_in', (int) $user->ID );

		wp_safe_redirect( self::clean_url( isset( $entry['r'] ) ? (string) $entry['r'] : '' ) );
		exit;
	}

	/** A page opened from the e-mail. */
	public static function handle_link(): void {
		try {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the token is the credential.
			if ( ! isset( $_GET['mgl'] ) || ! is_scalar( $_GET['mgl'] ) ) {
				return;
			}
			nocache_headers();
			header( 'Referrer-Policy: no-referrer' );
			add_filter( 'wp_robots', 'wp_robots_no_robots' );

			if ( is_user_logged_in() ) {
				wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
				exit;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the token is the credential.
			$key    = sanitize_text_field( wp_unslash( (string) $_GET['mgl'] ) );
			$parsed = MGL_Tokens::parse( $key );
			// Read only: a mail scanner opening the link uses nothing up.
			self::$link = array(
				'key'   => $key,
				'entry' => $parsed ? MGL_Tokens::check( $parsed[0], $parsed[1] ) : null,
			);
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}
}
