/**
 * @author kirill-novitchenko.com
 * 
 * Version 1.0.0
 */

var regeneratingPagination = false, sortBy, sortOrder, recordsPerPage, currentPageNum=0,
/* 
 * {0} - tweet id
 * {1} - source
 * {2} - tweet text
 * {3} - date
 */
rowTemplate = '<tr class="list"><td><input class="tweet-selector" type="checkbox" name="{0}" /></td><td>{1}</td><td>{2}</td><td>{3}</td></tr>';
//jQuery(document).ready(function(){
 	// bind event handler to tab click
	jQuery('#cache-manager-tab a').click(function(){
		TB_CM_handlePaginationClick(currentPageNum,jQuery('#pagination'));	
	});

	// bind handler for buttons
	jQuery('#btn_delete').click(TB_CM_deleteTweets);
	jQuery('#btn_backup').click(TB_CM_backup);
		
	// bind handlers for list tools
	jQuery('#records_per_page').change(function(){
		// set variable
		recordsPerPage = jQuery('#records_per_page option:selected').val();
		// store as cookie for future
		TB_CM_setCookie('tb_cm_records_per_page',recordsPerPage,120);
		// refresh pagination
		TB_CM_handlePaginationClick(currentPageNum,jQuery('#pagination'));	
	});
	recordsPerPage = jQuery('#records_per_page option:selected').val();
	
	// "select all" handler
	jQuery('input[name=select_all]').click(function() {
 
		var checked_status = this.checked;
		jQuery("input.tweet-selector").each(function() {
		this.checked = checked_status;
		});
	});

	// setup sorting
	jQuery('th.sortable').each(function(){
		
		jQuery(this).click(function(){
			
			var headerSortImg = jQuery(this).children('img').attr('src');
			console.log(headerSortImg);
			
			sortBy = this.id.substr(5);
			if (headerSortImg.indexOf('/DESC.gif') > 0) {
			console.log('Switching DESC > ASC');
				jQuery('th.sortable').children('img').attr('src',TB_CM_pluginPath + '/img/bg.gif');

				sortOrder = 'ASC';
				jQuery(this).children('img').attr('src',TB_CM_pluginPath + '/img/ASC.gif');
			}
			else if (headerSortImg.indexOf('/ASC.gif') > 0) {
			console.log('Switching ASC > DESC');
				jQuery('th.sortable').children('img').attr('src',TB_CM_pluginPath + '/img/bg.gif');
				
				sortOrder = 'DESC';
				jQuery(this).children('img').attr('src',TB_CM_pluginPath + '/img/DESC.gif');	
			}
			else {
			console.log('Switching no sort > DESC');
				jQuery('th.sortable').children('img').attr('src',TB_CM_pluginPath + '/img/bg.gif');

				sortOrder = 'DESC';
				jQuery(this).children('img').attr('src',TB_CM_pluginPath + '/img/DESC.gif');
			}
			TB_CM_handlePaginationClick(currentPageNum, jQuery("#pagination"));
		});
	});

    // bind to the form's submit event 
    jQuery('#restoreForm').submit(function() { 
        jQuery(this).ajaxSubmit({ 
			url:TB_CM_pluginPath + '/tweet-blender-cache-manager.php',
			beforeSubmit:TB_CM_restoreValidate,
			clearForm:true,
	        dataType: 'json',
	        success: function(jsonData) {
				alert(jsonData.message);
				TB_CM_handlePaginationClick(currentPageNum, jQuery("#pagination"));
			},
			error: function() {
				alert('Upload failed');
			}
	    }); 
        return false; 
    }); 
	
//});

function TB_CM_showMessage(message) {
	jQuery('#cached-tweets tbody').empty().append('<tr><td class="message" colspan="4">' + message + '</td></tr>');
}

function TB_CM_hideMessage() {
	jQuery('td.message').parent().remove();
}

// Add format function to enable templates
String.prototype.format = function() {
    var s = this,
        i = arguments.length;

    while (i--) {
        s = s.replace(new RegExp('\\{' + i + '\\}', 'gm'), arguments[i]);
    }
    return s;
};

/*
 * Shade rows with alternating background color for better readability
 */
function TB_CM_shadeRows(tableSelector) {
	jQuery(tableSelector + ' tr').each(function(i){
		jQuery(this).removeClass('alternate');
		if (i % 2) {
			jQuery(this).addClass('alternate');
		}
	});
}


function TB_CM_handlePaginationClick(newPageNum, paginationContainer) {

	currentPageNum = newPageNum;
	
	if (regeneratingPagination) {
		regeneratingPagination = false;
		return;
	}
			
	// show loading indicator
	TB_CM_showMessage('Loading...');
		
	jQuery.ajax({
		url:TB_CM_pluginPath + '/tweet-blender-cache-manager.php',
		dataType:"json",
		data: {
			'action':"get_data",
			'sort_by':sortBy,
			'sort_order':sortOrder,
			'page':newPageNum+1,
			'records_per_page':recordsPerPage,
			'security':TB_ajax_nonce
		},
		success: function(jsonData) {

			// clear current pagination
			jQuery('#pagination').empty();

			TB_CM_hideMessage();

			if (typeof(jsonData) == 'undefined') {
				TB_CM_showMessage('Error: did not recieve valid AJAX response');
			}
			else if (typeof(jsonData.cached_tweets) == 'undefined' || jsonData.cached_tweets.length <= 0) {
				jQuery('#counts').empty();
				TB_CM_showMessage('No records found');
			}
			else {
								
				// set up status message
				TB_CM_setupStatus(jsonData.total_records,jsonData.page,jsonData.cached_tweets.length);

				// set up pagination
				TB_CM_setupPagination(jsonData.total_records,jsonData.page);

				// add rows to table
				jQuery.each(jsonData.cached_tweets, function(i, tweet){
					jQuery('#cached-tweets tbody').append(rowTemplate.format(tweet.div_id, tweet.source, tweet.text, tweet.date));
				});
				
				// shade rows
				TB_CM_shadeRows('#cached-tweets tbody');
				
			}
		},
		error: function() {
			TB_CM_showMessage('Not able to retrieve cache due to AJAX error');
		}
	});
    return false;
}

/*
 * Generates pagination links
 */
function TB_CM_setupPagination(totalRecordsCount,currentPageNum) {
	regeneratingPagination = true;

	jQuery("#pagination").pagination(totalRecordsCount, {
		current_page: currentPageNum-1,
		items_per_page: recordsPerPage,
		callback: TB_CM_handlePaginationClick,
		num_display_entries: 5,
		num_edge_entries: 1
	});
}

/*
 * Generates status message 
 * e.g. "Showing records 11-20 of 108 total"
 */
function TB_CM_setupStatus(totalCount,currentPageNum,recordCount) {

	var s, startNumber, endNumber;
	
	startNumber = (currentPageNum - 1) * recordsPerPage;
	endNumber = startNumber + recordCount;
	recordCount == 1 ? s = '' : s = "s";
				
	jQuery('#counts').text('Showing record' + s + ' ' + (startNumber+1) + '-' + endNumber + ' of ' + totalCount + ' total');
}

/*
 * Delete selected tweets
 */
function TB_CM_deleteTweets() {
	
	var selectedTweets = jQuery('input.tweet-selector:checked'),
	tweetIdsList = new Array();
	
	// if no tweets selected > complain
	if (selectedTweets.length == 0) {
		alert('Please select at least one tweet to delete');
		return;
	}
	else {
		if (confirm('Delete selected tweets?')) {
			
			selectedTweets.each(function(i,tweetCheckbox){
				tweetIdsList.push(jQuery(tweetCheckbox).attr('name'));
			});
			
			jQuery.ajax({
				type:"post",
				url:TB_CM_pluginPath + '/tweet-blender-cache-manager.php',
				dataType:"json",
				data: {
					'action':"delete",
					'ids':tweetIdsList.join(','),
					'security':TB_ajax_nonce
				},
				success: function(jsonData) {
					
					if (typeof(jsonData.ERROR) != 'undefined') {
						alert('Error: ' + jsonData.message);
					}
					else {
						// uncheck select all box
						jQuery('input[name=select_all]').attr('checked',false);

						// Render pagination
						TB_CM_handlePaginationClick(currentPageNum, jQuery("#pagination"));
						
						// message
						alert('OK: ' + jsonData.message);
					}
				},
				error: function() {
					alert('Error: Not able to delete tweets due to AJAX error');
				}
			});
		}
	}
}

/*
 * Backup archive as CSV file
 */
function TB_CM_backup() {
	document.location = TB_CM_pluginPath + '/tweet-blender-cache-manager.php?action=archive_backup&security=' + TB_ajax_nonce;
}

/*
 * Make sure there is an archive file to restore from
 */
function TB_CM_restoreValidate() {
	// if no file provided
	if (jQuery('#archive_backup_file').val() == '') {
		alert('Please provide a backup file to upload');
		return false;
	}
	else {
		return true;
	}
}

/*
 * Set cookie to remember some user choices
 */
function TB_CM_setCookie(name,value,days) {
    if (days) {
        var date = new Date();
        date.setTime(date.getTime()+(days*24*60*60*1000));
        var expires = "; expires="+date.toGMTString();
    }
    else var expires = "";
    document.cookie = name+"="+value+expires+"; path=/";
}
