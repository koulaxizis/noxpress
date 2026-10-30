<?php
/**
 * BRC_Src_Instagram — module "instagram": new posts of the owner's
 * Instagram account (Business or Creator).
 *
 * Through the "Instagram API with Instagram Login" (graph.instagram.com)
 * and an access token the owner generates in their own Meta app; reading
 * one's own account needs no app review. Every ig_every minutes (from the
 * 5-minute tick) /me/media is read: no webhook, so a firewall or CDN in
 * front of the site does not matter.
 *  - Photos and albums (the first image) are posted; videos and Reels only
 *    with ig_reels (their cover image). Stories are not in /me/media.
 *  - The caption goes into {text}; with ig_tags the hashtags at its end
 *    are removed. A caption too long for a picture post is cut at a word,
 *    so the picture and the link stay.
 *  - Image URLs of Instagram expire, so the image is asked for again just
 *    before the post (fresh()).
 *  - The API can give a post without its caption at first (a caption
 *    added or edited after publishing). A pending item without text gets
 *    the caption on the next checks (refill()) and, at the latest, just
 *    before the post (fresh()). The checks also renew the picture link
 *    of pending items.
 *  - The first read only remembers the posts already there (option
 *    brc_ig_seen, the last 100 ids): nothing old is posted. The same when
 *    the Reels choice changes.
 *  - The token lives 60 days. Once a month it is refreshed
 *    (refresh_access_token); option brc_ig_meta keeps username, when it
 *    was set or refreshed and when it expires, and the admin warns 7 days
 *    before. A token from wp-config.php (BRC_INSTAGRAM_TOKEN) is never
 *    refreshed by the plugin.
 * The API host can be changed with brc_instagram_base (local tests only).
 */

defined( 'ABSPATH' ) || exit;

final class BRC_Src_Instagram {

	const SEEN_OPT   = 'brc_ig_seen';
	const META_OPT   = 'brc_ig_meta';
	const PER_POLL   = 5;
	const REFRESH_AT = 30 * DAY_IN_SECONDS;
	const LIFETIME   = 60 * DAY_IN_SECONDS;

	public static function init(): void {
		// Work happens in the tick (BRC_Jobs::run → maybe_poll).
	}

	private static function base(): string {
		/**
		 * Filters the Instagram API base URL (with a trailing slash). For local tests.
		 *
		 * @param string $base https://graph.instagram.com/
		 */
		return trailingslashit( (string) apply_filters( 'brc_instagram_base', 'https://graph.instagram.com/' ) );
	}

	/** username, id, at (set or refreshed), exp; empty before the first check. */
	public static function meta(): array {
		$m = get_option( self::META_OPT, array() );
		return is_array( $m ) ? $m : array();
	}

	/**
	 * One GET to the API.
	 *
	 * @return array code (HTTP, 0 = network), json (array|null), error (string)
	 */
	private static function api( string $path, array $args, string $token ): array {
		$args['access_token'] = $token;
		$res                  = wp_safe_remote_get(
			self::base() . $path . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 ),
			array(
				'timeout'     => 15,
				'redirection' => 0,
			)
		);
		if ( is_wp_error( $res ) ) {
			return array(
				'code'  => 0,
				'json'  => null,
				'error' => BRC_Crypt::mask( $res->get_error_message() ),
			);
		}
		$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$code = (int) wp_remote_retrieve_response_code( $res );
		$err  = '';
		if ( 200 !== $code || ! is_array( $json ) ) {
			$msg = is_array( $json ) && isset( $json['error']['message'] ) && is_scalar( $json['error']['message'] ) ? (string) $json['error']['message'] : '';
			$err = 'HTTP ' . $code . ( '' !== $msg ? ': ' . mb_substr( BRC_Crypt::mask( $msg ), 0, 200 ) : '' );
		}
		return array(
			'code'  => $code,
			'json'  => is_array( $json ) ? $json : null,
			'error' => $err,
		);
	}

	/**
	 * Checks the saved token and remembers the account (after the owner
	 * pastes a token). A new token restarts the 60 days.
	 *
	 * @return array ok (bool), username, error
	 */
	public static function check( bool $new_token ): array {
		$token = BRC_Crypt::get( 'instagram' );
		if ( '' === $token ) {
			return array(
				'ok'       => false,
				'username' => '',
				'error'    => 'no token',
			);
		}
		$r = self::api( 'me', array( 'fields' => 'user_id,username' ), $token );
		if ( '' !== $r['error'] || empty( $r['json']['username'] ) || ! is_scalar( $r['json']['username'] ) ) {
			return array(
				'ok'       => false,
				'username' => '',
				'error'    => '' !== $r['error'] ? $r['error'] : 'no username in the answer',
			);
		}
		$meta             = self::meta();
		$meta['username'] = mb_substr( sanitize_text_field( (string) $r['json']['username'] ), 0, 60 );
		$meta['id']       = isset( $r['json']['user_id'] ) && is_scalar( $r['json']['user_id'] ) ? mb_substr( (string) $r['json']['user_id'], 0, 40 ) : '';
		if ( $new_token || empty( $meta['at'] ) ) {
			$meta['at']  = time();
			$meta['exp'] = time() + self::LIFETIME;
		}
		unset( $meta['refresh_error'] );
		update_option( self::META_OPT, $meta, false );
		return array(
			'ok'       => true,
			'username' => $meta['username'],
			'error'    => '',
		);
	}

	/** Refreshes a sealed token once it is a month old. */
	private static function maybe_refresh(): void {
		if ( BRC_Crypt::from_constant( 'instagram' ) ) {
			return;
		}
		$meta = self::meta();
		if ( empty( $meta['at'] ) || (int) $meta['at'] > time() - self::REFRESH_AT ) {
			return;
		}
		$token = BRC_Crypt::get( 'instagram' );
		if ( '' === $token ) {
			return;
		}
		$r = self::api( 'refresh_access_token', array( 'grant_type' => 'ig_refresh_token' ), $token );
		if ( '' === $r['error'] && ! empty( $r['json']['access_token'] ) && is_string( $r['json']['access_token'] ) && BRC_Crypt::put( 'instagram', $r['json']['access_token'] ) ) {
			$life        = isset( $r['json']['expires_in'] ) && is_numeric( $r['json']['expires_in'] ) ? (int) $r['json']['expires_in'] : self::LIFETIME;
			$meta['at']  = time();
			$meta['exp'] = time() + max( DAY_IN_SECONDS, $life );
			unset( $meta['refresh_error'] );
		} else {
			// Tried again the next day, not on every poll.
			$meta['at']            = time() - self::REFRESH_AT + DAY_IN_SECONDS;
			$meta['refresh_error'] = '' !== $r['error'] ? $r['error'] : 'no token in the answer';
		}
		update_option( self::META_OPT, $meta, false );
	}

	/** True when the token expires within 7 days (or has expired). */
	public static function expiring(): bool {
		if ( BRC_Crypt::from_constant( 'instagram' ) ) {
			return false;
		}
		$meta = self::meta();
		return ! empty( $meta['exp'] ) && (int) $meta['exp'] < time() + 7 * DAY_IN_SECONDS;
	}

	private static function seen(): array {
		$v = get_option( self::SEEN_OPT, array() );
		return is_array( $v ) ? $v : array();
	}

	public static function maybe_poll( bool $force = false ): void {
		$s     = BRC_Settings::get();
		$notes = BRC_Queue::notes();
		$last  = isset( $notes['ig_poll'] ) ? (int) $notes['ig_poll'] : 0;
		if ( ! $force && $last > time() - $s['ig_every'] * MINUTE_IN_SECONDS + 30 ) {
			return;
		}
		BRC_Queue::note( 'ig_poll', time() );
		self::maybe_refresh();
		BRC_Queue::note( 'ig_state', self::poll( $s ) );
	}

	/** @return string 'ok' | 'no token' | 'error: …' */
	private static function poll( array $s ): string {
		$token = BRC_Crypt::get( 'instagram' );
		if ( '' === $token ) {
			return 'no token';
		}
		$r = self::api(
			'me/media',
			array(
				'fields' => 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp',
				'limit'  => 25,
			),
			$token
		);
		if ( '' !== $r['error'] ) {
			return 'error: ' . $r['error'];
		}
		$items = self::parse( isset( $r['json']['data'] ) && is_array( $r['json']['data'] ) ? $r['json']['data'] : array() );

		$seen  = self::seen();
		$key   = $s['ig_reels'] ? 'R' : '';
		$first = ! isset( $seen['ids'] ) || ( isset( $seen['key'] ) ? $seen['key'] : '' ) !== $key;
		$known = isset( $seen['ids'] ) ? (array) $seen['ids'] : array();
		$since = $first ? time() : (int) $seen['at'];

		// Oldest first, so the channel gets them in order.
		usort(
			$items,
			static function ( $a, $b ) {
				return $a['published'] <=> $b['published'];
			}
		);
		$posted = 0;
		foreach ( $items as $p ) {
			if ( in_array( $p['id'], $known, true ) ) {
				continue;
			}
			$known[] = $p['id'];
			if ( $first || $p['published'] < $since - DAY_IN_SECONDS || $posted >= self::PER_POLL ) {
				continue;
			}
			if ( 'VIDEO' === $p['type'] && ! $s['ig_reels'] ) {
				continue;
			}
			self::queue( $p, $s );
			++$posted;
		}
		self::refill( $items, $s );
		update_option(
			self::SEEN_OPT,
			array(
				'ids' => array_slice( array_values( array_unique( $known ) ), -100 ),
				'at'  => $since,
				'key' => $key,
			),
			false
		);
		return 'ok';
	}

	/** Media rows of /me/media: id, type, caption, image, url, published. */
	public static function parse( array $data ): array {
		$out = array();
		foreach ( array_slice( $data, 0, 50 ) as $m ) {
			if ( ! is_array( $m ) || empty( $m['id'] ) || ! is_scalar( $m['id'] ) || ! preg_match( '/^[0-9]{1,30}$/', (string) $m['id'] ) ) {
				continue;
			}
			$type = isset( $m['media_type'] ) && is_string( $m['media_type'] ) ? strtoupper( $m['media_type'] ) : '';
			if ( ! in_array( $type, array( 'IMAGE', 'CAROUSEL_ALBUM', 'VIDEO' ), true ) ) {
				continue;
			}
			$out[] = array(
				'id'        => (string) $m['id'],
				'type'      => $type,
				'caption'   => isset( $m['caption'] ) && is_string( $m['caption'] ) ? $m['caption'] : '',
				'image'     => self::image_of( $m ),
				'url'       => isset( $m['permalink'] ) ? BRC_Settings::clean_url( $m['permalink'] ) : '',
				'published' => isset( $m['timestamp'] ) && is_string( $m['timestamp'] ) ? (int) strtotime( $m['timestamp'] ) : 0,
			);
		}
		return $out;
	}

	/** The picture of a media row: the cover of a video, else media_url. */
	private static function image_of( array $m ): string {
		$type = isset( $m['media_type'] ) && is_string( $m['media_type'] ) ? strtoupper( $m['media_type'] ) : '';
		$k    = 'VIDEO' === $type ? 'thumbnail_url' : 'media_url';
		return isset( $m[ $k ] ) ? BRC_Settings::clean_url( $m[ $k ] ) : '';
	}

	/** A caption without the hashtags at its end (and the dots before them). */
	public static function strip_tags_end( string $caption ): string {
		$c = (string) preg_replace( '/(?:\s*#[\p{L}\p{N}_]+)+\s*$/u', '', $caption );
		// Separator lines ("." or "·") often stand between the text and the tags.
		$c = (string) preg_replace( '/(?:\n[ \t]*[.·•\-_]+[ \t]*)+\s*$/u', '', $c );
		return trim( $c );
	}

	/** Cuts a text at a word boundary to $max characters, with "…". */
	private static function cut( string $text, int $max ): string {
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		if ( $max < 2 ) {
			return '';
		}
		$cut = mb_substr( $text, 0, $max - 1 );
		$sp  = max( (int) mb_strrpos( $cut, ' ' ), (int) mb_strrpos( $cut, "\n" ) );
		if ( $sp > $max / 2 ) {
			$cut = mb_substr( $cut, 0, $sp );
		}
		return rtrim( $cut ) . '…';
	}

	/** The post text: the template, with the caption cut so a picture post fits. */
	public static function text( array $p, array $s ): string {
		require_once BRC_PATH . 'includes/class-viber.php';
		$caption = $s['ig_tags'] ? self::strip_tags_end( $p['caption'] ) : trim( $p['caption'] );
		$vars    = array(
			'text' => $caption,
			'url'  => $p['url'],
			'date' => $p['published'] ? wp_date( get_option( 'date_format' ), $p['published'] ) : '',
		);
		$text = BRC_Template::render( $s['tpl_instagram'], $vars );
		if ( '' !== $p['image'] && mb_strlen( $text ) > BRC_Viber::CAPTION_MAX ) {
			$room         = BRC_Viber::CAPTION_MAX - ( mb_strlen( $text ) - mb_strlen( $caption ) );
			$vars['text'] = self::cut( $caption, max( 0, $room ) );
			$text         = BRC_Template::render( $s['tpl_instagram'], $vars );
		}
		return $text;
	}

	/** The item title: the first line of the caption. */
	private static function title_of( string $caption ): string {
		$first = trim( (string) strtok( trim( $caption ), "\n" ) );
		return '' !== $first ? mb_substr( $first, 0, 80 ) : 'Instagram';
	}

	private static function queue( array $p, array $s ): void {
		BRC_Queue::add(
			'instagram',
			'ig:' . $p['id'],
			array(
				'title' => self::title_of( $p['caption'] ),
				'text'  => self::text( $p, $s ),
				'url'   => $p['url'],
				'image' => $p['image'],
				'kind'  => 'picture',
			)
		);
	}

	/**
	 * Pending items follow the feed: an item queued without a caption gets
	 * it once the feed has one, and the picture link is renewed (Instagram
	 * links expire, so the Queue and the app keep showing the picture).
	 * A text the owner wrote is never replaced.
	 */
	private static function refill( array $items, array $s ): void {
		$by = array();
		foreach ( $items as $p ) {
			$by[ 'ig:' . $p['id'] ] = $p;
		}
		if ( ! $by ) {
			return;
		}
		$rows = BRC_Queue::list(
			array(
				'status' => 'pending',
				'source' => 'instagram',
			),
			50
		);
		foreach ( $rows as $row ) {
			if ( ! isset( $by[ $row['skey'] ] ) ) {
				continue;
			}
			$p     = $by[ $row['skey'] ];
			$msg   = $row['msg'];
			$title = $row['title'];
			if ( '' !== $p['image'] && $p['image'] !== $msg['image'] && ( 'picture' === $msg['kind'] || '' === $msg['image'] ) ) {
				$msg['image'] = $p['image'];
				$msg['kind']  = 'picture';
			}
			if ( '' === $msg['text'] && '' !== trim( $p['caption'] ) ) {
				$msg['text'] = self::text( array_merge( $p, array( 'image' => $msg['image'] ) ), $s );
				$title       = self::title_of( $p['caption'] );
			}
			if ( $msg !== $row['msg'] ) {
				BRC_Queue::refill( (int) $row['id'], $msg, $title );
			}
		}
	}

	/**
	 * The message just before it is posted: a fresh image URL (the URLs in
	 * the feed expire) and, when the item has no text yet, the caption.
	 * What the API does not give stays as it was.
	 */
	public static function fresh( array $msg, string $skey ): array {
		if ( 0 !== strpos( $skey, 'ig:' ) ) {
			return $msg;
		}
		$id    = substr( $skey, 3 );
		$token = BRC_Crypt::get( 'instagram' );
		if ( '' === $token || ! preg_match( '/^[0-9]{1,30}$/', $id ) ) {
			return $msg;
		}
		$r = self::api( $id, array( 'fields' => 'caption,media_type,media_url,thumbnail_url,timestamp' ), $token );
		if ( '' !== $r['error'] || ! is_array( $r['json'] ) ) {
			return $msg;
		}
		$img = self::image_of( $r['json'] );
		if ( '' !== $img && ( 'picture' === $msg['kind'] || '' === $msg['image'] ) ) {
			$msg['image'] = $img;
			$msg['kind']  = 'picture';
		}
		$caption = isset( $r['json']['caption'] ) && is_string( $r['json']['caption'] ) ? $r['json']['caption'] : '';
		if ( '' === $msg['text'] && '' !== trim( $caption ) ) {
			$msg['text'] = self::text(
				array(
					'caption'   => $caption,
					'url'       => $msg['url'],
					'image'     => $msg['image'],
					'published' => isset( $r['json']['timestamp'] ) && is_string( $r['json']['timestamp'] ) ? (int) strtotime( $r['json']['timestamp'] ) : 0,
				),
				BRC_Settings::get()
			);
		}
		return $msg;
	}
}
