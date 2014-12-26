<?php

namespace IdnoPlugins\FlickrImport {

    class Main extends \Idno\Common\Plugin {

	function registerPages() {
	    \Idno\Core\site()->addPageHandler('account/flickrimport', '\IdnoPlugins\FlickrImport\Pages\Account');
	    \Idno\Core\site()->template()->extendTemplate('account/menu/items','account/flickrimport/menu');
	    
	    \Idno\Core\site()->addPageHandler('flickrimport/import', '\IdnoPlugins\FlickrImport\Pages\Import');
	}

    }

}
