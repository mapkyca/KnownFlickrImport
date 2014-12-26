<?php

namespace IdnoPlugins\FlickrImport {

    class Importer extends \Idno\Common\Plugin {

	/**
	 * If an import process is running, returns true, otherwise returns false.
	 */
	public static function isImporting() {
	    return file_exists(self::__pidFilename());
	}

	/**
	 * Import a photo.
	 * @param array $photo The photo details
	 */
	protected static function importPhoto(array $photo, $api) {

	    $lockfile = self::__workingDir() . $photo['id'] . '.lck';
	    $datafile = self::__workingDir() . $photo['id'] . '.dat';

	    if (!file_exists($lockfile)) {

		// Lock the file
		file_put_contents($lockfile, $photo);
		self::log("New Photo {$photo['id']} ('{$photo['title']}'), processing...");

		// See if we've saved this photo before
		$photo_obj = \IdnoPlugins\Photo\Photo::getOneFromAll(array('flickr_id' => $photo['id']));
		if (!$photo_obj) {
		    self::log("Not processed this photo before, creating new Photo object");
		    $photo_obj = new \IdnoPlugins\Photo\Photo();
		    $photo_obj->flickr_id = $photo['id'];
		
		    
		    // Retrieve photo
		    self::log("Retrieving {$photo['url_o']}...");
		    $data = \Idno\Core\Webservice::file_get_contents($photo['url_o']);

		    if (!$data) throw new \Exception("Could not retrieve photo");
		    self::log("Retrieved " . strlen($data) . " bytes, saving it to $datafile");
		    file_put_contents($datafile, $data);
		    $data = null;

		    // Retrieve extra photo details
		    self::log("Retrieving details...");
		    $details = $api->photosGetInfo($photo['id'], $photo['secret']);
		    if (!$details)
			throw new \Exception('Could not retrieve photo details');

		    // Fudge $_FILE and other vars
		    switch ($photo['originalformat'])
		    {
			case 'png' : 
			    $mime = 'image/png';
			    break;
			case 'gif' : 
			    $mime = 'image/gif';
			    break;
			case 'jpg':
			default: 
			    $mime = 'image/jpeg';
		    }
		    self::log("MIME type set to $mime");

		    $_FILES = [
			'photo' => [
			    'tmp_name' => $datafile,
			    'name' => "{$photo['id']}.{$photo['originalformat']}",
			    'type' => $mime
			]
		    ];
		} else {
		    self::log("Editing existing photo entry.");
		}
			    
		// Set input fields
		\Idno\Core\site()->currentPage()->setInput('title', $photo['title']);
		self::log("Setting title to " . \Idno\Core\site()->currentPage()->getInput('title'));
		
		\Idno\Core\site()->currentPage()->setInput('body', isset($photo['description']) ? $photo['description'] : $details['description']); // Description doesn't seem to be being returned in normal search
		self::log("Setting body to " . \Idno\Core\site()->currentPage()->getInput('body'));
		
		\Idno\Core\site()->currentPage()->setInput('tags', $photo['tags']);
		self::log("Setting tags to " . \Idno\Core\site()->currentPage()->getInput('tags'));
		
		\Idno\Core\site()->currentPage()->setInput('created', $photo['datetaken']);
		self::log("Setting created time to " . \Idno\Core\site()->currentPage()->getInput('created'));
		
		self::log("Adding raw flickr photo details to object...");
		$photo_obj->flickr_photo = serialize($photo);
		$photo_obj->flickr_photo_extra = serialize($details);
		
		if ($photo_obj->saveDataFromInput())
		    self::log("New photo entry created at " . $photo_obj->getUrl());
		
		$photo_obj = null;
		
	    } else
		self::log("Photo {$photo['id']} already seen (locked by $lockfile)");

	    gc_collect_cycles();    // Clean memory
	}

	/**
	 * Do the import.
	 * 
	 * This function will check for credentials, and then execute an import. Import is done in the background using pcntl_fork.
	 * 
	 */
	public static function import() {

	    $user = \Idno\Core\site()->session()->currentUser();

	    // Make lock dir
	    mkdir(self::__workingDir(), 0777, true);

	    // Tidy up from last run, start new log file
	    unlink(self::__logFilename());
	    self::log("STARTING IMPORT FOR " . $user->getName() . " ON " . date('r'));

	    // Sanity check
	    self::log("Sanity checking environment...");
	    self::__checkEnvironment();

	    // Ok, so execute nohub (better to fork, but pcntl_fork isn't widely avaliable...)
	    $pid = getmypid();
	    if (!$pid)
		throw new \Exception("FlickImport: Could not get process ID");

	    // Parent, save PID to file.
	    self::log("PID is $pid");
	    file_put_contents(self::__pidFilename(), $pid);

	    // Disconnect process from viewer
	    echo "Processing... Please check back later!";
	    self::log("Disconnecting session...");
	    self::__disconnectSession();


	    try {
		// Child, execute import
		// Load the flickr API from Flickr plugin
		if ($flickr = \Idno\Core\site()->plugins()->get('Flickr')) {

		    // Process photos
		    self::log('Connecting to flickr...');
		    if ($api = $flickr->connect()) {

			foreach (\Idno\Core\site()->session()->currentUser()->flickr as $account => $details) {

			    self::log("Importing for connected account {$account}");

			    // Get NSID
			    $nsid = $details['nsid'];
			    if (!$nsid)
				throw new \Exception('Could not retrieve NSID for user account, try updating your Flickr plugin and reconnecting to flickr.');
			    self::log("NSID is $nsid");

			    // Get initial stats
			    if (!$details = $api->photosSearch($nsid))
				throw new \Exception("Could not retrieve details from flickr.");

			    $pages = $details['pages'];
			    $limit = $details['perpage'];
			    $total = $details['total'];

			    self::log("Importing $total photos in $pages pages of $limit photos...");

			    // Do the actual import
			    for ($n = 1; $n <= $pages; $n++) {

				self::log("Processing page $n of $pages...");
				if ($page = $api->photosSearch($nsid, '', '', '', '', '', '', '', '', 
					'license, description, date_upload, date_taken, owner_name, icon_server, original_format, last_update, geo, tags, machine_tags, o_dims, views, media, path_alias, url_sq, url_t, url_s, url_q, url_m, url_n, url_z, url_c, url_l, url_o', $limit, $n)) {

				    foreach ($page['photos'] as $photo) {
					self::importPhoto($photo, $api);
				    }
				}
			    }
			}
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

	    foreach ([] as $function) {
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
	    $tmp = self::__workingDir();
	    $user = \Idno\Core\site()->session()->currentUserUUID();

	    return $tmp . "FlickrImport_" . md5($user) . '.log';
	}

	private static function __pidFilename() {
	    $tmp = self::__workingDir();
	    $user = \Idno\Core\site()->session()->currentUserUUID();

	    return $tmp . "FlickrImport_" . md5($user) . '.pid';
	}

	private static function __workingDir() {
	    $user = \Idno\Core\site()->session()->currentUserUUID();
	    return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'FlickrImport_' . md5($user) . DIRECTORY_SEPARATOR;
	}

	private static function __disconnectSession() {
	    ignore_user_abort(true);    // This is dangerous, but we need export to continue

	    session_write_close();

	    header('Connection: close');
	    header('Content-length: ' . (string) ob_get_length());

	    @ob_end_flush();     // Return output to the browser
	    @ob_end_clean();
	    @flush();

	    sleep(10);    // Pause

	    set_time_limit(0);   // Eliminate time limit - this could take a while
	}

    }

}