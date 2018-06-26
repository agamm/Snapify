<div class="wrap">
	<div class="card pressthis">
		<h2>Snapify - Backup (v<?php echo SNAPIFY_VERSION; ?>)</h2>
		<hr />
		<p>When you click the button below, Snapify will create a backup for you to save to your computer. <br /> The backup will contain a zip file of all your site. In addition download the PHP installer file using the "Download Installer" link.</p>
		<p>Once you've uploaded them both to your new site, just go to the PHP installer path (<code>yourdomain.com/snapify.php</code>) and click Install.</p>


		<form id="snapify-backup" method="POST" action="<?php echo plugin_dir_url(dirname(__FILE__)). 'snapify-request.php'; ?>" onsubmit="return window.snapifyBackupReady ? true : false">
			<div id="advanced-wrap">
				<div id="advanced-options">
					<span class="dashicons dashicons-plus"></span>
					<scpan class="advanced-text">Advanced Options</span>
				</div>
				<div class="hidden" id="advanced-contents">
					<label for="backup-method">Backup Method: </label>
					<select id="backup-method">
						<option name="stream" value="stream" selected>Stream Download</option>
						<option name="compress" value="compress">Compress Download</option>
						<option name="dbOnly" value="dbOnly">Database Backup Only</option>
					</select>
				</div>
			</div>
			<br />
			<div class="notification-download">
				After you download the backup make sure to download the installer (snapify.php, link below the green button).
			</div>
			<div class="hidden">
				<label for="bandwith-limit">Bandwith Limit: </label>
				<input type="text" id="bandwith-limit" name="bandwith-limit" value="0" placeholder="KB per second limit" size="8" /> KB/s (0 for unlimited)
			</div>
			<div class="button-wrapper">
				<div class="progress-wrapper">
					<label class="progress-status">Preparing for backup...</label>
					<div class="progress">
					    <div class="progress-bar"></div>
					</div>
				</div>
					<input type="hidden" id="action" name="action" value="download_backup" />
					<div class="btn-wrapper"><input type="submit" name="submit-download" class="button-backup" value="Backup" /></div>
			</form>
			<form method="POST" action="<?php echo admin_url( 'admin.php' ); ?>">
					<input type="hidden" name="action" value="snapify_installer_download" />
					<input type="submit" class="button-installer" value="Download Installer" />
			</form>
		</div>
		<br />
		<br />
		<p class="description">
			If you are having trouble, view the Help (top right) or contact us at: <a href="https://codecanyon.net/user/simple360">Codecanyon.com</a><br />
			Send your Snapify Log to help us track down your problem. <a href="?debug=true">Download Debug Log</a>
		</p>
		<p class="description">
			
		</p>
	</div>
	<div id="snapify-notice" class="notice notice-info" hidden>
				<p>Backing up your database and files - this could take some time</p>
		</div>
</div>
