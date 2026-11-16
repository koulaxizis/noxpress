<?php
/**
 * LGD_XMLRPC_Server — WordPress's XML-RPC server without system.multicall.
 *
 * IXR_Server::setCallbacks() adds system.multicall after the
 * xmlrpc_methods filter has run, so removing it there is not enough.
 * Loaded only from the wp_xmlrpc_server_class filter (xmlrpc.php has
 * already included wp_xmlrpc_server), and only when no other plugin has
 * chosen its own server class. A call to system.multicall then gets the
 * standard «requested method does not exist» fault (-32601), and
 * system.listMethods no longer lists it.
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'wp_xmlrpc_server' ) && ! class_exists( 'LGD_XMLRPC_Server' ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound, Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- conditional subclass.
	class LGD_XMLRPC_Server extends wp_xmlrpc_server {

		// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- IXR's own method name.
		public function setCallbacks() {
			parent::setCallbacks();
			unset( $this->callbacks['system.multicall'] );
		}
	}
}
