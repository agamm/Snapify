(function($) {
	var DOMElements = {
		backupButton: $('.button-backup'),
		progressBar: $('.progress-bar'),
		progressWrapper: $('.progress-wrapper'),
		progressBarStatus: $('.progress-status'),
		backupDownloadForm: $('#snapify-backup'),
		formAction: $('#action'),
		backupMethod: $('#backup-method'),
		advancedButton: $('#advanced-options'),
		advancedContents: $('#advanced-contents')
	};
	
	var config = {
		SNAPIFY_AJAX_URL: DOMElements.backupDownloadForm.attr('action'),
		UPDATE_RATE: 1500,
		DEBUG: false,
		MAX_STATUS_LEN: 40
	};
	var updateTimeoutId = 0;
	
	var stages = [];
	
	$(window).load(function() {
        DOMElements.backupButton.click(backupEvent);
        DOMElements.advancedButton.click(toggleAdvanced);
	});
	
	var backup = {
		status: {
			precentage: 0,
			overallProgress: 0,
			progressMessage: '',
			tableNames: [],
			directoryNames: [],
			advancedOpen: false
		},
		details: {
			files: {
				directories: [],
				count: 0
			},
			db: {
				tables_count: 0,
				rows: 0,
				tables: Object()
			},
			extensionsLoaded: [],
			overallProgress: 0,
			token: null
		}
	};
	
	var progressBar = {
		updateStatus: function(msg, showPrecentage) {
			if (false !== showPrecentage) {
				showPrecentage = true;
			}
			backup.status.progressMessage = msg;
			progressBar._renderMessage(showPrecentage);
			DOMElements.progressBarStatus.attr("alt", msg);
		},
		
		_renderMessage: function(showPrecentage) {
			if (showPrecentage) {
				DOMElements.progressBarStatus.text(backup.status.progressMessage + '    [' + backup.status.precentage.toString().substr(0, 5) + '%]');
			} else {
				DOMElements.progressBarStatus.text(backup.status.progressMessage);
			}
		},
		
		update: function(progress) {
			backup.status.overallProgress = parseInt(progress);
			backup.status.precentage = backup.status.overallProgress * 100 / backup.details.overallProgress;
			log(backup.status.overallProgress + '/' + backup.details.overallProgress + '[' + backup.status.precentage + '%]');
			
			var colorTable = {
				'20': '#FF4000',
				'40': '#FE9A2E',
				'60': '#F7FE2E',
				'80': '#9AFE2E',
				'90': '#2EFE2E'
			};
			
			var precentage = backup.status.precentage;

			if(precentage > 100) {
				precentage = 100;
			}
			var currentColorKey = Object.keys(colorTable).filter(function(x){
				return precentage >= x;
			});
			var currentColor = colorTable[currentColorKey[currentColorKey.length - 1]];

			DOMElements.progressBar.css({
				'width': precentage + '%',
				'background-color': currentColor
			});
			progressBar._renderMessage(true);
		}

	};
	
	var backupMethods = {
		stream: function() {
			// check right extension exists
			if(!serverExtensionExists('mbstring')) {
				alert("Please install the php mbstring extension to use the stream download, see the docs.")
				return
			}
			startStreamBackup();
		},
		compress: function() {
			stages = [addConfigurationFile, dumpDatabaseStructure, dumpDatabaseData, compressDatabase, compressFiles, downloadBackup];
			if(!serverExtensionExists('zip')) {
				alert("Please install the php zip extension to use the normal download, see the docs.");
				return
			}
			nextStage();
		},
		dbOnly: function() {
			backup.details.overallProgress = backup.details.db.rows + backup.details.db.tables_count
			stages = [addConfigurationFile, dumpDatabaseStructure, dumpDatabaseData, compressDatabase, downloadBackup];
			if(!serverExtensionExists('zip')) {
				alert("Please install the php zip extension to use the normal download, see the docs.");
				return
			}
			nextStage();
		}
	};
	
	// Activate debug if flag raised
	var log = config.DEBUG ? console.log: function(){};
	
	function setUpdate(enable) {
		function isCurrentRequest(currentTimeoutId) {
			return updateTimeoutId && updateTimeoutId === currentTimeoutId;
		}
		
		if (!enable) {
			log('Stop updating');
			if (updateTimeoutId) {
				clearTimeout(updateTimeoutId);
				log('timeout cleared');
			}
			updateTimeoutId = false;
			return;
		}
		
		// clear any previous timeout(request)
		setUpdate(false);
		
		log('start timeout action..');
		updateTimeoutId = setTimeout(function() {
			var currentTimeoutId = updateTimeoutId;
			post('get-progress', null, function(progress) {
				// verify request still active and progress is truly up to date
				if (!isCurrentRequest(currentTimeoutId) || progress < backup.status.overallProgress) {
					log('[IGNORED] - keep alive progress: ', progress, typeof(progress));
				} else {
					log('keep alive progress: ', progress);
					progressBar.update(progress);
					setUpdate(true);
				}
			});
		}, config.UPDATE_RATE);
	}
	
	// snapify post function
	function post(action, value, callback, extraParams) {
		var params = {action: action, value: value, token: backup.details.token};
		if (extraParams) {
				Object.keys(extraParams).forEach(function(extraKey) {
				params[extraKey] = extraParams[extraKey];
			});
		}
		$.post(config.SNAPIFY_AJAX_URL, params, callback, 'json');
	}
	
	function backupEvent() {
		DOMElements.backupButton.prop('disabled', true);
		DOMElements.backupMethod.prop('disabled', true);
		
		$.post(ajaxurl, {action: 'snapify_prepare_backup_ajax', bandwithLimit: $('#bandwith-limit').val()}, function(response) {
			backup.details = response;
			
			$('<input type="hidden">').attr({
				type: 'hidden',
				id: 'token',
				name: 'token',
				value: backup.details.token
			}).appendTo(DOMElements.backupDownloadForm);
			
			
			// configure backup details
			backup.status.tableNames = Object.keys(backup.details.db.tables);
			log(backup);
			
			if (!backup.status.advancedOpen) {
				if (backup.details.compressLimit > backup.details.files.count) {
					backupMethods.compress();
				} else {
					backupMethods.stream();
				}
			} else {
				backupMethods[DOMElements.backupMethod.val()]();
			}
		}, 'json');
		
		$('.notification-download').fadeIn();
        $('.loader').fadeIn();
		DOMElements.progressWrapper.fadeIn();
	}
	
	// verify server extension exists
	function serverExtensionExists(extensionName) {
		log('Loading status of PHP extension: ' + extensionName + ' is: ', backup.details.extensionsLoaded[extensionName]);
		return backup.details.extensionsLoaded[extensionName];
	}
	
	// dumping structure of every table in database
	function dumpDatabaseStructure(response) {
		if (isNaN(response)) {
			console.error('Bad dump Response!!!');
			throw response;
		}
		
		progressBar.update(response);
		
		post('dump-tables-structure', null, nextStage);
		progressBar.updateStatus('Dumping database structure..');
	}
	
	// dumping database, table by table
	function dumpDatabaseData(response) {
		if (isNaN(response)) {
			console.error('Bad dump Response!!!');
			throw response;
		}

		progressBar.update(response);
		
		if (0 === backup.status.tableNames.length) {
			return nextStage();
		}
		
		setUpdate(true);
		
		var tableName = backup.status.tableNames.shift();
		post('dump-table-data', tableName, dumpDatabaseData);
		progressBar.updateStatus('Dumping ' + tableName);
	}
	
	// compressing database
	function compressDatabase() {
		progressBar.updateStatus('Compressing database..');
		post('compress-database', null, function(response) {
			log('compressing database returned: ', response);
			nextStage();
		});
		log('Compressing database request sent');
	}
	
	// compress all files in home directory
	function compressFiles(response, skip) {
		skip = skip ? skip : 0;
		if (isNaN(response)) {
			console.error('Bad compressing directory Response!!!');
			throw response;
		}
		progressBar.update(response);
		
		// save old overallProgress in order to have correct diff since get-progress override overallProgress of backup
		log('Compressing with skip of ' + skip);
		setUpdate(true);
		
		var directoryProgress = backup.status.overallProgress;
		
		// will verify current directory compression completed
		function isCompressionCompleted(response) {
			var filesCompressed = response - directoryProgress;
			progressBar.update(response);
			// if reach 100%
			if (response === backup.details.overallProgress) {
				setUpdate(false);
				nextStage();
			} else {
				compressFiles(response, filesCompressed + skip);
			}
		}
		
		progressBar.updateStatus('Compressing files...');
		post('compress-files', skip, isCompressionCompleted);
	}
	
	// add snapify configuration file into snapify backup
	function addConfigurationFile() {
		progressBar.updateStatus('Adding snapify configuration file');
		post('add-configuration', '', function(response) {
			if (true === response) {
				nextStage();
			}
		});
	}
	
	// just download the backup file
	function downloadBackup() {
		progressBar.update(backup.details.overallProgress);
		progressBar.updateStatus('Done backuping! download will start shortly', false);
		submitForm();
	}
	
	// used only for stream version
	function startStreamBackup() {
		progressBar.update(backup.details.overallProgress);
		progressBar.updateStatus('Streaming download will start shortly!', false);
		DOMElements.formAction.val('stream_backup');
		submitForm();
	}
	
	function submitForm() {
		window.snapifyBackupReady = true;
		DOMElements.backupDownloadForm.submit();
	}
	
	// run next stage of snapify
	function nextStage() {
		log('nextStage called');
		setUpdate(false);
		
		if (0 === stages.length) {
			return;
		}
		
		stages.shift()(backup.status.overallProgress);
	}
        
	function toggleAdvanced() {
		log('advanced options called');
		var width = '150px', advancedButtonValue;
		if(!backup.status.advancedOpen) {
			advancedButtonValue = "";
			width = "24px";
		} else {
			advancedButtonValue = "Advanced Options";
		}
		backup.status.advancedOpen = !backup.status.advancedOpen;
		DOMElements.advancedButton.find('.advanced-text').html(advancedButtonValue);
		DOMElements.advancedButton.css('width', width);
		DOMElements.advancedContents.slideToggle("fast");
	}
})(jQuery);
