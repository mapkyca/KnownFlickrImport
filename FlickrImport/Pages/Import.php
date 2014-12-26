<?php

    namespace IdnoPlugins\FlickrImport\Pages {

        class Import extends \Idno\Common\Page
        {

            function getContent()
            {
		$this->gatekeeper();
		
		if (\IdnoPlugins\FlickrImport\Importer::isImporting()) {

		    // Display log file.
		    echo \IdnoPlugins\FlickrImport\Importer::getLog();

		} else {

		    // Do an import
		    \IdnoPlugins\FlickrImport\Importer::import();

		}
            }
	    
        }

    }