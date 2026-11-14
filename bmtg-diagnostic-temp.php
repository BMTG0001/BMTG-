<?php
/**
 * Plugin Name: BMTG Temporary Diagnostic (READ-ONLY, ADMIN-ONLY)
 * Description: Temporary read-only diagnostic for BMTG Business Manager. REMOVE AFTER TESTING.
 * Version: 0.1.0-temp
 * Author: Kilo
 *
 * DEPLOYMENT: Upload this single file to wp-content/mu-plugins/bmtg-diagnostic-temp.php
 * (create the mu-plugins directory if it does not exist). No other files modified.
 * REMOVAL: Delete wp-content/mu-plugins/bmtg-diagnostic-temp.php after testing.
 *
 * READ-ONLY: Does not modify encryption key, wp-config.php, database, credentials, or security settings.
 * NO SECRETS: Never outputs or logs secret/credential values.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// --- Temporary error capture (BMTG-related only, no secrets logged) ---
if ( ! isset( $GLOBALS['bmtg_diag_captured_errors'] ) ) {
	$GLOBALS['bmtg_diag_captured_errors'] = array();
}

if ( ! function_exists( 'bmtg_diag_should_capture' ) ) {
	function bmtg_diag_should_capture( $file, $message ) {
		$file    = is_string( $file ) ? $file : '';
		$message = is_string( $message ) ? $message : '';
		if ( $file !== '' && stripos( $file, 'bmtg-business-manager' ) !== false ) {
			return true;
		}
		if ( stripos( $message, 'bmtg' ) !== false ) {
			return true;
		}
		if ( strpos( $message, 'BMTG\\' ) !== false ) {
			return true;
		}
		return false;
	}
}

if ( ! function_exists( 'bmtg_diag_record_error' ) ) {
	function bmtg_diag_record_error( $type, $message, $file, $line ) {
		$entry = array(
			'type'    => $type,
			'message' => is_string( $message ) ? $message : '',
			'file'    => is_string( $file ) ? $file : '',
			'line'    => is_int( $line ) ? $line : 0,
		);
		// Redact obvious secret-like tokens just in case (defense-in-depth; no secrets expected).
		$entry['message'] = preg_replace( '/(base64:)[A-Za-z0-9+\/=]+/i', '$1[redacted]', $entry['message'] );
		$entry['message'] = preg_replace( '/(BMTG_ENCRYPTION_KEY[^\s=]*\s*[=:]\s*)\S+/i', '$1[redacted]', $entry['message'] );
		$entry['message'] = preg_replace( '/(Bearer\s+)\S+/i', '$1[redacted]', $entry['message'] );
		$GLOBALS['bmtg_diag_captured_errors'][] = $entry;
	}
}

if ( ! defined( 'BMTG_DIAG_ERROR_HANDLER_SET' ) ) {
	define( 'BMTG_DIAG_ERROR_HANDLER_SET', true );
	$prev = set_error_handler( function ( $errno, $errstr, $errfile, $errline ) use ( &$prev ) {
		if ( bmtg_diag_should_capture( $errfile, $errstr ) ) {
			$names = array(
				E_ERROR => 'E_ERROR', E_WARNING => 'E_WARNING', E_PARSE => 'E_PARSE', E_NOTICE => 'E_NOTICE',
				E_CORE_ERROR => 'E_CORE_ERROR', E_CORE_WARNING => 'E_CORE_WARNING', E_COMPILE_ERROR => 'E_COMPILE_ERROR',
				E_COMPILE_WARNING => 'E_COMPILE_WARNING', E_USER_ERROR => 'E_USER_ERROR', E_USER_WARNING => 'E_USER_WARNING',
				E_USER_NOTICE => 'E_USER_NOTICE', E_STRICT => 'E_STRICT', E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
				E_DEPRECATED => 'E_DEPRECATED', E_USER_DEPRECATED => 'E_USER_DEPRECATED',
			);
			bmtg_diag_record_error( isset( $names[ $errno ] ) ? $names[ $errno ] : (string) $errno, $errstr, $errfile, $errline );
		}
		if ( is_callable( $prev ) ) {
			return call_user_func( $prev, $errno, $errstr, $errfile, $errline );
		}
		return false; // continue normal PHP error handling
	} );

	register_shutdown_function( function () {
		$e = error_get_last();
		if ( ! $e ) {
			return;
		}
		$fatal = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR );
		if ( ! in_array( $e['type'], $fatal, true ) ) {
			return;
		}
		if ( bmtg_diag_should_capture( $e['file'], $e['message'] ) ) {
			// Avoid duplicates.
			foreach ( $GLOBALS['bmtg_diag_captured_errors'] as $ex ) {
				if ( $ex['file'] === $e['file'] && $ex['line'] === $e['line'] ) {
					return;
				}
			}
			bmtg_diag_record_error( 'FATAL/SHUTDOWN', $e['message'], $e['file'], $e['line'] );
		}
	} );
}

if ( ! function_exists( 'bmtg_diag_is_admin' ) ) {
	function bmtg_diag_is_admin() {
		return is_user_logged_in() && current_user_can( 'manage_options' );
	}
}

if ( ! function_exists( 'bmtg_diag_collect' ) ) {
	/**
	 * Collect read-only diagnostic state. NO secret values.
	 */
	function bmtg_diag_collect() {
		nocache_headers();
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		// --- BMTG_ENCRYPTION_KEY (no value) ---
		$defined   = defined( 'BMTG_ENCRYPTION_KEY' );
		$raw       = $defined ? constant( 'BMTG_ENCRYPTION_KEY' ) : null;
		$type      = $defined ? gettype( $raw ) : null;
		$is_string = $defined && is_string( $raw );
		$non_empty = $is_string && $raw !== '';
		$has_prefix = $is_string && strpos( $raw, 'base64:' ) === 0;
		$stripped   = $is_string ? ( $has_prefix ? substr( $raw, 7 ) : $raw ) : '';
		$decoded    = ( $is_string && $non_empty ) ? base64_decode( $stripped, true ) : false;
		$decode_ok  = ( $decoded !== false );
		$decoded_len = $decode_ok ? strlen( $decoded ) : null;

		$key_report = array(
			'defined'                => $defined,
			'type'                   => $type,
			'non_empty'              => $non_empty,
			'base64_prefix'          => $has_prefix,
			'strict_base64_decode_ok'=> $decode_ok,
			'decoded_byte_length'    => $decoded_len,
		);

		// --- Sodium ---
		$sodium_fn  = function_exists( 'sodium_crypto_secretbox' );
		$keybytes_defined = defined( 'SODIUM_CRYPTO_SECRETBOX_KEYBYTES' );
		$sodium = array(
			'sodium_crypto_secretbox_exists' => $sodium_fn,
			'SODIUM_CRYPTO_SECRETBOX_KEYBYTES_defined' => $keybytes_defined,
			'SODIUM_CRYPTO_SECRETBOX_KEYBYTES' => $keybytes_defined ? constant( 'SODIUM_CRYPTO_SECRETBOX_KEYBYTES' ) : null,
		);

		// --- Hub / boot indicators (clearly distinguished; no success assumed from class existence) ---
		$hub_class_exists = class_exists( '\\BMTG\\Integrations\\Hub' );
		$hub_instance_obtained = false;
		$hub_instance_note = 'Hub class not loaded; instance not obtained.';
		$hub = null;
		if ( $hub_class_exists ) {
			try {
				$hub = \BMTG\Integrations\Hub::instance();
				$hub_instance_obtained = is_object( $hub );
				$hub_instance_note = 'Hub::instance() called (this creates the singleton object if it did not exist; it does NOT run boot()).';
			} catch ( \Throwable $e ) {
				$hub_instance_note = 'Hub::instance() threw: ' . $e->getMessage();
			}
		}

		$config_obj = ( $hub && method_exists( $hub, 'config' ) ) ? $hub->config() : null;
		$registry_obj = ( $hub && method_exists( $hub, 'registry' ) ) ? $hub->registry() : null;
		$cred_obj = ( $hub && method_exists( $hub, 'credential_store' ) ) ? $hub->credential_store() : null;
		$token_obj = ( $hub && method_exists( $hub, 'token_store' ) ) ? $hub->token_store() : null;
		$manager_obj = ( $hub && method_exists( $hub, 'manager' ) ) ? $hub->manager() : null;
		$health_obj = ( $hub && method_exists( $hub, 'health' ) ) ? $hub->health() : null;
		$audit_obj = ( $hub && method_exists( $hub, 'audit' ) ) ? $hub->audit() : null;
		$kr_obj = ( $hub && method_exists( $hub, 'key_rotation' ) ) ? $hub->key_rotation() : null;

		$all_core_services_initialized = $config_obj && $registry_obj && $cred_obj && $token_obj && $manager_obj && $health_obj && $audit_obj && $kr_obj;
		$any_core_service_initialized = $config_obj || $registry_obj || $cred_obj || $token_obj || $manager_obj || $health_obj || $audit_obj || $kr_obj;

		$rest_route_present = false;
		if ( function_exists( 'rest_get_server' ) ) {
			try {
				$server = rest_get_server();
				$routes = $server ? $server->get_routes() : array();
				$rest_route_present = isset( $routes['/bmtg/v1/integrations'] );
			} catch ( \Throwable $e ) {
				$rest_route_present = false;
			}
		}

		$hub_status = array(
			'hub_class_exists' => $hub_class_exists,
			'hub_instantiated_singleton' => $hub_instance_obtained,
			'hub_instantiated_note' => $hub_instance_note,
			'hub_boot_started_indicator' => $any_core_service_initialized,
			'hub_boot_started_note' => 'Indicator/proxy: true if any core Hub service (config/registry/stores/manager/health/audit/key_rotation) is initialized. Hub::boot() sets these; this is not a direct "boot() entered" trace because Hub is unmodified.',
			'hub_boot_completed_indicator' => (bool) $all_core_services_initialized,
			'hub_boot_completed_note' => 'Indicator/proxy: true only if all core services (config, registry, credential_store, token_store, manager, health, audit, key_rotation) are initialized. Does NOT equal success merely because Hub class exists.',
			'core_services' => array(
				'config' => (bool) $config_obj,
				'registry' => (bool) $registry_obj,
				'credential_store' => (bool) $cred_obj,
				'token_store' => (bool) $token_obj,
				'manager' => (bool) $manager_obj,
				'health' => (bool) $health_obj,
				'audit' => (bool) $audit_obj,
				'key_rotation' => (bool) $kr_obj,
			),
			'integrations_rest_route_registered' => $rest_route_present,
			'plugins_loaded_did_action' => function_exists( 'did_action' ) ? ( did_action( 'plugins_loaded' ) > 0 ) : null,
		);

		// --- Registry ---
		$registry_count = null;
		$registry_ids = array();
		if ( $registry_obj ) {
			try {
				$all = $registry_obj->all();
				$registry_count = is_array( $all ) ? count( $all ) : null;
				if ( is_array( $all ) ) {
					foreach ( $all as $c ) {
						if ( is_object( $c ) && method_exists( $c, 'id' ) ) {
							$registry_ids[] = $c->id();
						}
					}
				}
			} catch ( \Throwable $e ) {
				$registry_count = null;
				$registry_ids = array( 'error' => 'registry->all() threw' );
			}
		}

		// --- Config key status ---
		$config_status = array(
			'config_service_initialized' => (bool) $config_obj,
			'key_configured' => null,
			'exact_key_error_message' => null,
		);
		if ( $config_obj ) {
			try {
				$config_status['key_configured'] = $config_obj->is_key_configured();
				$config_status['exact_key_error_message'] = $config_obj->key_error_message();
			} catch ( \Throwable $e ) {
				$config_status['exact_key_error_message'] = 'Config threw: ' . $e->getMessage();
			}
		}

		// --- Actual integrations REST response (internal dispatch, authenticated as current admin user) ---
		$integrations_rest = array(
			'http_status' => null,
			'body' => null,
			'dispatch' => 'internal',
			'note' => 'Internal rest_do_request() as current logged-in admin user. See browser instructions for the real network request/response.',
		);
		if ( function_exists( 'rest_do_request' ) && class_exists( 'WP_REST_Request' ) ) {
			try {
				$req = new WP_REST_Request( 'GET', '/bmtg/v1/integrations' );
				$req->set_param( 'environment', 'live' );
				$res = rest_do_request( $req );
				if ( $res instanceof WP_REST_Response ) {
					$integrations_rest['http_status'] = $res->get_status();
					$integrations_rest['body'] = $res->get_data();
				} else {
					$integrations_rest['http_status'] = null;
					$integrations_rest['body'] = array( 'error' => 'Unexpected response type' );
				}
			} catch ( \Throwable $e ) {
				$integrations_rest['body'] = array( 'error' => 'Dispatch threw: ' . $e->getMessage() );
			}
		} else {
			$integrations_rest['body'] = array( 'error' => 'REST API unavailable' );
		}

		$errors = isset( $GLOBALS['bmtg_diag_captured_errors'] ) && is_array( $GLOBALS['bmtg_diag_captured_errors'] )
			? array_values( $GLOBALS['bmtg_diag_captured_errors'] )
			: array();

		return array(
			'generated_at_gmt' => gmdate( 'c' ),
			'bmtg_encryption_key' => $key_report,
			'sodium' => $sodium,
			'hub' => $hub_status,
			'connector_registry' => array(
				'count' => $registry_count,
				'ids' => $registry_ids,
			),
			'config' => $config_status,
			'captured_bmtg_errors' => $errors,
			'integrations_rest_internal_dispatch' => $integrations_rest,
		);
	}
}

// --- REST endpoint (admin-only, authenticated + valid wp_rest nonce) ---
add_action( 'rest_api_init', function () {
	register_rest_route( 'bmtg-diag/v1', '/status', array(
		'methods'             => 'GET',
		'permission_callback' => function ( $request ) {
			if ( ! bmtg_diag_is_admin() ) {
				return false;
			}
			$nonce = $request->get_header( 'X-WP-Nonce' );
			if ( ! $nonce ) {
				$nonce = $request->get_param( '_wpnonce' );
			}
			return is_string( $nonce ) && wp_verify_nonce( $nonce, 'wp_rest' );
		},
		'callback'            => function () {
			$res = new WP_REST_Response( bmtg_diag_collect(), 200 );
			$res->header( 'Cache-Control', 'no-store, private, max-age=0' );
			$res->header( 'Pragma', 'no-cache' );
			return $res;
		},
	) );
} );

// --- Admin-only diagnostic page ---
add_action( 'admin_menu', function () {
	add_management_page(
		'BMTG Temporary Diagnostic',
		'BMTG Diagnostic (Temp)',
		'manage_options',
		'bmtg-temp-diagnostic',
		'bmtg_diag_render_page'
	);
} );

if ( ! function_exists( 'bmtg_diag_render_page' ) ) {
	function bmtg_diag_render_page() {
		if ( ! bmtg_diag_is_admin() ) {
			wp_die( esc_html( 'Access denied.' ) );
		}
		$data = bmtg_diag_collect();
		$json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$rest_url = esc_url( rest_url( 'bmtg/v1/integrations?environment=live' ) );
		$diag_rest_url = esc_url( rest_url( 'bmtg-diag/v1/status' ) );
		$nonce = wp_create_nonce( 'wp_rest' );
		?>
		<div class="wrap">
			<h1>BMTG Temporary Diagnostic (READ-ONLY)</h1>
			<div class="notice notice-warning"><p><strong>Temporary &amp; read-only.</strong> No secrets/credentials are shown or logged. This does not modify the encryption key, wp-config.php, database, or security settings. <strong>Remove this file after testing:</strong> delete <code>wp-content/mu-plugins/bmtg-diagnostic-temp.php</code>.</p></div>

			<h2>Summary (server-side, this request)</h2>
			<p><strong>Hub state (distinguished):</strong> instantiated(singleton)=<code><?php echo $data['hub']['hub_instantiated_singleton'] ? 'true' : 'false'; ?></code>, boot_started(indicator)=<code><?php echo $data['hub']['hub_boot_started_indicator'] ? 'true' : 'false'; ?></code>, boot_completed(indicator)=<code><?php echo $data['hub']['hub_boot_completed_indicator'] ? 'true' : 'false'; ?></code>, class_exists=<code><?php echo $data['hub']['hub_class_exists'] ? 'true' : 'false'; ?></code>. <em>Boot is not reported as successful merely because the Hub class exists.</em></p>
			<p><strong>Key configured:</strong> <code><?php echo esc_html( var_export( $data['config']['key_configured'], true ) ); ?></code> — <strong>Error:</strong> <?php echo esc_html( (string) $data['config']['exact_key_error_message'] ); ?></p>
			<p><strong>Registry:</strong> count=<code><?php echo esc_html( var_export( $data['connector_registry']['count'], true ) ); ?></code>, ids=<code><?php echo esc_html( implode( ', ', array_map( 'strval', (array) $data['connector_registry']['ids'] ) ) ); ?></code></p>
			<p><strong>Integrations REST (internal dispatch):</strong> HTTP <code><?php echo esc_html( var_export( $data['integrations_rest_internal_dispatch']['http_status'], true ) ); ?></code></p>

			<h2>Full diagnostic JSON</h2>
			<textarea readonly style="width:100%;min-height:420px;font-family:monospace;font-size:12px;"><?php echo esc_textarea( $json ); ?></textarea>

			<h2>Check the actual authenticated integrations REST request in the browser</h2>
			<ol>
				<li>Stay logged in as an administrator in this browser.</li>
				<li>Open <strong>DevTools</strong> (F12) → <strong>Network</strong> tab. Enable “Preserve log” if helpful.</li>
				<li>Navigate to <strong>Settings → BMTG Integrations</strong> (<code>options-general.php?page=bmtg-integrations</code>) and let the page load.</li>
				<li>Find the request to <code><?php echo $rest_url; ?></code> (method GET).</li>
				<li>Inspect: <strong>Status</strong> (HTTP code), <strong>Response</strong>/<strong>Preview</strong> (JSON body), and confirm request headers include <code>X-WP-Nonce</code> and an authenticated session cookie. Compare with the internal dispatch shown above.</li>
				<li>You can also click “Fetch integrations REST now” below to perform a real browser-authenticated fetch (uses your session + wp_rest nonce):</li>
			</ol>
			<p>
				<button type="button" class="button button-secondary" id="bmtg-diag-fetch">Fetch integrations REST now (browser-authenticated)</button>
				<button type="button" class="button" id="bmtg-diag-fetch-status">Fetch diagnostic REST (bmtg-diag/v1/status)</button>
			</p>
			<pre id="bmtg-diag-live" style="background:#f6f7f7;padding:12px;overflow:auto;max-height:360px;">(no live fetch yet)</pre>

			<h2>Removal</h2>
			<ul>
				<li>Delete the file: <code>wp-content/mu-plugins/bmtg-diagnostic-temp.php</code> (Hostinger File Manager: <code>public_html/wp-content/mu-plugins/</code> → select file → Delete).</li>
				<li>If <code>mu-plugins</code> becomes empty and you created it only for this test, you may leave it or remove it (optional).</li>
				<li>This diagnostic makes no DB/config changes; no other cleanup required.</li>
			</ul>
		</div>
		<script>
		(function(){
			function show(label, status, body){
				var el = document.getElementById('bmtg-diag-live');
				var text = label + "\nHTTP status: " + status + "\nBody:\n" + (typeof body === 'string' ? body : JSON.stringify(body, null, 2));
				el.textContent = text;
			}
			async function run(url){
				try {
					var r = await fetch(url, {credentials:'same-origin', headers:{'X-WP-Nonce':'<?php echo esc_js( $nonce ); ?>'}});
					var t = await r.text();
					var b; try { b = JSON.parse(t); } catch(e){ b = t; }
					show(url, r.status, b);
				} catch(e){ show(url, 'fetch_error', String(e)); }
			}
			var b1=document.getElementById('bmtg-diag-fetch');
			var b2=document.getElementById('bmtg-diag-fetch-status');
			if(b1) b1.addEventListener('click', function(){ run('<?php echo esc_url_raw( rest_url( 'bmtg/v1/integrations?environment=live' ) ); ?>'); });
			if(b2) b2.addEventListener('click', function(){ run('<?php echo esc_url_raw( rest_url( 'bmtg-diag/v1/status' ) ); ?>'); });
		})();
		</script>
		<?php
	}
}
