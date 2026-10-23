<?php
/**
 * LOP_Push — push notifications to the operator's phone.
 *
 *  - Keys: one VAPID key pair per site, option lop_vapid (autoload off),
 *    the private key sealed (LOP_Crypt). Made on first use. If it can no
 *    longer be read (salts changed), a new pair is made and the old
 *    subscriptions are dropped: they were bound to the old key.
 *  - Subscriptions: user meta lop_push, endpoint hash => { endpoint,
 *    p256dh, auth, created }, at most 10 per user, only for users with
 *    manage_options. Endpoints only https and only on known push services
 *    (filter lop_push_hosts), sent with wp_safe_remote_post, no redirects.
 *    A 404 / 410 from the service removes the subscription.
 *  - What is sent: a new Live Chat request (listeo_ai_live_handoff_requested,
 *    PurioChat's own alert limit respected) and every visitor message in a
 *    waiting or assigned conversation (listeo_ai_live_handoff_visitor_message;
 *    an assigned one goes to its operator only). With the «preview» setting
 *    the text of the message goes in the notification (encrypted end to end
 *    by Web Push; the push service sees only ciphertext).
 *  - When: queued during the request and sent on shutdown; in a REST
 *    request after fastcgi_finish_request(), so the visitor's message is
 *    not slowed down by the push service.
 */

defined( 'ABSPATH' ) || exit;

final class LOP_Push {

	const META     = 'lop_push';
	const KEYS     = 'lop_vapid';
	const MAX_SUBS = 10;

	/** Queued messages: list of [ user ids, payload ]. */
	private static $queue = array();

	public static function init(): void {
		add_action( 'listeo_ai_live_handoff_requested', array( __CLASS__, 'on_request' ), 30, 3 );
		add_action( 'listeo_ai_live_handoff_visitor_message', array( __CLASS__, 'on_message' ), 30, 2 );
	}

	public static function available(): bool {
		return LOP_Web_Push::available() && LOP_Crypt::available();
	}

	/* =====================================================================
	 * Keys
	 * =================================================================== */

	/** @return array{pub:string,pem:string}|null */
	public static function keys(): ?array {
		if ( ! self::available() ) {
			return null;
		}
		$opt = get_option( self::KEYS, array() );
		if ( is_array( $opt ) && ! empty( $opt['pub'] ) && ! empty( $opt['priv'] ) ) {
			$pub = LOP_Web_Push::unb64u( (string) $opt['pub'] );
			$pem = LOP_Crypt::unseal( (string) $opt['priv'] );
			if ( null !== $pub && 65 === strlen( $pub ) && is_string( $pem ) && '' !== $pem ) {
				return array(
					'pub' => $pub,
					'pem' => $pem,
				);
			}
		}
		$new = LOP_Web_Push::new_keys();
		if ( null === $new ) {
			return null;
		}
		$sealed = LOP_Crypt::seal( $new['pem'] );
		if ( '' === $sealed ) {
			return null;
		}
		// The old subscriptions belong to the old key: they would only fail.
		delete_metadata( 'user', 0, self::META, '', true );
		update_option(
			self::KEYS,
			array(
				'pub'     => LOP_Web_Push::b64u( $new['pub'] ),
				'priv'    => $sealed,
				'created' => time(),
			),
			false
		);
		return $new;
	}

	/** The public key for the browser (base64url), '' when push is unavailable. */
	public static function public_key(): string {
		$k = self::keys();
		return $k ? LOP_Web_Push::b64u( $k['pub'] ) : '';
	}

	/* =====================================================================
	 * Subscriptions
	 * =================================================================== */

	public static function subscriptions( int $user_id ): array {
		$subs = get_user_meta( $user_id, self::META, true );
		return is_array( $subs ) ? $subs : array();
	}

	/** Push services we send to (host or a parent domain). */
	private static function hosts(): array {
		$hosts = apply_filters(
			'lop_push_hosts',
			array( 'fcm.googleapis.com', 'android.googleapis.com', 'push.services.mozilla.com', 'notify.windows.com', 'push.apple.com' )
		);
		return array_values( array_filter( array_map( 'strtolower', (array) $hosts ), 'is_string' ) );
	}

	/** True for an https endpoint on a known push service. */
	public static function valid_endpoint( string $endpoint ): bool {
		if ( strlen( $endpoint ) > 1000 || ! wp_http_validate_url( $endpoint ) ) {
			return false;
		}
		$p = wp_parse_url( $endpoint );
		if ( ! is_array( $p ) || 'https' !== strtolower( (string) ( $p['scheme'] ?? '' ) ) || empty( $p['host'] ) || isset( $p['user'] ) || isset( $p['pass'] ) ) {
			return false;
		}
		$host = strtolower( (string) $p['host'] );
		foreach ( self::hosts() as $allowed ) {
			if ( $host === $allowed || substr( $host, -strlen( '.' . $allowed ) ) === '.' . $allowed ) {
				return true;
			}
		}
		return false;
	}

	/** @return true|WP_Error */
	public static function subscribe( int $user_id, string $endpoint, string $p256dh, string $auth ) {
		if ( ! self::available() || null === self::keys() ) {
			return new WP_Error( 'lop_push_unavailable', 'Push notifications are not available on this server (OpenSSL with EC keys is needed).', array( 'status' => 503 ) );
		}
		$pub  = LOP_Web_Push::unb64u( $p256dh );
		$sec  = LOP_Web_Push::unb64u( $auth );
		if ( ! self::valid_endpoint( $endpoint ) || null === $pub || 65 !== strlen( $pub ) || "\x04" !== $pub[0] || null === $sec || 16 !== strlen( $sec ) ) {
			return new WP_Error( 'lop_push_invalid', 'Invalid push subscription.', array( 'status' => 400 ) );
		}
		$subs                              = self::subscriptions( $user_id );
		$subs[ hash( 'sha256', $endpoint ) ] = array(
			'endpoint' => $endpoint,
			'p256dh'   => LOP_Web_Push::b64u( $pub ),
			'auth'     => LOP_Web_Push::b64u( $sec ),
			'created'  => time(),
		);
		uasort(
			$subs,
			static function ( $a, $b ) {
				return (int) $b['created'] - (int) $a['created'];
			}
		);
		update_user_meta( $user_id, self::META, array_slice( $subs, 0, self::MAX_SUBS, true ) );
		return true;
	}

	public static function unsubscribe( int $user_id, string $endpoint ): void {
		$subs = self::subscriptions( $user_id );
		unset( $subs[ hash( 'sha256', $endpoint ) ] );
		if ( $subs ) {
			update_user_meta( $user_id, self::META, $subs );
		} else {
			delete_user_meta( $user_id, self::META );
		}
	}

	/** Users with at least one subscription who can still operate. */
	public static function operator_ids(): array {
		$ids = get_users(
			array(
				'meta_key' => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery -- few users have it.
				'fields'   => 'ID',
				'number'   => 50,
			)
		);
		$out = array();
		foreach ( (array) $ids as $id ) {
			if ( user_can( (int) $id, 'manage_options' ) ) {
				$out[] = (int) $id;
			}
		}
		return $out;
	}

	/* =====================================================================
	 * Events from PurioChat
	 * =================================================================== */

	/**
	 * listeo_ai_live_handoff_requested.
	 *
	 * @param mixed $conversation_id Conversation id.
	 * @param mixed $reason          Escalation reason.
	 * @param mixed $alerts_allowed  PurioChat's alert limit (20 per 5 minutes).
	 */
	public static function on_request( $conversation_id, $reason = '', $alerts_allowed = true ): void {
		try {
			if ( ! $alerts_allowed || ! is_string( $conversation_id ) || '' === $conversation_id ) {
				return;
			}
			$snap = LOP_Purio::conversation( $conversation_id, 10 );
			if ( null === $snap || 'waiting' !== ( $snap['thread']['state'] ?? '' ) ) {
				return;
			}
			$text = LOP_Purio::last_visitor_text( $snap );
			if ( '' === $text && is_string( $reason ) ) {
				$text = $reason;
			}
			$name = self::visitor_name( $snap['thread'] );
			self::to_operators(
				array(
					'title' => __( 'Νέο αίτημα για συνομιλία', 'live-operator' ),
					'body'  => LOP_Settings::get()['preview'] && '' !== $text ? $name . ': ' . self::excerpt( $text ) : $name,
					'tag'   => self::tag( $conversation_id ),
					'url'   => LOP_App::url( $conversation_id ),
				)
			);
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * listeo_ai_live_handoff_visitor_message.
	 *
	 * @param mixed $conversation_id Conversation id.
	 * @param mixed $message         The saved message (content, …).
	 */
	public static function on_message( $conversation_id, $message = array() ): void {
		try {
			if ( ! is_string( $conversation_id ) || '' === $conversation_id ) {
				return;
			}
			$snap = LOP_Purio::conversation( $conversation_id, 1 );
			if ( null === $snap ) {
				return;
			}
			$thread = $snap['thread'];
			$state  = (string) ( $thread['state'] ?? '' );
			if ( 'waiting' !== $state && 'human' !== $state ) {
				return;
			}
			$text    = is_array( $message ) && isset( $message['content'] ) && is_scalar( $message['content'] ) ? (string) $message['content'] : '';
			$name    = self::visitor_name( $thread );
			$payload = array(
				/* translators: %s: visitor name */
				'title' => sprintf( __( 'Μήνυμα από %s', 'live-operator' ), $name ),
				'body'  => LOP_Settings::get()['preview'] && '' !== $text ? self::excerpt( $text ) : __( 'Νέο μήνυμα στη συνομιλία.', 'live-operator' ),
				'tag'   => self::tag( $conversation_id ),
				'url'   => LOP_App::url( $conversation_id ),
			);
			$assigned = (int) ( $thread['assigned_user_id'] ?? 0 );
			if ( 'human' === $state && $assigned > 0 ) {
				if ( in_array( $assigned, self::operator_ids(), true ) ) {
					self::queue( array( $assigned ), $payload );
				}
				return;
			}
			self::to_operators( $payload );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	private static function visitor_name( array $thread ): string {
		$name = isset( $thread['visitor_name'] ) && is_scalar( $thread['visitor_name'] ) ? trim( wp_strip_all_tags( (string) $thread['visitor_name'] ) ) : '';
		return '' !== $name ? self::excerpt( $name, 60 ) : __( 'Επισκέπτης', 'live-operator' );
	}

	/** One notification per conversation: a newer one replaces the older. */
	private static function tag( string $conversation_id ): string {
		return 'lop-' . substr( hash( 'sha256', $conversation_id ), 0, 20 );
	}

	private static function excerpt( string $text, int $max = 160 ): string {
		// PurioChat keeps a lone "<" as "&lt;" (sanitize_textarea_field); the push shows plain text.
		$text = trim( preg_replace( '/\s+/u', ' ', wp_specialchars_decode( wp_strip_all_tags( $text ), ENT_QUOTES ) ) );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > $max ) {
			return rtrim( mb_substr( $text, 0, $max - 1 ) ) . '…';
		}
		return $text;
	}

	/* =====================================================================
	 * Sending
	 * =================================================================== */

	public static function to_operators( array $payload ): void {
		$ids = self::operator_ids();
		if ( $ids ) {
			self::queue( $ids, $payload );
		}
	}

	private static function queue( array $user_ids, array $payload ): void {
		if ( ! self::$queue ) {
			add_action( 'shutdown', array( __CLASS__, 'flush' ), 100 );
		}
		self::$queue[] = array( $user_ids, $payload );
	}

	/** shutdown: after the response, send what was queued. */
	public static function flush(): void {
		$queue       = self::$queue;
		self::$queue = array();
		if ( ! $queue ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST && function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		foreach ( $queue as $item ) {
			foreach ( $item[0] as $uid ) {
				try {
					self::send( (int) $uid, $item[1] );
				} catch ( \Throwable $e ) {
					continue;
				}
			}
		}
	}

	/**
	 * Send one message to every device of a user, now.
	 *
	 * @return array{sent:int,failed:int}
	 */
	public static function send( int $user_id, array $payload ): array {
		$out  = array(
			'sent'   => 0,
			'failed' => 0,
		);
		$keys = self::keys();
		$subs = self::subscriptions( $user_id );
		if ( ! $keys || ! $subs ) {
			return $out;
		}
		$json = (string) wp_json_encode(
			array(
				'title' => (string) ( $payload['title'] ?? '' ),
				'body'  => (string) ( $payload['body'] ?? '' ),
				'tag'   => (string) ( $payload['tag'] ?? 'lop' ),
				'url'   => (string) ( $payload['url'] ?? LOP_App::url() ),
				'ts'    => time(),
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
		$home    = home_url( '/' );
		$subject = 0 === strpos( $home, 'https://' ) ? untrailingslashit( $home ) : 'mailto:' . get_option( 'admin_email' );
		$gone    = array();
		foreach ( $subs as $hash => $sub ) {
			$endpoint = (string) ( $sub['endpoint'] ?? '' );
			$p256dh   = LOP_Web_Push::unb64u( (string) ( $sub['p256dh'] ?? '' ) );
			$auth     = LOP_Web_Push::unb64u( (string) ( $sub['auth'] ?? '' ) );
			if ( ! self::valid_endpoint( $endpoint ) || null === $p256dh || null === $auth ) {
				$gone[] = $hash;
				continue;
			}
			$body  = LOP_Web_Push::encrypt( $json, $p256dh, $auth );
			$vapid = LOP_Web_Push::vapid( $endpoint, $keys['pub'], $keys['pem'], $subject, time() );
			if ( null === $body || null === $vapid ) {
				++$out['failed'];
				continue;
			}
			$res  = wp_safe_remote_post(
				$endpoint,
				array(
					'timeout'     => 5,
					'redirection' => 0,
					'headers'     => array(
						'Authorization'    => $vapid,
						'Content-Encoding' => 'aes128gcm',
						'Content-Type'     => 'application/octet-stream',
						'TTL'              => '600',
						'Urgency'          => 'high',
					),
					'body'        => $body,
				)
			);
			$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
			if ( $code >= 200 && $code < 300 ) {
				++$out['sent'];
			} else {
				++$out['failed'];
				if ( 404 === $code || 410 === $code ) {
					$gone[] = $hash;
				}
			}
		}
		if ( $gone ) {
			$subs = self::subscriptions( $user_id );
			foreach ( $gone as $hash ) {
				unset( $subs[ $hash ] );
			}
			if ( $subs ) {
				update_user_meta( $user_id, self::META, $subs );
			} else {
				delete_user_meta( $user_id, self::META );
			}
		}
		return $out;
	}
}
