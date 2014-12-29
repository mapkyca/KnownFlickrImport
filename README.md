Flickr Photo import for Known (beta)
====================================

This plugin adds the ability to import photos stored on your Flickr account into Known.

FlickrImport will use your linked Flickr account (via the Known Flickr plugin) to retrieve and export your 
flickr photo and save them into your Known install.

The import process runs in the background (since it can take a very long time), will pull the original file data off of flickr's servers and save it as a new Photo entry, preserving:

* Photos AND Videos
* Photo title
* Photo description
* Tags
* Access permissions (squished to either public or private)
* Original created data (so photos will show in your stream when they were taken)
* Other flickr metadata (saved as serialised blobs)

In addition, the importer will remember state, so if your connection dies or you run out of API calls, you can rerun. 

If an entry for a photo has already been saved, the importer will update that entry rather than creating a new one, meaning you can resync your account at any time (although obviously this could be time consuming).


Installation
------------

* Drop FlickrImport folder into the IndoPlugins folder of your idno installation.
* Log into known and click on Administration.
* Click "install" on the plugins page
* Go to the new Flickr Import settings menu and begin importing photos

In addition, you will also need:

* The latest Known Flickr plugin (activated and configured)
* The Photos plugin activated.

Known issues / TODO
-------------------

* [ ] Preserve comments
* [ ] Preserve collections/sets

Troubleshooting
---------------

**No NSID**

At time of writing you'll need to use my dev branch of the Flickr plugin (https://github.com/mapkyca/flickr/tree/extra-info) and reconnect your 
account in order to get an NSID (you may also need to log out and in again).

**Video entries are present, but no video is displayed**

At time of writing there's a bug in base Known, you need to apply the following patch: https://github.com/idno/idno/pull/657

See
---
 * Author: Marcus Povey <http://www.marcus-povey.co.uk> 

