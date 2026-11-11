<?php
/**
 * RS_Contracts — creators' contract expiries in the Author Portal (1.14.0).
 *
 * The source of truth is the orOS CRM (useoros.online), which keeps each
 * partner's contracts (title, format, signing and end date, state,
 * renewals) in the browser. orOS cannot be written to by the site, so orOS
 * sends the whole list here while it is open (POST wc/v3/rs/contracts, a
 * full, idempotent replacement) and the site shows each beneficiary only
 * their own contracts in the Author Portal. Design:
 * notes/rs-contracts/01-sxediasmos.md, approved by Christos 2026-11-11.
 *
 * Payload (format fixed with the orOS side; do not change it alone):
 *   { "v": 1, "sent": "Y-m-d", "contracts": [ { "id", "ben", "title",
 *     "fmt": "print"|"ebook"|"", "from": "Y-m-d", "to": "Y-m-d"|"",
 *     "state": "on"|"soon"|"ended"|"ended_by_agreement",
 *     "renewed": [ "Y-m-d", … ] } ] }
 * Every row is checked on its own; a bad row is skipped with a reason and
 * never stops the rest. "ben" must be a beneficiary RS knows, so a typo in
 * orOS can never put a contract in someone else's portal. An empty "to" is
 * an open-ended contract.
 *
 * The state shown is recomputed from "to" and today (soon = 90 days or
 * fewer), so it does not go stale while orOS is closed; ended_by_agreement
 * always wins. The state orOS sent is stored and returned as it came.
 *
 * Renewal requests: a beneficiary can ask to talk about a contract from
 * the portal. The request is stored (rs_contract_requests) and orOS pulls
 * it as it pulls Publishing Quote offers through orOS Connect (Bible §20,
 * «Δεδομένα που τραβά η υπηρεσία»): GET, then an explicit POST of the new
 * state after the import. The site sends no email.
 *
 * Storage (all autoload = no, JSON with JSON_UNESCAPED_UNICODE):
 *   rs_contracts          the current list
 *   rs_contracts_prev     the list before the last push (one-click restore)
 *   rs_contracts_meta     when the last list came, its "sent", saved, skipped
 *   rs_contract_requests  the portal requests
 * Nothing else is stored: no email, phone, address or amounts. The list is
 * left out of the RS settings backup, because orOS holds it.
 */

defined( 'ABSPATH' ) || exit;

final class RS_Contracts {

	const OPT      = 'rs_contracts';
	const OPT_PREV = 'rs_contracts_prev';
	const OPT_META = 'rs_contracts_meta';
	const OPT_REQ  = 'rs_contract_requests';

	const SLUG_ADMIN = 'revenue-splitter-contracts';

	const MAX          = 2000;    // Contracts per push.
	const MAX_BODY     = 1048576; // Bytes per push (1 MB).
	const MAX_RENEWED  = 50;
	const TITLE_MAX    = 200;
	const SOON_DAYS    = 90;
	const NOTE_MAX     = 500;
	const REQ_OPEN_MAX = 500;     // Open (new + oros) requests in total.
	const REQ_KEEP     = 1000;    // Stored requests in total (oldest closed go first).
	const REQ_BEN_DAY  = 3;       // Requests per beneficiary per day.
	const REQ_IP_HOUR  = 10;      // Requests per IP per hour.

	const FMTS     = array( 'print', 'ebook', '' );
	const STATES   = array( 'on', 'soon', 'ended', 'ended_by_agreement' );
	const RQ_STATE = array( 'new', 'oros', 'closed' );

	/** @var array|null Request cache of the list. */
	private static $cache = null;

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'route_portal' ), 11 );
		// After RS_Portal (same priority, added earlier): «RS Συμφωνητικά» sits under «RS Portal».
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 9 );
		add_action( 'admin_init', array( __CLASS__, 'route_admin' ) );
	}

	/* =====================================================================
	 * Storage
	 * =================================================================== */

	private static function read_json( string $opt ): array {
		$raw = get_option( $opt, '' );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$d = json_decode( $raw, true );
		return is_array( $d ) ? $d : array();
	}

	private static function write_json( string $opt, array $value ): void {
		update_option( $opt, (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE ), false );
	}

	/** @return array[] Every stored contract. */
	public static function all(): array {
		if ( null === self::$cache ) {
			self::$cache = array_values( array_filter( self::read_json( self::OPT ), 'is_array' ) );
		}
		return self::$cache;
	}

	/** @return array{received?:string,sent?:string,saved?:int,skipped?:array} */
	public static function meta(): array {
		return self::read_json( self::OPT_META );
	}

	/** @return array[] One beneficiary's contracts (exact name). */
	public static function for_ben( string $ben ): array {
		if ( '' === $ben ) {
			return array();
		}
		$out = array();
		foreach ( self::all() as $c ) {
			if ( isset( $c['ben'] ) && $c['ben'] === $ben ) {
				$out[] = $c;
			}
		}
		return $out;
	}

	public static function find( string $id ): ?array {
		foreach ( self::all() as $c ) {
			if ( isset( $c['id'] ) && $c['id'] === $id ) {
				return $c;
			}
		}
		return null;
	}

	/** Number of contracts in the list before the last push. */
	public static function prev_count(): int {
		$raw = get_option( self::OPT_PREV, false );
		if ( false === $raw ) {
			return -1;
		}
		return count( self::read_json( self::OPT_PREV ) );
	}

	/* =====================================================================
	 * Ingest (POST wc/v3/rs/contracts)
	 * =================================================================== */

	/**
	 * Validates and stores a full list.
	 *
	 * @param mixed $body The decoded JSON body.
	 * @return array{saved:int,skipped:array}|WP_Error
	 */
	public static function ingest( $body ) {
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'rs_contracts_body', 'The body must be a JSON object.', array( 'status' => 400 ) );
		}
		if ( ! isset( $body['v'] ) || 1 !== $body['v'] ) {
			return new WP_Error( 'rs_contracts_v', 'Unsupported format: v must be 1.', array( 'status' => 400 ) );
		}
		$sent = isset( $body['sent'] ) && is_string( $body['sent'] ) ? $body['sent'] : '';
		if ( ! self::is_date( $sent ) ) {
			return new WP_Error( 'rs_contracts_sent', 'sent must be a date, YYYY-MM-DD.', array( 'status' => 400 ) );
		}
		if ( ! isset( $body['contracts'] ) || ! is_array( $body['contracts'] ) || ( array() !== $body['contracts'] && array_keys( $body['contracts'] ) !== range( 0, count( $body['contracts'] ) - 1 ) ) ) {
			return new WP_Error( 'rs_contracts_list', 'contracts must be a list.', array( 'status' => 400 ) );
		}
		if ( count( $body['contracts'] ) > self::MAX ) {
			return new WP_Error( 'rs_contracts_max', sprintf( 'At most %d contracts per request; nothing was saved.', self::MAX ), array( 'status' => 400 ) );
		}

		$names   = array_flip( RS_Beneficiaries::collect_names() );
		$seen    = array();
		$keep    = array();
		$skipped = array();

		foreach ( $body['contracts'] as $row ) {
			$r = self::clean( $row, $names );
			if ( is_string( $r ) ) {
				$skipped[] = array(
					'id'  => self::row_id( $row ),
					'why' => $r,
				);
				continue;
			}
			if ( isset( $seen[ $r['id'] ] ) ) {
				$skipped[] = array(
					'id'  => $r['id'],
					'why' => 'duplicate_id',
				);
				continue;
			}
			$seen[ $r['id'] ] = true;
			$keep[]           = $r;
		}

		// The list before this push, for the admin's «restore» button.
		update_option( self::OPT_PREV, (string) get_option( self::OPT, '[]' ), false );
		self::write_json( self::OPT, $keep );
		self::write_json(
			self::OPT_META,
			array(
				'received' => gmdate( 'c' ),
				'sent'     => $sent,
				'saved'    => count( $keep ),
				'skipped'  => array_slice( $skipped, 0, 200 ),
				'skip_n'   => count( $skipped ),
			)
		);
		self::$cache = null;

		return array(
			'saved'   => count( $keep ),
			'skipped' => $skipped,
		);
	}

	/** The id of a row for the skipped list, even when the row is bad. */
	private static function row_id( $row ): string {
		if ( is_array( $row ) && isset( $row['id'] ) && ( is_string( $row['id'] ) || is_int( $row['id'] ) ) ) {
			return substr( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $row['id'] ), 0, 64 );
		}
		return '';
	}

	/**
	 * One row, cleaned, or the reason it was skipped.
	 *
	 * @param mixed $row
	 * @return array|string
	 */
	private static function clean( $row, array $names ) {
		if ( ! is_array( $row ) ) {
			return 'bad_item';
		}
		$id = isset( $row['id'] ) && is_string( $row['id'] ) ? $row['id'] : '';
		if ( ! preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $id ) ) {
			return 'bad_id';
		}
		$ben = isset( $row['ben'] ) && is_string( $row['ben'] ) ? $row['ben'] : '';
		if ( '' === $ben || ! isset( $names[ $ben ] ) ) {
			return 'unknown_beneficiary';
		}
		$title = isset( $row['title'] ) && is_string( $row['title'] ) ? trim( $row['title'] ) : '';
		$len   = function_exists( 'mb_strlen' ) ? mb_strlen( $title, 'UTF-8' ) : strlen( $title );
		if ( '' === $title || $len > self::TITLE_MAX || ! wp_check_invalid_utf8( $title )
			|| wp_strip_all_tags( $title ) !== $title || preg_match( '/[\x00-\x1F\x7F]/', $title ) ) {
			return 'bad_title';
		}
		$fmt = isset( $row['fmt'] ) ? $row['fmt'] : '';
		if ( ! is_string( $fmt ) || ! in_array( $fmt, self::FMTS, true ) ) {
			return 'bad_fmt';
		}
		$from = isset( $row['from'] ) && is_string( $row['from'] ) ? $row['from'] : '';
		$to   = isset( $row['to'] ) && is_string( $row['to'] ) ? $row['to'] : '';
		if ( ! self::is_date( $from ) || ( '' !== $to && ( ! self::is_date( $to ) || $to < $from ) ) ) {
			return 'bad_date';
		}
		$state = isset( $row['state'] ) ? $row['state'] : '';
		if ( ! is_string( $state ) || ! in_array( $state, self::STATES, true ) ) {
			return 'bad_state';
		}
		$renewed = isset( $row['renewed'] ) ? $row['renewed'] : array();
		if ( ! is_array( $renewed ) || count( $renewed ) > self::MAX_RENEWED ) {
			return 'bad_renewed';
		}
		foreach ( $renewed as $d ) {
			if ( ! is_string( $d ) || ! self::is_date( $d ) ) {
				return 'bad_renewed';
			}
		}
		$renewed = array_values( array_unique( $renewed ) );
		sort( $renewed );

		return array(
			'id'      => $id,
			'ben'     => $ben,
			'title'   => $title,
			'fmt'     => $fmt,
			'from'    => $from,
			'to'      => $to,
			'state'   => $state,
			'renewed' => $renewed,
		);
	}

	public static function is_date( $d ): bool {
		if ( ! is_string( $d ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/** Puts back the list from before the last push. */
	public static function restore_prev(): bool {
		$raw = get_option( self::OPT_PREV, false );
		if ( false === $raw ) {
			return false;
		}
		$cur = (string) get_option( self::OPT, '[]' );
		update_option( self::OPT, (string) $raw, false );
		update_option( self::OPT_PREV, $cur, false );
		$meta             = self::meta();
		$meta['restored'] = gmdate( 'c' );
		$meta['saved']    = count( self::read_json( self::OPT ) );
		self::write_json( self::OPT_META, $meta );
		self::$cache = null;
		return true;
	}

	/* =====================================================================
	 * State
	 * =================================================================== */

	public static function today(): string {
		return ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d' );
	}

	/** Days from today to "to" (negative when past), or null when open-ended. */
	public static function days_left( array $c, ?string $today = null ): ?int {
		$to = isset( $c['to'] ) ? (string) $c['to'] : '';
		if ( '' === $to || ! self::is_date( $to ) ) {
			return null;
		}
		$t = DateTimeImmutable::createFromFormat( '!Y-m-d', $today ?? self::today() );
		$e = DateTimeImmutable::createFromFormat( '!Y-m-d', $to );
		if ( ! $t || ! $e ) {
			return null;
		}
		$diff = $t->diff( $e );
		return $diff->invert ? -1 * (int) $diff->days : (int) $diff->days;
	}

	/** The state shown today: on | soon | ended | ended_by_agreement. */
	public static function status( array $c, ?string $today = null ): string {
		if ( isset( $c['state'] ) && 'ended_by_agreement' === $c['state'] ) {
			return 'ended_by_agreement';
		}
		$left = self::days_left( $c, $today );
		if ( null === $left ) {
			return 'on';
		}
		if ( $left < 0 ) {
			return 'ended';
		}
		return $left <= self::SOON_DAYS ? 'soon' : 'on';
	}

	/** Contracts sorted for display: soon, on, ended, ended by agreement; then by end date. */
	public static function sorted( array $list, string $today ): array {
		$rank = array(
			'soon'               => 0,
			'on'                 => 1,
			'ended'              => 2,
			'ended_by_agreement' => 3,
		);
		usort(
			$list,
			static function ( $a, $b ) use ( $rank, $today ) {
				$ra = $rank[ self::status( $a, $today ) ];
				$rb = $rank[ self::status( $b, $today ) ];
				if ( $ra !== $rb ) {
					return $ra <=> $rb;
				}
				$ta = '' === $a['to'] ? '9999-12-31' : $a['to'];
				$tb = '' === $b['to'] ? '9999-12-31' : $b['to'];
				if ( $ra >= 2 ) {
					return strcmp( $tb, $ta ); // Ended: most recent first.
				}
				return strcmp( $ta, $tb ) ?: strcmp( (string) $a['title'], (string) $b['title'] );
			}
		);
		return $list;
	}

	/** The list as the GET route and the ability return it. */
	public static function export( string $ben = '' ): array {
		$meta = self::meta();
		$list = '' !== $ben ? self::for_ben( $ben ) : self::all();
		return array(
			'received'  => isset( $meta['received'] ) ? (string) $meta['received'] : '',
			'sent'      => isset( $meta['sent'] ) ? (string) $meta['sent'] : '',
			'count'     => count( $list ),
			'contracts' => array_values( $list ),
		);
	}

	/* =====================================================================
	 * Renewal requests
	 * =================================================================== */

	/** @return array[] Every stored request, newest first. */
	private static function all_requests(): array {
		return array_values( array_filter( self::read_json( self::OPT_REQ ), 'is_array' ) );
	}

	/** @return array[] Requests in a state ('new', 'oros', 'closed' or 'all'), newest first. */
	public static function requests( string $state = 'new' ): array {
		$out = array();
		foreach ( self::all_requests() as $r ) {
			if ( 'all' === $state || ( isset( $r['state'] ) && $r['state'] === $state ) ) {
				$out[] = $r;
			}
		}
		return $out;
	}

	/** The open request (new or oros) of a beneficiary for a contract. */
	public static function open_request( string $ben, string $cid ): ?array {
		foreach ( self::all_requests() as $r ) {
			if ( $r['ben'] === $ben && $r['contract_id'] === $cid && 'closed' !== $r['state'] ) {
				return $r;
			}
		}
		return null;
	}

	/**
	 * Stores a portal request. The caller has already checked the session.
	 *
	 * @return string sent | dup | rl | full | bad
	 */
	public static function add_request( string $ben, string $cid, string $note, string $ip ): string {
		$c = self::find( $cid );
		if ( null === $c || $c['ben'] !== $ben || 'ended_by_agreement' === self::status( $c ) ) {
			return 'bad';
		}
		if ( null !== self::open_request( $ben, $cid ) ) {
			return 'dup';
		}
		$k_ben = 'rs_rl_c_b_' . md5( $ben );
		$k_ip  = 'rs_rl_c_i_' . substr( hash( 'sha256', wp_salt( 'nonce' ) . '|' . $ip ), 0, 32 );
		$n_ben = (int) get_transient( $k_ben );
		$n_ip  = (int) get_transient( $k_ip );
		if ( $n_ben >= self::REQ_BEN_DAY || $n_ip >= self::REQ_IP_HOUR ) {
			return 'rl';
		}
		$all = self::all_requests();
		if ( count( self::requests( 'new' ) ) + count( self::requests( 'oros' ) ) >= self::REQ_OPEN_MAX ) {
			return 'full';
		}
		set_transient( $k_ben, $n_ben + 1, DAY_IN_SECONDS );
		set_transient( $k_ip, $n_ip + 1, HOUR_IN_SECONDS );

		array_unshift(
			$all,
			array(
				'id'          => 'r' . strtolower( wp_generate_password( 15, false, false ) ),
				'contract_id' => $cid,
				'ben'         => $ben,
				'title'       => (string) $c['title'],
				'at'          => gmdate( 'c' ),
				'note'        => $note,
				'state'       => 'new',
			)
		);
		// Keep the store bounded: drop the oldest closed requests first.
		while ( count( $all ) > self::REQ_KEEP ) {
			$dropped = false;
			for ( $i = count( $all ) - 1; $i >= 0; $i-- ) {
				if ( 'closed' === $all[ $i ]['state'] ) {
					array_splice( $all, $i, 1 );
					$dropped = true;
					break;
				}
			}
			if ( ! $dropped ) {
				break;
			}
		}
		self::write_json( self::OPT_REQ, $all );
		return 'sent';
	}

	/** Sets a request's state; false when the id is unknown. */
	public static function set_request_state( string $id, string $state ): bool {
		if ( ! in_array( $state, self::RQ_STATE, true ) ) {
			return false;
		}
		$all = self::all_requests();
		foreach ( $all as $i => $r ) {
			if ( $r['id'] === $id ) {
				$all[ $i ]['state'] = $state;
				self::write_json( self::OPT_REQ, $all );
				return true;
			}
		}
		return false;
	}

	/** A request by id, or null. */
	public static function request( string $id ): ?array {
		foreach ( self::all_requests() as $r ) {
			if ( $r['id'] === $id ) {
				return $r;
			}
		}
		return null;
	}

	/* =====================================================================
	 * Portal (frontend)
	 * =================================================================== */

	/** The request POST of the portal (init, before output; nonce + session + PRG). */
	public static function route_portal(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked right below.
		if ( is_admin() || ! isset( $_POST['rs_ct_req'] ) ) {
			return;
		}
		if ( ! isset( $_POST['rs_ct_nonce'] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['rs_ct_nonce'] ) ), 'rs_ct_req' ) ) {
			return;
		}
		$back = RS_Portal::portal_url();
		$who  = RS_Portal::current();
		if ( null === $who ) {
			wp_safe_redirect( $back );
			exit;
		}
		$cid  = isset( $_POST['rs_ct_id'] ) ? sanitize_text_field( wp_unslash( $_POST['rs_ct_id'] ) ) : '';
		$note = isset( $_POST['rs_ct_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['rs_ct_note'] ) ) : '';
		if ( function_exists( 'mb_substr' ) ) {
			$note = mb_substr( $note, 0, self::NOTE_MAX, 'UTF-8' );
		} else {
			$note = substr( $note, 0, self::NOTE_MAX );
		}
		$code = preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $cid )
			? self::add_request( $who, $cid, $note, RS_Portal::client_ip() )
			: 'bad';

		wp_safe_redirect( add_query_arg( 'rs_ct_msg', $code, $back ) . '#rs-contracts' );
		exit;
	}

	/** dd/mm/yyyy in both languages (approved for this section). */
	public static function dmy( string $ymd ): string {
		$d = '' !== $ymd ? DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd ) : false;
		return $d ? $d->format( 'd/m/Y' ) : $ymd;
	}

	/** A local dd/mm/yyyy from an ISO 8601 UTC time. */
	private static function dmy_iso( string $iso ): string {
		$ts = '' !== $iso ? strtotime( $iso ) : false;
		return $ts ? wp_date( 'd/m/Y', $ts ) : '';
	}

	public static function fmt_label( string $fmt ): string {
		if ( 'print' === $fmt ) {
			return __( 'Έντυπο', 'revenue-splitter' );
		}
		if ( 'ebook' === $fmt ) {
			return __( 'Ψηφιακό', 'revenue-splitter' );
		}
		return '—';
	}

	public static function status_label( string $s ): string {
		switch ( $s ) {
			case 'soon':
				return __( 'Λήγει σύντομα', 'revenue-splitter' );
			case 'ended':
				return __( 'Έληξε', 'revenue-splitter' );
			case 'ended_by_agreement':
				return __( 'Λύθηκε', 'revenue-splitter' );
			default:
				return __( 'Σε ισχύ', 'revenue-splitter' );
		}
	}

	public static function left_label( array $c, string $today ): string {
		if ( 'ended_by_agreement' === self::status( $c, $today ) ) {
			return '—';
		}
		$n = self::days_left( $c, $today );
		if ( null === $n ) {
			return __( 'Αορίστου', 'revenue-splitter' );
		}
		if ( 0 === $n ) {
			return __( 'Λήγει σήμερα', 'revenue-splitter' );
		}
		if ( 1 === $n ) {
			return __( '1 ημέρα', 'revenue-splitter' );
		}
		if ( $n > 1 ) {
			/* translators: %d: days left */
			return sprintf( __( '%d ημέρες', 'revenue-splitter' ), $n );
		}
		if ( -1 === $n ) {
			return __( 'Έληξε χθες', 'revenue-splitter' );
		}
		/* translators: %d: days since the end */
		return sprintf( __( 'Έληξε πριν από %d ημέρες', 'revenue-splitter' ), -$n );
	}

	/** The «Τα συμφωνητικά σου» section, for the signed-in beneficiary only. */
	public static function render_portal( string $who ): void {
		$mine = self::for_ben( $who );
		if ( empty( $mine ) ) {
			return;
		}
		$today  = self::today();
		$mine   = self::sorted( $mine, $today );
		$active = array();
		$past   = array();
		foreach ( $mine as $c ) {
			if ( in_array( self::status( $c, $today ), array( 'on', 'soon' ), true ) ) {
				$active[] = $c;
			} else {
				$past[] = $c;
			}
		}
		$meta = self::meta();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only message flag.
		$msg = isset( $_GET['rs_ct_msg'] ) ? sanitize_key( wp_unslash( $_GET['rs_ct_msg'] ) ) : '';

		self::print_css();
		?>
		<hr class="rs-pt-sep" />
		<h3 id="rs-contracts"><?php esc_html_e( 'Τα συμφωνητικά σου', 'revenue-splitter' ); ?></h3>
		<?php if ( 'sent' === $msg ) : ?>
			<p class="rs-pt-msg-ok rs-ct-msg"><?php esc_html_e( 'Το αίτημά σου στάλθηκε στον εκδότη.', 'revenue-splitter' ); ?></p>
		<?php elseif ( 'dup' === $msg ) : ?>
			<p class="rs-pt-note rs-ct-msg"><?php esc_html_e( 'Υπάρχει ήδη ανοιχτό αίτημα για αυτό το συμφωνητικό.', 'revenue-splitter' ); ?></p>
		<?php elseif ( 'rl' === $msg || 'full' === $msg ) : ?>
			<p class="rs-pt-error rs-ct-msg"><?php esc_html_e( 'Πολλά αιτήματα σε λίγο χρόνο — δοκίμασε ξανά αργότερα.', 'revenue-splitter' ); ?></p>
		<?php elseif ( 'bad' === $msg ) : ?>
			<p class="rs-pt-error rs-ct-msg"><?php esc_html_e( 'Το αίτημα δεν μπορεί να σταλεί για αυτό το συμφωνητικό.', 'revenue-splitter' ); ?></p>
		<?php endif; ?>

		<?php if ( ! empty( $active ) ) : ?>
			<?php self::portal_table( $active, $who, $today ); ?>
		<?php else : ?>
			<p class="rs-pt-note"><?php esc_html_e( 'Κανένα συμφωνητικό σε ισχύ.', 'revenue-splitter' ); ?></p>
		<?php endif; ?>

		<?php if ( ! empty( $past ) ) : ?>
			<details class="rs-ct-past">
				<summary>
					<?php
					/* translators: %d: number of contracts */
					echo esc_html( sprintf( __( 'Ληγμένα και λυμένα (%d)', 'revenue-splitter' ), count( $past ) ) );
					?>
				</summary>
				<?php self::portal_table( $past, $who, $today ); ?>
			</details>
		<?php endif; ?>

		<?php if ( ! empty( $meta['received'] ) ) : ?>
			<p class="rs-pt-note">
				<?php
				/* translators: %s: date */
				echo esc_html( sprintf( __( 'Τελευταία ενημέρωση από τον εκδότη: %s', 'revenue-splitter' ), self::dmy_iso( (string) $meta['received'] ) ) );
				?>
			</p>
		<?php endif; ?>
		<?php
	}

	private static function portal_table( array $list, string $who, string $today ): void {
		?>
		<div class="rs-ct-scroll">
		<table class="rs-pt-table rs-ct-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Τίτλος', 'revenue-splitter' ); ?></th>
					<th><?php esc_html_e( 'Μορφή', 'revenue-splitter' ); ?></th>
					<th><?php esc_html_e( 'Υπογραφή', 'revenue-splitter' ); ?></th>
					<th><?php esc_html_e( 'Λήξη', 'revenue-splitter' ); ?></th>
					<th><?php esc_html_e( 'Απομένουν', 'revenue-splitter' ); ?></th>
					<th><?php esc_html_e( 'Κατάσταση', 'revenue-splitter' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $list as $c ) : ?>
				<?php
				$st   = self::status( $c, $today );
				$open = self::open_request( $who, (string) $c['id'] );
				?>
				<tr>
					<td>
						<strong><?php echo esc_html( (string) $c['title'] ); ?></strong>
						<?php if ( ! empty( $c['renewed'] ) ) : ?>
							<br /><small>
							<?php
							/* translators: %s: list of dates */
							echo esc_html( sprintf( __( 'Ανανεώθηκε: %s', 'revenue-splitter' ), implode( ', ', array_map( array( __CLASS__, 'dmy' ), (array) $c['renewed'] ) ) ) );
							?>
							</small>
						<?php endif; ?>
						<?php if ( null !== $open ) : ?>
							<br /><small class="rs-ct-asked">
							<?php
							/* translators: %s: date */
							echo esc_html( sprintf( __( 'Το αίτημά σου στάλθηκε στις %s.', 'revenue-splitter' ), self::dmy_iso( (string) $open['at'] ) ) );
							?>
							</small>
						<?php elseif ( 'ended_by_agreement' !== $st ) : ?>
							<details class="rs-ct-ask">
								<summary><?php esc_html_e( 'Θέλω να το συζητήσουμε / ανανέωση', 'revenue-splitter' ); ?></summary>
								<form method="post" class="rs-ct-form">
									<?php wp_nonce_field( 'rs_ct_req', 'rs_ct_nonce' ); ?>
									<input type="hidden" name="rs_ct_req" value="1" />
									<input type="hidden" name="rs_ct_id" value="<?php echo esc_attr( (string) $c['id'] ); ?>" />
									<label for="rs-ct-note-<?php echo esc_attr( (string) $c['id'] ); ?>"><?php esc_html_e( 'Σημείωμα (προαιρετικό)', 'revenue-splitter' ); ?></label>
									<textarea id="rs-ct-note-<?php echo esc_attr( (string) $c['id'] ); ?>" name="rs_ct_note" rows="3" maxlength="<?php echo esc_attr( (string) self::NOTE_MAX ); ?>"></textarea>
									<button type="submit" class="rs-pt-btn"><?php esc_html_e( 'Αποστολή αιτήματος', 'revenue-splitter' ); ?></button>
								</form>
							</details>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( self::fmt_label( (string) $c['fmt'] ) ); ?></td>
					<td><?php echo esc_html( self::dmy( (string) $c['from'] ) ); ?></td>
					<td><?php echo esc_html( '' === $c['to'] ? '—' : self::dmy( (string) $c['to'] ) ); ?></td>
					<td><?php echo esc_html( self::left_label( $c, $today ) ); ?></td>
					<td><span class="rs-ct-badge rs-ct-<?php echo esc_attr( $st ); ?>"><?php echo esc_html( self::status_label( $st ) ); ?></span></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	private static function print_css(): void {
		?>
<style>
.rs-portal .rs-ct-scroll { overflow-x: auto; }
.rs-portal .rs-ct-table td { vertical-align: top; }
.rs-portal .rs-ct-badge { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 0.82em; font-weight: 600; white-space: nowrap; border: 1px solid transparent; }
.rs-portal .rs-ct-on { color: #8fe6ab !important; background: rgba(143, 230, 171, 0.12) !important; border-color: rgba(143, 230, 171, 0.4); }
.rs-portal .rs-ct-soon { color: #f5c97e !important; background: rgba(245, 201, 126, 0.12) !important; border-color: rgba(245, 201, 126, 0.45); }
.rs-portal .rs-ct-ended { color: #ff9a9a !important; background: rgba(255, 154, 154, 0.12) !important; border-color: rgba(255, 154, 154, 0.45); }
.rs-portal .rs-ct-ended_by_agreement { color: #b7b2d6 !important; background: rgba(183, 178, 214, 0.10) !important; border-color: rgba(183, 178, 214, 0.35); }
.rs-portal .rs-ct-ask { margin-top: 6px; }
.rs-portal .rs-ct-ask summary, .rs-portal .rs-ct-past summary { cursor: pointer; color: #beb1ff; font-size: 0.88em; }
.rs-portal .rs-ct-past { margin-top: 1em; }
.rs-portal .rs-ct-form { display: grid; gap: 6px; margin-top: 6px; max-width: 420px; }
.rs-portal .rs-ct-form label { font-size: 0.85em; color: #b7b2d6; }
.rs-portal .rs-ct-form textarea { width: 100%; padding: 8px 10px; border: 1px solid #3a3f52 !important; border-radius: 3px; background: #1a1d26 !important; color: #f0eefc !important; font: inherit; }
.rs-portal .rs-ct-form button { justify-self: start; }
.rs-portal .rs-ct-asked { color: #f5c97e !important; }
.rs-portal .rs-ct-msg { text-align: left; margin: 0 0 0.6em; }
</style>
		<?php
	}

	/* =====================================================================
	 * Admin: «RS Συμφωνητικά» (read-only, plus restore and close)
	 * =================================================================== */

	public static function admin_menu(): void {
		add_submenu_page(
			RS_Admin_UI::SLUG_MENU,
			__( 'Revenue Splitter — Συμφωνητικά', 'revenue-splitter' ),
			__( 'RS Συμφωνητικά', 'revenue-splitter' ),
			RS_Admin_UI::CAP,
			self::SLUG_ADMIN,
			array( __CLASS__, 'render_admin' )
		);
	}

	/** Restore and close (POST + nonce + cap, PRG). */
	public static function route_admin(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked right below.
		if ( ! isset( $_POST['rs_ct_admin'] ) ) {
			return;
		}
		if ( ! isset( $_POST['rs_ct_admin_nonce'] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['rs_ct_admin_nonce'] ) ), 'rs_ct_admin' )
			|| ! current_user_can( RS_Admin_UI::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'revenue-splitter' ) );
		}
		$act  = sanitize_key( wp_unslash( $_POST['rs_ct_admin'] ) );
		$done = 'none';
		if ( 'restore' === $act ) {
			$done = self::restore_prev() ? 'restored' : 'none';
		} elseif ( 'close' === $act ) {
			$id   = isset( $_POST['rs_ct_rid'] ) ? sanitize_text_field( wp_unslash( $_POST['rs_ct_rid'] ) ) : '';
			$done = self::set_request_state( $id, 'closed' ) ? 'closed' : 'none';
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG_ADMIN . '&rs_ct_done=' . $done ) );
		exit;
	}

	public static function render_admin(): void {
		if ( ! current_user_can( RS_Admin_UI::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'revenue-splitter' ) );
		}
		$meta  = self::meta();
		$today = self::today();
		$list  = self::sorted( self::all(), $today );
		$reqs  = array_merge( self::requests( 'new' ), self::requests( 'oros' ) );
		$prevn = self::prev_count();
		$names = RS_Beneficiaries::collect_names();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only message flag.
		$done = isset( $_GET['rs_ct_done'] ) ? sanitize_key( wp_unslash( $_GET['rs_ct_done'] ) ) : '';

		$by_ben = array();
		foreach ( $list as $c ) {
			$by_ben[ $c['ben'] ] = ( $by_ben[ $c['ben'] ] ?? 0 ) + 1;
		}
		$no_key = array();
		foreach ( array_keys( $by_ben ) as $b ) {
			if ( ! RS_Portal::has_key( (string) $b ) ) {
				$no_key[] = (string) $b;
			}
		}
		$rest = rest_url( 'wc/v3/rs/' );
		?>
		<div class="wrap rs-wrap">
			<h1><?php esc_html_e( 'Revenue Splitter — Συμφωνητικά', 'revenue-splitter' ); ?></h1>

			<?php if ( 'restored' === $done ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Επανήλθε η προηγούμενη λίστα.', 'revenue-splitter' ); ?></p></div>
			<?php elseif ( 'closed' === $done ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Το αίτημα έκλεισε.', 'revenue-splitter' ); ?></p></div>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( 'Η λίστα έρχεται από το orOS CRM (πηγή αλήθειας) και αντικαθίσταται ολόκληρη σε κάθε αποστολή. Εδώ μόνο φαίνεται· οι αλλαγές γίνονται στο orOS. Κάθε δικαιούχος βλέπει στο Author Portal μόνο τα δικά του.', 'revenue-splitter' ); ?>
			</p>

			<h2 class="rs-h2"><?php esc_html_e( 'Τελευταία λίστα από το orOS', 'revenue-splitter' ); ?></h2>
			<?php if ( empty( $meta['received'] ) ) : ?>
				<p class="rs-muted"><?php esc_html_e( 'Δεν έχει έρθει ακόμη λίστα. Στείλ’ την από το orOS CRM, στην προβολή «Συμφωνητικά».', 'revenue-splitter' ); ?></p>
			<?php else : ?>
				<table class="widefat striped rs-table">
					<tbody>
						<tr><th><?php esc_html_e( 'Ήρθε', 'revenue-splitter' ); ?></th><td><?php echo esc_html( wp_date( 'd/m/Y H:i', (int) strtotime( (string) $meta['received'] ) ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Ημερομηνία αποστολής (orOS)', 'revenue-splitter' ); ?></th><td><?php echo esc_html( self::dmy( (string) ( $meta['sent'] ?? '' ) ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Αποθηκεύτηκαν', 'revenue-splitter' ); ?></th><td><?php echo esc_html( (string) count( $list ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Παραλείφθηκαν', 'revenue-splitter' ); ?></th><td><?php echo esc_html( (string) (int) ( $meta['skip_n'] ?? 0 ) ); ?></td></tr>
						<?php if ( ! empty( $meta['restored'] ) ) : ?>
							<tr><th><?php esc_html_e( 'Επαναφορά', 'revenue-splitter' ); ?></th><td><?php echo esc_html( wp_date( 'd/m/Y H:i', (int) strtotime( (string) $meta['restored'] ) ) ); ?></td></tr>
						<?php endif; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( ! empty( $meta['skipped'] ) ) : ?>
				<h2 class="rs-h2"><?php esc_html_e( 'Παραλείφθηκαν στην τελευταία αποστολή', 'revenue-splitter' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Διόρθωσέ τα στο orOS και στείλε ξανά. «unknown_beneficiary» σημαίνει ότι το όνομα δεν ταιριάζει ακριβώς με δικαιούχο του Revenue Splitter.', 'revenue-splitter' ); ?></p>
				<table class="widefat striped rs-table">
					<thead><tr><th>id</th><th><?php esc_html_e( 'Λόγος', 'revenue-splitter' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( (array) $meta['skipped'] as $s ) : ?>
						<tr><td><code><?php echo esc_html( (string) ( $s['id'] ?? '' ) ); ?></code></td><td><code><?php echo esc_html( (string) ( $s['why'] ?? '' ) ); ?></code></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( $prevn > 0 ) : ?>
				<form method="post" class="rs-ct-restore">
					<?php wp_nonce_field( 'rs_ct_admin', 'rs_ct_admin_nonce' ); ?>
					<input type="hidden" name="rs_ct_admin" value="restore" />
					<p>
						<button type="submit" class="button" onclick="return confirm(this.dataset.q);"
							data-q="<?php esc_attr_e( 'Να επανέλθει η προηγούμενη λίστα; Η τρέχουσα κρατιέται ως «προηγούμενη».', 'revenue-splitter' ); ?>">
							<?php esc_html_e( 'Επαναφορά προηγούμενης λίστας', 'revenue-splitter' ); ?>
						</button>
						<span class="rs-muted">
						<?php
						/* translators: %d: number of contracts */
						echo esc_html( sprintf( __( 'Η προηγούμενη λίστα έχει %d συμφωνητικά.', 'revenue-splitter' ), $prevn ) );
						?>
						</span>
					</p>
				</form>
			<?php endif; ?>

			<h2 class="rs-h2"><?php esc_html_e( 'Αιτήματα από το portal', 'revenue-splitter' ); ?></h2>
			<?php if ( empty( $reqs ) ) : ?>
				<p class="rs-muted"><?php esc_html_e( 'Κανένα ανοιχτό αίτημα.', 'revenue-splitter' ); ?></p>
			<?php else : ?>
				<table class="widefat striped rs-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Ημερομηνία', 'revenue-splitter' ); ?></th>
							<th><?php esc_html_e( 'Δικαιούχος', 'revenue-splitter' ); ?></th>
							<th><?php esc_html_e( 'Συμφωνητικό', 'revenue-splitter' ); ?></th>
							<th><?php esc_html_e( 'Σημείωμα', 'revenue-splitter' ); ?></th>
							<th><?php esc_html_e( 'Κατάσταση', 'revenue-splitter' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $reqs as $r ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( 'd/m/Y H:i', (int) strtotime( (string) $r['at'] ) ) ); ?></td>
							<td><?php echo esc_html( (string) $r['ben'] ); ?></td>
							<td><?php echo esc_html( (string) $r['title'] ); ?></td>
							<td><?php echo nl2br( esc_html( (string) $r['note'] ) ); ?></td>
							<td><?php echo 'oros' === $r['state'] ? esc_html__( 'Στο orOS', 'revenue-splitter' ) : esc_html__( 'Νέο', 'revenue-splitter' ); ?></td>
							<td>
								<form method="post">
									<?php wp_nonce_field( 'rs_ct_admin', 'rs_ct_admin_nonce' ); ?>
									<input type="hidden" name="rs_ct_admin" value="close" />
									<input type="hidden" name="rs_ct_rid" value="<?php echo esc_attr( (string) $r['id'] ); ?>" />
									<button type="submit" class="button button-small"><?php esc_html_e( 'Κλείσιμο', 'revenue-splitter' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2 class="rs-h2"><?php esc_html_e( 'Όλα τα συμφωνητικά', 'revenue-splitter' ); ?></h2>
			<?php if ( ! empty( $no_key ) ) : ?>
				<p class="rs-muted">
				<?php
				/* translators: %s: list of names */
				echo esc_html( sprintf( __( 'Χωρίς κλειδί portal (δεν βλέπουν ακόμη τα συμφωνητικά τους): %s', 'revenue-splitter' ), implode( ', ', $no_key ) ) );
				?>
				</p>
			<?php endif; ?>
			<?php if ( empty( $list ) ) : ?>
				<p class="rs-muted"><?php esc_html_e( 'Κανένα συμφωνητικό.', 'revenue-splitter' ); ?></p>
			<?php else : ?>
				<table class="widefat striped rs-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Δικαιούχος', 'revenue-splitter' ); ?></th>
							<th><?php esc_html_e( 'Τίτλος', 'revenue-splitter' ); ?></th>
							<th><?php esc_html_e( 'Μορφή', 'revenue-splitter' ); ?></th>
							<th><?php esc_html_e( 'Υπογραφή', 'revenue-splitter' ); ?></th>
							<th><?php esc_html_e( 'Λήξη', 'revenue-splitter' ); ?></th>
							<th><?php esc_html_e( 'Απομένουν', 'revenue-splitter' ); ?></th>
							<th><?php esc_html_e( 'Κατάσταση', 'revenue-splitter' ); ?></th>
							<th><?php esc_html_e( 'Ανανεώσεις', 'revenue-splitter' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $list as $c ) : ?>
						<?php $st = self::status( $c, $today ); ?>
						<tr>
							<td>
								<span class="rs-chip" style="background:<?php echo esc_attr( RS_Admin_UI::ben_color( (string) $c['ben'] ) ); ?>;color:<?php echo esc_attr( RS_Admin_UI::chip_fg( RS_Admin_UI::ben_color( (string) $c['ben'] ) ) ); ?>;"><?php echo esc_html( (string) $c['ben'] ); ?></span>
								<?php if ( ! in_array( $c['ben'], $names, true ) ) : ?>
									<em class="rs-muted"><?php esc_html_e( '(όχι πια δικαιούχος)', 'revenue-splitter' ); ?></em>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( (string) $c['title'] ); ?></td>
							<td><?php echo esc_html( self::fmt_label( (string) $c['fmt'] ) ); ?></td>
							<td><?php echo esc_html( self::dmy( (string) $c['from'] ) ); ?></td>
							<td><?php echo esc_html( '' === $c['to'] ? '—' : self::dmy( (string) $c['to'] ) ); ?></td>
							<td><?php echo esc_html( self::left_label( $c, $today ) ); ?></td>
							<td><span class="rs-ct-badge rs-ct-<?php echo esc_attr( $st ); ?>"><?php echo esc_html( self::status_label( $st ) ); ?></span></td>
							<td><?php echo esc_html( implode( ', ', array_map( array( __CLASS__, 'dmy' ), (array) $c['renewed'] ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2 class="rs-h2"><?php esc_html_e( 'Διευθύνσεις για το orOS', 'revenue-splitter' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Κλειδιά WooCommerce REST του καταστήματος: η αποστολή (POST) θέλει κλειδί «Ανάγνωση/Εγγραφή»· οι αναγνώσεις (GET) αρκούνται σε «Ανάγνωση».', 'revenue-splitter' ); ?></p>
			<ul class="rs-ct-routes">
				<li><code>POST <?php echo esc_html( $rest . 'contracts' ); ?></code></li>
				<li><code>GET <?php echo esc_html( $rest . 'contracts' ); ?></code></li>
				<li><code>GET <?php echo esc_html( $rest . 'contract-requests?state=new' ); ?></code></li>
				<li><code>POST <?php echo esc_html( $rest . 'contract-requests/<id>' ); ?></code></li>
			</ul>

			<?php RS_Admin_UI::footer(); ?>
		</div>
		<?php
	}
}
