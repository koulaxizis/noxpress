<?php
/**
 * RS_Privacy — WordPress's personal data exporter and eraser (Tools →
 * Export / Erase Personal Data) and the privacy policy text, for the
 * beneficiaries (authors, translators, partners) whose e-mail is in the
 * Revenue Splitter map (Bible §12 «Προσωπικά δεδομένα», 1.22.0).
 *
 * The requester is found only by the e-mail map (rs_beneficiary_emails):
 * an address that is not in it has no data here, so nothing of anyone
 * else is ever exported or touched. Customers' order data is
 * WooCommerce's; RS keeps no customer data of its own.
 *
 * Export: the beneficiary's name and e-mail, the creator page, whether a
 * portal key exists (never the key), contracts, ledger entries, monthly
 * statements, profile requests and renewal requests.
 *
 * Eraser: contracts, ledger entries, statements and the e-mail behind
 * them are contract and accounting records (GDPR art. 17(3)(b) and (e),
 * tax retention), so they are kept and the message says why. Closed
 * profile requests (with their photos) and closed renewal requests are
 * deleted; open ones stay until the store closes them.
 */

defined( 'ABSPATH' ) || exit;

final class RS_Privacy {

	const KEY = 'revenue-splitter';

	public static function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ) );
		add_action( 'admin_init', array( __CLASS__, 'policy' ) );
	}

	public static function exporters( $list ): array {
		$list              = is_array( $list ) ? $list : array();
		$list[ self::KEY ] = array(
			'exporter_friendly_name' => __( 'Revenue Splitter: δικαιούχοι', 'revenue-splitter' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $list;
	}

	public static function erasers( $list ): array {
		$list              = is_array( $list ) ? $list : array();
		$list[ self::KEY ] = array(
			'eraser_friendly_name' => __( 'Revenue Splitter: δικαιούχοι', 'revenue-splitter' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $list;
	}

	/** The beneficiary behind an e-mail, or ''. */
	private static function ben( $email ): string {
		$email = sanitize_email( (string) $email );
		if ( ! is_email( $email ) || ! class_exists( 'RS_Emails' ) ) {
			return '';
		}
		return RS_Emails::name_for_email( $email );
	}

	/** @return array[] Name/value pairs without empty values. */
	private static function pairs( array $rows ): array {
		$out = array();
		foreach ( $rows as $name => $value ) {
			$value = is_scalar( $value ) ? (string) $value : '';
			if ( '' !== $value ) {
				$out[] = array(
					'name'  => $name,
					'value' => $value,
				);
			}
		}
		return $out;
	}

	private static function item( string $group, string $label, string $id, array $data ): array {
		return array(
			'group_id'    => 'rs-' . $group,
			'group_label' => $label,
			'item_id'     => 'rs-' . $group . '-' . $id,
			'data'        => self::pairs( $data ),
		);
	}

	/** Exporter: everything is small, so one page. */
	public static function export( $email, $page = 1 ): array {
		$ben = self::ben( $email );
		if ( '' === $ben ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$items = array();
		$who   = __( 'Δικαιούχος', 'revenue-splitter' );
		$prof  = class_exists( 'RS_Profile' ) ? RS_Profile::page( $ben ) : array();

		$items[] = self::item(
			'beneficiary',
			$who,
			md5( $ben ),
			array(
				__( 'Όνομα', 'revenue-splitter' )                => $ben,
				__( 'Email', 'revenue-splitter' )                => sanitize_email( (string) $email ),
				__( 'Σελίδα δημιουργού', 'revenue-splitter' )    => (string) ( $prof['url'] ?? '' ),
				__( 'Κλειδί Author Portal', 'revenue-splitter' ) => ( class_exists( 'RS_Portal' ) && RS_Portal::has_key( $ben ) ) ? __( 'Ναι', 'revenue-splitter' ) : __( 'Όχι', 'revenue-splitter' ),
			)
		);

		if ( class_exists( 'RS_Contracts' ) ) {
			foreach ( RS_Contracts::for_ben( $ben ) as $c ) {
				$items[] = self::item(
					'contracts',
					__( 'Συμβόλαια', 'revenue-splitter' ),
					(string) ( $c['id'] ?? md5( wp_json_encode( $c ) ) ),
					array(
						__( 'Τίτλος', 'revenue-splitter' )   => (string) ( $c['title'] ?? '' ),
						__( 'Από', 'revenue-splitter' )      => (string) ( $c['from'] ?? '' ),
						__( 'Έως', 'revenue-splitter' )      => (string) ( $c['to'] ?? '' ),
						__( 'Κατάσταση', 'revenue-splitter' ) => (string) ( $c['state'] ?? '' ),
					)
				);
			}
			foreach ( RS_Contracts::requests_of( $ben ) as $r ) {
				$items[] = self::item(
					'contract-requests',
					__( 'Αιτήματα ανανέωσης', 'revenue-splitter' ),
					(string) ( $r['id'] ?? '' ),
					array(
						__( 'Τίτλος', 'revenue-splitter' )    => (string) ( $r['title'] ?? '' ),
						__( 'Ημερομηνία', 'revenue-splitter' ) => (string) ( $r['at'] ?? '' ),
						__( 'Σημείωση', 'revenue-splitter' )  => (string) ( $r['note'] ?? '' ),
						__( 'Κατάσταση', 'revenue-splitter' ) => (string) ( $r['state'] ?? '' ),
					)
				);
			}
		}

		if ( class_exists( 'RS_Ledger' ) ) {
			foreach ( RS_Ledger::for_beneficiary( $ben ) as $e ) {
				$items[] = self::item(
					'ledger',
					__( 'Καθολικό', 'revenue-splitter' ),
					(string) $e['id'],
					array(
						__( 'Ημερομηνία', 'revenue-splitter' ) => (string) $e['date'],
						__( 'Τύπος', 'revenue-splitter' )      => RS_Ledger::type_label( (string) $e['type'] ),
						__( 'Ποσό', 'revenue-splitter' )       => number_format( RS_Ledger::signed( $e ), 2, '.', '' ),
						__( 'Σημείωση', 'revenue-splitter' )   => RS_Ledger::describe( $e ),
					)
				);
			}
		}

		if ( class_exists( 'RS_Statements' ) ) {
			foreach ( RS_Statements::months() as $month ) {
				$st = RS_Statements::get( (string) $month, $ben );
				if ( null === $st ) {
					continue;
				}
				$items[] = self::item(
					'statements',
					__( 'Μηνιαίες καταστάσεις', 'revenue-splitter' ),
					(string) $month,
					array(
						__( 'Μήνας', 'revenue-splitter' )     => (string) $month,
						__( 'Κατάσταση', 'revenue-splitter' ) => (string) ( $st['status'] ?? '' ),
						__( 'Υπόλοιπο', 'revenue-splitter' )  => number_format( (float) ( $st['closing'] ?? 0 ), 2, '.', '' ),
						__( 'Στάλθηκε', 'revenue-splitter' )  => (string) ( $st['sent_at'] ?? '' ),
						__( 'Προς', 'revenue-splitter' )      => (string) ( $st['sent_to'] ?? '' ),
					)
				);
			}
		}

		if ( class_exists( 'RS_Profile' ) ) {
			foreach ( RS_Profile::requests( 'all', $ben ) as $r ) {
				$items[] = self::item(
					'profile-requests',
					__( 'Αιτήματα προφίλ', 'revenue-splitter' ),
					(string) $r['id'],
					array(
						__( 'Τύπος', 'revenue-splitter' )      => (string) ( $r['type'] ?? '' ),
						__( 'Ημερομηνία', 'revenue-splitter' ) => (string) ( $r['at'] ?? '' ),
						__( 'Κατάσταση', 'revenue-splitter' )  => (string) ( $r['state'] ?? '' ),
						__( 'Βιογραφικό', 'revenue-splitter' ) => (string) ( $r['bio'] ?? '' ),
						__( 'Σύνδεσμοι', 'revenue-splitter' )  => implode( ' ', array_map( 'strval', (array) ( $r['links'] ?? array() ) ) ),
						__( 'Τίτλος', 'revenue-splitter' )     => (string) ( $r['title'] ?? '' ),
						__( 'Αντίτυπα', 'revenue-splitter' )   => isset( $r['qty'] ) ? (string) (int) $r['qty'] : '',
						__( 'Σημείωση', 'revenue-splitter' )   => (string) ( $r['note'] ?? '' ),
						__( 'Απάντηση', 'revenue-splitter' )   => (string) ( $r['reply'] ?? '' ),
					)
				);
			}
		}

		return array(
			'data' => $items,
			'done' => true,
		);
	}

	public static function erase( $email, $page = 1 ): array {
		$out = array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
		$ben = self::ben( $email );
		if ( '' === $ben ) {
			return $out;
		}

		$gone = 0;
		if ( class_exists( 'RS_Profile' ) ) {
			$gone += RS_Profile::erase_closed( $ben );
		}
		if ( class_exists( 'RS_Contracts' ) ) {
			$gone += RS_Contracts::erase_closed_requests( $ben );
		}
		$out['items_removed'] = $gone > 0;

		// Contracts, ledger, statements and the e-mail behind them stay.
		$out['items_retained'] = true;
		$out['messages'][]     = __( 'Revenue Splitter: τα συμβόλαια, οι εγγραφές καθολικού, οι μηνιαίες καταστάσεις και το email του δικαιούχου κρατήθηκαν ως συμβατικά και λογιστικά αρχεία (ΓΚΠΔ άρθρο 17 παρ. 3 β και ε, φορολογική διατήρηση). Τα κλειστά αιτήματα προφίλ και ανανέωσης σβήστηκαν· τα ανοιχτά μένουν μέχρι να κλείσουν.', 'revenue-splitter' );
		return $out;
	}

	public static function policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text  = '<p>' . esc_html__( 'Για τους δικαιούχους (συγγραφείς, μεταφραστές, συνεργάτες) κρατάμε το όνομα, το email, τα συμβόλαια, τις εγγραφές καθολικού και τις μηνιαίες καταστάσεις, για να υπολογίζουμε και να πληρώνουμε το μερίδιό τους από τις πωλήσεις. Αυτά είναι συμβατικά και λογιστικά αρχεία και κρατιούνται όσο ορίζει η φορολογική νομοθεσία.', 'revenue-splitter' ) . '</p>';
		$text .= '<p>' . esc_html__( 'Στο Author Portal το κλειδί πρόσβασης κρατιέται μόνο ως κρυπτογραφημένο αποτύπωμα. Τα αιτήματα αλλαγής προφίλ (βιογραφικό, σύνδεσμοι, φωτογραφία) σβήνονται 12 μήνες αφού κλείσουν και η φωτογραφία 30 ημέρες αφού κλείσει το αίτημα. Τα κλειστά αιτήματα ανανέωσης συμβολαίου σβήνονται όταν ο δικαιούχος ζητήσει διαγραφή των δεδομένων του. Για τα όρια αιτημάτων η διεύθυνση IP κρατιέται για λίγο μόνο ως κρυπτογραφημένο αποτύπωμα.', 'revenue-splitter' ) . '</p>';
		$text .= '<p>' . esc_html__( 'Από τους πελάτες το plugin δεν κρατά δικά του δεδομένα· διαβάζει μόνο τις παραγγελίες του WooCommerce για να βγάλει τα σύνολα πωλήσεων.', 'revenue-splitter' ) . '</p>';
		wp_add_privacy_policy_content( 'Revenue Splitter', wp_kses_post( $text ) );
	}
}
