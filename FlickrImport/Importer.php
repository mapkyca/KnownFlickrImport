<?php

namespace IdnoPlugins\FlickrImport {

    class Importer extends \Idno\Common\Plugin {

	/**
	 * If an import process is running, returns true, otherwise returns false.
	 */
	public static function isImporting() {
	    
	}

	/**
	 * Do the import.
	 * 
	 * This function will check for credentials, and then execute an import. Import is done in the background using pcntl_fork.
	 * 
	 */
	public static function import() {

	    // Sanity check
	    self::__checkEnvironment();

	    // Ok, so fork
	    $pid = pcntl_fork();
	    if ($pid == -1)
		throw new \Exception("FlickImport: Could not fork");
	    else if ($pid) {
		// Parent, save PID to file.
		file_put_contents(self::__pidFilename(), $pid);
	    } else {
		try {
		    // Child, execute import
		    // Tidy up from last run
		    unlink(self::__logFilename());

		    // Load the flickr API from Flickr plugin
		    if ($flickr = \Idno\Core\site()->plugins()->get('Flickr')) {

			// Process photos
			if ($api = $flickr->connect()) {

			    // Get initial stats
			    self::log(print_r($api->photosSearch('me')));
			    
			    
			    
			} else
			    throw new \Exception("Could not connect to Flickr, possibly your API keys have expired.");
		    } else
			throw new \Exception("Flickr plugin not activated on this install.");
		} catch (\Exception $e) {
		    self::log($e->getMessage(), LOGLEVEL_ERROR);
		}

		// Unlock
		unlink(self::__pidFilename());
		exit;
	    }
	}

	/**
	 * Retrieve the log for the current user.
	 */
	public static function getLog() {
	    return file_get_contents(self::__logFilename());
	}

	public static function log($message, $level = 3) {

	    $message = "FlickrImport: " . trim($message, "\n ");

	    if ($level == 1) {
		$message = "*** $message ***";
	    }

	    $f = fopen(self::__logFilename(), 'a+');
	    fwrite($f, "$message\n");
	    fclose($f);

	    \Idno\Core\site()->logging()->log($message, $level);
	}

	private static function __checkEnvironment() {

	    // Sanitiy check for disabled functions
	    $disabled_functions = ini_get('disable_functions');

	    foreach (['pcntl_fork'] as $function) {
		if (strpos($disabled_functions, $function) !== false)
		    throw new \Exception("FlickrImport: Environment error; function $function is disabled in php.ini!");

		if (!function_exists($function))
		    throw new \Exception("FlickrImport: Environment error; function $funciton is unavailable on your installation of PHP");
	    }

	    // Check plugins
	    if (!\Idno\Core\site()->plugins()->get('Flickr'))
		throw new \Exception("FlickrImport requires the Known Flickr plugin, and this doesn't appear to be installed/activated.");
	    
	    if (!\Idno\Core\site()->plugins()->get('Photo'))
		throw new \Exception("FlickrImport requires the Known Photo plugin, and this doesn't appear to be installed/activated.");
	}

	private static function __logFilename() {
	    $tmp = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR;
	    $user = \Idno\Core\site()->session()->currentUserUUID();

	    return tempnam($tmp, "FlickrImport_" . md5($user) . '.log');
	}

	private static function __pidFilename() {
	    $tmp = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR;
	    $user = \Idno\Core\site()->session()->currentUserUUID();

	    return tempnam($tmp, "FlickrImport_" . md5($user) . '.pid');
	}

    }

}