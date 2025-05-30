<?php

if (!defined('UPDRAFTCENTRAL_CLIENT_DIR')) die('No access.');

/**
 * This class is the basic glue between the lower-level Remote Communications (RPC) class in UpdraftCentral, and the host plugin. It does not contain actual commands themselves; the class names to use for actual commands are passed in as a parameter to the constructor.
 */
class UpdraftCentral_Listener {

	public $udrpc_version;

	private $host = null;

	private $receivers = array();

	private $extra_info = array();

	private $php_events = array();

	private $commands = array();

	private $current_udrpc = null;

	private $command_classes;

	private $auto_logged_in_cookie;

	/**
	 * Class constructor
	 *
	 * @param Array $keys			 - keys to set up listeners for
	 * @param Array $command_classes - commands
	 */
	public function __construct($keys = array(), $command_classes = array()) {
		global $updraftcentral_host_plugin;
		$this->host = $updraftcentral_host_plugin;

		// It seems impossible for this condition to result in a return; but it seems Plesk can do something odd within the control panel that causes a problem - see HS#6276
		if (!is_a($this->host, 'UpdraftCentral_Host')) return;

		$this->command_classes = $command_classes;
		
		foreach ($keys as $name_hash => $key) {
			// publickey_remote isn't necessarily set yet, depending on the key exchange method
			if (!is_array($key) || empty($key['extra_info']) || empty($key['publickey_remote'])) continue;
			$indicator = $name_hash.'.central.updraftplus.com';
			$ud_rpc = $this->host->get_udrpc($indicator);
			$this->udrpc_version = $ud_rpc->version;
			
			// Only turn this on if you are comfortable with potentially anything appearing in your PHP error log
			if (defined('UPDRAFTCENTRAL_UDRPC_FORCE_DEBUG') && UPDRAFTCENTRAL_UDRPC_FORCE_DEBUG) $ud_rpc->set_debug(true);

			$this->receivers[$indicator] = $ud_rpc;
			$this->extra_info[$indicator] = isset($key['extra_info']) ? $key['extra_info'] : null;
			$ud_rpc->set_key_local($key['key']);
			$ud_rpc->set_key_remote($key['publickey_remote']);
			// Create listener (which causes WP actions to be fired when messages are received)
			$ud_rpc->activate_replay_protection();
			if (!empty($key['extra_info']) && isset($key['extra_info']['mothership'])) {
				$mothership = $key['extra_info']['mothership'];
				$url = '';
				if ('__updraftpluscom' == $mothership) {
					$url = 'https://teamupdraft.com';
				} elseif (false != ($parsed = parse_url($key['extra_info']['mothership'])) && is_array($parsed)) {
					$url = $parsed['scheme'].'://'.$parsed['host'];
				}
				if (!empty($url)) $ud_rpc->set_allow_cors_from(array($url));
			}
			$ud_rpc->create_listener();
		}
		
		// If we ever need to expand beyond a single GET action, this can/should be generalised and put into the commands class
		if (!empty($_GET['udcentral_action']) && 'login' == $_GET['udcentral_action']) {
			// auth_redirect() does not return, according to the documentation; but the code shows that it can
			// auth_redirect();

			if (!empty($_GET['login_id']) && is_numeric($_GET['login_id']) && !empty($_GET['login_key'])) {
				$login_user = get_user_by('id', $_GET['login_id']);
				
				// THis is included so we can get $wp_version
				include_once(ABSPATH.WPINC.'/version.php');

				if (is_a($login_user, 'WP_User') || (version_compare($wp_version, '3.5', '<') && !empty($login_user->ID))) {// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable -- The variable is defined inside the ABSPATH.WPINC.'/version.php'.
					// Allow site implementers to disable this functionality
					$allow_autologin = apply_filters('updraftcentral_allow_autologin', true, $login_user);
					if ($allow_autologin) {
						$login_key = get_user_meta($login_user->ID, 'updraftcentral_login_key', true);
						if (is_array($login_key) && !empty($login_key['created']) && $login_key['created'] > time() - 60 && !empty($login_key['key']) && $login_key['key'] == $_GET['login_key']) {
							$autologin = empty($login_key['redirect_url']) ? network_admin_url() : $login_key['redirect_url'];
						}
					}
				}
			}
			if (!empty($autologin)) {
				// Allow use once only
				delete_user_meta($login_user->ID, 'updraftcentral_login_key');
				$this->autologin_user($login_user, $autologin);
			}
		}
		
		add_filter('udrpc_action', array($this, 'udrpc_action'), 10, 5);
		add_filter('updraftcentral_get_command_info', array($this, 'updraftcentral_get_command_info'), 10, 2);
		add_filter('updraftcentral_get_updraftplus_status', array($this, 'get_updraftplus_status'), 10, 1);

	}

	/**
	 * Retrieves the UpdraftPlus plugin status whether it has been installed or activated
	 *
	 * @param mixed $data Default data to return
	 * @return array
	 */
	public function get_updraftplus_status($data) {

		// Handle cases of users who rename their plugin folders
		if (class_exists('UpdraftPlus')) {
			$data['is_updraftplus_installed'] = true;
			$data['is_updraftplus_active'] = true;
		} else {
			if (!function_exists('get_plugins')) require_once(ABSPATH.'wp-admin/includes/plugin.php');
			$plugins = get_plugins();
			$key = 'updraftplus/updraftplus.php';

			if (array_key_exists($key, $plugins)) {
				$data['is_updraftplus_installed'] = true;
				if (is_plugin_active($key)) $data['is_updraftplus_active'] = true;
			}
		}

		return $data;
	}

	/**
	 * Retrieves command class information and includes class file if class
	 * is currently not available.
	 *
	 * @param  mixed  $response The default response to return if the submitted command does not exists
	 * @param  string $command  The command to parse and check
	 * @return array  Contains the following command information "command_php_class", "class_prefix" and "command"
	 */
	public function updraftcentral_get_command_info($response, $command) {
		if (!preg_match('/^([a-z0-9]+)\.(.*)$/', $command, $matches)) return $response;
		$class_prefix = $matches[1];
		$command = $matches[2];
		
		// Other plugins might have registered the filter rather later so we need to make
		// sure that we get all the commands intended for UpdraftCentral.
		$this->command_classes = apply_filters('updraftcentral_remotecontrol_command_classes', $this->command_classes);

		// We only handle some commands - the others, we let something else deal with
		if (!isset($this->command_classes[$class_prefix])) return $response;

		$command_php_class = $this->command_classes[$class_prefix];
		$command_base_class_at = apply_filters('updraftcentral_command_base_class_at', UPDRAFTCENTRAL_CLIENT_DIR.'/commands.php');
		
		if (!class_exists('UpdraftCentral_Commands')) include_once($command_base_class_at);
		
		// Second parameter has been passed since
		do_action('updraftcentral_command_class_wanted', $command_php_class);
		
		if (!class_exists($command_php_class)) {
			if (file_exists(UPDRAFTCENTRAL_CLIENT_DIR.'/modules/'.$class_prefix.'.php')) {
				include_once(UPDRAFTCENTRAL_CLIENT_DIR.'/modules/'.$class_prefix.'.php');
			}
		}

		return array(
			'command_php_class' => $command_php_class,
			'class_prefix' => $class_prefix,
			'command' => $command
		);
	}

	/**
	 * In response to auto logging in the user; set a corresponding logged_in cookie to the $login_user_cookie class variable
	 *
	 * @param String  $cookie     Authentication cookie
	 * @param Integer $user_id    User ID
	 * @param Integer $expiration The time the cookie expires as a UNIX timestamp
	 * @param String  $scheme     Cookie scheme used. Accepts 'auth', 'secure_auth', or 'logged_in'
	 */
	public function set_global_logged_in_cookie($cookie, $user_id, $expiration, $scheme) {
		if ('logged_in' === $scheme) {
			$this->auto_logged_in_cookie = $cookie;
		}
		return $cookie;
	}
	
	/**
	 * Do verification before calling this method
	 *
	 * @param  WP_User|Object $user         user object for autologin
	 * @param  boolean        $redirect_url Redirect URL
	 */
	private function autologin_user($user, $redirect_url = false) {
		if (!is_user_logged_in()) {
	// $user = get_user_by('id', $user_id);
			// Don't check that it's a WP_User - that's WP 3.5+ only
			if (!is_object($user) || empty($user->ID)) return;
			wp_set_current_user($user->ID, $user->user_login);
			add_filter('auth_cookie', array($this, 'set_global_logged_in_cookie'), 10, 4);
			wp_set_auth_cookie($user->ID);
			remove_filter('auth_cookie', array($this, 'set_global_logged_in_cookie'), 10, 4);
			do_action('wp_login', $user->user_login, $user);
		}
		if ($redirect_url) {
			// the wp_set_auth_cookie() above uses setcookie() function but the corresponding LOGGED_IN_COOKIE variable is visible and can only be accessible on the next page load
			// so we set the auth cookie into the superglobal $_COOKIE variable manually, we do this because the previously non logged-in user is now being auto-logged in and wp_create_nonce() needs the value of LOGGED_IN_COOKIE variable to produce a correct nonce
			if ($this->auto_logged_in_cookie) $_COOKIE[LOGGED_IN_COOKIE] = $this->auto_logged_in_cookie;
			$redirect_url = add_query_arg('restore_initiation_nonce', wp_create_nonce('updraftplus_udcentral_initiate_restore'), $redirect_url);
			if ($this->auto_logged_in_cookie) unset($_COOKIE[LOGGED_IN_COOKIE]);
			wp_safe_redirect($redirect_url);
			exit;
		}
	}
	
	/**
	 * WP filter udrpc_action
	 *
	 * @param Array	 $response			 - the unfiltered response that will be returned
	 * @param String $command			 - the command being called
	 * @param Array	 $data				 - the parameters to the command
	 * @param String $key_name_indicator - the UC key that is in use
	 * @param Object $ud_rpc			 - the UDRP object
	 *
	 * @return Array - filtered response
	 */
	public function udrpc_action($response, $command, $data, $key_name_indicator, $ud_rpc) {
		try {

			if (empty($this->receivers[$key_name_indicator])) return $response;
			
			// This can be used to detect an UpdraftCentral context
			if (!defined('UPDRAFTCENTRAL_COMMAND')) define('UPDRAFTCENTRAL_COMMAND', $command);
			
			$this->initialise_listener_error_handling();

			// UpdraftCentral needs this extra information especially now that the UpdraftCentral
			// libraries can be totally embedded in other plugins (e.g. WP-Optimize, etc.) thus,
			// that makes the UpdraftPlus plugin optional.
			//
			// This will give UpdraftCentral a proper way of disabling the backup feature
			// for this site if the UpdraftPlus plugin is currently not installed or activated.
			//
			// In addition, we need to attached the host plugin who is handling the UpdraftCentral requests
			global $updraftcentral_host_plugin;
			$extra = apply_filters('updraftcentral_get_updraftplus_status', array(
				'is_updraftplus_installed' => false,
				'is_updraftplus_active' => false,
				'host_plugin' => $updraftcentral_host_plugin->plugin_name,
			));

			$command_info = apply_filters('updraftcentral_get_command_info', false, $command);
			if (!$command_info) {
				if (isset($response['data']) && is_array($response['data'])) $response['data']['extra'] = $extra;
				return $response;
			}

			$class_prefix = $command_info['class_prefix'];
			$command = $command_info['command'];
			$command_php_class = $command_info['command_php_class'];
			
			if (empty($this->commands[$class_prefix])) {
				if (class_exists($command_php_class)) {
					$this->commands[$class_prefix] = new $command_php_class($this);
				}
			}
			
			$command_class = isset($this->commands[$class_prefix]) ? $this->commands[$class_prefix] : new stdClass;
	
			if ('_' == substr($command, 0, 1) || !is_a($command_class, $command_php_class) || (!method_exists($command_class, $command) && !method_exists($command_class, '__call'))) {
				if (defined('UPDRAFTCENTRAL_UDRPC_FORCE_DEBUG') && UPDRAFTCENTRAL_UDRPC_FORCE_DEBUG) error_log("Unknown RPC command received: ".$command);

				return $this->return_rpc_message(array('response' => 'rpcerror', 'data' => array('code' => 'unknown_rpc_command', 'data' => array('prefix' => $class_prefix, 'command' => $command, 'class' => $command_php_class))));
			}
	
			$extra_info = isset($this->extra_info[$key_name_indicator]) ? $this->extra_info[$key_name_indicator] : null;
			
			// Make it so that current_user_can() checks can apply + work
			if (!empty($extra_info['user_id'])) wp_set_current_user($extra_info['user_id']);
			
			$this->current_udrpc = $ud_rpc;
			
			do_action('updraftcentral_listener_pre_udrpc_action', $command, $command_class, $data, $extra_info);
			
			// Allow the command class to perform any boiler-plate actions.
			if (is_callable(array($command_class, '_pre_action'))) call_user_func(array($command_class, '_pre_action'), $command, $data, $extra_info);
			
			// Despatch
			$msg = apply_filters('updraftcentral_listener_udrpc_action', call_user_func(array($command_class, $command), $data, $extra_info), $command_class, $class_prefix, $command, $data, $extra_info);
	
			if (is_callable(array($command_class, '_post_action'))) call_user_func(array($command_class, '_post_action'), $command, $data, $extra_info);
	
			do_action('updraftcentral_listener_post_udrpc_action', $command, $command_class, $data, $extra_info);

			if (isset($msg['data']) && is_array($msg['data'])) {
				$msg['data']['extra'] = $extra;
			}
					
			return $this->return_rpc_message($msg);
		} catch (Exception $e) {
			$log_message = 'PHP Fatal Exception error ('.get_class($e).') has occurred during UpdraftCentral command execution. Error Message: '.$e->getMessage().' (Code: '.$e->getCode().', line '.$e->getLine().' in '.$e->getFile().')';
			error_log($log_message);

			return $this->return_rpc_message(array('response' => 'rpcerror', 'data' => array('code' => 'rpc_fatal_error', 'data' => array('command' => $command, 'message' => $log_message))));
		// @codingStandardsIgnoreLine
		} catch (Error $e) {
			$log_message = 'PHP Fatal error ('.get_class($e).') has occurred during UpdraftCentral command execution. Error Message: '.$e->getMessage().' (Code: '.$e->getCode().', line '.$e->getLine().' in '.$e->getFile().')';
			error_log($log_message);

			return $this->return_rpc_message(array('response' => 'rpcerror', 'data' => array('code' => 'rpc_fatal_error', 'data' => array('command' => $command, 'message' => $log_message))));
		}
	}
	
	public function get_current_udrpc() {
		return $this->current_udrpc;
	}
	
	private function initialise_listener_error_handling() {
		global $updraftcentral_host_plugin;

		$this->host->error_reporting_stop_when_logged = true;
		$error_levels = version_compare(PHP_VERSION, '8.4.0', '>=') ? E_ALL : E_ALL & ~E_STRICT;
		set_error_handler(array($this->host, 'php_error'), $error_levels);
		$this->php_events = array();
		@ob_start();// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Might be a bigger picture that I am missing but do we need to silence errors here?
		add_filter($updraftcentral_host_plugin->get_logline_filter(), array($this, 'updraftcentral_logline'), 10, 4);
		if (!$updraftcentral_host_plugin->get_debug_mode()) return;
	}
	
	public function updraftcentral_logline($line, $nonce, $level, $uniq_id) {// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Unused parameter is present because the method is used as a WP filter.
		if ('notice' === $level && 'php_event' === $uniq_id) {
			$this->php_events[] = $line;
		}
		return $line;
	}

	public function return_rpc_message($msg) {
		if (is_array($msg) && isset($msg['response']) && 'error' == $msg['response']) {
			$this->host->log('Unexpected response code in remote communications: '.serialize($msg));
		}
		
		$caught_output = @ob_get_contents();// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Might be a bigger picture that I am missing but do we need to silence errors here?
		@ob_end_clean();// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Might be a bigger picture that I am missing but do we need to silence errors here?
		// If turning output-catching off, turn this on instead:
		// $caught_output = ''; @ob_end_flush();
		
		// If there's higher-level output buffering going on, then get rid of that
		if (ob_get_level()) ob_end_clean();
		
		if ($caught_output) {
			if (!isset($msg['data'])) $msg['data'] = null;
			$msg['data'] = array('caught_output' => $caught_output, 'previous_data' => $msg['data']);
			$already_rearranged_data = true;
		}
		
		if (!empty($this->php_events)) {
			if (!isset($msg['data'])) $msg['data'] = null;
			if (!empty($already_rearranged_data)) {
				$msg['data']['php_events'] = array();
			} else {
				$msg['data'] = array('php_events' => array(), 'previous_data' => $msg['data']);
			}
			foreach ($this->php_events as $logline) {
				$msg['data']['php_events'][] = $logline;
			}
		}
		restore_error_handler();

		return $msg;
	}
}
