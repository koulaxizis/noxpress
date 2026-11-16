<?php
/**
 * LGD_Unlock — «email me an unlock link» for an account whose password
 * logins are locked (wp-login.php?action=lgd_unlock).
 *
 * 1. The visitor writes a username or e-mail. Requests count per IP and
 *    per account before anything is looked up (LGD_Guard::over_requests),
 *    and the answer is the same whether the account exists, is locked or
 *    not. A binding cookie lgd_ub (16 random bytes, SameSite=Lax, HttpOnly)
 *    is set on every request, so its presence says nothing either.
 * 2. Only for an existing, locked account: a 256-bit token goes out by
 *    e-mail; the user meta _lgd_unlock keeps a JSON { h: HMAC-SHA256 of
 *    "<user id>|<token>|<cookie>" with wp_salt( 'auth' ), x: expiry }.
 *    A new request replaces it; a password change deletes it.
 * 3. The link opens a page with an «Unlock» button and changes nothing by
 *    itself (mail scanners open links first). The POST checks the HMAC with
 *    the cookie of this browser and the expiry, deletes the meta with a
 *    compare-and-delete (one use), then ends the account lockout and the
 *    lockout of the IP that confirms. It never logs anyone in: the password
 *    is still needed.
 */

defined( 'ABSPATH' ) || exit;

final class LGD_Unlock {

	const META   = '_lgd_unlock';
	const COOKIE = 'lgd_ub';
	const NONCE  = 'lgd_unlock';

	/** Links that can be valid at the same time for one account. */
	const MAX_TOKENS = 3;

	/** The account's links that have not expired. */
	private static function tokens( int $uid, ?string $raw = null ): array {
		$rec = json_decode( null === $raw ? (string) get_user_meta( $uid, self::META, true ) : $raw, true );
		if ( is_array( $rec ) && isset( $rec['h'] ) ) {
			$rec = array( $rec ); // 1.0.0 beta format: one link.
		}
		$now = time();
		return is_array( $rec ) ? array_values(
			array_filter(
				$rec,
				static function ( $t ) use ( $now ) {
					return is_array( $t ) && isset( $t['h'], $t['x'] ) && (int) $t['x'] > $now;
				}
			)
		) : array();
	}

	public static function init(): void {
		add_action( 'login_form_lgd_unlock', array( __CLASS__, 'page' ) );
		add_action( 'after_password_reset', array( __CLASS__, 'forget' ) );
		add_action(
			'wp_set_password',
			static function ( $password, $user_id = 0 ) {
				delete_user_meta( (int) $user_id, self::META );
			},
			10,
			2
		);
	}

	public static function url(): string {
		return add_query_arg( 'action', 'lgd_unlock', wp_login_url() );
	}

	public static function forget( $user ): void {
		if ( $user instanceof WP_User ) {
			delete_user_meta( (int) $user->ID, self::META );
		}
	}

	/** The binding cookie of this browser, set when missing. */
	private static function bind(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated by the pattern.
		$v = isset( $_COOKIE[ self::COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) : '';
		if ( preg_match( '/^[A-Za-z0-9_-]{22}$/', $v ) ) {
			return $v;
		}
		$v = rtrim( strtr( base64_encode( random_bytes( 16 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- random token.
		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE,
				$v,
				array(
					'expires'  => time() + HOUR_IN_SECONDS,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}
		$_COOKIE[ self::COOKIE ] = $v;
		return $v;
	}

	private static function hmac( int $uid, string $token, string $bind ): string {
		return hash_hmac( 'sha256', $uid . '|' . $token . '|' . $bind, wp_salt( 'auth' ) );
	}

	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in page().
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) ) : '';
	}

	private static function get( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, the POST is checked.
		return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) ) : '';
	}

	/** login_form_lgd_unlock: the whole page. */
	public static function page(): void {
		try {
			nocache_headers();
			if ( ! headers_sent() ) {
				header( 'Referrer-Policy: no-referrer' );
				header( 'X-Robots-Tag: noindex, nofollow' );
			}
			if ( ! LGD_Settings::get()['unlock_mail'] ) {
				wp_safe_redirect( wp_login_url() );
				exit;
			}
			$step = 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) ) : '' ) ? sanitize_key( self::post( 'lgd_step' ) ) : '';
			if ( '' !== $step ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- nonce.
				$nonce = isset( $_POST['_lgd_nonce'] ) ? sanitize_key( wp_unslash( (string) $_POST['_lgd_nonce'] ) ) : '';
				if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
					self::render( 'form', LGD_Settings::text( 'unlock_invalid' ), true );
				}
			}
			if ( 'request' === $step ) {
				self::request();
			}
			if ( 'confirm' === $step ) {
				self::confirm();
			}
			$uid   = absint( self::get( 'lgd_u' ) );
			$token = self::get( 'lgd_t' );
			if ( $uid > 0 && preg_match( '/^[A-Za-z0-9_-]{43}$/', $token ) ) {
				self::render( 'confirm', LGD_Settings::text( 'unlock_confirm' ), false, array( $uid, $token ) );
			}
			self::render( 'form', LGD_Settings::text( 'unlock_intro', array( 'minutes' => LGD_Settings::UNLOCK_TTL ) ), false );
		} catch ( \Throwable $e ) {
			return; // wp-login.php goes on with its own login form.
		}
	}

	private static function request(): void {
		$ident = strtolower( self::post( 'lgd_ident' ) );
		$bind  = self::bind();
		if ( '' === $ident ) {
			self::render( 'form', LGD_Settings::text( 'unlock_intro', array( 'minutes' => LGD_Settings::UNLOCK_TTL ) ), true );
		}
		if ( LGD_Guard::over_requests( 'k', $ident ) ) {
			self::render( 'done', LGD_Settings::text( 'limited' ), true );
		}
		$user = LGD_Store::find_user( $ident );
		if ( $user && LGD_Store::locked( LGD_Store::key_user( (int) $user->ID ) ) > 0 ) {
			self::issue( $user, $bind );
		}
		self::render( 'done', LGD_Settings::text( 'unlock_sent' ), false );
	}

	private static function issue( WP_User $user, string $bind ): void {
		$token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- random token.
		// Up to MAX_TOKENS links stay valid side by side, so a request from
		// someone else's browser does not cancel the owner's own link.
		$list   = array_slice( self::tokens( (int) $user->ID ), - ( self::MAX_TOKENS - 1 ) );
		$list[] = array(
			'h' => self::hmac( (int) $user->ID, $token, $bind ),
			'x' => time() + LGD_Settings::UNLOCK_TTL * MINUTE_IN_SECONDS,
		);
		update_user_meta( (int) $user->ID, self::META, wp_slash( (string) wp_json_encode( array_values( $list ) ) ) );
		$link = add_query_arg(
			array(
				'action' => 'lgd_unlock',
				'lgd_u'  => (int) $user->ID,
				'lgd_t'  => $token,
			),
			wp_login_url()
		);
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$vars = array(
			'site'    => $site,
			'minutes' => LGD_Settings::UNLOCK_TTL,
		);
		$body = str_replace( '{link}', "\n\n" . $link . "\n\n", LGD_Settings::text( 'mail_body', $vars ) );
		$sent = wp_mail( (string) $user->user_email, LGD_Settings::text( 'mail_subject', $vars ), $body );
		if ( $sent ) {
			LGD_Store::log( 'unlock_sent', LGD_Store::ip(), (int) $user->ID );
		}
	}

	private static function confirm(): void {
		$uid   = absint( self::post( 'lgd_u' ) );
		$token = self::post( 'lgd_t' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated by the pattern.
		$bind = isset( $_COOKIE[ self::COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) : '';
		$ok   = false;
		if ( $uid > 0 && preg_match( '/^[A-Za-z0-9_-]{43}$/', $token ) && preg_match( '/^[A-Za-z0-9_-]{22}$/', $bind ) ) {
			$raw  = (string) get_user_meta( $uid, self::META, true );
			$mac  = self::hmac( $uid, $token, $bind );
			$list = self::tokens( $uid, $raw );
			$rest = array();
			$hit  = false;
			foreach ( $list as $t ) {
				if ( ! $hit && hash_equals( (string) $t['h'], $mac ) ) {
					$hit = true;
					continue;
				}
				$rest[] = $t;
			}
			if ( $hit ) {
				// Compare-and-swap: only the request that changes the row goes on.
				$ok = $rest
					? update_user_meta( $uid, self::META, wp_slash( (string) wp_json_encode( $rest ) ), $raw )
					: delete_user_meta( $uid, self::META, wp_slash( $raw ) );
			}
		}
		if ( ! $ok ) {
			self::render( 'done', LGD_Settings::text( 'unlock_invalid' ), true );
		}
		LGD_Store::unlock( LGD_Store::key_user( $uid ), 'mail' );
		$ip = LGD_Store::ip();
		if ( '' !== $ip ) {
			LGD_Store::unlock( LGD_Store::key_ip( $ip ), 'mail' );
		}
		self::render( 'done', LGD_Settings::text( 'unlock_done' ), false, array(), true );
	}

	/**
	 * Prints the page with wp-login.php's own header and footer, then exits.
	 *
	 * @param string $view  'form' | 'confirm' | 'done'.
	 * @param array  $pair  array( uid, token ) for 'confirm'.
	 */
	private static function render( string $view, string $text, bool $error, array $pair = array(), bool $login_link = false ): void {
		$title = LGD_Settings::text( 'unlock_title' );
		$msg   = '<p class="' . ( $error ? 'message lgd-error' : 'message' ) . '">' . esc_html( $text ) . '</p>';
		if ( function_exists( 'login_header' ) ) {
			login_header( $title, $msg );
		} else {
			echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>' . esc_html( $title ) . '</title></head><body>' . wp_kses_post( $msg );
		}
		$action = esc_url( self::url() );
		if ( 'form' === $view ) {
			?>
			<form name="lgdunlockform" id="lostpasswordform" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above. ?>" method="post">
				<p>
					<label for="lgd_ident"><?php echo esc_html( LGD_Settings::text( 'unlock_field' ) ); ?></label>
					<input type="text" name="lgd_ident" id="lgd_ident" class="input" value="" size="20" autocapitalize="off" autocomplete="username" required="required" />
				</p>
				<input type="hidden" name="lgd_step" value="request" />
				<input type="hidden" name="_lgd_nonce" value="<?php echo esc_attr( wp_create_nonce( self::NONCE ) ); ?>" />
				<p class="submit"><input type="submit" class="button button-primary button-large" value="<?php echo esc_attr( LGD_Settings::text( 'unlock_send' ) ); ?>" /></p>
			</form>
			<?php
		} elseif ( 'confirm' === $view && 2 === count( $pair ) ) {
			?>
			<form name="lgdconfirmform" id="lostpasswordform" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above. ?>" method="post">
				<input type="hidden" name="lgd_step" value="confirm" />
				<input type="hidden" name="lgd_u" value="<?php echo (int) $pair[0]; ?>" />
				<input type="hidden" name="lgd_t" value="<?php echo esc_attr( (string) $pair[1] ); ?>" />
				<input type="hidden" name="_lgd_nonce" value="<?php echo esc_attr( wp_create_nonce( self::NONCE ) ); ?>" />
				<p class="submit"><input type="submit" class="button button-primary button-large" value="<?php echo esc_attr( LGD_Settings::text( 'unlock_go' ) ); ?>" /></p>
			</form>
			<?php
		}
		if ( $login_link || 'done' === $view ) {
			// WordPress's own string, in the visitor's language.
			echo '<p id="nav"><a href="' . esc_url( wp_login_url() ) . '">' . esc_html( __( 'Log in' ) ) . '</a></p>'; // phpcs:ignore WordPress.WP.I18n.MissingArgDomain
		}
		if ( function_exists( 'login_footer' ) ) {
			login_footer();
		} else {
			echo '</body></html>';
		}
		exit;
	}
}
