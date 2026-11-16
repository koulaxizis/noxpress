<?php
/**
 * BIS_Privacy — WordPress's personal data exporter and eraser (Tools →
 * Export / Erase Personal Data) for the requests of an address, and the
 * suggested text for the privacy policy.
 */

defined( 'ABSPATH' ) || exit;

final class BIS_Privacy {

	public static function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ) );
		add_action( 'admin_init', array( __CLASS__, 'policy' ) );
	}

	public static function exporters( $list ): array {
		$list                  = is_array( $list ) ? $list : array();
		$list['back-in-stock'] = array(
			'exporter_friendly_name' => __( 'Back in Stock: ειδοποιήσεις αποθέματος', 'back-in-stock' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $list;
	}

	public static function erasers( $list ): array {
		$list                  = is_array( $list ) ? $list : array();
		$list['back-in-stock'] = array(
			'eraser_friendly_name' => __( 'Back in Stock: ειδοποιήσεις αποθέματος', 'back-in-stock' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $list;
	}

	/** Status label for the export. */
	private static function status( string $s ): string {
		switch ( $s ) {
			case 'pending':
				return __( 'Περιμένει επιβεβαίωση', 'back-in-stock' );
			case 'active':
				return __( 'Ενεργό', 'back-in-stock' );
			default:
				return __( 'Στάλθηκε', 'back-in-stock' );
		}
	}

	public static function export( $email, $page = 1 ): array {
		$items = array();
		foreach ( BIS_Store::by_email( (string) $email ) as $r ) {
			$p    = wc_get_product( (int) ( $r['variation_id'] ? $r['variation_id'] : $r['product_id'] ) );
			$data = array(
				array(
					'name'  => __( 'Προϊόν', 'back-in-stock' ),
					'value' => $p ? wp_strip_all_tags( $p->get_name() ) : '#' . (int) $r['product_id'],
				),
				array(
					'name'  => __( 'Κατάσταση', 'back-in-stock' ),
					'value' => self::status( (string) $r['status'] ),
				),
				array(
					'name'  => __( 'Ημερομηνία αιτήματος', 'back-in-stock' ),
					'value' => (string) $r['created'] . ' UTC',
				),
			);
			if ( '' !== (string) $r['email'] ) {
				$data[] = array(
					'name'  => __( 'Email', 'back-in-stock' ),
					'value' => (string) $r['email'],
				);
			}
			if ( ! empty( $r['sent'] ) ) {
				$data[] = array(
					'name'  => __( 'Στάλθηκε', 'back-in-stock' ),
					'value' => (string) $r['sent'] . ' UTC',
				);
			}
			$items[] = array(
				'group_id'    => 'back-in-stock',
				'group_label' => __( 'Ειδοποιήσεις αποθέματος', 'back-in-stock' ),
				'item_id'     => 'bis-' . (int) $r['id'],
				'data'        => $data,
			);
		}
		return array(
			'data' => $items,
			'done' => true,
		);
	}

	public static function erase( $email, $page = 1 ): array {
		$n = BIS_Store::erase_email( (string) $email );
		return array(
			'items_removed'  => $n > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	public static function policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text = '<p>' . esc_html__( 'Όταν ζητάς ειδοποίηση για ένα εξαντλημένο προϊόν, κρατάμε το email σου, το προϊόν, τη γλώσσα και την ημερομηνία, για να σου στείλουμε ένα μόνο email όταν ξαναμπεί σε απόθεμα. Αν δεν έχεις λογαριασμό, σου στέλνουμε πρώτα email επιβεβαίωσης· ένα αίτημα που δεν επιβεβαιώνεται σβήνεται σε 48 ώρες. Μετά την αποστολή το email σου σβήνεται· κρατάμε για 30 ημέρες μόνο ένα κρυπτογραφημένο αποτύπωμά του, για να μη σου σταλεί το ίδιο μήνυμα δύο φορές. Ένα αίτημα που περιμένει σβήνεται μετά από 180 ημέρες. Για να εμποδίσουμε την κατάχρηση μετράμε τα αιτήματα ανά διεύθυνση IP για μία ώρα, χωρίς να αποθηκεύουμε τη διεύθυνση. Δεν σε γράφουμε σε κανένα newsletter.', 'back-in-stock' ) . '</p>';
		wp_add_privacy_policy_content( 'Back in Stock', wp_kses_post( $text ) );
	}
}
