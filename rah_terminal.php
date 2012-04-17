<?php

/**
 * Rah_terminal plugin for Textpattern CMS
 *
 * @author Jukka Svahn
 * @copyright (c) 2012 Jukka Svahn
 * @date 2012-
 * @license GNU GPLv2
 * @link https://github.com/gocom/rah_terminal
 *
 * Copyright (C) 2012 Jukka Svahn <http://rahforum.biz>
 * Licensed under GNU Genral Public License version 2
 * http://www.gnu.org/licenses/gpl-2.0.html
 */

	if(@txpinterface == 'admin') {
		add_privs('rah_terminal', '1');
		add_privs('rah_terminal.php', '1');
		add_privs('rah_terminal.sql', '1');
		add_privs('rah_terminal.exec', '1');
		add_privs('plugin_prefs.rah_terminal', '1');
		register_callback(array('rah_terminal', 'prefs'), 'plugin_prefs.rah_terminal');
		register_tab('extensions', 'rah_terminal', gTxt('rah_terminal'));
		register_callback(array('rah_terminal', 'panes'), 'rah_terminal');
		register_callback(array('rah_terminal', 'head'), 'admin_side', 'head_end');
		register_callback(array('rah_terminal', 'uninstall'), 'plugin_lifecycle.rah_terminal', 'deleted');
	}

class rah_terminal {

	/**
	 * @var object Stores instances
	 */
	
	static public $instance;

	/**
	 * @var array Captured error messages
	 */

	public $error = array();

	/**
	 * @var array Terminal callbacks
	 * @access private
	 * @see rah_terminal::add_terminal()
	 */
	
	private $terminals = array();
	
	/**
	 * @var array Terminal labels
	 * @access private
	 * @see rah_terminal::add_terminal()
	 */
	
	private $terminal_labels = array();
	
	/**
	 * @var array Diagnostics notes. Added to result messages notes
	 * @todo public method, make private
	 */
	
	public $notes = array();
	
	/**
	 * @var string User-stamp. Defaults to PHP process owner
	 * @todo public method, make private
	 */
	
	public $userstamp;
	
	/**
	 * @var string Last result's returned variable type
	 * @todo public method, make private
	 */
	
	public $type;

	/**
	 * Un-installer
	 */

	static public function uninstall() {
		safe_delete(
			'txp_prefs',
			"name LIKE 'rah\_terminal\_%'"
		);
	}
	
	/**
	 * Gets an instance
	 * @param bool $new
	 * @return obj
	 */
	
	static public function get($new=false) {
		
		if($new || !self::$instance) {
			self::$instance = new rah_terminal();
		}
		
		return self::$instance;
	}
	
	/**
	 * Delivers panes
	 */
	
	static public function panes() {
		require_privs('rah_terminal');
		global $step;
		
		$steps = 
			array(
				'form' => false,
				'execute' => true,
			);
		
		if(!$step || !bouncer($step, $steps))
			$step = 'form';
		
		rah_terminal::get()->verify_terminals()->$step();
	}
	
	/**
	 * Constructor
	 */
	
	public function __construct() {
	
		global $txp_user, $siteurl;
	
		$this
			->add_terminal('php', gTxt('rah_terminal_php'), array($this, 'process_php'))
			->add_terminal('sql', gTxt('rah_terminal_sql'), array($this, 'process_sql'))
			->add_terminal('exec', gTxt('rah_terminal_exec'), array($this, 'process_exec'));
		
		if(function_exists('posix_getpwuid')) {
			$u = posix_getpwuid(posix_geteuid());
			$this->userstamp = !empty($u['name']) ? $u['name'] : NULL;
		}
		
		if(!$this->userstamp) {
			$this->userstamp = $txp_user;
		}
		
		if(is_callable('php_uname')) {
			$this->userstamp .= '@' . php_uname('n');
		}
		
		else {
			$this->userstamp .= '@Textpattern';
		}
	}
	
	/**
	 * Adds a terminal processor
	 * @param string name
	 * @param string|null $label
	 * @param callback|null $callback
	 * @retrun obj
	 */
	
	public function add_terminal($name, $label, $callback=NULL) {
		
		if($label === NULL || $callback === NULL) {
			unset($this->terminal_labels[$name], $this->terminals[$name]);
		}
		
		elseif(!in_array($label, $this->terminal_labels)) {
			$this->terminals[$name] = $callback;
			$this->terminal_labels[$name] = $label;
		}
		
		return $this;
	}
	
	/**
	 * Verifies user's terminal permissions
	 * @return obj
	 */
	
	public function verify_terminals() {
		foreach($this->terminals as $name => $callback) {
			if(!has_privs('rah_terminal.'.$name) || !is_callable($callback)) {
				unset($this->terminal_labels[$name], $this->terminals[$name]);
			}
		}
		
		return $this;
	}

	/**
	 * The pane
	 */

	public function form() {
		global $event;
		
		pagetop(gTxt('rah_terminal'));
		asort($this->terminal_labels);
		
		echo
			'<form method="post" action="index.php" id="rah_terminal_container" class="txp-container rah_ui_container">'.n.
			eInput($event).
			sInput('execute').
			tInput().n.
			'	<p>'.selectInput('type', $this->terminal_labels, get_pref('rah_terminal_last_type')).'</p>'.n.
			'	<p>'.n.
			'		<textarea class="code" name="code" rows="12" cols="20"></textarea>'.n.
			'	</p>'.n.
			'	<p>'.n.
			'		<input type="submit" value="'.gTxt('rah_terminal_run').'" class="publish" />'.n.
			'	</p>'.n.
			'</form>' .n;
	}

	/**
	 * Gets bookmarks
	 * @return array
	 */
	
	private function get_bookmarks() {
		global $prefs;
		
		$out = array();
		
		foreach($prefs as $name => $value) {
			
			if(strpos($name, 'rah_terminal_b.') !== 0) {
				continue;
			}
			
			$n = explode('.', $name);
			$out[$name] = implode('.', array_slice($n, 1));
		}
		
		asort($out);
		
		return $out;
	}
	
	/**
	 * Executes commands, content or code
	 */
	
	public function execute() {
		
		global $theme;
		
		extract(psa(array(
			'type',
			'code'
		)));

		$js = array();
		$msg = gTxt('rah_terminal_success');
		
		if(!isset($this->terminals[$type])) {
			$msg = array(gTxt('rah_terminal_unknown_type'), E_WARNING);
		}
		
		elseif(trim($code) === '') {
			$msg = array(gTxt('rah_terminal_code_required'), E_WARNING);
		}
		
		else {
			set_pref('rah_terminal_last_type', $type, 'rah_terminal', PREF_HIDDEN, '', 0, PREF_PRIVATE);
			
			try {
				ob_start();
				@error_reporting(-1);
				@set_error_handler(array('rah_terminal', 'error_handler'));
				$runtime = getmicrotime();
				@$direct = call_user_func($this->terminals[$type], $code);
				$runtime = rtrim(number_format(getmicrotime() - $runtime, 15, '.', ''), 0);
				restore_error_handler();
				$buffer = ob_get_clean();
			}
			catch(exception $e) {
				$this->error[] = $e->getMessage();
			}
			
			if($this->error) {
				$msg = array(gTxt('rah_terminal_error'), E_ERROR);
			}
			
			elseif($direct === false && $buffer === '') {
				$msg = array(gTxt('rah_terminal_blackhole'), E_WARNING);
			}
			
			if($buffer !== ' ' && $direct === NULL) {
				$direct = trim($buffer);
			}
			
			$stamp = gTxt('rah_terminal_said_by', array(
				'{time}' => safe_strftime(gTxt('rah_terminal_timestamp')),
				'{user}' => $this->userstamp,
			));
			
			if($this->type === NULL) {
				$this->type = gettype($direct);
			}
			
			$notes = 
				gTxt('rah_terminal_notes', array(
					'{runtime}' => $runtime,
					'{type}' => $this->type,
					'{notes}' => implode(' ', $this->notes),
				));
			
			$direct = htmlspecialchars($this->output($direct));
			$errors = array();
			
			foreach($this->error as $error) {
				$errors[] = '<span>'.htmlspecialchars($error).'</span>';
			}
			
			$errors = $errors ? '<p class="rah_terminal_errors error">' . implode('<br />', $errors) . '</p>' : '';
			
			$code = 
				'<div class="rah_terminal_result">'.
					'<p>'.$stamp.' <a class="rah_terminal_result_close" href="#">'.gTxt('rah_terminal_close').'</a></p>'.
					'<pre><code>'.$direct.'</code></pre>'.
					$errors.
					'<p class="rah_terminal_note">'.$notes.'</p>'.
				'</div>';
			
			$js[] = "$('#rah_terminal_container input.publish').parent().after('".escape_js($code)."');";
		}
		
		send_script_response(implode(n, $js) . $theme->announce_async($msg));
		die;
	}
	
	/**
	 * Takes command's output and makes that into a safe string
	 * @param mixed $code
	 * @return string
	 */
	
	private function output($code) {
	
		if(is_bool($code)) {
			return $code ? '(bool) true' : '(bool) false';
		}
	
		if(is_scalar($code)) {
			return $code;
		}
		
		if(is_array($code)) {
			ob_start();
			@print_r($code);
			return ob_get_clean();
		}
		
		return getType($code);
	}
	
	/**
	 * Evaluates PHP
	 * @param string $php
	 * @return mixed Returned value, NULL or FALSE
	 */
	
	private function process_php($php) {
		return eval("echo ' '; {$php}");
	}
	
	/**
	 * Executes shell commands
	 * @param string $cmd
	 * @return string Standard output
	 */
	
	private function process_exec($cmd) {
		system($cmd, $output);
		return $output;
	}
	
	/**
	 * Executes a DB query
	 * @param string $sql
	 * @return mixed
	 */
	
	private function process_sql($sql) {
		global $DB;
	
		$q = safe_query($sql);
		
		$this->type = gettype($q);
		$this->userstamp = $DB->user . '@' . $DB->host;
		
		if($q === false) {
			$this->error = array();
			trigger_error(mysql_error() . ' ('.mysql_errno().')', E_USER_ERROR);
			return $q;
		}
		
		else {
			$this->notes[] = gTxt('rah_terminal_rows_affected', array('{count}' => mysql_affected_rows()));
		}
		
		if(is_resource($q)) {
			
			$out = array();
			
			while($r = mysql_fetch_assoc($q)) {
				$out[] = $r;
			}
			
			return $out;
		}
		
		return $q;
	}

	/**
	 * Adds styles and JavaScript to the <head>
	 */

	static public function head() {
		
		global $event, $theme;
		
		if($event != 'rah_terminal')
			return;
		
		$error = escape_js($theme->announce_async(array(gTxt('rah_terminal_fatal_error'), E_ERROR)));
		
		echo <<<EOF
			<style type="text/css">
				#rah_terminal_container {
					width: 650px;
					margin: 0 auto;
				}
				#rah_terminal_container textarea {
					width: 100%;
				}
				.rah_terminal_result_close {
					float: right;
				}
			</style>
			<script type="text/javascript">
				<!--
				$(document).ready(function(){
					
					$('form#rah_terminal_container').txpAsyncForm({
						error : function() {
							$.globalEval('{$error}');
						},
						
						success : function(form, event, data) {
							if($.trim(data) === '') {
								$.globalEval('{$error}');
							}
						}
					});
					
					$('.rah_terminal_result_close').live('click', function(e) {
						e.preventDefault();
						$(this).parents('.rah_terminal_result').remove();
					});
					
				});
				//-->
			</script>
EOF;
	}
	
	/**
	 * Error handler
	 */
	
	static public function error_handler($n, $str, $file, $line) {
		
		$error = array(
			E_WARNING => 'Warning',
			E_NOTICE => 'Notice',
			E_USER_ERROR => 'Error',
			E_USER_WARNING => 'Warning',
			E_USER_NOTICE => 'Notice'
		);
		
		if(isset($error[$n])) {
			rah_terminal::get()->error[] = $error[$n].': '.$str;
		}
		
		return true;
	}

	/**
	 * Redirects to the plugin's panel
	 */

	static public function prefs() {
		header('Location: ?event=rah_terminal');
		echo 
			'<p>'.n.
			'	<a href="?event=rah_terminal">'.gTxt('continue').'</a>'.n.
			'</p>';
	}
}
?>