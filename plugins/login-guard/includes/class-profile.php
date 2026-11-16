<?php
/**
 * LGD_Profile — «Two-step verification» in the user profile.
 *
 * Own profile (profile.php):
 *  - Off: a new secret waits in _lgd_2fa_setup (sealed, 30 minutes) and is
 *    shown as a QR code (drawn in the browser by the bundled MIT library
 *    qrcode-generator, no outside service) and as text. 2FA turns on only
 *    when «Update profile» comes with a right code from the app. Then the
 *    ten recovery codes appear once (sealed transient, 10 minutes).
 *  - On: new recovery codes; turn off with a code from the app or a
 *    recovery code (not while a role requires it); forget the remembered
 *    browsers.
 * Another user's profile (user-edit.php): status, and «Reset» for someone
 * who may edit that user and has 2FA on.
 *
 * WordPress checks the profile nonce and edit_user before
 * personal_options_update / edit_user_profile_update; a wrong code stops
 * the save through user_profile_update_errors.
 */

defined( 'ABSPATH' ) || exit;

final class LGD_Profile {

	const SETUP = '_lgd_2fa_setup';
	const SHOW  = 'lgd_rc_';
	const MSG   = 'lgd_pmsg_';

	/** Error for user_profile_update_errors, or ''. */
	private static $error = '';

	public static function init(): void {
		add_action( 'show_user_profile', array( __CLASS__, 'own' ), 5 );
		add_action( 'edit_user_profile', array( __CLASS__, 'other' ), 5 );
		add_action( 'personal_options_update', array( __CLASS__, 'save_own' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_other' ) );
		add_action( 'user_profile_update_errors', array( __CLASS__, 'errors' ), 10, 3 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function assets( $hook ): void {
		if ( 'profile.php' !== $hook || ! LGD_TwoFA::active() || LGD_TwoFA::is_on( get_current_user_id() ) ) {
			return;
		}
		wp_enqueue_script( 'lgd-qrcode', LGD_URL . 'assets/vendor/qrcode.js', array(), '1.4.4', true );
		wp_enqueue_script( 'lgd-profile', LGD_URL . 'assets/profile.js', array( 'lgd-qrcode' ), LGD_VERSION, true );
	}

	/** The waiting secret of the setup, made when missing or expired. */
	private static function setup_secret( int $uid ): string {
		$r = get_user_meta( $uid, self::SETUP, true );
		if ( is_array( $r ) && isset( $r['s'], $r['x'] ) && (int) $r['x'] > time() ) {
			$s = LGD_Crypt::unseal( (string) $r['s'], $uid );
			if ( null !== $s ) {
				return $s;
			}
		}
		$secret = LGD_TOTP::new_secret();
		update_user_meta(
			$uid,
			self::SETUP,
			array(
				's' => LGD_Crypt::seal( $secret, $uid ),
				'x' => time() + 30 * MINUTE_IN_SECONDS,
			)
		);
		return $secret;
	}

	private static function flash( int $uid, string $msg ): void {
		set_transient( self::MSG . $uid, $msg, 5 * MINUTE_IN_SECONDS );
	}

	/** Recovery codes to show once. */
	private static function keep_codes( int $uid, array $codes ): void {
		if ( array() !== $codes ) {
			set_transient( self::SHOW . $uid, LGD_Crypt::seal( implode( ' ', $codes ), $uid ), 10 * MINUTE_IN_SECONDS );
		}
	}

	private static function take_codes( int $uid ): array {
		$raw = get_transient( self::SHOW . $uid );
		delete_transient( self::SHOW . $uid );
		$plain = is_string( $raw ) ? LGD_Crypt::unseal( $raw, $uid ) : null;
		return null === $plain ? array() : explode( ' ', $plain );
	}

	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WordPress checked the profile nonce.
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) ) : '';
	}

	/* =====================================================================
	 * Screens
	 * =================================================================== */

	public static function own( $user ): void {
		if ( ! $user instanceof WP_User ) {
			return;
		}
		$uid = (int) $user->ID;
		echo '<h2 id="lgd-2fa">' . esc_html__( 'Επαλήθευση σε δύο βήματα', 'login-guard' ) . '</h2>';
		if ( ! LGD_TwoFA::active() ) {
			echo '<p class="description">' . esc_html( self::why_off() ) . '</p>';
			return;
		}
		$msg = get_transient( self::MSG . $uid );
		if ( is_string( $msg ) && '' !== $msg ) {
			delete_transient( self::MSG . $uid );
			echo '<div class="notice notice-success inline"><p>' . esc_html( $msg ) . '</p></div>';
		}
		$codes = self::take_codes( $uid );
		if ( array() !== $codes ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Οι κωδικοί ανάκτησης φαίνονται μόνο τώρα.', 'login-guard' ) . '</strong> ' . esc_html__( 'Γράψε τους ή τύπωσέ τους και κράτησέ τους μακριά από τον υπολογιστή. Καθένας δουλεύει μία φορά, αν χάσεις το κινητό.', 'login-guard' ) . '</p>';
			echo '<pre style="font-size:15px;line-height:1.7;user-select:all">' . esc_html( implode( "\n", $codes ) ) . '</pre></div>';
		}
		echo '<table class="form-table" role="presentation"><tbody>';
		if ( LGD_TwoFA::is_on( $uid ) ) {
			$rec  = LGD_TwoFA::rec( $uid );
			$left = isset( $rec['rc'] ) ? count( (array) $rec['rc'] ) : 0;
			echo '<tr><th>' . esc_html__( 'Κατάσταση', 'login-guard' ) . '</th><td><strong>' . esc_html__( 'Ενεργή', 'login-guard' ) . '</strong> · ';
			/* translators: 1: date, 2: recovery codes left */
			echo esc_html( sprintf( __( 'από %1$s, %2$d κωδικοί ανάκτησης απομένουν', 'login-guard' ), wp_date( (string) get_option( 'date_format' ), (int) $rec['on'] ), $left ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Κωδικοί ανάκτησης', 'login-guard' ) . '</th><td><label><input type="checkbox" name="lgd_tfa_codes" value="1" /> ' . esc_html__( 'Νέοι κωδικοί ανάκτησης (οι παλιοί σταματούν να δουλεύουν)', 'login-guard' ) . '</label></td></tr>';
			echo '<tr><th>' . esc_html__( 'Θυμημένοι browsers', 'login-guard' ) . '</th><td><label><input type="checkbox" name="lgd_tfa_forget" value="1" /> ' . esc_html__( 'Να ζητηθεί ξανά ο κωδικός σε όλους τους browsers', 'login-guard' ) . '</label></td></tr>';
			if ( LGD_TwoFA::required( $user ) ) {
				echo '<tr><th>' . esc_html__( 'Απενεργοποίηση', 'login-guard' ) . '</th><td><p class="description">' . esc_html__( 'Ο ρόλος σου τη χρειάζεται, γι\' αυτό δεν κλείνει από εδώ.', 'login-guard' ) . '</p></td></tr>';
			} else {
				echo '<tr><th><label for="lgd_tfa_off">' . esc_html__( 'Απενεργοποίηση', 'login-guard' ) . '</label></th><td><input type="text" name="lgd_tfa_off" id="lgd_tfa_off" class="regular-text" inputmode="numeric" autocomplete="one-time-code" value="" /><p class="description">' . esc_html__( 'Για να την κλείσεις, γράψε έναν κωδικό από την εφαρμογή ή έναν κωδικό ανάκτησης και πάτα «Ενημέρωση προφίλ».', 'login-guard' ) . '</p></td></tr>';
			}
		} else {
			$secret = self::setup_secret( $uid );
			$issuer = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
			$uri    = LGD_TOTP::uri( $secret, '' !== $issuer ? $issuer : (string) wp_parse_url( home_url(), PHP_URL_HOST ), (string) $user->user_login );
			echo '<tr><th>' . esc_html__( 'Κατάσταση', 'login-guard' ) . '</th><td>' . esc_html__( 'Ανενεργή', 'login-guard' );
			if ( LGD_TwoFA::required( $user ) ) {
				echo ' · <strong>' . esc_html__( 'ο ρόλος σου τη χρειάζεται', 'login-guard' ) . '</strong>';
			}
			echo '</td></tr>';
			echo '<tr><th>' . esc_html__( '1. Σάρωση', 'login-guard' ) . '</th><td><div id="lgd-qr" data-uri="' . esc_attr( $uri ) . '" style="background:#fff;display:inline-block;padding:12px;line-height:0"></div>';
			echo '<p class="description">' . esc_html__( 'Σάρωσε τον κωδικό QR με μια εφαρμογή επαλήθευσης (Aegis, 2FAS, Google Authenticator, Microsoft Authenticator, 1Password κ.ά.), ή γράψε στην εφαρμογή αυτό το κλειδί:', 'login-guard' ) . '</p>';
			echo '<p><code style="font-size:15px;letter-spacing:.08em;user-select:all">' . esc_html( trim( chunk_split( $secret, 4, ' ' ) ) ) . '</code></p></td></tr>';
			echo '<tr><th><label for="lgd_tfa_code">' . esc_html__( '2. Επιβεβαίωση', 'login-guard' ) . '</label></th><td><input type="text" name="lgd_tfa_code" id="lgd_tfa_code" class="regular-text" inputmode="numeric" autocomplete="one-time-code" maxlength="7" value="" />';
			echo '<p class="description">' . esc_html__( 'Γράψε τον 6ψήφιο κωδικό που δείχνει τώρα η εφαρμογή και πάτα «Ενημέρωση προφίλ». Μετά θα δεις τους κωδικούς ανάκτησης.', 'login-guard' ) . '</p></td></tr>';
		}
		echo '</tbody></table>';
	}

	public static function other( $user ): void {
		if ( ! $user instanceof WP_User ) {
			return;
		}
		echo '<h2 id="lgd-2fa">' . esc_html__( 'Επαλήθευση σε δύο βήματα', 'login-guard' ) . '</h2>';
		if ( ! LGD_TwoFA::active() ) {
			echo '<p class="description">' . esc_html( self::why_off() ) . '</p>';
			return;
		}
		$on = LGD_TwoFA::is_on( (int) $user->ID );
		echo '<table class="form-table" role="presentation"><tbody><tr><th>' . esc_html__( 'Κατάσταση', 'login-guard' ) . '</th><td>';
		echo $on ? '<strong>' . esc_html__( 'Ενεργή', 'login-guard' ) . '</strong>' : esc_html__( 'Ανενεργή', 'login-guard' );
		if ( ! $on && LGD_TwoFA::required( $user ) ) {
			echo ' · ' . esc_html__( 'ο ρόλος τη χρειάζεται', 'login-guard' );
		}
		echo '</td></tr>';
		if ( $on ) {
			echo '<tr><th>' . esc_html__( 'Μηδενισμός', 'login-guard' ) . '</th><td>';
			if ( self::can_reset( (int) $user->ID ) ) {
				echo '<label><input type="checkbox" name="lgd_tfa_reset" value="1" /> ' . esc_html__( 'Μηδενισμός της επαλήθευσης σε δύο βήματα (ο χρήστης τη ρυθμίζει ξανά· του στέλνουμε email)', 'login-guard' ) . '</label>';
			} else {
				echo '<p class="description">' . esc_html__( 'Για να μηδενίσεις την επαλήθευση άλλου χρήστη, ενεργοποίησε πρώτα τη δική σου.', 'login-guard' ) . '</p>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function why_off(): string {
		if ( defined( 'LGD_DISABLE_2FA' ) && LGD_DISABLE_2FA ) {
			return __( 'Το LGD_DISABLE_2FA είναι ενεργό στο wp-config.php: η επαλήθευση σε δύο βήματα δεν ζητείται.', 'login-guard' );
		}
		if ( ! LGD_TwoFA::possible() ) {
			return __( 'Η επαλήθευση σε δύο βήματα χρειάζεται δικά σου κλειδιά (salts) στο wp-config.php και OpenSSL, για να κρατιούνται τα μυστικά κρυπτογραφημένα.', 'login-guard' );
		}
		return __( 'Η επαλήθευση σε δύο βήματα είναι κλειστή στις ρυθμίσεις του Login Guard.', 'login-guard' );
	}

	/** May the current user reset another user's 2FA? */
	public static function can_reset( int $uid ): bool {
		$me = get_current_user_id();
		return $me > 0 && $me !== $uid && current_user_can( 'edit_user', $uid ) && current_user_can( 'manage_options' ) && LGD_TwoFA::is_on( $me );
	}

	/* =====================================================================
	 * Saving
	 * =================================================================== */

	public static function save_own( $uid ): void {
		$uid  = (int) $uid;
		$user = get_userdata( $uid );
		if ( ! $user instanceof WP_User || get_current_user_id() !== $uid || ! LGD_TwoFA::active() ) {
			return;
		}
		if ( ! LGD_TwoFA::is_on( $uid ) ) {
			$code = self::post( 'lgd_tfa_code' );
			if ( '' === $code ) {
				return;
			}
			$r      = get_user_meta( $uid, self::SETUP, true );
			$secret = is_array( $r ) && isset( $r['s'], $r['x'] ) && (int) $r['x'] > time() ? LGD_Crypt::unseal( (string) $r['s'], $uid ) : null;
			$step   = null !== $secret ? LGD_TOTP::verify( $secret, $code ) : 0;
			if ( $step <= 0 ) {
				self::$error = __( 'Ο κωδικός της εφαρμογής δεν ταιριάζει. Έλεγξε ότι η ώρα του κινητού είναι σωστή και γράψε τον νέο κωδικό.', 'login-guard' );
				return;
			}
			$codes = LGD_TwoFA::enable( $user, (string) $secret, $step );
			delete_user_meta( $uid, self::SETUP );
			if ( array() === $codes ) {
				self::$error = __( 'Η επαλήθευση σε δύο βήματα δεν ενεργοποιήθηκε: η κρυπτογράφηση απέτυχε.', 'login-guard' );
				return;
			}
			self::keep_codes( $uid, $codes );
			self::flash( $uid, __( 'Η επαλήθευση σε δύο βήματα ενεργοποιήθηκε.', 'login-guard' ) );
			return;
		}
		$off = self::post( 'lgd_tfa_off' );
		if ( '' !== $off && ! LGD_TwoFA::required( $user ) ) {
			if ( '' === LGD_TwoFA::check( $uid, $off ) ) {
				self::$error = __( 'Ο κωδικός για την απενεργοποίηση δεν ταιριάζει.', 'login-guard' );
				return;
			}
			LGD_TwoFA::disable( $user, 'self' );
			self::flash( $uid, __( 'Η επαλήθευση σε δύο βήματα απενεργοποιήθηκε.', 'login-guard' ) );
			return;
		}
		if ( '1' === self::post( 'lgd_tfa_codes' ) ) {
			self::keep_codes( $uid, LGD_TwoFA::regenerate( $user ) );
			self::flash( $uid, __( 'Νέοι κωδικοί ανάκτησης. Οι παλιοί δεν δουλεύουν πια.', 'login-guard' ) );
		}
		if ( '1' === self::post( 'lgd_tfa_forget' ) ) {
			LGD_TwoFA::bump( $uid );
			self::flash( $uid, __( 'Ο κωδικός θα ζητηθεί ξανά σε όλους τους browsers.', 'login-guard' ) );
		}
	}

	public static function save_other( $uid ): void {
		$uid  = (int) $uid;
		$user = get_userdata( $uid );
		if ( ! $user instanceof WP_User || '1' !== self::post( 'lgd_tfa_reset' ) || ! LGD_TwoFA::active() || ! LGD_TwoFA::is_on( $uid ) ) {
			return;
		}
		if ( ! self::can_reset( $uid ) ) {
			self::$error = __( 'Για να μηδενίσεις την επαλήθευση άλλου χρήστη, ενεργοποίησε πρώτα τη δική σου.', 'login-guard' );
			return;
		}
		LGD_TwoFA::disable( $user, 'admin' );
	}

	/** user_profile_update_errors: a wrong code stops the save. */
	public static function errors( $errors, $update = false, $user = null ): void {
		if ( '' !== self::$error && $errors instanceof WP_Error ) {
			$errors->add( 'lgd_2fa', esc_html( self::$error ) );
		}
	}
}
