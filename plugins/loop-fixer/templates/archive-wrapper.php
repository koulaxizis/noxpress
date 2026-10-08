<?php
/**
 * Loop Fixer — archive wrapper (text fixes only).
 *
 * Loaded through template_include in place of the archive template when
 * the active theme has text fixes. Runs the real template inside a buffer
 * whose handler (LF_Replace::text_fixes) applies the find → replace pairs.
 * Template loader scope is global, so no local variables are introduced.
 */

defined( 'ABSPATH' ) || exit;

if ( '' === LF_Replace::wrapped() || ! is_file( LF_Replace::wrapped() ) ) {
	return;
}

ob_start( array( 'LF_Replace', 'text_fixes' ) );
$GLOBALS['lf_wrapper_level'] = ob_get_level();

include LF_Replace::wrapped();

if ( ob_get_level() === $GLOBALS['lf_wrapper_level'] && LF_Replace::HANDLER === current( array_slice( ob_list_handlers(), -1 ) ) ) {
	ob_end_flush();
}
unset( $GLOBALS['lf_wrapper_level'] );
