<?php
/**
 * FRM_Entries — stored messages.
 *
 *  - Posts of the private type frm_entry (no URL, no UI of its own),
 *    post_parent = the form. Meta: _frm_state (new | read | spam),
 *    _frm_email (lower case, for the privacy tools), _frm_mail (sent |
 *    failed | none) and _frm_data (JSON: the fields as the visitor saw
 *    them, page, product, language, consent, spam reason).
 *  - What is stored depends on the form (Chris 2026-11-16, Q2): «only when
 *    the e-mail fails» (default), «always» or «never». A message caught by
 *    a content rule goes to Spam whenever the form stores anything.
 *  - Retention (Q3): a daily event deletes messages older than `keep`
 *    months (0 = keep) and Spam older than `spam_days` days. WordPress's
 *    privacy tools export and erase them by e-mail.
 */

defined( 'ABSPATH' ) || exit;

final class FRM_Entries {

	const PT     = 'frm_entry';
	const CRON   = 'frm_cleanup';
	const STATES = array( 'new', 'read', 'spam' );

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
				'label'               => 'Form messages',
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
			case 'read':
				return __( 'Διαβασμένο', 'forms' );
			case 'spam':
				return 'Spam';
			default:
				return __( 'Νέο', 'forms' );
		}
	}

	public static function mail_label( string $mail ): string {
		switch ( $mail ) {
			case 'sent':
				return __( 'Στάλθηκε', 'forms' );
			case 'failed':
				return __( 'Δεν στάλθηκε', 'forms' );
			default:
				return __( 'Χωρίς email', 'forms' );
		}
	}

	/**
	 * Store a message.
	 *
	 * @param array  $data  The submission (FRM_Submit::build()).
	 * @param string $state new | spam.
	 * @param string $mail  sent | failed | none.
	 * @return int The id, or 0.
	 */
	public static function store( array $data, string $state, string $mail ): int {
		$title = '' !== $data['name'] ? $data['name'] : ( '' !== $data['email'] ? $data['email'] : $data['title'] );
		// The values as plain text in post_content, so the admin search finds them.
		$text = array();
		foreach ( $data['fields'] as $f ) {
			$text[] = $f['label'] . ': ' . $f['value'];
		}
		$id = wp_insert_post(
			array(
				'post_type'    => self::PT,
				'post_status'  => 'publish',
				'post_parent'  => (int) $data['form'],
				'post_title'   => mb_substr( $title, 0, 150 ),
				'post_content' => wp_slash( implode( "\n", $text ) ),
				'meta_input'  => array(
					'_frm_state' => in_array( $state, self::STATES, true ) ? $state : 'new',
					'_frm_email' => $data['email'],
					'_frm_mail'  => $mail,
					'_frm_data'  => wp_slash( (string) wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) ),
				),
			),
			true
		);
		return is_wp_error( $id ) || ! $id ? 0 : (int) $id;
	}

	public static function set_mail( int $id, string $mail ): void {
		if ( self::data( $id ) ) {
			update_post_meta( $id, '_frm_mail', in_array( $mail, array( 'sent', 'failed', 'none' ), true ) ? $mail : 'none' );
		}
	}

	/** The stored data of a message, or null. */
	public static function data( int $id ): ?array {
		$p = $id > 0 ? get_post( $id ) : null;
		if ( ! $p || self::PT !== $p->post_type ) {
			return null;
		}
		$d = json_decode( (string) get_post_meta( $id, '_frm_data', true ), true );
		return is_array( $d ) ? $d : null;
	}

	/**
	 * A message for the admin and the public API, or null.
	 *
	 * @return array|null id, form, form_title, date (ISO 8601, GMT), state, mail, name, email,
	 *                    fields [ key, label, type, value ], page, product, lang, reason, consent.
	 */
	public static function get( int $id ): ?array {
		$d = self::data( $id );
		if ( ! $d ) {
			return null;
		}
		$p    = get_post( $id );
		$form = FRM_Forms::get( (int) $p->post_parent );
		return array(
			'id'         => $id,
			'form'       => (int) $p->post_parent,
			'form_title' => $form ? $form['title'] : (string) ( $d['title'] ?? '' ),
			'date'       => (string) get_post_time( 'c', true, $p ),
			'state'      => (string) get_post_meta( $id, '_frm_state', true ),
			'mail'       => (string) get_post_meta( $id, '_frm_mail', true ),
			'name'       => (string) ( $d['name'] ?? '' ),
			'email'      => (string) ( $d['email'] ?? '' ),
			'fields'     => isset( $d['fields'] ) && is_array( $d['fields'] ) ? $d['fields'] : array(),
			'page'       => (string) ( $d['page'] ?? '' ),
			'product'    => isset( $d['product'] ) && is_array( $d['product'] ) ? $d['product'] : null,
			'lang'       => (string) ( $d['lang'] ?? '' ),
			'reason'     => (string) ( $d['reason'] ?? '' ),
			'consent'    => isset( $d['consent'] ) && is_array( $d['consent'] ) ? $d['consent'] : null,
		);
	}

	/**
	 * Message ids, newest first.
	 *
	 * @param array $args form (int), state (new|read|spam|inbox = new + read|''), search, page, per,
	 *                    after (Y-m-d, local), mail (failed).
	 * @return array ids (int[]), total.
	 */
	public static function query( array $args = array() ): array {
		$per   = isset( $args['per'] ) ? max( 1, min( 500, (int) $args['per'] ) ) : 30;
		$page  = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$q     = array(
			'post_type'      => self::PT,
			'post_status'    => 'publish',
			'posts_per_page' => $per,
			'paged'          => $page,
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		$meta  = array();
		$state = isset( $args['state'] ) ? (string) $args['state'] : 'inbox';
		if ( 'inbox' === $state ) {
			$meta[] = array(
				'key'     => '_frm_state',
				'value'   => array( 'new', 'read' ),
				'compare' => 'IN',
			);
		} elseif ( in_array( $state, self::STATES, true ) ) {
			$meta[] = array(
				'key'   => '_frm_state',
				'value' => $state,
			);
		}
		if ( ! empty( $args['mail'] ) && 'failed' === $args['mail'] ) {
			$meta[] = array(
				'key'   => '_frm_mail',
				'value' => 'failed',
			);
		}
		if ( $meta ) {
			$q['meta_query'] = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery -- admin list.
		}
		if ( ! empty( $args['form'] ) ) {
			$q['post_parent'] = (int) $args['form'];
		}
		if ( ! empty( $args['search'] ) && is_string( $args['search'] ) ) {
			$q['s'] = mb_substr( $args['search'], 0, 100 );
		}
		if ( ! empty( $args['after'] ) && is_string( $args['after'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $args['after'] ) ) {
			$q['date_query'] = array(
				array(
					'after'     => $args['after'],
					'inclusive' => true,
				),
			);
		}
		$wq = new WP_Query( $q );
		return array(
			'ids'   => array_map( 'intval', $wq->posts ),
			'total' => (int) $wq->found_posts,
		);
	}

	/** Counts per state (optionally for one form): new, read, spam, failed. */
	public static function counts( int $form_id = 0 ): array {
		$out = array();
		foreach ( array( 'new', 'read', 'spam' ) as $st ) {
			$out[ $st ] = self::query(
				array(
					'form'  => $form_id,
					'state' => $st,
					'per'   => 1,
				)
			)['total'];
		}
		$out['failed'] = self::query(
			array(
				'form'  => $form_id,
				'state' => 'inbox',
				'mail'  => 'failed',
				'per'   => 1,
			)
		)['total'];
		return $out;
	}

	public static function set_state( int $id, string $state ): bool {
		if ( ! self::data( $id ) || ! in_array( $state, self::STATES, true ) ) {
			return false;
		}
		update_post_meta( $id, '_frm_state', $state );
		return true;
	}

	public static function delete( int $id ): bool {
		if ( ! self::data( $id ) ) {
			return false;
		}
		return (bool) wp_delete_post( $id, true );
	}

	/** Delete every message of a form (when the form is deleted). */
	public static function delete_for_form( int $form_id ): int {
		$n = 0;
		do {
			$ids = self::query(
				array(
					'form'  => $form_id,
					'state' => '',
					'per'   => 200,
				)
			)['ids'];
			foreach ( $ids as $id ) {
				if ( wp_delete_post( $id, true ) ) {
					++$n;
				}
			}
		} while ( count( $ids ) === 200 );
		return $n;
	}

	/* =====================================================================
	 * Retention
	 * =================================================================== */

	private static function older_than( string $state_cmp, array $states, string $before ): array {
		return get_posts(
			array(
				'post_type'      => self::PT,
				'post_status'    => 'any',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery -- daily cleanup.
					array(
						'key'     => '_frm_state',
						'value'   => $states,
						'compare' => $state_cmp,
					),
				),
				'date_query'     => array(
					array(
						'before' => $before,
						'column' => 'post_date_gmt',
					),
				),
			)
		);
	}

	public static function cleanup(): void {
		$s = FRM_Settings::get();
		if ( (int) $s['keep'] > 0 ) {
			$before = gmdate( 'Y-m-d H:i:s', strtotime( '-' . (int) $s['keep'] . ' months' ) );
			foreach ( self::older_than( 'IN', array( 'new', 'read' ), $before ) as $id ) {
				wp_delete_post( (int) $id, true );
			}
		}
		$before = gmdate( 'Y-m-d H:i:s', time() - (int) $s['spam_days'] * DAY_IN_SECONDS );
		foreach ( self::older_than( 'IN', array( 'spam' ), $before ) as $id ) {
			wp_delete_post( (int) $id, true );
		}
	}

	/* =====================================================================
	 * Privacy tools
	 * =================================================================== */

	public static function exporters( $list ): array {
		$list          = is_array( $list ) ? $list : array();
		$list['forms'] = array(
			'exporter_friendly_name' => __( 'Μηνύματα φορμών (Forms)', 'forms' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $list;
	}

	public static function erasers( $list ): array {
		$list          = is_array( $list ) ? $list : array();
		$list['forms'] = array(
			'eraser_friendly_name' => __( 'Μηνύματα φορμών (Forms)', 'forms' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $list;
	}

	private static function by_email( string $email, int $page ): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => self::PT,
					'post_status'    => 'any',
					'posts_per_page' => 100,
					'paged'          => max( 1, $page ),
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery -- privacy tool.
						array(
							'key'   => '_frm_email',
							'value' => strtolower( $email ),
						),
					),
				)
			)
		);
	}

	public static function export( $email, $page = 1 ): array {
		$items = array();
		$ids   = self::by_email( (string) $email, (int) $page );
		foreach ( $ids as $id ) {
			$e = self::get( $id );
			if ( ! $e ) {
				continue;
			}
			$data = array(
				array(
					'name'  => __( 'Ημερομηνία', 'forms' ),
					'value' => get_the_date( '', $id ),
				),
				array(
					'name'  => __( 'Φόρμα', 'forms' ),
					'value' => $e['form_title'],
				),
			);
			foreach ( $e['fields'] as $f ) {
				if ( ! is_array( $f ) || ! isset( $f['label'], $f['value'] ) ) {
					continue;
				}
				$data[] = array(
					'name'  => (string) $f['label'],
					'value' => (string) $f['value'],
				);
			}
			if ( '' !== $e['page'] ) {
				$data[] = array(
					'name'  => __( 'Σελίδα', 'forms' ),
					'value' => $e['page'],
				);
			}
			$items[] = array(
				'group_id'    => 'forms',
				'group_label' => __( 'Μηνύματα φορμών', 'forms' ),
				'item_id'     => 'frm-' . $id,
				'data'        => $data,
			);
		}
		return array(
			'data' => $items,
			'done' => count( $ids ) < 100,
		);
	}

	public static function erase( $email, $page = 1 ): array {
		$n = 0;
		// Always page 1: every pass deletes what the previous one found.
		$ids = self::by_email( (string) $email, 1 );
		foreach ( $ids as $id ) {
			if ( wp_delete_post( $id, true ) ) {
				++$n;
			}
		}
		return array(
			'items_removed'  => $n > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $ids ) < 100,
		);
	}
}
