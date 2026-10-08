<?php
/**
 * Theme Patcher — page wrapper.
 *
 * Loaded through template_include in place of the page template when
 * page-level rules apply to the request. Runs the real template (header
 * and footer included) inside a buffer whose handler (TP_Page::process)
 * applies the text table and the HTML rules. Template loader scope is
 * global, so no local variables are introduced.
 */

defined( 'ABSPATH' ) || exit;

if ( '' === TP_Page::wrapped() || ! is_file( TP_Page::wrapped() ) ) {
	return;
}

ob_start( array( 'TP_Page', 'process' ) );
$GLOBALS['tp_wrapper_level'] = ob_get_level();

include TP_Page::wrapped();

if ( ob_get_level() === $GLOBALS['tp_wrapper_level'] && TP_Page::HANDLER === current( array_slice( ob_list_handlers(), -1 ) ) ) {
	ob_end_flush();
}
unset( $GLOBALS['tp_wrapper_level'] );
