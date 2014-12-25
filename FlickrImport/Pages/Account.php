<?php

    /**
     * FlickrImport account page
     */

    namespace IdnoPlugins\FlickrImport\Pages {

        /**
         * Default class to serve FlickrImport-related account settings
         */
        class Account extends \Idno\Common\Page
        {

            function getContent()
            {
                $this->createGatekeeper(); // Logged-in users only
                $t = \Idno\Core\site()->template();
                $body = $t->draw('account/flickrimport');
                $t->__(array('title' => 'FlickrImport', 'body' => $body))->drawPage();
            }

        }
    }