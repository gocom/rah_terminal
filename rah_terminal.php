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

	rah_terminal::get();

class rah_terminal {

	/**
	 * Stores instances.
	 *
	 * @var rah_terminal
	 */

	static public $instance;

	/**
	 * Captured error messages.
	 *
	 * @var array
	 */

	private $error = array();

	/**
	 * Terminal callbacks.
	 *
	 * @access private
	 * @see    rah_terminal::add_terminal()
	 */

	private $terminals = array();

	/**
	 * Terminal labels.
	 *
	 * @access private
	 * @see    rah_terminal::add_terminal()
	 */

	private $terminal_labels = array();

	/**
	 * Diagnostics notes. Attached to result messages.
	 *
	 * @var array 
	 */

	public $notes = array();

	/**
	 * User-stamp. Defaults to PHP process owner.
	 *
	 * @var string 
	 */

	public $userstamp;

	/**
	 * Last result's returned variable type.
	 *
	 * @var string 
	 */

	public $type;

	/**
	 * Gets an instance.
	 *
	 * @return obj
	 */

	static public function get()
	{
		if (!self::$instance)
		{
			self::$instance = new rah_terminal();
		}

		return self::$instance;
	}

	/**
	 * Delivers panes.
	 */

	public function panes()
	{
		global $step;

		$steps = array(
			'form' => false,
			'execute' => true,
		);

		if (!$step || !bouncer($step, $steps))
		{
			$step = 'form';
		}

		$this->initialize();
		$this->verify_terminals();
		$this->$step();
	}

	/**
	 * Constructor.
	 */

	public function __construct()
	{
		add_privs('rah_terminal', '1');
		add_privs('rah_terminal.php', '1');
		add_privs('rah_terminal.sql', '1');
		add_privs('rah_terminal.exec', '1');
		add_privs('plugin_prefs.rah_terminal', '1');
		register_callback(array($this, 'prefs'), 'plugin_prefs.rah_terminal');
		register_callback(array($this, 'panes'), 'rah_terminal');
		register_callback(array($this, 'head'), 'admin_side', 'head_end');
		register_tab('extensions', 'rah_terminal', gTxt('rah_terminal'));
	}

	/**
	 * Initializes.
	 */

	public function initialize()
	{
		global $txp_user;

		$this
			->add_terminal('php', gTxt('rah_terminal_php'), array($this, 'process_php'))
			->add_terminal('sql', gTxt('rah_terminal_sql'), array($this, 'process_sql'))
			->add_terminal('exec', gTxt('rah_terminal_exec'), array($this, 'process_exec'));

		if (function_exists('posix_getpwuid'))
		{
			$u = posix_getpwuid(posix_geteuid());
			$this->userstamp = !empty($u['name']) ? $u['name'] : NULL;
		}

		if (!$this->userstamp)
		{
			$this->userstamp = $txp_user;
		}

		if (is_callable('php_uname'))
		{
			$this->userstamp .= '@' . php_uname('n');
		}
		else
		{
			$this->userstamp .= '@Textpattern';
		}
	}

	/**
	 * Registers a terminal processor.
	 *
	 * @param  string        $name
	 * @param  string|null   $label
	 * @param  callback|null $callback
	 * @return rah_terminal
	 */

	public function add_terminal($name, $label, $callback = null)
	{
		if ($label === null || $callback === null)
		{
			unset($this->terminal_labels[$name], $this->terminals[$name]);
		}
		else if (!in_array($label, $this->terminal_labels))
		{
			$this->terminals[$name] = $callback;
			$this->terminal_labels[$name] = $label;
			asort($this->terminal_labels);
		}

		return $this;
	}

	/**
	 * Verifies user's terminal permissions.
	 *
	 * This method strips out terminal options which the current
	 * user doesn't have privileges to.
	 */

	private function verify_terminals() {
		foreach ($this->terminals as $name => $callback)
		{
			if (!has_privs('rah_terminal.'.$name) || !is_callable($callback))
			{
				unset($this->terminal_labels[$name], $this->terminals[$name]);
			}
		}
	}

	/**
	 * The main panel.
	 *
	 * Returns the main terminal form.
	 *
	 * @param string|array $message The activity message
	 */

	public function form($message = '')
	{
		global $event;

		pagetop(gTxt('rah_terminal'), $message);

		echo
			'<h1 class="txp-heading">'.gTxt('rah_terminal').'</h1>'.n.
			'<form method="post" action="index.php" id="rah_terminal_container" class="txp-container">'.n.
			eInput($event).
			sInput('execute').
			tInput().n.
			'	<p>'.selectInput('type', $this->terminal_labels, get_pref('rah_terminal_last_type')).'</p>'.n.
			'	<p>'.n.
			'		<textarea class="code" name="code" rows="12" cols="20">'.txpspecialchars(ps('code')).'</textarea>'.n.
			'	</p>'.n.
			'	<p>'.n.
			'		<input type="submit" value="'.gTxt('rah_terminal_run').'" class="publish" />'.n.
			'	</p>'.n.
			'</form>' .n;
	}

	/**
	 * Executes commands, content or code.
	 *
	 * Outputs results as an asynchronous response script.
	 */

	public function execute()
	{	
		global $theme, $app_mode;

		extract(psa(array(
			'type',
			'code'
		)));

		$js = array();
		$msg = gTxt('rah_terminal_success');

		if (!isset($this->terminals[$type]))
		{
			$msg = array(gTxt('rah_terminal_unknown_type'), E_WARNING);
		}

		else if (trim($code) === '')
		{
			$msg = array(gTxt('rah_terminal_code_required'), E_WARNING);
		}

		else
		{
			set_pref('rah_terminal_last_type', $type, 'rah_terminal', PREF_HIDDEN, '', 0, PREF_PRIVATE);

			try
			{
				ob_start();
				@error_reporting(-1);
				@set_error_handler(array($this, 'error'));
				$runtime = getmicrotime();
				@$direct = call_user_func($this->terminals[$type], $code);
				$runtime = rtrim(number_format(getmicrotime() - $runtime, 15, '.', ''), 0);
				restore_error_handler();
				$buffer = ob_get_clean();
			}
			catch (exception $e)
			{
				$this->error[] = $e->getMessage();
			}

			if ($this->error)
			{
				$msg = array(gTxt('rah_terminal_error'), E_ERROR);
			}

			elseif ($direct === false && $buffer === '')
			{
				$msg = array(gTxt('rah_terminal_blackhole'), E_WARNING);
			}

			if ($buffer !== ' ' && $direct === NULL)
			{
				$direct = trim($buffer);
			}

			$stamp = gTxt('rah_terminal_said_by', array(
				'{time}' => safe_strftime(gTxt('rah_terminal_timestamp')),
				'{user}' => $this->userstamp,
			));

			if ($this->type === NULL)
			{
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

			foreach ($this->error as $error)
			{
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
			
			$js[] = "$('#rah_terminal_container').after('".escape_js($code)."');";
		}

		if ($app_mode == 'async')
		{
			send_script_response(implode(n, $js) . $theme->announce_async($msg));
			return;
		}

		$this->form($msg);
		echo $code;
	}

	/**
	 * Takes command's output and makes that into a safe, human-readable string.
	 *
	 * @param  mixed  $code The input
	 * @return string The input in safe format
	 */

	private function output($code)
	{
		if (is_bool($code))
		{
			return $code ? '(bool) true' : '(bool) false';
		}

		if (is_scalar($code))
		{
			return $code;
		}

		if (is_array($code))
		{
			return print_r($code, true);
		}

		return getType($code);
	}

	/**
	 * Evaluates PHP.
	 *
	 * This method handles 'php' terminal option.
	 *
	 * @param  string $php
	 * @return mixed  Returned value, NULL or FALSE
	 */

	private function process_php($php)
	{
		return eval("echo ' '; {$php}");
	}

	/**
	 * Executes shell commands.
	 *
	 * This method handles 'shell' terminal option.
	 *
	 * @param  string $cmd
	 * @return string Standard output
	 */

	private function process_exec($cmd)
	{
		system($cmd, $output);
		return $output;
	}

	/**
	 * Executes an SQL query.
	 *
	 * @param  string $sql The statement
	 * @return mixed
	 */

	private function process_sql($sql)
	{
		global $DB;

		$q = safe_query($sql);

		$this->type = gettype($q);
		$this->userstamp = $DB->user . '@' . $DB->host;

		if ($q === false)
		{
			$this->error = array();
			trigger_error(mysql_error($DB->link) . ' ('.mysql_errno($DB->link).')', E_USER_ERROR);
			return $q;
		}
		else
		{
			$this->notes[] = gTxt('rah_terminal_rows_affected', array('{count}' => mysql_affected_rows($DB->link)));
		}
		
		if (is_resource($q))
		{
			$out = array();

			while($r = mysql_fetch_assoc($q))
			{
				$out[] = $r;
			}

			return $out;
		}

		return $q;
	}

	/**
	 * Adds styles and JavaScript to the &lt;head&gt;.
	 */

	public function head()
	{
		global $event, $theme;

		if ($event != 'rah_terminal')
		{
			return;
		}

		$error = escape_js($theme->announce_async(array(gTxt('rah_terminal_fatal_error'), E_ERROR)));

		echo <<<EOF
			<style type="text/css">
				.rah_terminal_result_close {
					float: right;
				}
			</style>
EOF;

		$js = <<<EOF
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

				$(document).on('click', '.rah_terminal_result_close', function(e) {
					e.preventDefault();
					$(this).parents('.rah_terminal_result').remove();
				});
			});
EOF;

		echo script_js($js);
	}

	/**
	 * Error handler for terminal options.
	 *
	 * @param  int    $type    The error type
	 * @param  string $message The error message
	 * @return bool   Returns TRUE
	 */

	public function error($type, $message)
	{	
		$error = array(
			E_WARNING => 'Warning',
			E_NOTICE => 'Notice',
			E_USER_ERROR => 'Error',
			E_USER_WARNING => 'Warning',
			E_USER_NOTICE => 'Notice'
		);

		if (isset($error[$type]))
		{
			$this->error[] = $error[$type].': '.$message;
		}

		return true;
	}

	/**
	 * Plugin's options page.
	 *
	 * Redirects to the plugin's panel.
	 */

	public function prefs()
	{
		header('Location: ?event=rah_terminal');
		echo '<p><a href="?event=rah_terminal">'.gTxt('continue').'</a></p>';
	}
}

?>