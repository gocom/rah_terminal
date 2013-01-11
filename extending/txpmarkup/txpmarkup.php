<?php

/**
 * Generates HTML markup from supplied Textpattern tag markup.
 *
 * @package   rah_terminal
 * @author    Jukka Svahn
 * @copyright (c) 2012 Jukka Svahn
 * @date      2012-
 * @license   GNU GPLv2
 *
 * Copyright (C) 2012 Jukka Svahn http://rahforum.biz
 * Licensed under GNU Genral Public License version 2
 * http://www.gnu.org/licenses/gpl-2.0.html
 */

	new rah_terminal__txpmarkup();

/**
 * Texttpattern markup module.
 */

class rah_terminal__txpmarkup
{
	/**
	 * Constructor.
	 */

	public function __construct()
	{
		register_callback(array($this, 'register'), 'rah_terminal', '', 1);
	}

	/**
	 * Registers a terminal option.
	 */

	public function register()
	{
		add_privs('rah_terminal.txpmarkup', '1,2');
		rah_terminal::get()->add_terminal('txpmarkup', 'TXP Markup', array($this, 'process'));
	}

	/**
	 * Processes Textpattern tags.
	 *
	 * @param  string $markup Markup
	 * @return string HTML
	 */

	public function process($markup)
	{
		if (!function_exists('parse'))
		{
			include_once txpath . '/publish.php';
		}

		return parse($markup);
	}
}