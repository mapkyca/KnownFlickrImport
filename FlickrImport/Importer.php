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
	 * Extend the flickr API to get the sizes of a photo
	 * @param type $photoid
	 */
	protected static function __photoGetSizes($photo_id, $api) {

	    $params = array();
	    if ($api->token)
		$params['auth_token'] = $api->token;
	    $params['photo_id'] = $photo_id;
	    if ($secret)
		$params['secret'] = $secret;

	    $xml = $api->callMethod('flickr.photos.getSizes', $params);
	    if (!$xml) {
		return FALSE;
	    }

	    foreach ($xml->sizes->attributes() as $k => $v) {
		$ret[$k] = (string) $v;
	    }
	    $i = 0;
	    foreach ($xml->sizes->size as $photo) {
		foreach ($photo->attributes() as $k => $v) {
		    $ret['sizes'][$i][$k] = (string) $v;
		}

		// Correcting array (don't ask)
		$label = $ret['sizes'][$i]['label'];
		$ret['sizes'][$label] = $ret['sizes'][$i];
		unset($ret['sizes'][$i]);

		$i++;
	    }

	    return $ret;
	}

	/**
	 * Replace flickr API version with one that accepts parameters.
	 * @param type $photoset_id
	 * @param type $params
	 * @param type $api
	 * @return boolean
	 */
	protected static function __photosetsGetPhotos($photoset_id, $params = [], $api) {
	    $params = array_merge(array('photoset_id' => $photoset_id), $params);
	    $xml = $api->callMethod('flickr.photosets.getPhotos', $params);
	    if (!$xml) {
		return FALSE;
	    }
	    foreach ($xml->photoset->attributes() as $k => $v) {
		$ret[$k] = (string) $v;
	    }
	    $i = 0;
	    foreach ($xml->photoset->photo as $photo) {
		foreach ($photo->attributes() as $k => $v) {
		    $ret['photos'][(string) $photo['id']][$k] = (string) $v;
		}
		$i++;
	    }
	    return $ret;
	}
	
	/**
	 * Retrieve a user's collections.
	 * @param type $nsid
	 * @param type $params
	 * @param type $api
	 */
	protected static function __collectionsGetTree($nsid, $params= [], $api) {
	    $params = array_merge(array('user_id' => $nsid), $params);
	    $xml = $api->callMethod('flickr.collections.getTree', $params);
	    if (!$xml) {
		return FALSE;
	    }
	    foreach ($xml->collections->attributes() as $k => $v) {
		$ret[$k] = (string) $v;
	    }
	    $i = 0;
	    foreach ($xml->collections->collection as $collection) {
		foreach ($collection->attributes() as $k => $v) {
		    $ret['collections'][(string) $collection['id']][$k] = (string) $v;
		}
		
		$ret['collections'][(string) $collection['id']]['sets'] = [];
		foreach ($collection->set as $set) {
		    $ret['collections'][(string) $collection['id']]['sets'][] = (string)$set['id'];
		}
		
		$i++;
		
	    }
	    return $ret;
	}

	protected static function importVideo(array $photo, $api) {
	    $lockfile = self::__workingDir() . $photo['id'] . '.lck';
	    $datafile = self::__workingDir() . $photo['id'] . '.mp4';

	    if (!file_exists($lockfile)) {

		// Lock the file
		file_put_contents($lockfile, $photo);
		self::log("New Video {$photo['id']} ('{$photo['title']}'), processing...");

		// See if we've saved this photo before
		$photo_obj = \IdnoPlugins\Media\Media::getOneFromAll(array('flickr_id' => $photo['id']));
		if (!$photo_obj) {
		    self::log("Not processed this video before, creating new Media object");
		    $photo_obj = new \IdnoPlugins\Media\Media();
		    $photo_obj->flickr_id = $photo['id'];

		    // Retrieve video file
		    self::log("Retrieving video sizes...");
		    $sizes = self::__photoGetSizes($photo['id'], $api);

		    $source = $sizes['sizes']['HD MP4']['source'];
		    if (!$source) {
			$source = $sizes['sizes']['Site MP4']['source'];
		    }
		    if (!$source) {
			$source = $sizes['sizes']['Mobile MP4']['source'];
		    }

		    if (!$source)
			throw new \Exception("Could not find a source for the video download!");

		    // Download source file
		    self::log("Source for video found at $source, downloading...");
		    $data = \Idno\Core\Webservice::file_get_contents($source); // TODO: use some cool download script to avoid sucking things into memory.
		    if (!$data)
			throw new \Exception('Could not download file...');
		    file_put_contents($datafile, $data);
		    $data = null;
		    unset($data); // clean up

		    gc_collect_cycles();

		    $_FILES = [
			'media' => [
			    'tmp_name' => $datafile,
			    'name' => "{$photo['id']}.mp4",
			    'type' => 'video/mp4'
			]
		    ];
		} else {
		    self::log("Editing existing Media entry.");
		}

		// Retrieve extra photo details
		self::log("Retrieving details...");
		$details = $api->photosGetInfo($photo['id'], $photo['secret']);
		if (!$details)
		    throw new \Exception('Could not retrieve photo details');


		// Set input fields
		\Idno\Core\site()->currentPage()->setInput('title', $photo['title']);
		self::log("Setting title to " . \Idno\Core\site()->currentPage()->getInput('title'));

		\Idno\Core\site()->currentPage()->setInput('body', isset($photo['description']) ? $photo['description'] : $details['description']); // Description doesn't seem to be being returned in normal search
		self::log("Setting body to " . \Idno\Core\site()->currentPage()->getInput('body'));

		// Turn tags into #tags
		$tags = [];
		if ($details['tags']) {
		    foreach ($details['tags'] as $tag) {
			$tags[] = '#' . trim(str_replace(' ', '', $tag['text']), '"#');
		    }
		}
		//\Idno\Core\site()->currentPage()->setInput('tags', $tags);
		//self::log("Setting tags to " . \Idno\Core\site()->currentPage()->getInput('tags'));
		\Idno\Core\site()->currentPage()->setInput('body', \Idno\Core\site()->currentPage()->getInput('body') . "\n\n" . trim(implode(' ', $tags)));
		self::log("Adding tags as hashtags: " . trim(implode(' ', $tags)));

		\Idno\Core\site()->currentPage()->setInput('created', "{$photo['datetaken']} PST"); // Flickr times appear to be in PST
		self::log("Setting created time to " . \Idno\Core\site()->currentPage()->getInput('created'));

		if (!$photo['ispublic']) {
		    // Not a public photo
		    $uuid = \Idno\Core\site()->session()->currentUserUUID();
		    \Idno\Core\site()->currentPage()->setInput('access', $uuid);

		    self::log("Not a public picture, setting access to " . \Idno\Core\site()->currentPage()->getInput('access'));
		} else
		    self::log("Picture is public, using default ACL");

		self::log("Adding raw flickr photo details to object (for later processing and error correction)...");
		$photo_obj->flickr_photo = serialize($photo);
		$photo_obj->flickr_photo_extra = serialize($details);
		$photo_obj->flickr_video_sizes = serialize($sizes);

		// Save some "flickr" details
		$photo_obj->flickr_page = $details['urls']['photopage'];
		self::log("Saving flickr page for this entry: {$photo_obj->flickr_page}");

		if ($photo_obj->saveDataFromInput())
		    self::log("New video entry created at " . $photo_obj->getUrl());

		$photo_obj = null;
	    } else
		self::log("Video {$photo['id']} already seen (locked by $lockfile)");

	    gc_collect_cycles();    // Clean memory

	    self::log("Ok\n");
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

		    if (!$data)
			throw new \Exception("Could not retrieve photo");
		    self::log("Retrieved " . strlen($data) . " bytes, saving it to $datafile");
		    file_put_contents($datafile, $data);
		    $data = null;

		    // Fudge $_FILE and other vars
		    switch ($photo['originalformat']) {
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

		// Retrieve extra photo details
		self::log("Retrieving details...");
		$details = $api->photosGetInfo($photo['id'], $photo['secret']);
		if (!$details)
		    throw new \Exception('Could not retrieve photo details');


		// Set input fields
		\Idno\Core\site()->currentPage()->setInput('title', $photo['title']);
		self::log("Setting title to " . \Idno\Core\site()->currentPage()->getInput('title'));

		\Idno\Core\site()->currentPage()->setInput('body', isset($photo['description']) ? $photo['description'] : $details['description']); // Description doesn't seem to be being returned in normal search
		self::log("Setting body to " . \Idno\Core\site()->currentPage()->getInput('body'));

		// Turn tags into #tags
		$tags = [];
		if ($details['tags']) {
		    foreach ($details['tags'] as $tag) {
			$tags[] = '#' . trim(str_replace(' ', '', $tag['text']), '"#');
		    }
		}
		//\Idno\Core\site()->currentPage()->setInput('tags', $tags);
		//self::log("Setting tags to " . \Idno\Core\site()->currentPage()->getInput('tags'));
		\Idno\Core\site()->currentPage()->setInput('body', \Idno\Core\site()->currentPage()->getInput('body') . "\n\n" . trim(implode(' ', $tags)));
		self::log("Adding tags as hashtags: " . trim(implode(' ', $tags)));

		\Idno\Core\site()->currentPage()->setInput('created', "{$photo['datetaken']} PST"); // Flickr times appear to be in PST
		self::log("Setting created time to " . \Idno\Core\site()->currentPage()->getInput('created'));

		if (!$photo['ispublic']) {
		    // Not a public photo
		    $uuid = \Idno\Core\site()->session()->currentUserUUID();
		    \Idno\Core\site()->currentPage()->setInput('access', $uuid);

		    self::log("Not a public picture, setting access to " . \Idno\Core\site()->currentPage()->getInput('access'));
		} else
		    self::log("Picture is public, using default ACL");

		self::log("Adding raw flickr photo details to object (for later processing and error correction)...");
		$photo_obj->flickr_photo = serialize($photo);
		$photo_obj->flickr_photo_extra = serialize($details);

		// Save some "flickr" details
		$photo_obj->flickr_page = $details['urls']['photopage'];
		self::log("Saving flickr page for this entry: {$photo_obj->flickr_page}");

		// See if this is a video
		if ($details['media'] == 'video') {
		    self::log("Aha... this photo is actually a video...");

		    self::importVideoData($photo, $photo_obj, $api);
		}

		if ($photo_obj->saveDataFromInput())
		    self::log("New photo entry created at " . $photo_obj->getUrl());

		$photo_obj = null;
	    } else
		self::log("Photo {$photo['id']} already seen (locked by $lockfile)");

	    gc_collect_cycles();    // Clean memory

	    self::log("Ok\n");
	}

	/**
	 * Import a photoset
	 * @param array $set
	 * @param type $api
	 */
	protected static function importPhotoset(array $set, $api) {

	    // Create new storage
	    if ($photoset = \Idno\Entities\GenericDataItem::getByDatatype('Flickr/Photoset', ['photoset_id' => $set['id']])) {
		self::log("Existing photoset {$set['id']} - '{$set['title']}', updating...");

		$newset = $photoset[0];
	    } else {
		self::log("Importing new photoset {$set['id']} - '{$set['title']}'");
		$newset = new \Idno\Entities\GenericDataItem();
		$newset->setDatatype('Flickr/Photoset');
	    }

	    // Remap certain fields, as they clash
	    $translate = [
		'id' => 'photoset_id',
		'primary' => 'primary_photo_id',
	    ];

	    foreach ($set as $key => $value) {

		if (isset($translate[$key]))
		    $key = $translate[$key];

		$newset->$key = $value;

		self::log("$key => $value");
	    }

	    // Now get a list of photos in this photoset
	    if ($photos = self::__photosetsGetPhotos($set['id'], [], $api)) {

		$photosInSet = [];

		self::log("Photoset consists of {$photos['total']} photos in {$photos['pages']} pages of max {$photos['per_page']} photos.");

		$page = $photos['page'];
		$pages = $photos['pages'];
		$limit = $photos['per_page'];

		do {

		    // Add to set
		    foreach ($photos['photos'] as $photo_id => $details) {
			$photosInSet[] = "$photo_id"; // Add photo ID as string to avoid unexpected values
			
			// Catch rare instances when primary photo isn't set
			if (!$newset->primary_photo_id) {
			    self::log("Primary photo doesn't appear to have been set, using $photo_id");
			    $newset->primary_photo_id = "$photo_id";
			}
			
			self::log("Added $photo_id to set");
		    }

		    $page++;
		    if ($page <= $pages) {
			self::log("Retrieving page $page of $pages");
			$photos = self::__photosetsGetPhotos($set['id'], ['page' => $page], $api);
		    }
		} while ($page <= $pages);

		$newset->photos = $photosInSet;
	    } else
		self::log("Photoset {$set['id']} appears to contain no photos!", LOGLEVEL_WARNING);

	    self::log("Ok\n");
	    return $newset->save();
	}

	/**
	 * Import a collection
	 * @param array $collection
	 * @param type $api
	 */
	protected static function importCollection(array $collection, $api) {

	    // Create new storage
	    if ($collection_set = \Idno\Entities\GenericDataItem::getByDatatype('Flickr/Collection', ['collection_id' => $collection['id']])) {
		self::log("Existing collection {$collection['id']} - '{$collection['title']}', updating...");

		$newcollection = $collection_set[0];
	    } else {
		self::log("Importing new collection {$collection['id']} - '{$collection['title']}'");
		$newcollection = new \Idno\Entities\GenericDataItem();
		$newcollection->setDatatype('Flickr/Collection');
	    }

	    // Remap certain fields, as they clash
	    $translate = [
		'id' => 'collection_id',
	    ];

	    foreach ($collection as $key => $value) {

		if (isset($translate[$key]))
		    $key = $translate[$key];

		$newcollection->$key = $value;

		self::log("$key => " . var_export($value, true));
	    }
	    
	    self::log("Ok\n");
	    return $newcollection->save();
	}
	
	/**
	 * Do the import.
	 * 
	 * This function will check for credentials, and then execute an import. Import is done in the background using pcntl_fork.
	 * 
	 */
	public static function import() {

	    $user = \Idno\Core\site()->session()->currentUser();

	    // Register a shutdown hook to do cleanup
	    register_shutdown_function(function() {

		// Unlock
		unlink(self::__pidFilename());
	    });

	    // Make working dir
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

	    // Parent, save PID to file (this locks the process).
	    self::log("PID is $pid, creating pidfile " . self::__pidFilename() . ' (if something goes totally wrong, try deleting this file and rerunning the import!)');
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

			    $cnt = 0;

			    self::log("Importing for connected account {$account}");

			    /**
			     * First things first, check we have a user ID attached to this account
			     */
			    // Get NSID
			    $nsid = $details['nsid'];
			    if ((!isset($details['nsid'])) || (!$nsid))
				throw new \Exception('Could not retrieve NSID for user account, try updating your Flickr plugin and reconnecting to flickr.');
			    self::log("NSID is $nsid");

			    /**
			     * Import the photos
			     */
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
				if ($page = $api->photosSearch($nsid, '', '', '', '', '', '', '', '', 'license, description, date_upload, date_taken, owner_name, icon_server, original_format, last_update, geo, tags, machine_tags, o_dims, views, media, path_alias, url_sq, url_t, url_s, url_q, url_m, url_n, url_z, url_c, url_l, url_o', $limit, $n)) {
				    foreach ($page['photos'] as $photo) {

					if ($photo['media'] == 'video')
					    self::importVideo($photo, $api);
					else
					    self::importPhoto($photo, $api);

					$cnt++;
				    }
				}
			    }

			    self::log("Imported $cnt photos/videos from {$account}");


			    /**
			     * Now, import photosets (if we can)
			     */
			    if (class_exists('Idno\Entities\GenericDataItem')) {
				self::log("Storing photosets as GenericDataItem. Photosets currently have no direct mapping in Known, so we're just storing the data so your themes can make sense of it.");

				if ($photosets = $api->photosetsGetList($nsid)) {
				    self::log("Importing {$photosets['total']} photosets...");

				    foreach ($photosets['photosets'] as $setid => $set) {
					self::importPhotoset($set, $api);
				    }
				} else
				    self::log("No photosets could be retrieved");
			    } else
				self::log("GenericDataItem class doesn't exist on your version of Known, so your photosets can't be imported. Update Known and try again!", LOGLEVEL_WARNING);


			    /**
			     * Now, import collections (if we can) - Currently only the top level of the tree is imported.
			     */
			    if (class_exists('Idno\Entities\GenericDataItem')) {
				self::log("Storing Collections as GenericDataItem. Collections currently have no direct mapping in Known, so we're just storing the data so your themes can make sense of it.");
				
				if ($collection = self::__collectionsGetTree($nsid, [], $api)) {
				    self::log("Importing collections...");

				    foreach ($collection['collections'] as $id => $collection) {
					self::importCollection($collection, $api);
				    }
				} else
				    self::log("No photosets could be retrieved");
				
			    } else
				self::log("GenericDataItem class doesn't exist on your version of Known, so your collections can't be imported. Update Known and try again!", LOGLEVEL_WARNING);
			}

			// We got here without error, so lets send a success message
			$mail = new \Idno\Core\Email();
			$mail->setHTMLBodyFromTemplate('account/flickrimport');
			$mail->setTextBodyFromTemplate('account/flickrimport');
			$mail->addTo(\Idno\Core\site()->session()->currentUser()->email);
			$mail->setSubject("Your Flickr account has been imported!");
			$mail->send();
		    } else
			throw new \Exception("Could not connect to Flickr, possibly your API keys have expired.");
		} else
		    throw new \Exception("Flickr plugin not activated on this install.");
	    } catch (\Exception $e) {
		self::log($e->getMessage(), LOGLEVEL_ERROR);
	    }

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

	    if (!\Idno\Core\site()->plugins()->get('Media'))
		throw new \Exception("FlickrImport requires the Known Media plugin to import videos, and this doesn't appear to be installed/activated.");
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
