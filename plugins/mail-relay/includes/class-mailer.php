<?php
/**
 * MRL_Mailer — the sending path.
 *
 * WordPress's wp_mail() is never replaced. With the mode «on»:
 *
 *  1. pre_wp_mail (last priority) takes the call: one log row, the staging
 *     guard (a site that moved to another address holds every mail), then
 *     deliver() tries the primary connection and, if it fails, the backup.
 *  2. Each try is an inner wp_mail() with the same, already filtered
 *     arguments (a last-priority wp_mail filter puts them back, so no
 *     other filter runs twice on them). Inside it, pre_wp_mail lets core
 *     continue, and phpmailer_init sets the connection: SMTP fields, or
 *     the "mrlapi" mailer of MRL_PHPMailer for the APIs.
 *  3. The result of the whole chain is what the caller of wp_mail() gets,
 *     so a mail saved by the backup counts as sent.
 *
 * With the mode «off» nothing is changed, except a test mail or a resend
 * from the admin page, which use the same deliver().
 *
 * Sensitive mails (password reset, new user, email-change confirmation,
 * privacy request confirmation, recovery mode, links with key= / token= …,
 * or the noxpress_mail_sensitive filter) are logged without their content
 * and cannot be resent.
 */

defined( 'ABSPATH' ) || exit;

final class MRL_Mailer {

	/** Nesting of our inner wp_mail() calls. */
	private static $depth = 0;

	/** Connection of the current inner call: primary | backup | php. */
	private static $slot = '';

	/** Filtered wp_mail() arguments of the current delivery. */
	private static $atts = null;

	/** Error of the current inner call (scrubbed). */
	private static $error = '';

	/** Reason to refuse inside phpmailer_init (scrubbed). */
	private static $abort = '';

	/** A sensitive mail is on its way (set by core filters). */
	private static $sensitive_next = false;

	/** WooCommerce email id of the next mail. */
	private static $wc_id = '';

	/** Log row of a resend, for resent_of. */
	private static $resent_of = 0;

	/** Source label forced by the plugin itself (test, resend, summary). */
	private static $own_source = '';

	public static function init(): void {
		add_filter( 'pre_wp_mail', array( __CLASS__, 'pre_wp_mail' ), PHP_INT_MAX - 100, 2 );
		add_action( 'phpmailer_init', array( __CLASS__, 'configure' ), PHP_INT_MAX - 100 );
		add_action( 'wp_mail_failed', array( __CLASS__, 'failed' ), 1 );
		add_filter( 'wp_mail_from', array( __CLASS__, 'default_from' ), PHP_INT_MAX - 100 );
		add_filter( 'wp_mail_from_name', array( __CLASS__, 'default_from_name' ), PHP_INT_MAX - 100 );

		// Core mails that carry a login, reset or confirmation link.
		foreach ( array( 'retrieve_password_notification_email', 'wp_new_user_notification_email', 'new_user_email_content', 'user_request_action_email_content', 'recovery_mode_email', 'retrieve_password_message', 'new_admin_email_content', 'site_admin_email_change_email' ) as $f ) {
			add_filter( $f, array( __CLASS__, 'mark_sensitive' ), PHP_INT_MAX );
		}
		add_filter( 'woocommerce_mail_callback_params', array( __CLASS__, 'wc_params' ), PHP_INT_MAX, 2 );
	}

	/**
	 * WordPress's default sender wordpress@<host> is replaced by ours while
	 * we send: setFrom() would refuse it on hosts without a dot, and the
	 * providers refuse it anyway. Any other From is handled in configure().
	 */
	public static function default_from( $from ) {
		if ( self::$depth > 0 && 0 === strpos( strtolower( (string) $from ), 'wordpress@' ) ) {
			$ours = MRL_Settings::from_email();
			if ( '' !== $ours ) {
				return $ours;
			}
		}
		return $from;
	}

	public static function default_from_name( $name ) {
		if ( self::$depth > 0 && 'WordPress' === $name && '' !== MRL_Settings::from_email() ) {
			return MRL_Settings::from_name();
		}
		return $name;
	}

	public static function mark_sensitive( $value ) {
		self::$sensitive_next = true;
		return $value;
	}

	public static function wc_params( $params, $email = null ) {
		if ( is_object( $email ) && isset( $email->id ) && is_string( $email->id ) ) {
			self::$wc_id = sanitize_key( $email->id );
		}
		return $params;
	}

	/* ---------------------------------------------------------------------
	 * Coexistence
	 * ------------------------------------------------------------------- */

	/**
	 * Another plugin's wp_mail() (FluentSMTP, Post SMTP…), or '' when it is
	 * WordPress's own. Then pre_wp_mail never runs and we cannot send.
	 */
	public static function foreign_wp_mail(): string {
		if ( ! function_exists( 'wp_mail' ) ) {
			return '';
		}
		try {
			$f = ( new ReflectionFunction( 'wp_mail' ) )->getFileName();
		} catch ( \Throwable $e ) {
			return '';
		}
		$f = wp_normalize_path( (string) $f );
		return ( '' === $f || wp_normalize_path( ABSPATH . 'wp-includes/pluggable.php' ) === $f ) ? '' : $f;
	}

	/** Active plugins that send mail themselves: name list. */
	public static function other_smtp_plugins(): array {
		$known = array(
			'fluent-smtp/fluent-smtp.php'           => 'FluentSMTP',
			'wp-mail-smtp/wp_mail_smtp.php'         => 'WP Mail SMTP',
			'wp-mail-smtp-pro/wp_mail_smtp.php'     => 'WP Mail SMTP Pro',
			'post-smtp/postman-smtp.php'            => 'Post SMTP',
			'easy-wp-smtp/easy-wp-smtp.php'         => 'Easy WP SMTP',
			'smtp-mailer/main.php'                  => 'SMTP Mailer',
			'gosmtp/gosmtp.php'                     => 'GoSMTP',
			'suremails/suremails.php'               => 'SureMail',
			'wp-ses/wp-ses.php'                     => 'WP Offload SES',
			'mailgun/mailgun.php'                   => 'Mailgun for WordPress',
			'sendgrid-email-delivery-simplified/wpsendgrid.php' => 'SendGrid',
		);
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$out = array();
		foreach ( $known as $file => $name ) {
			if ( in_array( $file, $active, true ) ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * pre_wp_mail
	 * ------------------------------------------------------------------- */

	/**
	 * @param null|bool $return Short-circuit value of earlier filters.
	 * @param array     $atts   Filtered wp_mail() arguments.
	 */
	public static function pre_wp_mail( $return, $atts ) {
		if ( null !== $return || self::$depth > 0 || ! is_array( $atts ) ) {
			return $return;
		}
		if ( 'on' !== MRL_Settings::get()['mode'] ) {
			self::reset_next();
			return null;
		}
		try {
			$id = self::log_start( $atts );
			if ( MRL_Settings::moved() ) {
				MRL_Log::finish( $id, 'held', '', 'The site address changed: sending is paused until it is confirmed in Mail Relay.' );
				MRL_Alerts::held();
				return false;
			}
			$slots = array( 'primary' );
			if ( MRL_Settings::get()['backup_on'] ) {
				$slots[] = 'backup';
			}
			return self::deliver( $atts, $id, $slots );
		} catch ( \Throwable $e ) {
			// Never break the caller: let WordPress send as it would without us.
			return null;
		}
	}

	private static function reset_next(): void {
		self::$sensitive_next = false;
		self::$wc_id          = '';
	}

	/** Recipients of the To argument plus Cc / Bcc headers. */
	private static function recipients( array $atts ): array {
		$to   = isset( $atts['to'] ) ? $atts['to'] : array();
		$list = is_array( $to ) ? $to : explode( ',', (string) $to );
		foreach ( self::header_lines( isset( $atts['headers'] ) ? $atts['headers'] : '' ) as $line ) {
			if ( preg_match( '/^\s*(cc|bcc)\s*:(.*)$/i', $line, $m ) ) {
				$list = array_merge( $list, explode( ',', $m[2] ) );
			}
		}
		return MRL_Log::addresses( $list );
	}

	private static function header_lines( $headers ): array {
		if ( is_array( $headers ) ) {
			$out = array();
			foreach ( $headers as $k => $v ) {
				$out[] = is_string( $k ) ? $k . ': ' . ( is_scalar( $v ) ? (string) $v : '' ) : ( is_scalar( $v ) ? (string) $v : '' );
			}
			return $out;
		}
		return preg_split( "/\r\n|\n|\r/", (string) $headers );
	}

	/** True when the mail must not be kept or resent. */
	public static function is_sensitive( array $atts ): bool {
		$sensitive = self::$sensitive_next;
		if ( ! $sensitive ) {
			$text = ( isset( $atts['message'] ) && is_string( $atts['message'] ) ? $atts['message'] : '' ) . ' ' . ( isset( $atts['subject'] ) && is_string( $atts['subject'] ) ? $atts['subject'] : '' );
			// Links with a key, token, nonce, code or password in them, and WordPress's reset / login actions.
			if ( preg_match( '/[?&;](?:amp;)?(?:key|token|[a-z_]*_token|nonce|code|otp|hash|sig|signature|confirm[a-z_]*|activation[a-z_]*|reset[a-z_]*|login[a-z_]*|pass[a-z_]*|pwd|auth[a-z_]*|mgl[a-z_]*)=/i', $text ) || preg_match( '/action=(?:rp|resetpass|confirmaction|login)/i', $text ) ) {
				$sensitive = true;
			}
		}
		return (bool) apply_filters( 'noxpress_mail_sensitive', $sensitive, $atts );
	}

	/** Where the call came from: woocommerce:<id>, plugin slug, theme:<slug>, mu:<file>, wordpress. */
	private static function source(): string {
		if ( '' !== self::$own_source ) {
			return self::$own_source;
		}
		if ( '' !== self::$wc_id ) {
			return 'woocommerce:' . self::$wc_id;
		}
		$file = '';
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- reads only file paths, no arguments.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 40 ) as $f ) {
			if ( isset( $f['function'] ) && 'wp_mail' === $f['function'] && ! isset( $f['class'] ) && isset( $f['file'] ) ) {
				$file = wp_normalize_path( (string) $f['file'] );
			}
		}
		$src = 'wordpress';
		if ( '' !== $file ) {
			$plugins = wp_normalize_path( WP_PLUGIN_DIR ) . '/';
			$mu      = wp_normalize_path( WPMU_PLUGIN_DIR ) . '/';
			$themes  = wp_normalize_path( get_theme_root() ) . '/';
			if ( 0 === strpos( $file, $plugins ) ) {
				$src = strtok( substr( $file, strlen( $plugins ) ), '/' );
			} elseif ( 0 === strpos( $file, $mu ) ) {
				$src = 'mu:' . strtok( substr( $file, strlen( $mu ) ), '/' );
			} elseif ( 0 === strpos( $file, $themes ) ) {
				$src = 'theme:' . strtok( substr( $file, strlen( $themes ) ), '/' );
			}
		}
		return (string) apply_filters( 'mrl_mail_source', sanitize_text_field( (string) $src ), $file );
	}

	private static function attach_info( $attachments ): array {
		$list = is_array( $attachments ) ? $attachments : preg_split( "/\r\n|\n|\r/", (string) $attachments );
		$out  = array();
		foreach ( $list as $k => $path ) {
			if ( ! is_string( $path ) || '' === trim( $path ) ) {
				continue;
			}
			$name  = is_string( $k ) ? $k : wp_basename( $path );
			$out[] = array( sanitize_file_name( $name ), is_readable( $path ) ? (int) filesize( $path ) : 0 );
		}
		return $out;
	}

	private static function log_start( array $atts ): int {
		$sensitive = self::is_sensitive( $atts );
		$s         = MRL_Settings::get();
		$body      = null;
		if ( $s['bodies'] && ! $sensitive ) {
			$body = array(
				'to'      => isset( $atts['to'] ) ? $atts['to'] : '',
				'subject' => isset( $atts['subject'] ) ? (string) $atts['subject'] : '',
				'message' => isset( $atts['message'] ) && is_string( $atts['message'] ) ? $atts['message'] : '',
				'headers' => isset( $atts['headers'] ) ? $atts['headers'] : '',
			);
		}
		$id = MRL_Log::add(
			array(
				'to'        => self::recipients( $atts ),
				'subject'   => isset( $atts['subject'] ) && is_scalar( $atts['subject'] ) ? (string) $atts['subject'] : '',
				'source'    => self::source(),
				'attach'    => self::attach_info( isset( $atts['attachments'] ) ? $atts['attachments'] : array() ),
				'sensitive' => $sensitive,
				'body'      => $body,
				'resent_of' => self::$resent_of,
			)
		);
		self::reset_next();
		return $id;
	}

	/**
	 * Tries the slots in order; true on the first that sends. The log row
	 * gets the result and every error (a mail saved by the backup keeps the
	 * error of the primary).
	 */
	public static function deliver( array $atts, int $id, array $slots ): bool {
		$errors = array();
		$last   = '';
		foreach ( $slots as $slot ) {
			$last = $slot;
			if ( 'php' !== $slot ) {
				$p = MRL_Settings::problems( $slot, false );
				if ( $p ) {
					$errors[] = $slot . ': ' . implode( ' ', $p );
					continue;
				}
			}
			if ( self::inner( $atts, $slot ) ) {
				MRL_Log::finish( $id, 'sent', $slot, implode( ' | ', $errors ) );
				if ( $errors ) {
					MRL_Alerts::failed( implode( ' | ', $errors ), true );
				}
				return true;
			}
			$errors[] = $slot . ': ' . ( '' !== self::$error ? self::$error : 'unknown error' );
		}
		$err = implode( ' | ', $errors );
		MRL_Log::finish( $id, 'failed', $last, $err );
		MRL_Alerts::failed( $err, false );
		return false;
	}

	/** One try through WordPress's own wp_mail(), with the connection of $slot. */
	private static function inner( array $atts, string $slot ): bool {
		self::ensure_phpmailer();
		++self::$depth;
		$prev_slot   = self::$slot;
		$prev_atts   = self::$atts;
		self::$slot  = $slot;
		self::$atts  = $atts;
		self::$error = '';
		self::$abort = '';
		add_filter( 'wp_mail', array( __CLASS__, 'restore_atts' ), PHP_INT_MAX );
		try {
			$ok = wp_mail(
				isset( $atts['to'] ) ? $atts['to'] : '',
				isset( $atts['subject'] ) ? $atts['subject'] : '',
				isset( $atts['message'] ) ? $atts['message'] : '',
				isset( $atts['headers'] ) ? $atts['headers'] : '',
				isset( $atts['attachments'] ) ? $atts['attachments'] : array(),
				isset( $atts['embeds'] ) ? $atts['embeds'] : array()
			);
		} catch ( \Throwable $e ) {
			$ok          = false;
			self::$error = MRL_Crypt::scrub( $e->getMessage() );
		} finally {
			remove_filter( 'wp_mail', array( __CLASS__, 'restore_atts' ), PHP_INT_MAX );
			--self::$depth;
			self::$slot = $prev_slot;
			self::$atts = $prev_atts;
		}
		return (bool) $ok;
	}

	public static function restore_atts( $atts ) {
		return ( self::$depth > 0 && is_array( self::$atts ) ) ? self::$atts : $atts;
	}

	/** Our PHPMailer (with the "mrlapi" mailer) as WordPress's global one. */
	private static function ensure_phpmailer(): void {
		global $phpmailer;
		if ( class_exists( 'MRL_PHPMailer', false ) && $phpmailer instanceof MRL_PHPMailer ) {
			return;
		}
		if ( ! class_exists( 'PHPMailer\\PHPMailer\\PHPMailer' ) ) {
			require_once ABSPATH . 'wp-includes/PHPMailer/PHPMailer.php';
			require_once ABSPATH . 'wp-includes/PHPMailer/SMTP.php';
			require_once ABSPATH . 'wp-includes/PHPMailer/Exception.php';
		}
		if ( ! class_exists( 'WP_PHPMailer' ) && file_exists( ABSPATH . 'wp-includes/class-wp-phpmailer.php' ) ) {
			require_once ABSPATH . 'wp-includes/class-wp-phpmailer.php';
		}
		require_once MRL_PATH . 'includes/class-phpmailer.php';
		$phpmailer = new MRL_PHPMailer( true ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WordPress reuses an existing PHPMailer instance by design.
	}

	public static function failed( $error ): void {
		if ( self::$depth > 0 && is_wp_error( $error ) ) {
			self::$error = MRL_Crypt::scrub( $error->get_error_message() );
		}
	}

	/* ---------------------------------------------------------------------
	 * phpmailer_init
	 * ------------------------------------------------------------------- */

	/** @param PHPMailer\PHPMailer\PHPMailer $m */
	public static function configure( $m ): void {
		if ( self::$depth <= 0 || '' === self::$slot || ! is_object( $m ) ) {
			return;
		}
		$s = MRL_Settings::get();

		// From: forced (Proton Mail and the APIs refuse other senders), the original becomes Reply-To.
		$from = MRL_Settings::from_email();
		if ( '' !== $from ) {
			$orig      = (string) $m->From;
			$orig_name = (string) $m->FromName;
			$default   = 0 === strpos( strtolower( $orig ), 'wordpress@' );
			$same      = strtolower( $orig ) === strtolower( $from );
			if ( $s['force_from'] || $default ) {
				// Same address: keep the caller's name; another or the default: our name.
				$name = ( $same && '' !== $orig_name && 'WordPress' !== $orig_name ) ? $orig_name : MRL_Settings::from_name();
				$m->setFrom( $from, $name, false );
				if ( ! $default && ! $same && is_email( $orig ) && ! $m->getReplyToAddresses() ) {
					$m->addReplyTo( $orig, $orig_name );
				}
			}
			$m->Sender = $m->From;
		}

		// Never any debug output: it would contain the AUTH line.
		$m->SMTPDebug   = 0;
		$m->Debugoutput = static function () {};

		if ( 'php' === self::$slot ) {
			return; // PHP mail() of the server (summary email when nothing else works).
		}

		$c      = MRL_Settings::conn( self::$slot );
		$secret = MRL_Crypt::get( self::$slot, MRL_Settings::bind( $c ) );

		if ( 'smtp' !== $c['type'] ) {
			self::use_api( $m );
			return;
		}

		$problem = MRL_Settings::host_problem( $c['host'], '' !== $secret );
		if ( '' === $problem && '' !== $secret && 'none' === $c['enc'] ) {
			$problem = 'A password is never sent without encryption.';
		}
		if ( '' !== $problem ) {
			self::$abort = $problem;
			self::use_api( $m );
			return;
		}

		$m->isSMTP();
		$m->Host          = $c['host'];
		$m->Port          = (int) $c['port'];
		$m->SMTPSecure    = 'ssl' === $c['enc'] ? 'ssl' : ( 'tls' === $c['enc'] ? 'tls' : '' );
		$m->SMTPAutoTLS   = false;
		$m->SMTPAuth      = '' !== $c['user'];
		$m->Username      = $c['user'];
		$m->Password      = $m->SMTPAuth ? $secret : '';
		$m->Timeout       = 15;
		$m->SMTPKeepAlive = false;
		$m->SMTPOptions   = array(
			'ssl' => array(
				'verify_peer'       => true,
				'verify_peer_name'  => true,
				'allow_self_signed' => false,
			),
		);
	}

	/** Mailer «mrlapi»; when the instance is not ours, refuse by clearing recipients. */
	private static function use_api( $m ): void {
		if ( class_exists( 'MRL_PHPMailer', false ) && $m instanceof MRL_PHPMailer ) {
			$m->Mailer = 'mrlapi';
			return;
		}
		if ( '' === self::$abort ) {
			self::$abort = 'Another plugin replaced PHPMailer; the API connection cannot be used.';
		}
		self::$error = self::$abort;
		$m->clearAllRecipients();
	}

	/** Called by MRL_PHPMailer::mrlapiSend(): '' on success, else the error. */
	public static function api_send( $m, string $raw ): string {
		if ( '' !== self::$abort ) {
			return self::$abort;
		}
		$c      = MRL_Settings::conn( self::$slot );
		$secret = MRL_Crypt::get( self::$slot, MRL_Settings::bind( $c ) );
		$pairs  = static function ( $list ) {
			$o = array();
			foreach ( (array) $list as $a ) {
				if ( is_array( $a ) && isset( $a[0] ) ) {
					$o[] = array( (string) $a[0], isset( $a[1] ) ? (string) $a[1] : '' );
				}
			}
			return $o;
		};
		$att = array();
		foreach ( (array) $m->getAttachments() as $a ) {
			$bytes = '';
			if ( ! empty( $a[5] ) ) {
				$bytes = (string) $a[0];
			} elseif ( is_string( $a[0] ) && is_readable( $a[0] ) ) {
				$bytes = (string) file_get_contents( $a[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local attachment path from wp_mail().
			}
			$att[] = array( (string) $a[2], $bytes, (string) $a[4], (string) $a[6], (string) $a[7] );
		}
		$msg = array(
			'from'         => (string) $m->From,
			'from_name'    => (string) $m->FromName,
			'to'           => $pairs( $m->getToAddresses() ),
			'cc'           => $pairs( $m->getCcAddresses() ),
			'bcc'          => $pairs( $m->getBccAddresses() ),
			'reply_to'     => $pairs( array_values( $m->getReplyToAddresses() ) ),
			'subject'      => (string) $m->Subject,
			'content_type' => (string) $m->ContentType,
			'body'         => (string) $m->Body,
			'alt'          => (string) $m->AltBody,
			'headers'      => (array) $m->getCustomHeaders(),
			'attachments'  => $att,
			'raw'          => $raw,
		);
		return MRL_Crypt::scrub( MRL_Api::send( $c, $secret, $msg ) );
	}

	/* ---------------------------------------------------------------------
	 * Test, resend, own mails
	 * ------------------------------------------------------------------- */

	/**
	 * Test mail through one connection, whatever the mode.
	 *
	 * @return array{0: bool, 1: string} ok, error.
	 */
	public static function test( string $slot, string $to ): array {
		$to = sanitize_email( $to );
		if ( ! is_email( $to ) ) {
			return array( false, __( 'Μη έγκυρη διεύθυνση.', 'mail-relay' ) );
		}
		$p = MRL_Settings::problems( $slot, true );
		if ( $p ) {
			return array( false, implode( ' ', $p ) );
		}
		$labels = MRL_Api::labels();
		$c      = MRL_Settings::conn( $slot );
		$atts   = array(
			'to'          => $to,
			/* translators: 1: site name, 2: provider */
			'subject'     => sprintf( __( 'Δοκιμή Mail Relay: %1$s (%2$s)', 'mail-relay' ), wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ), isset( $labels[ $c['type'] ] ) ? $labels[ $c['type'] ] : $c['type'] ),
			'message'     => __( 'Αυτό είναι ένα δοκιμαστικό email από το Mail Relay. Αν το διαβάζεις, η σύνδεση δουλεύει.', 'mail-relay' ) . "\n\n" . home_url( '/' ) . "\n" . wp_date( 'Y-m-d H:i:s' ),
			'headers'     => array(),
			'attachments' => array(),
		);
		self::$own_source = 'mail-relay:test';
		try {
			$id = self::log_start( $atts );
		} finally {
			self::$own_source = '';
		}
		$ok = self::deliver_quiet( $atts, $id, array( $slot ) );
		if ( $ok ) {
			MRL_Settings::mark_tested( $slot );
			return array( true, '' );
		}
		$row = MRL_Log::get( $id );
		return array( false, $row ? $row['error'] : self::$error );
	}

	/** deliver() without failure alerts (test mails, summaries). */
	private static function deliver_quiet( array $atts, int $id, array $slots ): bool {
		MRL_Alerts::$quiet = true;
		try {
			return self::deliver( $atts, $id, $slots );
		} finally {
			MRL_Alerts::$quiet = false;
		}
	}

	/** Sends a logged mail again (only with a kept, non-sensitive body). */
	public static function resend( int $id ): array {
		$b = MRL_Log::body( $id );
		if ( null === $b ) {
			return array( false, __( 'Το περιεχόμενο αυτού του email δεν κρατήθηκε, άρα δεν ξαναστέλνεται.', 'mail-relay' ) );
		}
		$atts = array(
			'to'          => isset( $b['to'] ) ? $b['to'] : '',
			'subject'     => isset( $b['subject'] ) ? (string) $b['subject'] : '',
			'message'     => isset( $b['message'] ) ? (string) $b['message'] : '',
			'headers'     => isset( $b['headers'] ) ? $b['headers'] : '',
			'attachments' => array(),
		);
		if ( MRL_Settings::moved() ) {
			return array( false, __( 'Η διεύθυνση του site άλλαξε: η αποστολή είναι σε παύση μέχρι να την επιβεβαιώσεις.', 'mail-relay' ) );
		}
		self::$own_source = 'mail-relay:resend';
		self::$resent_of  = $id;
		try {
			$new = self::log_start( $atts );
		} finally {
			self::$own_source = '';
			self::$resent_of  = 0;
		}
		$s     = MRL_Settings::get();
		$slots = array( 'primary' );
		if ( $s['backup_on'] ) {
			$slots[] = 'backup';
		}
		$ok  = self::deliver( $atts, $new, $slots );
		$row = MRL_Log::get( $new );
		return array( $ok, $ok ? '' : ( $row ? $row['error'] : '' ) );
	}

	/** The failure summary: primary, backup, then the server's mail(). */
	public static function send_summary( string $to, string $subject, string $message ): bool {
		$atts = array(
			'to'          => $to,
			'subject'     => $subject,
			'message'     => $message,
			'headers'     => array(),
			'attachments' => array(),
		);
		self::$own_source = 'mail-relay:alert';
		try {
			$id = self::log_start( $atts );
		} finally {
			self::$own_source = '';
		}
		$slots = array( 'primary' );
		if ( MRL_Settings::get()['backup_on'] ) {
			$slots[] = 'backup';
		}
		$slots[] = 'php';
		return self::deliver_quiet( $atts, $id, $slots );
	}
}
