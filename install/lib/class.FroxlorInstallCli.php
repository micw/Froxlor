<?php

/**
 * This file is part of the Froxlor project.
 * Copyright (c) 2010 the Froxlor Team (see authors).
 *
 * For the full copyright and license information, please view the COPYING
 * file that was distributed with this source code. You can also view the
 * COPYING file online at http://files.froxlor.org/misc/COPYING.txt
 *
 * @copyright  (c) the authors
 * @author     Michael Kaufmann <mkaufmann@nutime.de>
 * @author     Froxlor team <team@froxlor.org> (2010-)
 * @license    GPLv2 http://files.froxlor.org/misc/COPYING.txt
 * @package    Classes
 *
 * @since      0.9.29.1
 *
 */

require 'class.FroxlorInstall.php';

/**
 * Class FroxlorInstallCli
 *
 * Does the dirty work (CLI variant)
 *
 * @copyright (c) the authors
 * @author Michael Kaufmann <mkaufmann@nutime.de>
 * @author Froxlor team <team@froxlor.org> (2010-)
 * @license GPLv2 http://files.froxlor.org/misc/COPYING.txt
 * @package Install
 *         
 */
class FroxlorInstallCli extends FroxlorInstall
{
	public function run($argv)
	{
		// include the functions
		require $this->_basepath . '/lib/functions.php';
		// include the MySQL-Table-Definitions
		require $this->_basepath . '/lib/tables.inc.php';
		// include language
		$this->_includeLanguageFile();

		if (count($argv)!=2 || !in_array($argv[1],array('--check','--setup')) )
		{
			print('USAGE: ' . $argv[0] . ' --check|--setup' . PHP_EOL);
			exit(1);
		}

		print('Checking system requirements ...'.PHP_EOL);
		$requirement_check_result=$this->_requirementCheck();

		if (!$this->_processCheckResults($requirement_check_result))
		{
			print('Please install the required system requirements to continue!'.PHP_EOL);
			exit(1);
		}
		print(PHP_EOL);


		print('Getting setup information from environment variables ...'.PHP_EOL);
		$data_check_result=$this->_checkInstallData();
		foreach ($this->_data as $key => $value)
		{
			if (!array_key_exists($key,$data_check_result))
			{
				if (substr($key, -5) === '_pass')
				{
					$value='*****';
				}
				print(' OK:      '.str_pad($key,45,' ').' '.$value.PHP_EOL);
			}
		}
		foreach ($data_check_result as $key => $value)
		{
			print(' ERROR:   '.str_pad($key,45,' ').' '.$value.PHP_EOL);
		}
		if (count($data_check_result)>0)
		{
			print('Please provide all required environment variables to continue!'.PHP_EOL);
			exit(1);
		}
		print(PHP_EOL);


		print('Starting setup ...'.PHP_EOL);
		$setup_result=$this->_doInstall();

		if (!$this->_processCheckResults($setup_result))
		{
			print('Setup failed. Please fix the errors to continue!'.PHP_EOL);
			exit(1);
		}
		print(PHP_EOL);
		print('Done.'.PHP_EOL);
	}

	/**
	 * Prints a list of check results. Returns true if all results are green or orange,
	 * false otherwise
	 */
	private function _processCheckResults($checkresults)
	{
		$ok=true;
		foreach ($checkresults as &$r)
		{
			if ($r['status']=='green')
			{
				print(' OK:      ');
			}
			else if ($r['status']=='orange')
			{
				print(' WARNING: ');
			}
			else
			{
				$ok=false;
				print(' ERROR:   ');
			}

			print(str_pad($r['check'],45,' ').' '.$r['message'].PHP_EOL);
			if (array_key_exists('description',$r))
			{
				print('           '.$r['description'].PHP_EOL);
			}

		}
		return $ok;
	}

	protected function _getField($fieldname)
	{
		return getenv($fieldname);
	}

}