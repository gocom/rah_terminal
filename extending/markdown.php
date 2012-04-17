<?php

/**
 * Generates HTML markup from supplied Markdown markup
 *
 * @package rah_terminal
 * @author Jukka Svahn
 * @copyright (c) 2012 Jukka Svahn
 * @date 2012-
 * @license GNU GPLv2
 *
 * Requires PHP Markup
 * http://michelf.com/projects/php-markdown/
 *
 * Copyright (C) 2012 Jukka Svahn <http://rahforum.biz>
 * Licensed under GNU Genral Public License version 2
 * http://www.gnu.org/licenses/gpl-2.0.html
 */

new rah_terminal__markdown();

class rah_terminal__markdown {

	/**
	 * Constructor
	 */

	public function __construct() {
		add_privs('rah_terminal.markdown', '1,2,3,4');
		rah_terminal::get()->add_terminal('markdown', 'Markdown', array($this, 'process'));
	}

	/**
	 * Process Markdown
	 * @param string $markup Textile Markup passed to the function.
	 * @return string HTML
	 */

	public function process($markup) {
		
		$markdown = defined('rah_terminal__markdown') ? rah_terminal__markdown : '';
		
		if($markdown && !function_exists('Markdown') && file_exists($markdown) && is_file($markdown) && is_readable($markdown)) {
			include_once $markdown;
		}
		
		if(!function_exists('Markdown')) {
			trigger_error('Markdown is not available. Include markdown.php in textpattern/config.php or set a path to it with "rah_terminal__markdown" constant');
			return false;
		}
		
		return Markdown($markup);
	}
}

?>