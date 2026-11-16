<?php
/**
 * LGD_Notify — e-mails to the people who run the site.
 *
 *  - New device (notify_device, test and live): when a user with
 *    manage_options logs in from a device not seen in 30 days, an e-mail
 *    goes to that user. A device is a salted hash of the truncated IP
 *    (203.0.113.x), the browser family and the system family; user meta
 *    _lgd_devices keeps hash => last seen (≤10, 30 days). The first login
 *    after activation only records the device. The e-mail has the time,
 *    browser and system, and a link to the profile, never the IP.
 *  - Attack digest (notify_surge, live): when surge_at lockouts happen in
 *    one hour, one e-mail to the site address (Settings → General), at
 *    most one an hour, with counts and a link to the overview.
 *
 * E-mails are plain text in the recipient's language (rs_lang, else the
 * user's locale) and go through wp_mail().
 */

defined( 'ABSPATH' ) || exit;

final class LGD_Notify {

	const DEVICES     = '_lgd_devices';
	const MAX_DEVICES = 10;

	public static function init(): void {
		add_action( 'wp_login', array( __CLASS__, 'login' ), 20, 2 );
	}

	/** Browser and system family from the user agent: array( browser, system ). */
	public static function agent( string $ua ): array {
		$b = 'Other';
		foreach ( array(
			'Edge'    => '/Edg(e|A|iOS)?\//',
			'Opera'   => '/OPR\/|Opera/',
			'Samsung' => '/SamsungBrowser/',
			'Firefox' => '/Firefox\/|FxiOS/',
			'Chrome'  => '/Chrome\/|CriOS/',
			'Safari'  => '/Safari\//',
		) as $name => $re ) {
			if ( preg_match( $re, $ua ) ) {
				$b = $name;
				break;
			}
		}
		$o = 'Other';
		foreach ( array(
			'Android' => '/Android/',
			'iOS'     => '/iPhone|iPad|iPod/',
			'Windows' => '/Windows/',
			'macOS'   => '/Macintosh|Mac OS X/',
			'Linux'   => '/Linux|X11/',
		) as $name => $re ) {
			if ( preg_match( $re, $ua ) ) {
				$o = $name;
				break;
			}
		}
		return array( $b, $o );
	}

	/** wp_login: ( $user_login, $user ). */
	public static function login( $login, $user = null ): void {
		try {
			if ( ! $user instanceof WP_User || ! user_can( $user, 'manage_options' ) || ! LGD_Settings::get()['notify_device'] ) {
				return;
			}
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only hashed and matched.
			$ua   = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ), 0, 512 ) : '';
			$ag   = self::agent( $ua );
			$ip   = LGD_Store::ip();
			$dev  = LGD_Store::hash( 'dev:' . LGD_Store::trunc( $ip ) . '|' . $ag[0] . '|' . $ag[1] );
			$list = get_user_meta( (int) $user->ID, self::DEVICES, true );
			$list = is_array( $list ) ? $list : array();
			$now  = time();
			$list = array_filter(
				$list,
				static function ( $t ) use ( $now ) {
					return (int) $t > $now - 30 * DAY_IN_SECONDS;
				}
			);
			$first = empty( $list );
			$known = isset( $list[ $dev ] );
			$list[ $dev ] = $now;
			arsort( $list );
			update_user_meta( (int) $user->ID, self::DEVICES, array_slice( $list, 0, self::MAX_DEVICES, true ) );
			if ( $known || $first ) {
				return;
			}
			LGD_Store::log( 'new_device', $ip, (int) $user->ID, $ag[0] . ' / ' . $ag[1] );
			self::mail_device( $user, $ag );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	private static function mail_device( WP_User $user, array $ag ): void {
		$prev = LGD_Lang::force( LGD_Lang::lang_of( $user ) );
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: %s: site name */
		$subject = sprintf( __( 'Σύνδεση διαχειριστή από νέα συσκευή στο %s', 'login-guard' ), $site );
		$lines   = array(
			/* translators: 1: user login, 2: site name */
			sprintf( __( 'Ο λογαριασμός %1$s συνδέθηκε στο %2$s από συσκευή που δεν είχε χρησιμοποιήσει τις τελευταίες 30 ημέρες.', 'login-guard' ), $user->user_login, $site ),
			'',
			/* translators: %s: date and time */
			sprintf( __( 'Ώρα: %s', 'login-guard' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ),
			/* translators: 1: browser, 2: operating system */
			sprintf( __( 'Browser και σύστημα: %1$s σε %2$s', 'login-guard' ), $ag[0], $ag[1] ),
			'',
			__( 'Αν ήσουν εσύ, δεν χρειάζεται να κάνεις τίποτα.', 'login-guard' ),
			__( 'Αν δεν ήσουν εσύ, άλλαξε αμέσως τον κωδικό σου και αποσύνδεσε όλες τις άλλες συσκευές από το προφίλ σου:', 'login-guard' ),
			admin_url( 'profile.php' ),
		);
		wp_mail( (string) $user->user_email, $subject, implode( "\n", $lines ) );
		LGD_Lang::force( $prev );
	}

	/** After each lockout (live): the attack digest, at most once an hour. */
	public static function surge(): void {
		try {
			$s = LGD_Settings::get();
			if ( ! $s['notify_surge'] || ! LGD_Settings::live() ) {
				return;
			}
			$st   = LGD_Store::state();
			$hour = (int) floor( time() / HOUR_IN_SECONDS );
			$sg   = isset( $st['surge'] ) && is_array( $st['surge'] ) && isset( $st['surge']['hour'] ) && (int) $st['surge']['hour'] === $hour ? $st['surge'] : array(
				'hour'   => $hour,
				'n'      => 0,
				'mailed' => false,
			);
			$sg['n'] = (int) $sg['n'] + 1;
			$send    = ! $sg['mailed'] && $sg['n'] >= (int) $s['surge_at'];
			if ( $send ) {
				$sg['mailed'] = true;
			}
			$st['surge'] = $sg;
			update_option( LGD_Store::STATE, $st, false );
			if ( $send ) {
				self::mail_surge( (int) $sg['n'] );
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	private static function mail_surge( int $n ): void {
		$to   = (string) get_option( 'admin_email' );
		$who  = get_user_by( 'email', $to );
		$prev = LGD_Lang::force( $who instanceof WP_User ? LGD_Lang::lang_of( $who ) : LGD_Lang::from_locale( (string) get_locale() ) );
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: %s: site name */
		$subject = sprintf( __( 'Επίθεση σε συνδέσεις στο %s', 'login-guard' ), $site );
		$lines   = array(
			/* translators: 1: number of lockouts, 2: site name */
			sprintf( __( 'Το Login Guard κλείδωσε %1$d διευθύνσεις ή λογαριασμούς την τελευταία ώρα στο %2$s. Οι επιθέσεις σταματούν μόνες τους στο όριο· δεν χρειάζεται κάτι από σένα, εκτός αν βλέπεις και κλειδωμένους δικούς σου λογαριασμούς.', 'login-guard' ), $n, $site ),
			'',
			__( 'Επισκόπηση και ημερολόγιο:', 'login-guard' ),
			admin_url( 'admin.php?page=lgd-settings' ),
			'',
			__( 'Αυτό το email στέλνεται το πολύ μία φορά την ώρα. Το κλείνεις στις ρυθμίσεις του Login Guard.', 'login-guard' ),
		);
		if ( wp_mail( $to, $subject, implode( "\n", $lines ) ) ) {
			LGD_Store::log( 'surge', '', 0, (string) $n );
		}
		LGD_Lang::force( $prev );
	}
}
