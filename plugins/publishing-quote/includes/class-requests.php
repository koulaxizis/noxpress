<?php
/**
 * PBQ_Requests — the «I'm interested» requests.
 *
 *  - Stored as posts of the private type pbq_request (no URL, no UI of its
 *    own: the list lives in the plugin page). Meta: _pbq_state (new |
 *    contact | oros | closed), _pbq_email (lower case, for the privacy
 *    tools) and _pbq_data (JSON snapshot: contact details, language, the
 *    choices, what the visitor saw and the full breakdown with the rates of
 *    that moment, so later price changes do not change what was quoted).
 *  - Submission (Bible §14.15): the server recomputes the quote from the
 *    choices (amounts from the browser are never trusted); honeypot; at
 *    most 5 sends per hour from one IP (the IP only as an HMAC in a
 *    transient); the same e-mail with the same choices within 10 minutes
 *    is the same request (double clicks, refresh).
 *  - E-mails: to the business with the breakdown and a reply-to of the
 *    visitor; optionally to the visitor without any amount (the offer is
 *    sent only after a person checks it).
 *  - Retention: a daily event deletes requests older than the set number
 *    of months (0 = keep). WordPress privacy tools export and erase them by
 *    e-mail.
 *  - Cooperation: pbq_request_created( $id, $request ) after a new request,
 *    pbq_request_state( $id, $state, $old ) after a state change (orOS
 *    Connect will mark the requests it has taken to orOS).
 */

defined( 'ABSPATH' ) || exit;

final class PBQ_Requests {

	const PT     = 'pbq_request';
	const CRON   = 'pbq_cleanup';
	const STATES = array( 'new', 'contact', 'oros', 'closed' );
	const RL_MAX = 5;
	const RL_WIN = HOUR_IN_SECONDS;
	const DUP    = 600;

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( self::CRON, array( __CLASS__, 'cleanup' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ) );
		// Activation runs before init on a network or bulk activate: make
		// sure the cleanup event exists.
		add_action( 'admin_init', array( __CLASS__, 'schedule' ) );
	}

	public static function register(): void {
		register_post_type(
			self::PT,
			array(
				'label'               => 'Publishing Quote requests',
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'show_in_nav_menus'   => false,
				'rewrite'             => false,
				'query_var'           => false,
				'can_export'          => false,
				'supports'            => array( 'title' ),
			)
		);
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON );
	}

	/** State labels (admin, msgids). */
	public static function state_label( string $state ): string {
		switch ( $state ) {
			case 'contact':
				return __( 'Σε επικοινωνία', 'publishing-quote' );
			case 'oros':
				return __( 'Στο orOS', 'publishing-quote' );
			case 'closed':
				return __( 'Κλειστό', 'publishing-quote' );
			default:
				return __( 'Νέο', 'publishing-quote' );
		}
	}

	/* =====================================================================
	 * Submission
	 * =================================================================== */

	private static function ip_hash(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		return substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 32 );
	}

	private static function str( array $raw, string $k, int $max, bool $multi = false ): string {
		if ( ! isset( $raw[ $k ] ) || ! is_scalar( $raw[ $k ] ) ) {
			return '';
		}
		$v = $multi ? sanitize_textarea_field( (string) $raw[ $k ] ) : sanitize_text_field( (string) $raw[ $k ] );
		return mb_substr( trim( $v ), 0, $max );
	}

	/**
	 * A visitor's «Send». The caller checked the nonce for logged-in users.
	 *
	 * @param array  $raw  Contact fields (name, email, phone, book, msg,
	 *                     consent, website) and q (the calculator choices).
	 * @param string $lang 'el' | 'en'.
	 * @return array ok (bool), message, errors[], fields[], id
	 */
	public static function submit( array $raw, string $lang ): array {
		$t    = static function ( string $k ) use ( $lang ): string {
			return PBQ_Settings::text( $k, $lang );
		};
		$fail = static function ( string $msg, array $fields = array() ): array {
			return array(
				'ok'      => false,
				'message' => $msg,
				'errors'  => array( $msg ),
				'fields'  => $fields,
				'id'      => 0,
			);
		};

		if ( ! PBQ_Settings::visible() ) {
			return $fail( $t( 'err_generic' ) );
		}

		// Honeypot: thank and drop.
		if ( '' !== self::str( $raw, 'website', 200 ) ) {
			return array(
				'ok'      => true,
				'message' => $t( 'thanks' ),
				'errors'  => array(),
				'fields'  => array(),
				'id'      => 0,
			);
		}

		$rl = 'pbq_rl_' . self::ip_hash();
		$n  = (int) get_transient( $rl );
		if ( $n >= self::RL_MAX ) {
			return $fail( $t( 'err_limit' ) );
		}

		$contact = array(
			'name'  => self::str( $raw, 'name', 100 ),
			'email' => strtolower( sanitize_email( self::str( $raw, 'email', 254 ) ) ),
			'phone' => preg_replace( '/[^0-9+()\-\s]/', '', self::str( $raw, 'phone', 40 ) ),
			'book'  => self::str( $raw, 'book', 150 ),
			'msg'   => self::str( $raw, 'msg', 2000, true ),
		);
		$fields  = array();
		if ( '' === $contact['name'] ) {
			$fields[] = 'name';
		}
		if ( ! is_email( $contact['email'] ) ) {
			$fields[] = 'email';
		}
		if ( empty( $raw['consent'] ) ) {
			$fields[] = 'consent';
		}
		if ( $fields ) {
			return $fail( $t( 'err_contact' ), $fields );
		}

		$q     = isset( $raw['q'] ) && is_array( $raw['q'] ) ? $raw['q'] : array();
		$in    = PBQ_Calc::input( $q );
		$quote = PBQ_Calc::quote( $in, $lang );
		$view  = PBQ_Calc::public_view( $quote );
		if ( ! $view['ok'] ) {
			return $fail( $view['errors'] ? $view['errors'][0] : $view['empty'], $view['fields'] );
		}

		set_transient( $rl, $n + 1, self::RL_WIN );

		$dup = 'pbq_dup_' . substr( md5( $contact['email'] . '|' . wp_json_encode( $in ) ), 0, 32 );
		$old = (int) get_transient( $dup );
		if ( $old > 0 && get_post( $old ) ) {
			return array(
				'ok'      => true,
				'message' => $t( 'thanks' ),
				'errors'  => array(),
				'fields'  => array(),
				'id'      => $old,
			);
		}

		$data = array(
			'v'       => 1,
			'time'    => time(),
			'lang'    => $lang,
			'contact' => $contact,
			'consent' => array(
				'time' => time(),
				'text' => PBQ_Settings::text( 'f_consent', $lang ),
			),
			'input'   => $in,
			'view'    => $view,
			'quote'   => $quote,
			'version' => PBQ_VERSION,
		);
		$id   = wp_insert_post(
			array(
				'post_type'   => self::PT,
				'post_status' => 'publish',
				'post_title'  => $contact['name'],
				'meta_input'  => array(
					'_pbq_state' => 'new',
					'_pbq_email' => $contact['email'],
					'_pbq_data'  => wp_slash( (string) wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) ),
				),
			),
			true
		);
		if ( is_wp_error( $id ) || ! $id ) {
			return $fail( $t( 'err_generic' ) );
		}
		$id = (int) $id;
		set_transient( $dup, $id, self::DUP );

		self::mail_owner( $id, $data );
		if ( PBQ_Settings::get()['ack'] ) {
			self::mail_visitor( $data );
		}

		/**
		 * After a new request was saved.
		 *
		 * @param int   $id      Request id.
		 * @param array $request pbq_get_request() of it.
		 */
		do_action( 'pbq_request_created', $id, self::get( $id ) );

		return array(
			'ok'      => true,
			'message' => $t( 'thanks' ),
			'errors'  => array(),
			'fields'  => array(),
			'id'      => $id,
		);
	}

	/* =====================================================================
	 * E-mails
	 * =================================================================== */

	/** Where requests go: the setting, else the site admin e-mail. */
	public static function owner_email(): string {
		$e = PBQ_Settings::get()['email'];
		return '' !== $e ? $e : (string) get_option( 'admin_email' );
	}

	/** Plain-text summary of a request (owner e-mail, admin screen). */
	public static function summary( array $data, bool $breakdown ): string {
		$v   = $data['view'];
		$out = array();
		foreach ( $v['lines'] as $l ) {
			$out[] = $l['label'] . ': ' . $l['amount'];
		}
		if ( $v['typeset'] ) {
			$out[] = $v['typeset']['label'] . ': ' . $v['typeset']['amount'];
		}
		if ( $v['net'] ) {
			$out[] = $v['net']['label'] . ': ' . $v['net']['amount'];
		}
		foreach ( $v['vat'] as $x ) {
			$out[] = $x['label'] . ': ' . $x['amount'];
		}
		$out[] = $v['total']['label'] . ': ' . $v['total']['amount'];
		if ( $v['per_copy'] ) {
			$out[] = $v['per_copy']['label'] . ': ' . $v['per_copy']['amount'];
		}
		if ( $breakdown ) {
			$out[] = '';
			$out[] = __( 'Ανάλυση (μόνο για σένα)', 'publishing-quote' ) . ':';
			foreach ( self::breakdown_rows( $data ) as $r ) {
				$out[] = '- ' . $r[0] . ': ' . $r[1];
			}
		}
		return implode( "\n", $out );
	}

	/** Breakdown rows [ label, value ] of a stored request. */
	public static function breakdown_rows( array $data ): array {
		$q    = $data['quote'];
		$bd   = isset( $q['breakdown'] ) ? $q['breakdown'] : array();
		$rows = array();
		if ( isset( $bd['pages'] ) ) {
			/* translators: 1: manuscript pages, 2: words, 3: book pages */
			$rows[] = array( __( 'Σελίδες', 'publishing-quote' ), sprintf( __( 'χειρόγραφο %1$s (%2$d λέξεις), βιβλίο %3$d', 'publishing-quote' ), number_format_i18n( (float) $bd['pages']['ms'], floor( (float) $bd['pages']['ms'] ) == $bd['pages']['ms'] ? 0 : 1 ), (int) $bd['pages']['words'], (int) $bd['pages']['book'] ) . ( ! empty( $bd['pages']['estimated'] ) ? ' ' . __( '(εκτίμηση)', 'publishing-quote' ) : '' ) );
		}
		if ( isset( $bd['print'] ) ) {
			$p      = $bd['print'];
			$rows[] = array( __( 'Εκτύπωση', 'publishing-quote' ), trim( $p['size'] . ', ' . $p['paper'] . ', ' . $p['binding'] . ( $p['finishes'] ? ', ' . implode( ', ', $p['finishes'] ) : '' ) ) );
		}
		foreach ( $q['lines'] as $l ) {
			$val = $l['calc'] . ' = ' . self::money( $l['base'] );
			if ( $l['share'] > 0 ) {
				/* translators: %s: amount */
				$val .= ' + ' . sprintf( __( 'σελιδοποίηση %s', 'publishing-quote' ), self::money( $l['share'] ) );
			}
			if ( ! empty( $l['minimum'] ) ) {
				$val .= ' → ' . __( 'ελάχιστη χρέωση', 'publishing-quote' );
			}
			$val   .= ' · ' . __( 'ΦΠΑ', 'publishing-quote' ) . ' ' . PBQ_Settings::rate_label( (float) $l['vat'] );
			$rows[] = array( $l['label'], $val );
		}
		if ( ! empty( $bd['fee'] ) ) {
			$rows[] = array( __( 'Σελιδοποίηση (κρυφή)', 'publishing-quote' ), self::money( (float) $bd['fee'] ) );
		}
		return $rows;
	}

	private static function money( float $v ): string {
		return PBQ_Settings::format( (int) round( $v * 100 ), 2 );
	}

	private static function mail_owner( int $id, array $data ): void {
		$c    = $data['contact'];
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: 1: site name, 2: visitor name, 3: total */
		$subj = sprintf( __( '[%1$s] Νέο αίτημα προσφοράς: %2$s, %3$s', 'publishing-quote' ), $site, $c['name'], $data['view']['total']['amount'] );
		$body = array(
			__( 'Όνομα', 'publishing-quote' ) . ': ' . $c['name'],
			'Email: ' . $c['email'],
		);
		if ( '' !== $c['phone'] ) {
			$body[] = __( 'Τηλέφωνο', 'publishing-quote' ) . ': ' . $c['phone'];
		}
		if ( '' !== $c['book'] ) {
			$body[] = __( 'Βιβλίο', 'publishing-quote' ) . ': ' . $c['book'];
		}
		if ( '' !== $c['msg'] ) {
			$body[] = __( 'Μήνυμα', 'publishing-quote' ) . ":\n" . $c['msg'];
		}
		$body[] = '';
		$body[] = self::summary( $data, true );
		$body[] = '';
		$body[] = __( 'Ο επισκέπτης είδε μόνο τα ποσά ανά υπηρεσία και το σύνολο. Η προσφορά φεύγει μόνο όταν τη στείλεις εσύ.', 'publishing-quote' );
		$body[] = admin_url( 'admin.php?page=pbq-settings&tab=requests&view=' . $id );
		$headers = array( 'Reply-To: ' . str_replace( array( "\r", "\n", '<', '>' ), '', $c['name'] ) . ' <' . $c['email'] . '>' );
		wp_mail( self::owner_email(), $subj, implode( "\n", $body ), $headers );
	}

	private static function mail_visitor( array $data ): void {
		$lang = $data['lang'];
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$vars = array(
			'name' => $data['contact']['name'],
			'site' => $site,
		);
		$subj = PBQ_Settings::text( 'ack_subject', $lang, $vars );
		$body = PBQ_Settings::text( 'ack_body', $lang, $vars );
		if ( '' === $subj || '' === $body ) {
			return;
		}
		wp_mail( $data['contact']['email'], $subj, $body );
	}

	/* =====================================================================
	 * Reading and states (public API in functions.php)
	 * =================================================================== */

	/** The stored data of a request, or null. */
	public static function data( int $id ): ?array {
		$p = get_post( $id );
		if ( ! $p || self::PT !== $p->post_type ) {
			return null;
		}
		$d = json_decode( (string) get_post_meta( $id, '_pbq_data', true ), true );
		return is_array( $d ) && isset( $d['contact'], $d['view'], $d['quote'] ) ? $d : null;
	}

	public static function state( int $id ): string {
		$s = (string) get_post_meta( $id, '_pbq_state', true );
		return in_array( $s, self::STATES, true ) ? $s : 'new';
	}

	/** The request for other plugins (see pbq_get_request()). */
	public static function get( int $id ): ?array {
		$d = self::data( $id );
		if ( ! $d ) {
			return null;
		}
		$p     = get_post( $id );
		$lines = array();
		foreach ( $d['quote']['lines'] as $l ) {
			$lines[] = array(
				'label'  => $l['label'],
				'amount' => (int) $l['amount'],
				'vat'    => (float) $l['vat'],
				'ask'    => (bool) $l['ask'],
			);
		}
		return array(
			'id'          => $id,
			'date'        => get_post_time( 'c', true, $p ),
			'state'       => self::state( $id ),
			'lang'        => $d['lang'],
			'contact'     => $d['contact'],
			'input'       => $d['input'],
			'lines'       => $lines,
			'typeset'     => (bool) $d['quote']['typeset'],
			'total'       => (int) $d['quote']['total'],
			'incl_vat'    => (bool) $d['quote']['incl'],
			'vat'         => $d['quote']['vat'],
			'per_copy'    => (int) $d['quote']['per_copy'],
			'valid_until' => (string) $d['quote']['valid_until'],
			'view'        => $d['view'],
			'breakdown'   => self::breakdown_rows( $d ),
			'currency'    => Publishing_Quote::woo() && function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '',
		);
	}

	/**
	 * Request ids, newest first.
	 *
	 * @param array $args state ('' = all), after (id: only newer ones),
	 *                    limit (default 50, max 500), search (name / e-mail),
	 *                    page (1-based).
	 */
	public static function ids( array $args = array() ): array {
		$q = array(
			'post_type'      => self::PT,
			'post_status'    => 'publish',
			'posts_per_page' => isset( $args['limit'] ) ? max( 1, min( 500, (int) $args['limit'] ) ) : 50,
			'paged'          => isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1,
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		);
		if ( ! empty( $args['state'] ) && in_array( $args['state'], self::STATES, true ) ) {
			$q['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery -- small private list.
				array(
					'key'   => '_pbq_state',
					'value' => $args['state'],
				),
			);
		}
		if ( ! empty( $args['search'] ) && is_string( $args['search'] ) ) {
			$q['s'] = $args['search'];
		}
		$after = isset( $args['after'] ) ? (int) $args['after'] : 0;
		$ids   = array_map( 'intval', get_posts( $q ) );
		if ( $after > 0 ) {
			$ids = array_values(
				array_filter(
					$ids,
					static function ( $i ) use ( $after ) {
						return $i > $after;
					}
				)
			);
		}
		return $ids;
	}

	/** Counts per state, for the admin filter. */
	public static function counts(): array {
		$out = array_fill_keys( self::STATES, 0 );
		foreach ( self::STATES as $st ) {
			$out[ $st ] = count(
				get_posts(
					array(
						'post_type'      => self::PT,
						'post_status'    => 'publish',
						'posts_per_page' => -1,
						'fields'         => 'ids',
						'no_found_rows'  => true,
						'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery -- small private list.
							array(
								'key'   => '_pbq_state',
								'value' => $st,
							),
						),
					)
				)
			);
		}
		return $out;
	}

	public static function set_state( int $id, string $state, string $note = '' ): bool {
		if ( ! in_array( $state, self::STATES, true ) || ! self::data( $id ) ) {
			return false;
		}
		$old = self::state( $id );
		update_post_meta( $id, '_pbq_state', $state );
		if ( '' !== $note ) {
			$notes   = get_post_meta( $id, '_pbq_notes', true );
			$notes   = is_array( $notes ) ? $notes : array();
			$notes[] = array(
				't'    => time(),
				'text' => mb_substr( sanitize_text_field( $note ), 0, 500 ),
			);
			update_post_meta( $id, '_pbq_notes', array_slice( $notes, -50 ) );
		}
		if ( $old !== $state ) {
			/**
			 * After the state of a request changed.
			 *
			 * @param int    $id    Request id.
			 * @param string $state new | contact | oros | closed.
			 * @param string $old   The previous state.
			 */
			do_action( 'pbq_request_state', $id, $state, $old );
		}
		return true;
	}

	public static function delete( int $id ): bool {
		$p = get_post( $id );
		if ( ! $p || self::PT !== $p->post_type ) {
			return false;
		}
		return (bool) wp_delete_post( $id, true );
	}

	/* =====================================================================
	 * Retention
	 * =================================================================== */

	public static function cleanup(): void {
		$months = (int) PBQ_Settings::get()['keep'];
		if ( $months <= 0 ) {
			return;
		}
		$ids = get_posts(
			array(
				'post_type'      => self::PT,
				'post_status'    => 'any',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'date_query'     => array(
					array(
						'before' => gmdate( 'Y-m-d H:i:s', strtotime( '-' . $months . ' months' ) ),
						'column' => 'post_date_gmt',
					),
				),
			)
		);
		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
	}

	/* =====================================================================
	 * Privacy tools
	 * =================================================================== */

	public static function exporters( $list ): array {
		$list                     = is_array( $list ) ? $list : array();
		$list['publishing-quote'] = array(
			'exporter_friendly_name' => __( 'Αιτήματα προσφοράς (Publishing Quote)', 'publishing-quote' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $list;
	}

	public static function erasers( $list ): array {
		$list                     = is_array( $list ) ? $list : array();
		$list['publishing-quote'] = array(
			'eraser_friendly_name' => __( 'Αιτήματα προσφοράς (Publishing Quote)', 'publishing-quote' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $list;
	}

	private static function by_email( string $email ): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => self::PT,
					'post_status'    => 'any',
					'posts_per_page' => 100,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery -- privacy tool.
						array(
							'key'   => '_pbq_email',
							'value' => strtolower( $email ),
						),
					),
				)
			)
		);
	}

	public static function export( $email, $page = 1 ): array {
		$items = array();
		foreach ( self::by_email( (string) $email ) as $id ) {
			$d = self::data( $id );
			if ( ! $d ) {
				continue;
			}
			$c       = $d['contact'];
			$items[] = array(
				'group_id'    => 'publishing-quote',
				'group_label' => __( 'Αιτήματα προσφοράς', 'publishing-quote' ),
				'item_id'     => 'pbq-' . $id,
				'data'        => array(
					array(
						'name'  => __( 'Ημερομηνία', 'publishing-quote' ),
						'value' => get_the_date( '', $id ),
					),
					array(
						'name'  => __( 'Όνομα', 'publishing-quote' ),
						'value' => $c['name'],
					),
					array(
						'name'  => 'Email',
						'value' => $c['email'],
					),
					array(
						'name'  => __( 'Τηλέφωνο', 'publishing-quote' ),
						'value' => $c['phone'],
					),
					array(
						'name'  => __( 'Βιβλίο', 'publishing-quote' ),
						'value' => $c['book'],
					),
					array(
						'name'  => __( 'Μήνυμα', 'publishing-quote' ),
						'value' => $c['msg'],
					),
					array(
						'name'  => __( 'Προσφορά', 'publishing-quote' ),
						'value' => self::summary( $d, false ),
					),
				),
			);
		}
		return array(
			'data' => $items,
			'done' => true,
		);
	}

	public static function erase( $email, $page = 1 ): array {
		$n = 0;
		foreach ( self::by_email( (string) $email ) as $id ) {
			if ( wp_delete_post( $id, true ) ) {
				++$n;
			}
		}
		return array(
			'items_removed'  => $n > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
