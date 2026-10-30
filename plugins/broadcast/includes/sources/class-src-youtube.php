<?php
/**
 * BRC_Src_Youtube — module "youtube": new videos of up to 5 channels.
 *
 * Every yt_every minutes (from the 5-minute tick) the channel's public
 * feed is read, without an API key:
 *  - Shorts off (default): the feed of the channel's "long videos" playlist,
 *    UULF + the channel id after "UC". YouTube still lets some Shorts into
 *    it, so every new video there is checked too: a feed entry that links
 *    to /shorts/, or a youtube.com/shorts/<id> that answers 200, is a Short
 *    and is skipped.
 *  - Shorts on: the feed of all uploads, UU + the same part.
 *  - Live streams on: also the UULV playlist.
 * These playlist ids are not documented by YouTube. When the UULF feed
 * answers 404 for a channel, the plugin falls back to all uploads and
 * asks youtube.com/shorts/<id> for each new video (200 = a Short,
 * a redirect = a normal video); a video it cannot tell waits for approval
 * (reason "unsure"). The admin shows which way each channel is read.
 *
 * The first time a channel is read, the videos already there are only
 * remembered (option brc_yt_seen, the last 100 ids per channel): nothing
 * old is posted. The same happens when the Shorts or live choice changes,
 * so switching Shorts on does not post the old ones. Feed hosts can be changed with brc_youtube_base (local
 * tests only).
 */

defined( 'ABSPATH' ) || exit;

final class BRC_Src_Youtube {

	const SEEN_OPT = 'brc_yt_seen';
	const PER_POLL = 5;

	public static function init(): void {
		// Work happens in the tick (BRC_Jobs::run → maybe_poll).
	}

	private static function base(): string {
		/**
		 * Filters the YouTube base URL (with a trailing slash). For local tests.
		 *
		 * @param string $base https://www.youtube.com/
		 */
		return trailingslashit( (string) apply_filters( 'brc_youtube_base', 'https://www.youtube.com/' ) );
	}

	private static function seen(): array {
		$v = get_option( self::SEEN_OPT, array() );
		return is_array( $v ) ? $v : array();
	}

	public static function maybe_poll( bool $force = false ): void {
		$s     = BRC_Settings::get();
		$notes = BRC_Queue::notes();
		$last  = isset( $notes['yt_poll'] ) ? (int) $notes['yt_poll'] : 0;
		if ( ! $force && $last > time() - $s['yt_every'] * MINUTE_IN_SECONDS + 30 ) {
			return;
		}
		BRC_Queue::note( 'yt_poll', time() );
		$state = array();
		foreach ( $s['yt_channels'] as $ch ) {
			$state[ $ch ] = self::poll( $ch, $s );
		}
		BRC_Queue::note( 'yt_state', $state );
	}

	/**
	 * Reads one channel.
	 *
	 * @return string 'long' (UULF) | 'all' (Shorts on) | 'fallback' | 'error: …'
	 */
	private static function poll( string $ch, array $s ): string {
		$tail  = substr( $ch, 2 );
		$mode  = $s['yt_shorts'] ? 'all' : 'long';
		$lists = array( ( $s['yt_shorts'] ? 'UU' : 'UULF' ) . $tail );
		$items = self::feed( $lists[0] );
		if ( is_int( $items ) && 404 === $items && ! $s['yt_shorts'] ) {
			$mode  = 'fallback';
			$items = self::feed( 'UU' . $tail );
		}
		if ( is_int( $items ) ) {
			return 'error: HTTP ' . $items;
		}
		if ( $s['yt_live'] ) {
			$live = self::feed( 'UULV' . $tail );
			if ( is_array( $live ) ) {
				$items = array_merge( $items, $live );
			}
		}

		$seen = self::seen();
		$key  = ( $s['yt_shorts'] ? 'S' : '' ) . ( $s['yt_live'] ? 'L' : '' );
		// A new channel, or a changed Shorts / live choice: only remember what is there.
		$first = ! isset( $seen[ $ch ] ) || ( isset( $seen[ $ch ]['key'] ) ? $seen[ $ch ]['key'] : '' ) !== $key;
		$known = isset( $seen[ $ch ]['ids'] ) ? (array) $seen[ $ch ]['ids'] : array();
		$since = $first ? time() : (int) $seen[ $ch ]['at'];

		// Oldest first, so the channel gets them in order.
		usort(
			$items,
			static function ( $a, $b ) {
				return $a['published'] <=> $b['published'];
			}
		);
		$posted = 0;
		foreach ( $items as $v ) {
			if ( in_array( $v['id'], $known, true ) ) {
				continue;
			}
			$known[] = $v['id'];
			if ( $first || $v['published'] < $since - DAY_IN_SECONDS || $posted >= self::PER_POLL ) {
				continue;
			}
			$reason = '';
			if ( ! $s['yt_shorts'] ) {
				$probe = $v['short'] ? 'short' : self::is_short( $v['id'] );
				if ( 'short' === $probe ) {
					continue;
				}
				// The long-videos list already says "long"; only the fallback waits.
				$reason = 'fallback' === $mode && 'unsure' === $probe ? 'unsure' : '';
			}
			self::queue( $v, $s, $reason );
			++$posted;
		}
		$seen[ $ch ] = array(
			'ids' => array_slice( array_values( array_unique( $known ) ), -100 ),
			'at'  => $since,
			'key' => $key,
		);
		update_option( self::SEEN_OPT, $seen, false );
		return $mode;
	}

	private static function queue( array $v, array $s, string $reason ): void {
		$text = BRC_Template::render(
			$s['tpl_youtube'],
			array(
				'title' => $v['title'],
				'url'   => $v['url'],
				'date'  => wp_date( get_option( 'date_format' ), $v['published'] ),
			)
		);
		BRC_Queue::add(
			'youtube',
			'yt:' . $v['id'],
			array(
				'title' => $v['title'],
				'text'  => $text,
				'url'   => $v['url'],
				'image' => $v['thumb'],
				'kind'  => 'picture',
			),
			'' !== $reason ? array( 'reason' => $reason ) : array()
		);
	}

	/**
	 * A playlist feed.
	 *
	 * @return array|int Videos (id, title, url, published, thumb), or the HTTP code.
	 */
	private static function feed( string $playlist ) {
		$res = wp_safe_remote_get(
			self::base() . 'feeds/videos.xml?playlist_id=' . rawurlencode( $playlist ),
			array(
				'timeout'     => 15,
				'redirection' => 2,
			)
		);
		if ( is_wp_error( $res ) ) {
			return 0;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			return $code;
		}
		return self::parse( (string) wp_remote_retrieve_body( $res ) );
	}

	/** Atom entries of a YouTube feed (regex: no XML extension needed). */
	public static function parse( string $xml ): array {
		$out = array();
		if ( ! preg_match_all( '#<entry>(.*?)</entry>#s', $xml, $m ) ) {
			return $out;
		}
		foreach ( array_slice( $m[1], 0, 50 ) as $e ) {
			if ( ! preg_match( '#<yt:videoId>([A-Za-z0-9_-]{6,20})</yt:videoId>#', $e, $id ) ) {
				continue;
			}
			$title = preg_match( '#<title>(.*?)</title>#s', $e, $t ) ? html_entity_decode( $t[1], ENT_QUOTES | ENT_XML1, 'UTF-8' ) : '';
			$pub   = preg_match( '#<published>([^<]+)</published>#', $e, $p ) ? (int) strtotime( $p[1] ) : 0;
			$short = (bool) preg_match( '#<link[^>]+href="https://(?:www\.)?youtube\.com/shorts/#', $e );
			$out[] = array(
				'id'        => $id[1],
				'title'     => trim( wp_strip_all_tags( $title ) ),
				'url'       => 'https://www.youtube.com/watch?v=' . $id[1],
				'published' => $pub,
				'thumb'     => 'https://i.ytimg.com/vi/' . $id[1] . '/hqdefault.jpg',
				'short'     => $short,
			);
		}
		return $out;
	}

	/** Headers that skip the cookie consent page servers in the EU get. */
	private static function consent_headers(): array {
		return array(
			'Cookie'          => 'SOCS=CAI; CONSENT=YES+1',
			'Accept-Language' => 'en-US,en;q=0.8',
			'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
		);
	}

	/**
	 * 'short' | 'long' | 'unsure', from youtube.com/shorts/<id>: 200 is a
	 * Short, a redirect to /watch a normal video; anything else (the consent
	 * page, an error) is unsure.
	 */
	private static function is_short( string $id ): string {
		$res = wp_safe_remote_head(
			self::base() . 'shorts/' . rawurlencode( $id ),
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'headers'     => self::consent_headers(),
			)
		);
		if ( is_wp_error( $res ) ) {
			return 'unsure';
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 === $code ) {
			return 'short';
		}
		$to = (string) wp_remote_retrieve_header( $res, 'location' );
		return in_array( $code, array( 301, 302, 303, 307, 308 ), true ) && false !== strpos( $to, '/watch' ) ? 'long' : 'unsure';
	}

	/**
	 * A channel id from what the owner typed: UC…, a channel URL, @handle or
	 * a handle URL (one request to youtube.com). '' when not found.
	 */
	public static function resolve( string $in ): string {
		$in = trim( $in );
		if ( preg_match( '#(UC[A-Za-z0-9_-]{22})#', $in, $m ) ) {
			return $m[1];
		}
		if ( ! preg_match( '#@([A-Za-z0-9._-]{3,100})#', $in, $h ) ) {
			return '';
		}
		$res = wp_safe_remote_get(
			self::base() . '@' . rawurlencode( $h[1] ),
			array(
				'timeout'     => 10,
				'redirection' => 3,
				// Servers in the EU get the cookie consent page (consent.youtube.com)
				// instead of the channel; these cookies skip it.
				'headers'     => self::consent_headers(),
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return '';
		}
		$body = (string) wp_remote_retrieve_body( $res );
		if ( preg_match( '#"(?:channelId|externalId)":"(UC[A-Za-z0-9_-]{22})"#', $body, $m ) || preg_match( '#/channel/(UC[A-Za-z0-9_-]{22})#', $body, $m ) ) {
			return $m[1];
		}
		return '';
	}
}
