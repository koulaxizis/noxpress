<?php
/**
 * FRM_Fields — the field types: schema, markup and server-side validation.
 *
 * Types: text, email, tel, url, number, date, textarea, select, radio,
 * checkboxes, consent, hidden (page address, product, fixed value) and note
 * (text shown in the form, nothing is sent).
 *
 * The browser sends frm[{key}]; choices are sent as option indices, so a
 * visitor can only pick an option of the form. Everything is validated
 * again here; nothing from the browser is trusted, HTML5 attributes are
 * only hints. Hidden page / product values are filled on the server from
 * the context (never read from the browser as text).
 */

defined( 'ABSPATH' ) || exit;

final class FRM_Fields {

	const TYPES = array( 'text', 'email', 'tel', 'url', 'number', 'date', 'textarea', 'select', 'radio', 'checkboxes', 'consent', 'hidden', 'note' );

	/** Types that carry a value the visitor types or picks. */
	const INPUTS = array( 'text', 'email', 'tel', 'url', 'number', 'date', 'textarea', 'select', 'radio', 'checkboxes', 'consent' );

	/** Default and maximum length (characters) of text values. */
	const MAX_TEXT     = 200;
	const MAX_TEXTAREA = 5000;
	const MAX_LIMIT    = 10000;
	const MAX_OPTIONS  = 50;

	/** Type labels for the builder (msgids). */
	public static function type_labels(): array {
		return array(
			'text'       => __( 'Κείμενο', 'forms' ),
			'email'      => 'Email',
			'tel'        => __( 'Τηλέφωνο', 'forms' ),
			'url'        => __( 'Διεύθυνση (URL)', 'forms' ),
			'number'     => __( 'Αριθμός', 'forms' ),
			'date'       => __( 'Ημερομηνία', 'forms' ),
			'textarea'   => __( 'Μεγάλο κείμενο', 'forms' ),
			'select'     => __( 'Λίστα', 'forms' ),
			'radio'      => __( 'Μία επιλογή', 'forms' ),
			'checkboxes' => __( 'Πολλές επιλογές', 'forms' ),
			'consent'    => __( 'Συναίνεση', 'forms' ),
			'hidden'     => __( 'Κρυφό', 'forms' ),
			'note'       => __( 'Κείμενο στη φόρμα', 'forms' ),
		);
	}

	/** An empty field of a type, with sensible defaults. */
	public static function blank( string $type ): array {
		$type = in_array( $type, self::TYPES, true ) ? $type : 'text';
		return array(
			'id'       => self::new_id(),
			'key'      => '',
			'type'     => $type,
			'label_el' => '',
			'label_en' => '',
			'ph_el'    => '',
			'ph_en'    => '',
			'help_el'  => '',
			'help_en'  => '',
			'required' => in_array( $type, array( 'email', 'consent' ), true ),
			'width'    => 'full',
			'max'      => 'textarea' === $type ? self::MAX_TEXTAREA : self::MAX_TEXT,
			'nmin'     => '',
			'nmax'     => '',
			'options'  => array(),
			'source'   => 'page',
			'value'    => '',
		);
	}

	public static function new_id(): string {
		return 'f' . substr( strtolower( wp_generate_password( 8, false, false ) ), 0, 7 );
	}

	/** A clean key (input name / placeholder): a–z, 0–9, _ and -, at most 40. */
	public static function clean_key( string $k ): string {
		$k = strtolower( remove_accents( $k ) );
		$k = preg_replace( '/[^a-z0-9_-]+/', '-', $k );
		$k = trim( (string) $k, '-_' );
		return substr( (string) $k, 0, 40 );
	}

	private static function str( array $raw, string $k, int $max, bool $multi = false ): string {
		if ( ! isset( $raw[ $k ] ) || ! is_scalar( $raw[ $k ] ) ) {
			return '';
		}
		$v = $multi ? sanitize_textarea_field( (string) $raw[ $k ] ) : sanitize_text_field( (string) $raw[ $k ] );
		return mb_substr( trim( $v ), 0, $max );
	}

	private static function num( $v ): string {
		if ( ! is_scalar( $v ) || '' === trim( (string) $v ) ) {
			return '';
		}
		$v = str_replace( ',', '.', trim( (string) $v ) );
		return is_numeric( $v ) ? (string) ( 0 + $v ) : '';
	}

	/**
	 * One field from the builder or an import, cleaned. Keys are made unique
	 * by the caller (FRM_Forms::sanitize).
	 */
	public static function sanitize( array $raw ): array {
		$type = isset( $raw['type'] ) && is_string( $raw['type'] ) && in_array( $raw['type'], self::TYPES, true ) ? $raw['type'] : 'text';
		$f    = self::blank( $type );

		$id      = isset( $raw['id'] ) && is_string( $raw['id'] ) ? preg_replace( '/[^a-z0-9]/', '', strtolower( $raw['id'] ) ) : '';
		$f['id'] = ( is_string( $id ) && strlen( $id ) >= 4 && strlen( $id ) <= 12 ) ? $id : self::new_id();

		foreach ( array( 'label_el', 'label_en' ) as $k ) {
			$f[ $k ] = self::str( $raw, $k, 300 );
		}
		foreach ( array( 'ph_el', 'ph_en' ) as $k ) {
			$f[ $k ] = self::str( $raw, $k, 150 );
		}
		foreach ( array( 'help_el', 'help_en' ) as $k ) {
			$f[ $k ] = self::str( $raw, $k, 'note' === $type ? 1500 : 300, 'note' === $type );
		}
		$key      = isset( $raw['key'] ) && is_string( $raw['key'] ) ? self::clean_key( $raw['key'] ) : '';
		$f['key'] = '' !== $key ? $key : self::clean_key( '' !== $f['label_en'] ? $f['label_en'] : $f['label_el'] );

		$f['required'] = in_array( $type, array( 'hidden', 'note' ), true ) ? false : ! empty( $raw['required'] );
		if ( 'consent' === $type ) {
			$f['required'] = true; // A consent box is always required.
		}
		$f['width'] = isset( $raw['width'] ) && 'half' === $raw['width'] ? 'half' : 'full';

		$def      = 'textarea' === $type ? self::MAX_TEXTAREA : self::MAX_TEXT;
		$max      = isset( $raw['max'] ) && is_numeric( $raw['max'] ) ? (int) $raw['max'] : $def;
		$f['max'] = max( 1, min( self::MAX_LIMIT, $max > 0 ? $max : $def ) );

		$f['nmin'] = self::num( $raw['nmin'] ?? '' );
		$f['nmax'] = self::num( $raw['nmax'] ?? '' );

		$opts = array();
		if ( isset( $raw['options'] ) && is_array( $raw['options'] ) ) {
			foreach ( $raw['options'] as $o ) {
				if ( is_string( $o ) ) {
					$o = array( 'el' => $o );
				}
				if ( ! is_array( $o ) ) {
					continue;
				}
				$el = self::str( $o, 'el', 200 );
				$en = self::str( $o, 'en', 200 );
				if ( '' === $el && '' === $en ) {
					continue;
				}
				$opts[] = array(
					'el' => '' !== $el ? $el : $en,
					'en' => $en,
				);
				if ( count( $opts ) >= self::MAX_OPTIONS ) {
					break;
				}
			}
		} elseif ( isset( $raw['options_text'] ) && is_string( $raw['options_text'] ) ) {
			// Builder: one option per line, "Ελληνικά | English".
			foreach ( preg_split( '/\r\n|\r|\n/', $raw['options_text'] ) as $line ) {
				$parts = array_map( 'trim', explode( '|', (string) $line, 2 ) );
				$el    = mb_substr( sanitize_text_field( $parts[0] ), 0, 200 );
				$en    = isset( $parts[1] ) ? mb_substr( sanitize_text_field( $parts[1] ), 0, 200 ) : '';
				if ( '' === $el && '' === $en ) {
					continue;
				}
				$opts[] = array(
					'el' => '' !== $el ? $el : $en,
					'en' => $en,
				);
				if ( count( $opts ) >= self::MAX_OPTIONS ) {
					break;
				}
			}
		}
		$f['options'] = in_array( $type, array( 'select', 'radio', 'checkboxes' ), true ) ? $opts : array();

		$src         = isset( $raw['source'] ) && is_string( $raw['source'] ) ? $raw['source'] : '';
		$f['source'] = in_array( $src, array( 'page', 'product', 'static' ), true ) ? $src : 'page';
		$f['value']  = 'hidden' === $type ? self::str( $raw, 'value', 200 ) : '';

		return $f;
	}

	/** The options of a field as builder text, one per line. */
	public static function options_text( array $f ): string {
		$lines = array();
		foreach ( $f['options'] as $o ) {
			$lines[] = $o['el'] . ( '' !== $o['en'] ? ' | ' . $o['en'] : '' );
		}
		return implode( "\n", $lines );
	}

	public static function label( array $f, string $lang ): string {
		$l = FRM_Settings::pick( $f['label_el'], $f['label_en'], $lang );
		if ( '' === $l && 'consent' === $f['type'] ) {
			$l = 'en' === $lang
				? 'I agree with the {privacy} and that my details are kept to answer me.'
				: 'Συμφωνώ με την {privacy} και να κρατηθούν τα στοιχεία μου για να λάβω απάντηση.';
		}
		return $l;
	}

	/** The label as plain text (e-mails, entries): {privacy} becomes the link text. */
	public static function plain_label( array $f, string $lang ): string {
		$l = self::label( $f, $lang );
		return str_replace( '{privacy}', FRM_Settings::text( 'privacy', $lang ), '' !== $l ? $l : $f['key'] );
	}

	/** The escaped label HTML, with {privacy} as a link to the privacy page. */
	private static function label_html( array $f, string $lang ): string {
		$h   = esc_html( self::label( $f, $lang ) );
		$url = function_exists( 'get_privacy_policy_url' ) ? (string) get_privacy_policy_url() : '';
		$txt = esc_html( FRM_Settings::text( 'privacy', $lang ) );
		$lnk = '' !== $url ? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . $txt . '</a>' : $txt;
		return str_replace( '{privacy}', $lnk, $h );
	}

	private static function autocomplete( array $f ): string {
		switch ( $f['type'] ) {
			case 'email':
				return 'email';
			case 'tel':
				return 'tel';
			case 'url':
				return 'url';
		}
		if ( 'text' === $f['type'] ) {
			if ( preg_match( '/(^|[-_])(name|onoma|fullname)([-_]|$)/', $f['key'] ) ) {
				return 'name';
			}
			if ( preg_match( '/(^|[-_])(company|etaireia)([-_]|$)/', $f['key'] ) ) {
				return 'organization';
			}
		}
		return 'off';
	}

	/**
	 * The markup of one field.
	 *
	 * @param array  $f     Field.
	 * @param string $uid   Form instance id (unique on the page).
	 * @param string $lang  'el' | 'en'.
	 * @param mixed  $value Value to show again (no-JS post with errors).
	 * @param string $error Error text for this field ('' = none).
	 */
	public static function render( array $f, string $uid, string $lang, $value = '', string $error = '' ): string {
		$type = $f['type'];
		if ( 'hidden' === $type ) {
			return ''; // Filled on the server from the context.
		}
		$id    = $uid . '-' . $f['id'];
		$err   = $id . '-err';
		$help  = FRM_Settings::pick( $f['help_el'], $f['help_en'], $lang );
		$desc  = '' !== $help ? $id . '-help' : '';
		$cls   = 'frm-field frm-field--' . $type . ( 'half' === $f['width'] ? ' frm-half' : '' ) . ( '' !== $error ? ' is-error' : '' );
		$req   = $f['required'] ? ' required aria-required="true"' : '';
		$descr = trim( $desc . ' ' . $err );
		$aria  = ' aria-describedby="' . esc_attr( $descr ) . '"' . ( '' !== $error ? ' aria-invalid="true"' : '' );
		$mark  = $f['required'] ? ' <span class="frm-req" aria-hidden="true">*</span><span class="frm-sr"> (' . esc_html( FRM_Settings::text( 'required_mark', $lang ) ) . ')</span>' : '';
		$name  = 'frm[' . $f['key'] . ']';
		$ph    = FRM_Settings::pick( $f['ph_el'], $f['ph_en'], $lang );
		$phat  = '' !== $ph ? ' placeholder="' . esc_attr( $ph ) . '"' : '';
		$label = self::label_html( $f, $lang );

		if ( 'note' === $type ) {
			$text = FRM_Settings::pick( $f['help_el'], $f['help_en'], $lang );
			if ( '' === $text && '' === $label ) {
				return '';
			}
			$h = '<div class="frm-field frm-field--note">';
			if ( '' !== $label ) {
				$h .= '<p class="frm-note-title"><strong>' . $label . '</strong></p>';
			}
			if ( '' !== $text ) {
				$h .= '<p class="frm-note">' . nl2br( esc_html( $text ) ) . '</p>';
			}
			return $h . '</div>';
		}

		$h = '<div class="' . esc_attr( $cls ) . '" data-key="' . esc_attr( $f['key'] ) . '">';

		if ( in_array( $type, array( 'radio', 'checkboxes' ), true ) ) {
			$h .= '<fieldset class="frm-set"' . $aria . '><legend class="frm-label">' . $label . $mark . '</legend>';
			$cur = is_array( $value ) ? array_map( 'strval', $value ) : array( (string) $value );
			foreach ( $f['options'] as $i => $o ) {
				$oid = $id . '-' . $i;
				$in  = 'radio' === $type
					? '<input type="radio" id="' . esc_attr( $oid ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $i ) . '"' . ( in_array( (string) $i, $cur, true ) ? ' checked' : '' ) . ( $f['required'] && 0 === $i ? ' required' : '' ) . ' />'
					: '<input type="checkbox" id="' . esc_attr( $oid ) . '" name="' . esc_attr( $name . '[]' ) . '" value="' . esc_attr( (string) $i ) . '"' . ( in_array( (string) $i, $cur, true ) ? ' checked' : '' ) . ' />';
				$h  .= '<label class="frm-choice" for="' . esc_attr( $oid ) . '">' . $in . '<span class="frm-box" aria-hidden="true"></span><span>' . esc_html( FRM_Settings::pick( $o['el'], $o['en'], $lang ) ) . '</span></label>';
			}
			$h .= '</fieldset>';
		} elseif ( 'consent' === $type ) {
			$h .= '<label class="frm-choice frm-consent" for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . ( '1' === (string) $value ? ' checked' : '' ) . $req . $aria . ' /><span class="frm-box" aria-hidden="true"></span><span>' . $label . $mark . '</span></label>';
		} else {
			$h .= '<label class="frm-label" for="' . esc_attr( $id ) . '">' . $label . $mark . '</label>';
			$v  = is_scalar( $value ) ? (string) $value : '';
			if ( 'textarea' === $type ) {
				$h .= '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="6" maxlength="' . (int) $f['max'] . '"' . $phat . $req . $aria . '>' . esc_textarea( $v ) . '</textarea>';
			} elseif ( 'select' === $type ) {
				$h .= '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $req . $aria . '>';
				$h .= '<option value="">' . esc_html( '' !== $ph ? $ph : '—' ) . '</option>';
				foreach ( $f['options'] as $i => $o ) {
					$h .= '<option value="' . esc_attr( (string) $i ) . '"' . selected( (string) $i, $v, false ) . '>' . esc_html( FRM_Settings::pick( $o['el'], $o['en'], $lang ) ) . '</option>';
				}
				$h .= '</select>';
			} else {
				$extra = '';
				if ( 'number' === $type ) {
					$extra .= '' !== $f['nmin'] ? ' min="' . esc_attr( $f['nmin'] ) . '"' : '';
					$extra .= '' !== $f['nmax'] ? ' max="' . esc_attr( $f['nmax'] ) . '"' : '';
					$extra .= ' step="any" inputmode="decimal"';
				} else {
					$extra .= ' maxlength="' . (int) min( $f['max'], 'email' === $type ? 254 : $f['max'] ) . '"';
				}
				$ac   = self::autocomplete( $f );
				$h   .= '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $v ) . '" autocomplete="' . esc_attr( $ac ) . '"' . $extra . $phat . $req . $aria . ' />';
			}
		}
		if ( '' !== $help ) {
			$h .= '<p class="frm-help" id="' . esc_attr( $desc ) . '">' . esc_html( $help ) . '</p>';
		}
		$h .= '<p class="frm-error" id="' . esc_attr( $err ) . '"' . ( '' === $error ? ' hidden' : '' ) . '>' . esc_html( $error ) . '</p>';
		return $h . '</div>';
	}

	/**
	 * Validate one visitor value.
	 *
	 * @param array  $f    Field.
	 * @param mixed  $raw  What the browser sent for frm[key] (unslashed).
	 * @param string $lang 'el' | 'en'.
	 * @return array array( value, error key of FRM_Settings::texts() or '' ). The value is
	 *               a string (choices: the option labels as the visitor saw them, joined with ", ").
	 */
	public static function validate( array $f, $raw, string $lang ): array {
		$type = $f['type'];

		if ( 'checkboxes' === $type ) {
			$picked = array();
			foreach ( is_array( $raw ) ? $raw : array() as $i ) {
				if ( is_scalar( $i ) && ctype_digit( (string) $i ) && isset( $f['options'][ (int) $i ] ) ) {
					$picked[ (int) $i ] = FRM_Settings::pick( $f['options'][ (int) $i ]['el'], $f['options'][ (int) $i ]['en'], $lang );
				}
			}
			ksort( $picked );
			if ( ! $picked ) {
				return array( '', $f['required'] ? 'err_required' : '' );
			}
			return array( implode( ', ', $picked ), '' );
		}

		$v = is_scalar( $raw ) ? (string) $raw : '';

		if ( 'select' === $type || 'radio' === $type ) {
			if ( '' === $v ) {
				return array( '', $f['required'] ? 'err_required' : '' );
			}
			if ( ! ctype_digit( $v ) || ! isset( $f['options'][ (int) $v ] ) ) {
				return array( '', 'err_choice' );
			}
			$o = $f['options'][ (int) $v ];
			return array( FRM_Settings::pick( $o['el'], $o['en'], $lang ), '' );
		}

		if ( 'consent' === $type ) {
			if ( '1' !== $v ) {
				return array( '', 'err_consent' );
			}
			return array( 'en' === $lang ? 'Yes' : 'Ναι', '' );
		}

		$multi = 'textarea' === $type;
		$v     = $multi ? sanitize_textarea_field( $v ) : sanitize_text_field( $v );
		$v     = trim( $v );
		if ( '' === $v ) {
			return array( '', $f['required'] ? 'err_required' : '' );
		}
		if ( mb_strlen( $v ) > (int) $f['max'] ) {
			return array( '', 'err_long' );
		}

		switch ( $type ) {
			case 'email':
				$e = strtolower( sanitize_email( $v ) );
				if ( strlen( $e ) > 254 || ! is_email( $e ) ) {
					return array( '', 'err_email' );
				}
				return array( $e, '' );
			case 'tel':
				$t = preg_replace( '/[^0-9+()\-.\s\/]/', '', $v );
				if ( preg_match_all( '/[0-9]/', (string) $t ) < 5 || strlen( (string) $t ) > 40 || (string) $t !== $v ) {
					return array( '', 'err_tel' );
				}
				return array( trim( (string) $t ), '' );
			case 'url':
				$u = esc_url_raw( $v, array( 'http', 'https' ) );
				if ( '' === $u || ! wp_http_validate_url( $u ) ) {
					return array( '', 'err_url' );
				}
				return array( $u, '' );
			case 'number':
				$n = str_replace( ',', '.', $v );
				if ( ! is_numeric( $n ) ) {
					return array( '', 'err_number' );
				}
				$n = 0 + $n;
				if ( ( '' !== $f['nmin'] && $n < (float) $f['nmin'] ) || ( '' !== $f['nmax'] && $n > (float) $f['nmax'] ) ) {
					return array( '', 'err_number' );
				}
				return array( (string) $n, '' );
			case 'date':
				$d = DateTime::createFromFormat( '!Y-m-d', $v );
				if ( ! $d || $d->format( 'Y-m-d' ) !== $v ) {
					return array( '', 'err_date' );
				}
				return array( $v, '' );
		}
		return array( $v, '' );
	}
}
