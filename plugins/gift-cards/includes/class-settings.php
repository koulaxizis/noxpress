<?php
/**
 * GFC_Settings — the gfc_settings option and the customer texts.
 *
 * Every value goes through sanitize() (forms and backup import alike).
 * Amounts are stored in cents (int). Customer texts are store settings
 * with a Greek and an English value (Bible §14.17), not msgids: text()
 * picks the language (auto = site locale), falls back to Greek, and
 * passes the filter gfc_text.
 */

defined( 'ABSPATH' ) || exit;

final class GFC_Settings {

	const OPT = 'gfc_settings';

	/** @var array|null */
	private static $cache = null;

	public static function defaults(): array {
		return array(
			'mode'           => 'test',   // test | live (test: only shop managers can buy and redeem).
			'paid_statuses'  => array( 'processing', 'completed' ),
			'delay_hours'    => 0,        // E10: delivery delay after payment.
			'review_over'    => 0,        // E10: manual approval above this amount (cents), 0 = off.
			'expiry_months'  => 0,        // E6: 0 = no expiry.
			'remind_days'    => 30,
			'no_card_for_gc' => true,     // E11: no gift card bought with a gift card.
			'restore_refund' => true,     // Fully refunded order: the card part goes back too.
			'pending_hours'  => 48,       // Unpaid orders that took a card are cancelled after this.
			'max_cards'      => 5,        // Cards per order.
			'limit_n'        => 5,        // E4: attempts per window, per IP and per session.
			'limit_min'      => 15,
			'site_fail_hour' => 200,      // Failed attempts per hour for the whole site.
			'pdf'            => true,     // E15: printable PDF attached to the card email.
			'pdf_size'       => 'a5',     // a5 (landscape) | a4.
			'lang'           => 'auto',   // auto | el | en (customer texts).
			'delete_data'    => '',       // 'ΔΙΑΓΡΑΦΗ' when the admin confirmed deleting cards on uninstall.
			'texts'          => array(),  // key => [ el, en ] overrides.
		);
	}

	/**
	 * Customer texts: key => [ label msgid (admin), el default, en default ].
	 * Placeholders are filled by fill() with esc_html'd values.
	 */
	public static function text_defs(): array {
		return array(
			'btn_choose'     => array( __( 'Κουμπί στις λίστες προϊόντων', 'gift-cards' ), 'Επιλογή ποσού', 'Choose amount' ),
			'amount'         => array( __( 'Ποσό', 'gift-cards' ), 'Ποσό', 'Amount' ),
			'custom_amount'  => array( __( 'Δικό σου ποσό', 'gift-cards' ), 'Δικό σου ποσό ({min} – {max})', 'Your own amount ({min} – {max})' ),
			'for_me'         => array( __( 'Για μένα', 'gift-cards' ), 'Για μένα', 'For me' ),
			'for_gift'       => array( __( 'Για δώρο', 'gift-cards' ), 'Για δώρο σε κάποιον άλλο', 'As a gift for someone else' ),
			'rcpt_name'      => array( __( 'Όνομα παραλήπτη', 'gift-cards' ), 'Όνομα παραλήπτη', 'Recipient name' ),
			'rcpt_email'     => array( __( 'Email παραλήπτη', 'gift-cards' ), 'Email παραλήπτη', 'Recipient email' ),
			'sender'         => array( __( 'Από', 'gift-cards' ), 'Από (το όνομά σου)', 'From (your name)' ),
			'message'        => array( __( 'Μήνυμα', 'gift-cards' ), 'Μήνυμα (προαιρετικό)', 'Message (optional)' ),
			'date'           => array( __( 'Ημερομηνία αποστολής', 'gift-cards' ), 'Ημερομηνία αποστολής', 'Delivery date' ),
			'date_hint'      => array( __( 'Υπόδειξη ημερομηνίας', 'gift-cards' ), 'Κενό = αμέσως μετά την πληρωμή.', 'Empty = right after payment.' ),
			'err_amount'     => array( __( 'Σφάλμα ποσού', 'gift-cards' ), 'Διάλεξε ένα ποσό.', 'Choose an amount.' ),
			'err_fields'     => array( __( 'Σφάλμα στοιχείων δώρου', 'gift-cards' ), 'Συμπλήρωσε όνομα και σωστό email παραλήπτη.', 'Fill in the recipient name and a valid email.' ),
			'err_date'       => array( __( 'Σφάλμα ημερομηνίας', 'gift-cards' ), 'Η ημερομηνία πρέπει να είναι από σήμερα έως {max}.', 'The date must be between today and {max}.' ),
			'line_to'        => array( __( 'Γραμμή «Προς» στο καλάθι', 'gift-cards' ), 'Προς', 'To' ),
			'line_from'      => array( __( 'Γραμμή «Από» στο καλάθι', 'gift-cards' ), 'Από', 'From' ),
			'line_date'      => array( __( 'Γραμμή «Αποστολή» στο καλάθι', 'gift-cards' ), 'Αποστολή', 'Delivery' ),
			'line_now'       => array( __( 'Αποστολή αμέσως', 'gift-cards' ), 'Αμέσως μετά την πληρωμή', 'Right after payment' ),
			'line_me'        => array( __( 'Γραμμή «Για μένα» στο καλάθι', 'gift-cards' ), 'Ο κωδικός έρχεται στο email σου', 'The code comes to your email' ),
			'have_card'      => array( __( 'Τίτλος πεδίου εξαργύρωσης', 'gift-cards' ), 'Έχεις δωροκάρτα;', 'Have a gift card?' ),
			'placeholder'    => array( __( 'Placeholder κωδικού', 'gift-cards' ), 'Κωδικός δωροκάρτας', 'Gift card code' ),
			'apply'          => array( __( 'Κουμπί εφαρμογής', 'gift-cards' ), 'Εφαρμογή', 'Apply' ),
			'remove'         => array( __( 'Σύνδεσμος αφαίρεσης', 'gift-cards' ), 'Αφαίρεση', 'Remove' ),
			'row'            => array( __( 'Γραμμή στα σύνολα', 'gift-cards' ), 'Δωροκάρτα {card}', 'Gift card {card}' ),
			'row_left'       => array( __( 'Υπόλοιπο μετά', 'gift-cards' ), 'υπόλοιπο μετά: {balance}', 'balance after: {balance}' ),
			'applied'        => array( __( 'Μήνυμα επιτυχίας', 'gift-cards' ), 'Η δωροκάρτα εφαρμόστηκε. Υπόλοιπο: {balance}.', 'The gift card was applied. Balance: {balance}.' ),
			'removed'        => array( __( 'Μήνυμα αφαίρεσης', 'gift-cards' ), 'Η δωροκάρτα αφαιρέθηκε.', 'The gift card was removed.' ),
			'invalid'        => array( __( 'Μη έγκυρος κωδικός', 'gift-cards' ), 'Ο κωδικός δεν είναι έγκυρος ή η κάρτα δεν έχει υπόλοιπο.', 'The code is not valid or the card has no balance.' ),
			'locked'         => array( __( 'Πολλές προσπάθειες', 'gift-cards' ), 'Πολλές προσπάθειες. Δοκίμασε ξανά σε λίγα λεπτά.', 'Too many attempts. Try again in a few minutes.' ),
			'max_cards'      => array( __( 'Όριο καρτών', 'gift-cards' ), 'Έχεις ήδη το μέγιστο αριθμό δωροκαρτών σε αυτή την παραγγελία.', 'You already have the maximum number of gift cards on this order.' ),
			'no_gc_with_gc'  => array( __( 'Δωροκάρτα με δωροκάρτα', 'gift-cards' ), 'Δωροκάρτα δεν αγοράζεται με δωροκάρτα.', 'A gift card cannot be bought with a gift card.' ),
			'changed'        => array( __( 'Το υπόλοιπο άλλαξε', 'gift-cards' ), 'Το υπόλοιπο μιας δωροκάρτας άλλαξε. Έλεγξε τα σύνολα και δοκίμασε ξανά.', 'The balance of a gift card changed. Check the totals and try again.' ),
			'account_title'  => array( __( 'Μενού στον λογαριασμό', 'gift-cards' ), 'Δωροκάρτες', 'Gift cards' ),
			'subj_card'      => array( __( 'Email: θέμα δωροκάρτας', 'gift-cards' ), 'Μια δωροκάρτα {amount} για το {site_title}', 'A {amount} gift card for {site_title}' ),
			'head_card'      => array( __( 'Email: επικεφαλίδα δωροκάρτας', 'gift-cards' ), 'Η δωροκάρτα σου', 'Your gift card' ),
			'subj_sent'      => array( __( 'Email αγοραστή: θέμα', 'gift-cards' ), 'Η δωροκάρτα σου στάλθηκε', 'Your gift card was sent' ),
			'subj_remind'    => array( __( 'Email υπενθύμισης: θέμα', 'gift-cards' ), 'Η δωροκάρτα σου λήγει σύντομα', 'Your gift card expires soon' ),
			'email_intro'    => array( __( 'Email: εισαγωγή', 'gift-cards' ), '{sender} σου έστειλε μια δωροκάρτα {amount} για το {site}.', '{sender} sent you a {amount} gift card for {site}.' ),
			'email_intro_me' => array( __( 'Email: εισαγωγή (για μένα)', 'gift-cards' ), 'Η δωροκάρτα σου {amount} για το {site} είναι έτοιμη.', 'Your {amount} gift card for {site} is ready.' ),
			'email_how'      => array( __( 'Email: πώς χρησιμοποιείται', 'gift-cards' ), 'Γράψε τον κωδικό στο καλάθι ή στο ταμείο, στο πεδίο «Έχεις δωροκάρτα;». Ό,τι περισσέψει μένει για την επόμενη αγορά.', 'Enter the code in the cart or at checkout, in the "Have a gift card?" field. Whatever is left stays for your next order.' ),
			'email_expires'  => array( __( 'Email: λήξη', 'gift-cards' ), 'Ισχύει έως {date}.', 'Valid until {date}.' ),
			'email_keep'     => array( __( 'Email: φύλαξε τον κωδικό', 'gift-cards' ), 'Φύλαξε αυτό το email: για την ασφάλειά σου ο κωδικός δεν αποθηκεύεται πουθενά αλλού.', 'Keep this email: for your safety the code is not stored anywhere else.' ),
			'email_shop'     => array( __( 'Email: κουμπί', 'gift-cards' ), 'Στο κατάστημα', 'Go to the shop' ),
			'email_sent'     => array( __( 'Email αγοραστή', 'gift-cards' ), 'Η δωροκάρτα {amount} στάλθηκε στον/στην {recipient} ({email}).', 'The {amount} gift card was sent to {recipient} ({email}).' ),
			'email_remind'   => array( __( 'Email υπενθύμισης', 'gift-cards' ), 'Η δωροκάρτα σου {card} έχει υπόλοιπο {balance} και λήγει στις {date}.', 'Your gift card {card} has {balance} left and expires on {date}.' ),
			'pdf_title'      => array( __( 'PDF: τίτλος', 'gift-cards' ), 'Δωροκάρτα', 'Gift card' ),
			'pdf_code'       => array( __( 'PDF: κωδικός', 'gift-cards' ), 'Κωδικός', 'Code' ),
			'pdf_to'         => array( __( 'PDF: για', 'gift-cards' ), 'Για', 'For' ),
			'pdf_from'       => array( __( 'PDF: από', 'gift-cards' ), 'Από', 'From' ),
			'acc_bought'     => array( __( 'Λογαριασμός: αγορές', 'gift-cards' ), 'Δωροκάρτες που αγόρασες', 'Gift cards you bought' ),
			'acc_used'       => array( __( 'Λογαριασμός: χρήσεις', 'gift-cards' ), 'Δωροκάρτες που χρησιμοποίησες', 'Gift cards you used' ),
			'acc_none'       => array( __( 'Λογαριασμός: τίποτα', 'gift-cards' ), 'Δεν υπάρχει ακόμα καμία δωροκάρτα.', 'No gift cards yet.' ),
			'st_pending'     => array( __( 'Κατάσταση: προγραμματισμένη', 'gift-cards' ), 'Προγραμματισμένη', 'Scheduled' ),
			'st_review'      => array( __( 'Κατάσταση: σε έλεγχο', 'gift-cards' ), 'Σε έλεγχο', 'Under review' ),
			'st_active'      => array( __( 'Κατάσταση: ενεργή', 'gift-cards' ), 'Ενεργή', 'Active' ),
			'st_used'        => array( __( 'Κατάσταση: εξαντλήθηκε', 'gift-cards' ), 'Εξαντλήθηκε', 'Used up' ),
			'st_off'         => array( __( 'Κατάσταση: ανενεργή', 'gift-cards' ), 'Ανενεργή', 'Inactive' ),
		);
	}

	public static function get(): array {
		if ( null === self::$cache ) {
			$raw         = get_option( self::OPT, array() );
			self::$cache = self::sanitize( is_array( $raw ) ? $raw : array() );
		}
		return self::$cache;
	}

	public static function save( array $raw ): void {
		$clean = self::sanitize( $raw );
		update_option( self::OPT, $clean, false );
		self::$cache = $clean;
	}

	/** Strict validation: unknown keys dropped, every value clamped. */
	public static function sanitize( array $raw ): array {
		$d   = self::defaults();
		$out = $d;

		$out['mode'] = isset( $raw['mode'] ) && in_array( $raw['mode'], array( 'live', 'test' ), true ) ? $raw['mode'] : $d['mode'];

		if ( isset( $raw['paid_statuses'] ) && is_array( $raw['paid_statuses'] ) ) {
			$known                = array( 'processing', 'completed', 'on-hold' );
			$out['paid_statuses'] = array_values( array_intersect( $known, array_map( 'strval', $raw['paid_statuses'] ) ) );
			if ( ! $out['paid_statuses'] ) {
				$out['paid_statuses'] = $d['paid_statuses'];
			}
		}

		$ints = array(
			'delay_hours'    => array( 0, 720 ),
			'review_over'    => array( 0, 100000000 ),
			'expiry_months'  => array( 0, 120 ),
			'remind_days'    => array( 0, 90 ),
			'pending_hours'  => array( 1, 720 ),
			'max_cards'      => array( 1, 10 ),
			'limit_n'        => array( 1, 50 ),
			'limit_min'      => array( 1, 1440 ),
			'site_fail_hour' => array( 10, 100000 ),
		);
		foreach ( $ints as $k => $range ) {
			if ( isset( $raw[ $k ] ) && is_numeric( $raw[ $k ] ) ) {
				$out[ $k ] = max( $range[0], min( $range[1], (int) $raw[ $k ] ) );
			}
		}

		foreach ( array( 'no_card_for_gc', 'restore_refund', 'pdf' ) as $k ) {
			if ( isset( $raw[ $k ] ) ) {
				$out[ $k ] = (bool) $raw[ $k ];
			}
		}

		$out['pdf_size']    = isset( $raw['pdf_size'] ) && in_array( $raw['pdf_size'], array( 'a5', 'a4' ), true ) ? $raw['pdf_size'] : $d['pdf_size'];
		$out['lang']        = isset( $raw['lang'] ) && in_array( $raw['lang'], array( 'auto', 'el', 'en' ), true ) ? $raw['lang'] : $d['lang'];
		$out['delete_data'] = isset( $raw['delete_data'] ) && 'ΔΙΑΓΡΑΦΗ' === $raw['delete_data'] ? 'ΔΙΑΓΡΑΦΗ' : '';

		$out['texts'] = array();
		if ( isset( $raw['texts'] ) && is_array( $raw['texts'] ) ) {
			foreach ( self::text_defs() as $k => $def ) {
				if ( ! isset( $raw['texts'][ $k ] ) || ! is_array( $raw['texts'][ $k ] ) ) {
					continue;
				}
				$pair = array();
				foreach ( array( 'el', 'en' ) as $l ) {
					$v          = isset( $raw['texts'][ $k ][ $l ] ) && is_string( $raw['texts'][ $k ][ $l ] ) ? $raw['texts'][ $k ][ $l ] : '';
					$pair[ $l ] = mb_substr( sanitize_text_field( $v ), 0, 400 );
				}
				if ( '' !== $pair['el'] || '' !== $pair['en'] ) {
					$out['texts'][ $k ] = $pair;
				}
			}
		}
		return $out;
	}

	/** 'el' | 'en' for customer texts. */
	public static function visitor_lang(): string {
		$l = self::get()['lang'];
		if ( 'auto' === $l ) {
			$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
			if ( ! is_admin() || wp_doing_ajax() ) {
				$locale = get_locale();
			}
			return 0 === strpos( strtolower( (string) $locale ), 'el' ) ? 'el' : 'en';
		}
		return $l;
	}

	/** A customer text, raw (escape on output). */
	public static function text( string $key, string $lang = '' ): string {
		$defs = self::text_defs();
		if ( ! isset( $defs[ $key ] ) ) {
			return '';
		}
		$lang = in_array( $lang, array( 'el', 'en' ), true ) ? $lang : self::visitor_lang();
		$set  = self::get()['texts'][ $key ] ?? array();
		$el   = '' !== ( $set['el'] ?? '' ) ? $set['el'] : $defs[ $key ][1];
		$en   = '' !== ( $set['en'] ?? '' ) ? $set['en'] : $defs[ $key ][2];
		$txt  = 'en' === $lang ? ( '' !== $en ? $en : $el ) : $el;
		return (string) apply_filters( 'gfc_text', $txt, $key, $lang );
	}

	/**
	 * A customer text with placeholders filled; the result is safe HTML
	 * (the text and every value are escaped).
	 *
	 * @param array<string,string> $vars Placeholder => plain value.
	 */
	public static function fill( string $key, array $vars = array(), string $lang = '' ): string {
		$out = esc_html( self::text( $key, $lang ) );
		foreach ( $vars as $k => $v ) {
			$out = str_replace( '{' . $k . '}', esc_html( (string) $v ), $out );
		}
		return (string) preg_replace( '/\{[a-z_]+\}/', '', $out );
	}

	/** Same as fill(), as plain text (emails in plain format, PDF). */
	public static function fill_plain( string $key, array $vars = array(), string $lang = '' ): string {
		$out = self::text( $key, $lang );
		foreach ( $vars as $k => $v ) {
			$out = str_replace( '{' . $k . '}', (string) $v, $out );
		}
		return trim( (string) preg_replace( '/\{[a-z_]+\}/', '', $out ) );
	}

	/** Cents → formatted price (plain text, no HTML). */
	public static function money( int $cents, string $currency = '' ): string {
		$price = wc_price( $cents / 100, $currency ? array( 'currency' => $currency ) : array() );
		return html_entity_decode( wp_strip_all_tags( $price ), ENT_QUOTES, 'UTF-8' );
	}

	/** A decimal string from a form or setting → cents (or null when not a valid amount). */
	public static function to_cents( string $value ): ?int {
		$v = str_replace( array( ' ', "\xC2\xA0" ), '', trim( $value ) );
		$v = str_replace( ',', '.', $v );
		if ( '' === $v || ! preg_match( '/^\d{1,9}(\.\d{1,2})?$/', $v ) ) {
			return null;
		}
		$parts = explode( '.', $v );
		$cents = (int) $parts[0] * 100;
		if ( isset( $parts[1] ) ) {
			$cents += (int) str_pad( $parts[1], 2, '0' );
		}
		return $cents;
	}

	/** Test mode: redemption only for shop managers. */
	public static function can_redeem_now(): bool {
		if ( Gift_Cards::killed() ) {
			return false;
		}
		return 'live' === self::get()['mode'] || current_user_can( 'manage_woocommerce' );
	}
}
