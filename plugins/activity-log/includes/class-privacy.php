<?php
/**
 * ACL_Privacy — WordPress personal data tools (Bible §12) and the privacy
 * policy text.
 *
 *  exporter  the rows where the user (found by e-mail) is the actor or the
 *            object: date, what, from where, the stored (masked) IP
 *  eraser    anonymises those rows: the event stays (the history of a shop
 *            must not get holes), the actor becomes «Διαγραμμένος χρήστης»
 *            without IP, a row about the user loses the name
 *
 * Customers are already stored only as «customer» + id, so for them the
 * export holds little more than logins (if that category is on) and role
 * changes.
 */

defined( 'ABSPATH' ) || exit;

final class ACL_Privacy {

	const PER_PAGE = 200;

	public static function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ) );
		add_action( 'admin_init', array( __CLASS__, 'policy' ) );
	}

	public static function exporters( $list ): array {
		$list                 = is_array( $list ) ? $list : array();
		$list['activity-log'] = array(
			'exporter_friendly_name' => __( 'Activity Log', 'activity-log' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $list;
	}

	public static function erasers( $list ): array {
		$list                 = is_array( $list ) ? $list : array();
		$list['activity-log'] = array(
			'eraser_friendly_name' => __( 'Activity Log', 'activity-log' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $list;
	}

	public static function export( $email, $page = 1 ): array {
		$user = get_user_by( 'email', (string) $email );
		if ( ! $user instanceof WP_User ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}
		global $wpdb;
		$page   = max( 1, (int) $page );
		$offset = ( $page - 1 ) * self::PER_PAGE;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- prepared, our table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, ts, action, context, ip, object_label FROM ' . ACL_Store::table() . " WHERE actor_id = %d OR ( object_type = 'user' AND object_id = %d ) ORDER BY id LIMIT %d OFFSET %d", $user->ID, $user->ID, self::PER_PAGE, $offset ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();
		$data = array();
		foreach ( $rows as $r ) {
			$data[] = array(
				'group_id'    => 'activity-log',
				'group_label' => __( 'Ιστορικό αλλαγών', 'activity-log' ),
				'item_id'     => 'acl-' . (int) $r['id'],
				'data'        => array(
					array(
						'name'  => __( 'Ημερομηνία', 'activity-log' ),
						'value' => get_date_from_gmt( (string) $r['ts'], 'Y-m-d H:i' ),
					),
					array(
						'name'  => __( 'Ενέργεια', 'activity-log' ),
						'value' => ACL_Events::action( (string) $r['action'] ),
					),
					array(
						'name'  => __( 'Από', 'activity-log' ),
						'value' => ACL_Events::context( (string) $r['context'] ),
					),
					array(
						'name'  => __( 'IP', 'activity-log' ),
						'value' => (string) $r['ip'],
					),
				),
			);
		}
		return array(
			'data' => $data,
			'done' => count( $rows ) < self::PER_PAGE,
		);
	}

	public static function erase( $email, $page = 1 ): array {
		$user = get_user_by( 'email', (string) $email );
		$n    = $user instanceof WP_User ? ACL_Store::anonymise( (int) $user->ID ) : 0;
		return array(
			'items_removed'  => $n > 0,
			'items_retained' => false,
			'messages'       => $n > 0 ? array( __( 'Activity Log: τα γεγονότα μένουν, χωρίς όνομα και IP.', 'activity-log' ) ) : array(),
			'done'           => true,
		);
	}

	/** Suggested text for the privacy policy page. */
	public static function policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$s    = ACL_Settings::get();
		$text = '<p>' . esc_html__( 'Για την ασφάλεια του site κρατάμε ιστορικό των αλλαγών που κάνουν οι διαχειριστές και το προσωπικό: ποιος άλλαξε τι και πότε. Για τους πελάτες γράφονται μόνο αλλαγές κατάστασης παραγγελιών και λογαριασμών, με αριθμό και χωρίς όνομα, email ή διεύθυνση.', 'activity-log' ) . '</p>';
		if ( 'none' !== $s['ip'] ) {
			$text .= '<p>' . esc_html( 'full' === $s['ip'] ? __( 'Μαζί γράφεται η διεύθυνση IP.', 'activity-log' ) : __( 'Μαζί γράφεται η διεύθυνση IP χωρίς το τελευταίο μέρος της, ώστε να μη δείχνει μία σύνδεση.', 'activity-log' ) ) . '</p>';
		}
		/* translators: %d: days */
		$text .= '<p>' . esc_html( sprintf( __( 'Το ιστορικό σβήνεται αυτόματα μετά από %d ημέρες.', 'activity-log' ), (int) $s['days'] ) ) . '</p>';
		wp_add_privacy_policy_content( 'Activity Log', wp_kses_post( $text ) );
	}
}
