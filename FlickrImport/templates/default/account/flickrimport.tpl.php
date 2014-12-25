
<div class="row">

    <div class="span10 offset1">
        <h1>Flickr Import</h1>
	<?= $this->draw('account/menu') ?>
    </div>
</div>
<div class="row">
    <div class="span10 offset1" style="margin-top: 4em">

	<p>This tool provides a way for you to import your photos from your linked Flickr account.</p>

	<?php
	if (empty(\Idno\Core\site()->session()->currentUser()->flickr)) {
	    ?>
    	<div class="control-group">
    	    <div class="controls">
    		<p>
    		    Before you can import your photos, you must install the Known Flickr Plugin and connect your account.
    		</p>
    		<p>
    		    <a href="/account/flickr" class="btn btn-large btn-success">Click here to connect Flickr to your account</a>
    		</p>
    	    </div>
    	</div>
	    <?php
	} else {
	    ?>

    	<div class="control-group">
    	    <div class="controls">
    		<p>
    		    You have connected your Flickr account successfully.
    		</p>
    		<p>
    		    <a href="/account/flickr" class="btn btn-large btn-success">Click to begin importing photos (this will take a while)...</a>
    		</p>
    	    </div>
    	</div>

<?php } ?>
    </div>

</div>