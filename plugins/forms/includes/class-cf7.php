<?php
/**
 * FRM_CF7 — import from Contact Form 7 (Chris 2026-11-16, Q8).
 *
 * Reads only: the wpcf7_contact_form posts and their meta _form, _mail,
 * _mail_2, _messages and _hash. Contact Form 7 does not need to be active
 * (its posts stay in the database after it is turned off). Nothing of
 * Contact Form 7 is changed or deleted.
 *
 * Each import makes a Forms form (inactive until the owner checks it) and
 * records in the option frm_cf7_map the Contact Form 7 id and hash
 * (full and the 7 characters of the shortcode) => the new form id, so
 * [contact-form-7 id="…"] keeps working once Contact Form 7 is off
 * (FRM_Front::compat_shortcode).
 *
 * Not imported, listed in the report: file uploads, quizzes, CAPTCHA and
 * reCAPTCHA tags, the second mail (auto-reply, which Forms keeps off and
 * with fixed text), conditional fields and custom HTML around the fields.
 */

defined( 'ABSPATH' ) || exit;

final class FRM_CF7 {

	const PT  = 'wpcf7_contact_form';
	const MAP = 'frm_cf7_map';

	/** Tags that become a field: Contact Form 7 type => Forms type. */
	const TYPES = array(
		'text'       => 'text',
		'email'      => 'email',
		'tel'        => 'tel',
		'url'        => 'url',
		'number'     => 'number',
		'range'      => 'number',
		'date'       => 'date',
		'textarea'   => 'textarea',
		'select'     => 'select',
		'checkbox'   => 'checkboxes',
		'radio'      => 'radio',
		'acceptance' => 'consent',
		'hidden'     => 'hidden',
	);

	/** Tags that are not imported (named in the report). */
	const SKIPPED = array( 'file', 'quiz', 'captchac', 'captchar', 'recaptcha', 'cf7sr-simple-recaptcha', 'turnstile', 'stripe', 'count', 'response', 'group' );

	/**
	 * The Contact Form 7 forms found in the database.
	 *
	 * @return array[] id, title, hash, short (7 characters), imported (Forms id or 0).
	 */
	public static function found(): array {
		$posts = get_posts(
			array(
				'post_type'      => self::PT,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'suppress_filters' => true,
			)
		);
		$map = self::map();
		$out = array();
		foreach ( $posts as $p ) {
			$hash  = strtolower( preg_replace( '/[^a-f0-9]/i', '', (string) get_post_meta( $p->ID, '_hash', true ) ) );
			$fid   = isset( $map[ (string) $p->ID ] ) ? (int) $map[ (string) $p->ID ] : 0;
			$out[] = array(
				'id'       => (int) $p->ID,
				'title'    => (string) $p->post_title,
				'hash'     => $hash,
				'short'    => substr( $hash, 0, 7 ),
				'imported' => $fid > 0 && FRM_Forms::get( $fid ) ? $fid : 0,
			);
		}
		return $out;
	}

	/** True when Contact Form 7 itself is active. */
	public static function active(): bool {
		return defined( 'WPCF7_VERSION' ) || class_exists( 'WPCF7' );
	}

	/** The shortcode map: cf7 id / hash => Forms id. */
	public static function map(): array {
		$m = get_option( self::MAP, array() );
		return is_array( $m ) ? $m : array();
	}

	/** Remove the map rows of a Forms form (when it is deleted). */
	public static function unmap( int $form_id ): void {
		$map = self::map();
		$new = array_filter(
			$map,
			static function ( $v ) use ( $form_id ) {
				return (int) $v !== $form_id;
			}
		);
		if ( count( $new ) !== count( $map ) ) {
			if ( $new ) {
				update_option( self::MAP, $new, true );
			} else {
				delete_option( self::MAP );
			}
		}
	}

	/* =====================================================================
	 * Parsing
	 * =================================================================== */

	/**
	 * The tags of a form template, in order.
	 *
	 * @return array[] type, required, name, options (bare words), values (quoted), content, raw, pos.
	 */
	public static function tags( string $tpl ): array {
		$out = array();
		// [type* name opt opt "value" "value"] and [acceptance name] content [/acceptance].
		$re = '/\[\s*([a-zA-Z][a-zA-Z0-9_-]*)(\*?)(?:\s+((?:[^\]"\']|"[^"]*"|\'[^\']*\')*))?\s*\](?:(.*?)\[\/\1\])?/s';
		if ( ! preg_match_all( $re, $tpl, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return $out;
		}
		foreach ( $m as $t ) {
			$type = strtolower( $t[1][0] );
			$args = isset( $t[3] ) ? (string) $t[3][0] : '';
			preg_match_all( '/"([^"]*)"|\'([^\']*)\'|(\S+)/', $args, $parts, PREG_SET_ORDER );
			$bare   = array();
			$quoted = array();
			foreach ( $parts as $p ) {
				if ( isset( $p[3] ) && '' !== $p[3] ) {
					$bare[] = $p[3];
				} else {
					$quoted[] = isset( $p[2] ) && '' !== $p[2] ? $p[2] : $p[1];
				}
			}
			$name  = 'submit' === $type ? '' : (string) array_shift( $bare );
			$out[] = array(
				'type'     => $type,
				'required' => '*' === $t[2][0],
				'name'     => $name,
				'options'  => $bare,
				'values'   => $quoted,
				'content'  => isset( $t[4] ) ? trim( (string) $t[4][0] ) : '',
				'raw'      => $t[0][0],
				'pos'      => (int) $t[0][1],
			);
		}
		return $out;
	}

	/** A bare option value: "min:1" => "1". */
	private static function opt( array $tag, string $name ): string {
		foreach ( $tag['options'] as $o ) {
			if ( 0 === strpos( $o, $name . ':' ) ) {
				return substr( $o, strlen( $name ) + 1 );
			}
		}
		return '';
	}

	private static function has_opt( array $tag, string $name ): bool {
		return in_array( $name, $tag['options'], true );
	}

	/** Plain text of a piece of template: no tags, no shortcodes, single spaces. */
	private static function plain( string $html ): string {
		$t = preg_replace( '/\[[^\]]*\]/', ' ', $html );
		$t = wp_strip_all_tags( (string) $t );
		$t = html_entity_decode( $t, ENT_QUOTES, 'UTF-8' );
		$t = preg_replace( '/\s+/u', ' ', $t );
		return trim( (string) $t, " \t\n\r\0\x0B:*" );
	}

	/** The label of a tag: its <label> text, else the text just before it, else its placeholder. */
	private static function label_for( string $tpl, array $tag, string $placeholder ): string {
		// Inside <label>…</label>.
		if ( preg_match_all( '/<label\b[^>]*>(.*?)<\/label>/is', $tpl, $labels, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $labels[0] as $i => $whole ) {
				$start = (int) $whole[1];
				$end   = $start + strlen( $whole[0] );
				if ( $tag['pos'] >= $start && $tag['pos'] < $end ) {
					$text = self::plain( str_replace( $tag['raw'], ' ', $labels[1][ $i ][0] ) );
					if ( '' !== $text ) {
						return mb_substr( $text, 0, 300 );
					}
				}
			}
		}
		// The text since the previous tag or block boundary.
		$before = substr( $tpl, 0, $tag['pos'] );
		$cut    = 0;
		foreach ( array( ']', '</p>', '</div>', '</label>', '<p>', '<div>' ) as $edge ) {
			$at = strrpos( $before, $edge );
			if ( false !== $at ) {
				$cut = max( $cut, $at + strlen( $edge ) );
			}
		}
		$text = self::plain( substr( $before, $cut ) );
		if ( '' !== $text && mb_strlen( $text ) <= 120 ) {
			return $text;
		}
		return $placeholder;
	}

	/** A choice value: "Label|value" keeps the label. */
	private static function choice( string $v ): string {
		$p = explode( '|', $v, 2 );
		return trim( $p[0] );
	}

	/**
	 * A Forms schema from a Contact Form 7 form.
	 *
	 * @param int $cf7_id The wpcf7_contact_form post.
	 * @return array|null schema, title, notes (string[]), or null when not found.
	 */
	public static function convert( int $cf7_id ): ?array {
		$p = get_post( $cf7_id );
		if ( ! $p || self::PT !== $p->post_type ) {
			return null;
		}
		$tpl   = (string) get_post_meta( $cf7_id, '_form', true );
		$mail  = get_post_meta( $cf7_id, '_mail', true );
		$mail2 = get_post_meta( $cf7_id, '_mail_2', true );
		$msgs  = get_post_meta( $cf7_id, '_messages', true );
		$hash  = strtolower( preg_replace( '/[^a-f0-9]/i', '', (string) get_post_meta( $cf7_id, '_hash', true ) ) );
		$mail  = is_array( $mail ) ? $mail : array();
		$msgs  = is_array( $msgs ) ? $msgs : array();

		$s       = FRM_Forms::blank();
		$notes   = array();
		$skipped = array();
		$fields  = array();
		$keys    = array();

		foreach ( self::tags( $tpl ) as $tag ) {
			$type = $tag['type'];
			if ( 'submit' === $type ) {
				if ( $tag['values'] ) {
					$s['texts']['submit_el'] = mb_substr( sanitize_text_field( $tag['values'][0] ), 0, 60 );
				}
				continue;
			}
			if ( in_array( $type, self::SKIPPED, true ) || ! isset( self::TYPES[ $type ] ) ) {
				$skipped[] = '[' . $type . ( '' !== $tag['name'] ? ' ' . $tag['name'] : '' ) . ']';
				continue;
			}
			$ftype = self::TYPES[ $type ];
			if ( 'select' === $type && self::has_opt( $tag, 'multiple' ) ) {
				$ftype = 'checkboxes';
			}
			$placeholder = '';
			if ( in_array( $ftype, array( 'text', 'email', 'tel', 'url', 'number', 'date', 'textarea' ), true )
				&& ( self::has_opt( $tag, 'placeholder' ) || self::has_opt( $tag, 'watermark' ) ) && $tag['values'] ) {
				$placeholder = mb_substr( sanitize_text_field( $tag['values'][0] ), 0, 150 );
			}
			$f             = FRM_Fields::blank( $ftype );
			$f['key']      = FRM_Fields::clean_key( $tag['name'] );
			$f['required'] = $tag['required'];
			$f['ph_el']    = $placeholder;

			if ( 'consent' === $ftype ) {
				$text          = self::plain( $tag['content'] );
				$f['label_el'] = mb_substr( '' !== $text ? $text : self::label_for( $tpl, $tag, '' ), 0, 300 );
				$f['required'] = true;
				if ( self::has_opt( $tag, 'optional' ) ) {
					$notes[] = sprintf(
						/* translators: %s: field name */
						__( 'Η συναίνεση «%s» ήταν προαιρετική στο Contact Form 7· στο Forms είναι πάντα υποχρεωτική.', 'forms' ),
						$tag['name']
					);
				}
			} elseif ( 'hidden' === $ftype ) {
				$f['source']   = 'static';
				$f['value']    = $tag['values'] ? mb_substr( sanitize_text_field( $tag['values'][0] ), 0, 200 ) : '';
				$f['label_el'] = $tag['name'];
			} else {
				$f['label_el'] = self::label_for( $tpl, $tag, $placeholder );
			}
			if ( '' === $f['label_el'] ) {
				$f['label_el'] = ucfirst( str_replace( array( '-', '_' ), ' ', $tag['name'] ) );
			}

			if ( in_array( $ftype, array( 'select', 'radio', 'checkboxes' ), true ) ) {
				$opts = array();
				foreach ( $tag['values'] as $v ) {
					$v = self::choice( $v );
					if ( '' !== $v ) {
						$opts[] = array(
							'el' => $v,
							'en' => '',
						);
					}
				}
				if ( 'checkboxes' === $ftype && 1 === count( $opts ) && ! $tag['required'] ) {
					// A single optional box: a yes/no, kept as a one-option choice.
					$notes[] = sprintf(
						/* translators: %s: field name */
						__( 'Το «%s» είναι ένα μόνο κουτάκι: έγινε «Πολλές επιλογές» με μία επιλογή.', 'forms' ),
						$tag['name']
					);
				}
				if ( self::has_opt( $tag, 'first_as_label' ) && $opts ) {
					array_shift( $opts ); // The first value was the empty choice's label.
				}
				$f['options'] = $opts;
			}
			if ( 'number' === $ftype ) {
				$f['nmin'] = self::opt( $tag, 'min' );
				$f['nmax'] = self::opt( $tag, 'max' );
			}
			$maxlen = self::opt( $tag, 'maxlength' );
			if ( '' !== $maxlen && ctype_digit( $maxlen ) ) {
				$f['max'] = (int) $maxlen;
			}
			if ( in_array( $f['key'], $keys, true ) ) {
				continue; // The same name twice (e.g. a field repeated): keep the first.
			}
			$keys[]   = $f['key'];
			$fields[] = $f;
		}
		$s['fields'] = $fields;

		// Mail: recipient, subject, reply-to (the e-mail field), sender name.
		$admin = (string) get_option( 'admin_email' );
		$to    = isset( $mail['recipient'] ) ? (string) $mail['recipient'] : '';
		$to    = str_replace( array( '[_site_admin_email]', '[_site_admin_email ]' ), $admin, $to );
		if ( false !== strpos( $to, '[' ) ) {
			$notes[] = __( 'Ο παραλήπτης του Contact Form 7 είχε πεδίο της φόρμας: μπήκε η διεύθυνση του site.', 'forms' );
			$to      = preg_replace( '/\[[^\]]*\]/', '', $to );
		}
		$to                  = FRM_Forms::clean_emails( (string) $to );
		$s['mail']['to']     = $to === $admin ? '' : $to;
		$subject             = isset( $mail['subject'] ) ? (string) $mail['subject'] : '';
		$subject             = str_replace( array( '[_site_title]', '[_site_url]' ), array( '{site}', '{site}' ), $subject );
		$subject             = preg_replace_callback(
			'/\[\s*([a-zA-Z0-9_-]+)\s*\]/',
			static function ( $m ) use ( $keys ) {
				$k = FRM_Fields::clean_key( $m[1] );
				return in_array( $k, $keys, true ) ? '{' . $k . '}' : '';
			},
			(string) $subject
		);
		// Quotes left empty by a tag of a field the form does not have.
		$subject                 = preg_replace( '/"\s*"|«\s*»|\'\s*\'|“\s*”/u', '', (string) $subject );
		$subject                 = trim( preg_replace( '/\s+/', ' ', (string) $subject ), " \t:-–" );
		// Without any field in it (e.g. «[_site_title]» alone), the Forms default.
		$s['mail']['subject_el'] = preg_match( '/\{(?!site\})[a-z0-9_-]+\}/', $subject ) ? mb_substr( $subject, 0, 200 ) : '[{site}] {form}';

		$headers = isset( $mail['additional_headers'] ) ? (string) $mail['additional_headers'] : '';
		if ( preg_match( '/reply-to:[^\[]*\[\s*([a-zA-Z0-9_-]+)\s*\]/i', $headers, $rm ) ) {
			$s['mail']['email_key'] = FRM_Fields::clean_key( $rm[1] );
		}
		$sender = isset( $mail['sender'] ) ? (string) $mail['sender'] : '';
		if ( preg_match( '/^\s*\[\s*([a-zA-Z0-9_-]+)\s*\]/', $sender, $sm ) ) {
			$s['mail']['name_key'] = FRM_Fields::clean_key( $sm[1] );
		}
		if ( ! empty( $mail['use_html'] ) ) {
			$notes[] = __( 'Το email του Contact Form 7 ήταν σε HTML· το Forms στέλνει απλό κείμενο με όλα τα πεδία.', 'forms' );
		}
		if ( ! empty( $mail['attachments'] ) ) {
			$skipped[] = __( 'συνημμένα στο email', 'forms' );
		}

		if ( is_array( $mail2 ) && ! empty( $mail2['active'] ) ) {
			$notes[] = __( 'Το Contact Form 7 έστελνε δεύτερο email (αυτόματη απάντηση). Δεν εισήχθη: η αυτόματη απάντηση του Forms ανοίγει στη φόρμα, με σταθερό κείμενο.', 'forms' );
		}

		if ( isset( $msgs['mail_sent_ok'] ) && is_string( $msgs['mail_sent_ok'] ) && '' !== trim( $msgs['mail_sent_ok'] ) ) {
			$s['texts']['thanks_el'] = mb_substr( sanitize_text_field( $msgs['mail_sent_ok'] ), 0, 300 );
		}

		if ( ! FRM_Forms::has_type( array( 'fields' => $fields ), 'consent' ) ) {
			$notes[] = __( 'Η φόρμα δεν ζητά συναίνεση. Για εγγραφές στο orOS Newsletter χρειάζεται ένα πεδίο «Συναίνεση».', 'forms' );
		}
		if ( $skipped ) {
			$notes[] = sprintf(
				/* translators: %s: list of Contact Form 7 tags */
				__( 'Δεν εισήχθησαν: %s.', 'forms' ),
				implode( ', ', array_unique( $skipped ) )
			);
		}

		$s['cf7'] = array(
			'id'   => $cf7_id,
			'hash' => $hash,
		);
		return array(
			'schema' => FRM_Forms::sanitize( $s ),
			'title'  => (string) $p->post_title,
			'hash'   => $hash,
			'notes'  => $notes,
		);
	}

	/**
	 * Import one Contact Form 7 form (again: the earlier import is kept,
	 * a new one is made and the shortcode points to it).
	 *
	 * @return array ok, form (id), title, notes.
	 */
	public static function import( int $cf7_id ): array {
		$c = self::convert( $cf7_id );
		if ( ! $c ) {
			return array(
				'ok'    => false,
				'form'  => 0,
				'title' => '',
				'notes' => array( __( 'Η φόρμα του Contact Form 7 δεν βρέθηκε.', 'forms' ) ),
			);
		}
		if ( ! $c['schema']['fields'] ) {
			return array(
				'ok'    => false,
				'form'  => 0,
				'title' => $c['title'],
				'notes' => array_merge( array( __( 'Δεν βρέθηκε κανένα πεδίο που να μπορεί να εισαχθεί.', 'forms' ) ), $c['notes'] ),
			);
		}
		$id = FRM_Forms::save( 0, $c['title'], $c['schema'], false );
		if ( $id <= 0 ) {
			return array(
				'ok'    => false,
				'form'  => 0,
				'title' => $c['title'],
				'notes' => array( __( 'Η φόρμα δεν αποθηκεύτηκε.', 'forms' ) ),
			);
		}
		$map                      = self::map();
		$map[ (string) $cf7_id ]  = $id;
		if ( '' !== $c['hash'] ) {
			$map[ $c['hash'] ]                 = $id;
			$map[ substr( $c['hash'], 0, 7 ) ] = $id;
		}
		update_option( self::MAP, $map, true );
		return array(
			'ok'    => true,
			'form'  => $id,
			'title' => $c['title'],
			'notes' => $c['notes'],
		);
	}
}
