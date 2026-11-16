<?php
/**
 * ACL_Events — words for the rows: what each action means, the names of
 * the fields, the actor tokens and the contexts. Shared by the admin
 * page, the e-mail, the CSV and the ability, so they all say the same.
 *
 * Every text is a Greek msgid of the text domain activity-log; ACL_Lang
 * gives the English for users who chose English (Bible §9).
 */

defined( 'ABSPATH' ) || exit;

final class ACL_Events {

	/** Action => description. Unknown actions are shown as they are. */
	public static function actions(): array {
		return array(
			'product.created'               => __( 'Νέο προϊόν', 'activity-log' ),
			'product.updated'               => __( 'Αλλαγή προϊόντος', 'activity-log' ),
			'product.published'             => __( 'Δημοσίευση προϊόντος', 'activity-log' ),
			'product.unpublished'           => __( 'Το προϊόν έφυγε από το κατάστημα', 'activity-log' ),
			'product.scheduled'             => __( 'Προγραμματισμένη δημοσίευση προϊόντος', 'activity-log' ),
			'product.trashed'               => __( 'Προϊόν στον κάδο', 'activity-log' ),
			'product.restored'              => __( 'Επαναφορά προϊόντος', 'activity-log' ),
			'product.deleted'               => __( 'Οριστική διαγραφή προϊόντος', 'activity-log' ),
			'order.status'                  => __( 'Αλλαγή κατάστασης παραγγελίας', 'activity-log' ),
			'order.created'                 => __( 'Νέα παραγγελία', 'activity-log' ),
			'order.updated'                 => __( 'Αλλαγή παραγγελίας', 'activity-log' ),
			'order.refunded'                => __( 'Επιστροφή χρημάτων', 'activity-log' ),
			'order.trashed'                 => __( 'Παραγγελία στον κάδο', 'activity-log' ),
			'order.deleted'                 => __( 'Οριστική διαγραφή παραγγελίας', 'activity-log' ),
			'coupon.created'                => __( 'Νέο κουπόνι', 'activity-log' ),
			'coupon.updated'                => __( 'Αλλαγή κουπονιού', 'activity-log' ),
			'coupon.published'              => __( 'Δημοσίευση κουπονιού', 'activity-log' ),
			'coupon.unpublished'            => __( 'Το κουπόνι απενεργοποιήθηκε', 'activity-log' ),
			'coupon.trashed'                => __( 'Κουπόνι στον κάδο', 'activity-log' ),
			'coupon.restored'               => __( 'Επαναφορά κουπονιού', 'activity-log' ),
			'coupon.deleted'                => __( 'Οριστική διαγραφή κουπονιού', 'activity-log' ),
			'user.login'                    => __( 'Σύνδεση', 'activity-log' ),
			'user.logout'                   => __( 'Αποσύνδεση', 'activity-log' ),
			'user.login_failed'             => __( 'Αποτυχημένη σύνδεση', 'activity-log' ),
			'user.login_failed_unknown'     => __( 'Αποτυχημένη σύνδεση με άγνωστο όνομα χρήστη', 'activity-log' ),
			'user.login_failed_many'        => __( 'Πάνω από 30 αποτυχημένες συνδέσεις αυτή την ώρα: οι υπόλοιπες δεν γράφονται', 'activity-log' ),
			'user.magic_login'              => __( 'Σύνδεση με σύνδεσμο (Magic Login)', 'activity-log' ),
			'user.created'                  => __( 'Νέος χρήστης', 'activity-log' ),
			'user.deleted'                  => __( 'Διαγραφή χρήστη', 'activity-log' ),
			'user.role'                     => __( 'Αλλαγή ρόλου', 'activity-log' ),
			'user.updated'                  => __( 'Αλλαγή λογαριασμού', 'activity-log' ),
			'user.password_reset'           => __( 'Επαναφορά κωδικού', 'activity-log' ),
			'user.app_password_created'     => __( 'Νέο Application Password', 'activity-log' ),
			'user.app_password_revoked'     => __( 'Ανάκληση Application Password', 'activity-log' ),
			'login_guard.locked'            => __( 'Login Guard: κλείδωμα', 'activity-log' ),
			'login_guard.unlocked'          => __( 'Login Guard: ξεκλείδωμα', 'activity-log' ),
			'login_guard.2fa_failed'        => __( 'Login Guard: λάθος κωδικός 2FA', 'activity-log' ),
			'login_guard.session_blocked'   => __( 'Login Guard: η συνεδρία κόπηκε', 'activity-log' ),
			'plugin.installed'              => __( 'Εγκατάσταση plugin', 'activity-log' ),
			'plugin.activated'              => __( 'Ενεργοποίηση plugin', 'activity-log' ),
			'plugin.deactivated'            => __( 'Απενεργοποίηση plugin', 'activity-log' ),
			'plugin.updated'                => __( 'Ενημέρωση plugin', 'activity-log' ),
			'plugin.deleted'                => __( 'Διαγραφή plugin', 'activity-log' ),
			'theme.installed'               => __( 'Εγκατάσταση θέματος', 'activity-log' ),
			'theme.updated'                 => __( 'Ενημέρωση θέματος', 'activity-log' ),
			'theme.switched'                => __( 'Αλλαγή θέματος', 'activity-log' ),
			'theme.deleted'                 => __( 'Διαγραφή θέματος', 'activity-log' ),
			'core.updated'                  => __( 'Ενημέρωση WordPress', 'activity-log' ),
			'file.edited'                   => __( 'Αλλαγή αρχείου από τον επεξεργαστή αρχείων', 'activity-log' ),
			'option.updated'                => __( 'Αλλαγή ρύθμισης', 'activity-log' ),
			'media.uploaded'                => __( 'Νέο αρχείο πολυμέσων', 'activity-log' ),
			'media.deleted'                 => __( 'Διαγραφή αρχείου πολυμέσων', 'activity-log' ),
			'menu.updated'                  => __( 'Αλλαγή μενού', 'activity-log' ),
			'menu.deleted'                  => __( 'Διαγραφή μενού', 'activity-log' ),
			'withdrawal.submitted'          => __( 'Easy Withdrawal: νέα αίτηση υπαναχώρησης', 'activity-log' ),
			'withdrawal.status'             => __( 'Easy Withdrawal: αλλαγή κατάστασης', 'activity-log' ),
			'quote.state'                   => __( 'Publishing Quote: αλλαγή κατάστασης αιτήματος', 'activity-log' ),
			'consignment.created'           => __( 'Trade Accounts: νέα παρακαταθήκη', 'activity-log' ),
			'consignment.settled'           => __( 'Trade Accounts: εκκαθάριση παρακαταθήκης', 'activity-log' ),
			'consignment.paid'              => __( 'Trade Accounts: πληρωμή παρακαταθήκης', 'activity-log' ),
			'creator.changed'               => __( 'Featured Creators: αλλαγή δημιουργού', 'activity-log' ),
			'preorder.released'             => __( 'Pre-Orders: κυκλοφόρησε', 'activity-log' ),
			'ledger.changed'                => __( 'Production Ledger: αλλαγή εγγραφών', 'activity-log' ),
			'broadcast.sent'                => __( 'Broadcast: στάλθηκε', 'activity-log' ),
			'log.cleared'                   => __( 'Το ιστορικό άδειασε', 'activity-log' ),
		);
	}

	/** Description of a row's action; posts of other types use the generic words. */
	public static function action( string $action ): string {
		$a = self::actions();
		if ( isset( $a[ $action ] ) ) {
			return $a[ $action ];
		}
		$generic = array(
			'published'   => __( 'Δημοσίευση', 'activity-log' ),
			'unpublished' => __( 'Κατάργηση δημοσίευσης', 'activity-log' ),
			'scheduled'   => __( 'Προγραμματισμένη δημοσίευση', 'activity-log' ),
			'updated'     => __( 'Αλλαγή', 'activity-log' ),
			'trashed'     => __( 'Στον κάδο', 'activity-log' ),
			'restored'    => __( 'Επαναφορά', 'activity-log' ),
			'deleted'     => __( 'Οριστική διαγραφή', 'activity-log' ),
			'created'     => __( 'Δημιουργία', 'activity-log' ),
		);
		$verb = (string) preg_replace( '/^.*\./', '', $action );
		$type = (string) preg_replace( '/\..*$/', '', $action );
		if ( isset( $generic[ $verb ] ) ) {
			$obj = get_post_type_object( $type );
			$name = $obj && isset( $obj->labels->singular_name ) ? (string) $obj->labels->singular_name : $type;
			return $generic[ $verb ] . ': ' . $name;
		}
		return $action;
	}

	/** Field names that read better than the raw keys. */
	public static function field( string $f ): string {
		$map = array(
			'title'          => __( 'Τίτλος', 'activity-log' ),
			'name'           => __( 'Όνομα', 'activity-log' ),
			'slug'           => __( 'Slug', 'activity-log' ),
			'status'         => __( 'Κατάσταση', 'activity-log' ),
			'author'         => __( 'Συντάκτης', 'activity-log' ),
			'parent'         => __( 'Γονέας', 'activity-log' ),
			'content'        => __( 'Περιεχόμενο', 'activity-log' ),
			'excerpt'        => __( 'Απόσπασμα', 'activity-log' ),
			'password'       => __( 'Κωδικός', 'activity-log' ),
			'email'          => __( 'Email', 'activity-log' ),
			'role'           => __( 'Ρόλος', 'activity-log' ),
			'sku'            => __( 'SKU', 'activity-log' ),
			'regular_price'  => __( 'Κανονική τιμή', 'activity-log' ),
			'sale_price'     => __( 'Τιμή προσφοράς', 'activity-log' ),
			'price'          => __( 'Τρέχουσα τιμή', 'activity-log' ),
			'date_on_sale_from' => __( 'Προσφορά από', 'activity-log' ),
			'date_on_sale_to'   => __( 'Προσφορά έως', 'activity-log' ),
			'stock_quantity' => __( 'Απόθεμα', 'activity-log' ),
			'stock_status'   => __( 'Διαθεσιμότητα', 'activity-log' ),
			'manage_stock'   => __( 'Διαχείριση αποθέματος', 'activity-log' ),
			'backorders'     => __( 'Προπαραγγελίες χωρίς απόθεμα', 'activity-log' ),
			'catalog_visibility' => __( 'Ορατότητα στον κατάλογο', 'activity-log' ),
			'featured'       => __( 'Προβεβλημένο', 'activity-log' ),
			'category_ids'   => __( 'Κατηγορίες', 'activity-log' ),
			'tag_ids'        => __( 'Ετικέτες', 'activity-log' ),
			'shipping_class_id' => __( 'Κλάση αποστολής', 'activity-log' ),
			'weight'         => __( 'Βάρος', 'activity-log' ),
			'tax_status'     => __( 'Φορολογική κατάσταση', 'activity-log' ),
			'tax_class'      => __( 'Φορολογική κλάση', 'activity-log' ),
			'description'    => __( 'Περιγραφή', 'activity-log' ),
			'short_description' => __( 'Σύντομη περιγραφή', 'activity-log' ),
			'image_id'       => __( 'Εικόνα', 'activity-log' ),
			'gallery_image_ids' => __( 'Συλλογή εικόνων', 'activity-log' ),
			'attributes'     => __( 'Χαρακτηριστικά', 'activity-log' ),
			'downloads'      => __( 'Αρχεία λήψης', 'activity-log' ),
			'total'          => __( 'Σύνολο', 'activity-log' ),
			'amount'         => __( 'Ποσό', 'activity-log' ),
			'reason'         => __( 'Αιτία', 'activity-log' ),
			'by_gateway'     => __( 'Μέσω της πύλης πληρωμής', 'activity-log' ),
			'billing'        => __( 'Στοιχεία χρέωσης', 'activity-log' ),
			'shipping'       => __( 'Στοιχεία αποστολής', 'activity-log' ),
			'customer'       => __( 'Στοιχεία πελάτη', 'activity-log' ),
			'payment_method_title' => __( 'Τρόπος πληρωμής', 'activity-log' ),
			'date_paid'      => __( 'Ημερομηνία πληρωμής', 'activity-log' ),
			'date_completed' => __( 'Ημερομηνία ολοκλήρωσης', 'activity-log' ),
			'discount_type'  => __( 'Είδος έκπτωσης', 'activity-log' ),
			'date_expires'   => __( 'Λήξη', 'activity-log' ),
			'usage_limit'    => __( 'Όριο χρήσεων', 'activity-log' ),
			'version'        => __( 'Έκδοση', 'activity-log' ),
			'file'           => __( 'Αρχείο', 'activity-log' ),
			'theme'          => __( 'Θέμα', 'activity-log' ),
			'value'          => __( 'Τιμή', 'activity-log' ),
			'type'           => __( 'Τύπος', 'activity-log' ),
			'created_via'    => __( 'Από', 'activity-log' ),
			'order'          => __( 'Παραγγελία', 'activity-log' ),
			'kind'           => __( 'Τι κλειδώθηκε', 'activity-log' ),
			'until'          => __( 'Έως', 'activity-log' ),
		);
		return isset( $map[ $f ] ) ? $map[ $f ] : $f;
	}

	/** Words for the 't' notes the watchers write. */
	public static function note( string $t ): string {
		$map = array(
			'changed' => __( 'άλλαξε', 'activity-log' ),
			'saved'   => __( 'αποθηκεύτηκε', 'activity-log' ),
			'more'    => __( 'και άλλες αλλαγές', 'activity-log' ),
			'cut'     => __( 'πολλές αλλαγές: δεν χωρούν', 'activity-log' ),
		);
		return isset( $map[ $t ] ) ? $map[ $t ] : $t;
	}

	/** Who: the stored label, or the words for a token. */
	public static function actor( array $r ): string {
		$label = (string) ( $r['actor_label'] ?? '' );
		$id    = (int) ( $r['actor_id'] ?? 0 );
		switch ( $label ) {
			case 'customer':
				/* translators: %d: user id */
				return sprintf( __( 'Πελάτης #%d', 'activity-log' ), $id );
			case 'visitor':
				return __( 'Επισκέπτης', 'activity-log' );
			case 'gateway':
				return __( 'Πύλη πληρωμής', 'activity-log' );
			case 'system':
				return __( 'Σύστημα', 'activity-log' );
			case 'deleted':
				return __( 'Διαγραμμένος χρήστης', 'activity-log' );
		}
		return '' !== $label ? $label : ( $id ? '#' . $id : __( 'Σύστημα', 'activity-log' ) );
	}

	/** The object: the stored label, with the words for tokens. */
	public static function object( array $r ): string {
		$label = (string) ( $r['object_label'] ?? '' );
		if ( 'customer' === $label ) {
			/* translators: %d: user id */
			return sprintf( __( 'Πελάτης #%d', 'activity-log' ), (int) $r['object_id'] );
		}
		if ( 'deleted' === $label ) {
			return __( 'Διαγραμμένος χρήστης', 'activity-log' );
		}
		return $label;
	}

	public static function contexts(): array {
		return array(
			'admin'       => __( 'Admin', 'activity-log' ),
			'ajax'        => __( 'Admin (AJAX)', 'activity-log' ),
			'rest'        => __( 'REST API', 'activity-log' ),
			'cli'         => __( 'WP-CLI', 'activity-log' ),
			'cron'        => __( 'Cron', 'activity-log' ),
			'queue'       => __( 'Εργασία παρασκηνίου', 'activity-log' ),
			'site-bridge' => __( 'Site Bridge (Claude)', 'activity-log' ),
			'checkout'    => __( 'Ταμείο', 'activity-log' ),
			'front'       => __( 'Site', 'activity-log' ),
		);
	}

	public static function context( string $c ): string {
		$all = self::contexts();
		return isset( $all[ $c ] ) ? $all[ $c ] : $c;
	}

	public static function severities(): array {
		return array(
			'info'     => __( 'Πληροφορία', 'activity-log' ),
			'notice'   => __( 'Σημαντικό', 'activity-log' ),
			'warning'  => __( 'Προσοχή', 'activity-log' ),
			'critical' => __( 'Κρίσιμο', 'activity-log' ),
		);
	}

	/** One details entry as text: «old → new», «changed (hidden)» or a note. */
	public static function detail( $v ): string {
		if ( ! is_array( $v ) ) {
			return '';
		}
		if ( isset( $v['h'] ) ) {
			return __( 'άλλαξε (η τιμή δεν γράφεται)', 'activity-log' );
		}
		if ( isset( $v['t'] ) ) {
			return self::note( (string) $v['t'] );
		}
		$o = isset( $v['o'] ) && '' !== (string) $v['o'] ? (string) $v['o'] : '∅';
		$n = isset( $v['n'] ) && '' !== (string) $v['n'] ? (string) $v['n'] : '∅';
		return $o . ' → ' . $n;
	}

	/** All details as «Field: text» lines. */
	public static function details_lines( array $details ): array {
		$out = array();
		foreach ( $details as $k => $v ) {
			$k     = (string) $k;
			$leaf  = (string) preg_replace( '/^.*\./', '', $k );
			$name  = $leaf === $k ? self::field( $k ) : $k;
			$out[] = $name . ': ' . self::detail( $v );
		}
		return $out;
	}
}
