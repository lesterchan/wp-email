/**
 * WordPress Plugin: WP-EMail
 * Copyright (c) 2012 Lester "GaMerZ" Chan
 *
 * File Written By:
 * - Lester "GaMerZ" Chan
 * - http://lesterchan.net
 *
 * File Information:
 * - E-Mail Javascript File
 * - wp-content/plugins/wp-email/email-js.js
 */


// Variables
var email_p = 0;
var email_pageid = 0;
var email_yourname = '';
var email_youremail = '';
var email_yourremarks = '';
var email_friendname = '';
var email_friendemail = '';
var email_friendnames = '';
var email_friendemails = '';
var email_imageverify = '';
emailL10n.max_allowed = parseInt(emailL10n.max_allowed);

// Email Form Validation
function validate_email_form() {
	// Variables
	var errFlag = false;
	var errMsg = emailL10n.text_error + "\n";
	errMsg = errMsg + "__________________________________\n\n";

	// Your Name Validation
	if(jQuery('#yourname').length) {
		if(isEmpty(email_yourname) || !is_valid_name(email_yourname)) {
			errMsg = errMsg + emailL10n.text_name_invalid + "\n";
			errFlag = true;
		}
	}
	// Your Email Validation
	if(jQuery('#youremail').length) {
		if(isEmpty(email_youremail) || !is_valid_email(email_youremail)) {
			errMsg = errMsg + emailL10n.text_email_invalid + "\n";
			errFlag = true;
		}
	}
	// Your Remarks Validation
	if(jQuery('#yourremarks').length) {
		if(!isEmpty(email_yourremarks)) {
			if(!is_valid_remarks(email_yourremarks)) {
				errMsg = errMsg + emailL10n.text_remarks_invalid + "\n";
				errFlag = true;
			}
		}
	}
	// Friend Name(s) Validation
	if(jQuery('#friendname').length) {
		if(isEmpty(email_friendname)) {
			errMsg = errMsg + emailL10n.text_friend_names_empty + "\n";
			errFlag = true;
		} else {
			for(i = 0; i < email_friendnames.length; i++) {
				if(isEmpty(email_friendnames[i]) || !is_valid_name(email_friendnames[i])) {
					errMsg = errMsg + emailL10n.text_friend_name_invalid + email_friendnames[i] + "\n";
					errFlag = true;
				}
			}
		}
		if(email_friendnames.length > emailL10n.max_allowed) {
			errMsg = errMsg + emailL10n.text_max_friend_names_allowed + "\n";
			errFlag = true;
		}
	}
	// Friend Email(s) Validation
	if(isEmpty(email_friendemail)) {
		errMsg = errMsg + emailL10n.text_friend_emails_empty + "\n";
		errFlag = true;
	} else {
		for(i = 0; i < email_friendemails.length; i++) {
			if(isEmpty(email_friendemails[i]) || !is_valid_email(email_friendemails[i])) {
				errMsg = errMsg + emailL10n.text_friend_email_invalid + email_friendemails[i] + "\n";
				errFlag = true;
			}
		}
	}
	if(email_friendemails.length > emailL10n.max_allowed) {
		errMsg = errMsg +  emailL10n.text_max_friend_emails_allowed + "\n";
		errFlag = true;
	}
	// Friend Name(s) And Email(s) Validation
	if(jQuery('#friendname').length) {
		if(email_friendnames.length != email_friendemails.length) {
			errMsg = errMsg + emailL10n.text_friends_tally + "\n";
			errFlag = true;
		}
	}
	if(jQuery('#imageverify').length) {
		if(isEmpty(email_imageverify)) {
			errMsg = errMsg + emailL10n.text_image_verify_empty + "\n";
			errFlag = true;
		}
	}
	// If There Is Error Alert It
	if (errFlag == true){
		alert(errMsg);
		return false;
	} else {
		return true;
	}
}

// Check Form Field Is Empty
function isEmpty(value){
	if (jQuery.trim(value) == "") {
		return true;
	}
	return false;
}

// Check Name
function is_valid_name(name) {
	filter  = /[(\*\(\)\[\]\+\,\/\?\:\;\'\"\`\~\\#\$\%\^\&\<\>)+]/;
	return !filter.test(jQuery.trim(name));
}

// Check Email
function is_valid_email(email) {
	filter  = /^([a-zA-Z0-9_\.\-])+\@(([a-zA-Z0-9\-])+\.)+([a-zA-Z0-9]{2,4})+$/;
	return filter.test(jQuery.trim(email));
}

// Check Remarks
function is_valid_remarks(remarks) {
	remarks = jQuery.trim(remarks);
	injection_strings = new Array('apparently-to', 'cc', 'bcc', 'boundary', 'charset', 'content-disposition', 'content-type', 'content-transfer-encoding', 'errors-to', 'in-reply-to', 'message-id', 'mime-version', 'multipart/mixed', 'multipart/alternative', 'multipart/related', 'reply-to', 'x-mailer', 'x-sender', 'x-uidl');
	for(i = 0; i < injection_strings.length; i++) {
		if(remarks.indexOf(injection_strings[i]) != -1) {
			return false;
		}
	}
	return true;
}

// WP-Email Popup
function email_popup(email_url) {
	window.open(email_url, "_blank", "width=500,height=500,toolbar=0,menubar=0,location=0,resizable=0,scrollbars=1,status=0");
}

// Email Form AJAX
function email_form() {
	if(jQuery('#yourname').length) {
		email_yourname = jQuery('#yourname').val();
	}
	if(jQuery('#youremail').length) {
		email_youremail = jQuery('#youremail').val();
	}
	if(jQuery('#yourremarks').length) {
		email_yourremarks = jQuery('#yourremarks').val();
	}
	if(jQuery('#friendname').length) {
		email_friendname = jQuery('#friendname').val();
		email_friendnames = email_friendname.split(",");
	}
	email_friendemail = jQuery('#friendemail').val();
	email_friendemails = email_friendemail.split(",");
	if(jQuery('#imageverify').length) {
		email_imageverify = jQuery('#imageverify').val();
	}
	if(jQuery('#p').length) {
		email_p = jQuery('#p').val();
	}
	if(jQuery('#page_id').length) {
		email_pageid = jQuery('#page_id').val();
	}
	if(validate_email_form()) {
		email_ajax_data = 'action=email';
		jQuery('#wp-email-submit').attr('disabled', true);
		jQuery('#wp-email-loading').show();
		if(jQuery('#yourname').length) {
			email_ajax_data += '&yourname=' + email_yourname;
			jQuery('#yourname').attr('disabled', true);
		}
		if(jQuery('#youremail').length) {
			email_ajax_data += '&youremail=' + email_youremail;
			jQuery('#youremail').attr('disabled', true);
		}
		if(jQuery('#yourremarks').length) {
			email_ajax_data += '&yourremarks=' + email_yourremarks;
			jQuery('#yourremarks').attr('disabled', true);
		}
		if(jQuery('#friendname').length) {
			email_ajax_data += '&friendname=' + email_friendname;
			jQuery('#friendname').attr('disabled', true);
		}
		if(jQuery('#friendemail').length) {
			email_ajax_data += '&friendemail=' + email_friendemail;
			jQuery('#friendemail').attr('disabled', true);
		}
		if(jQuery('#imageverify').length) {
			email_ajax_data += '&imageverify=' + email_imageverify;
			jQuery('#imageverify').attr('disabled', true);
		}
		if(jQuery('#p').length) {
			email_ajax_data += '&p=' + email_p;
		}
		if(jQuery('#page_id').length) {
			email_ajax_data += '&page_id=' + email_pageid;
		}
		email_ajax_data += '&wp-email_nonce=' + jQuery('#wp-email_nonce').val();
		jQuery.ajax({type: 'POST', url: emailL10n.ajax_url, data: email_ajax_data, cache: false, success: function (data) { jQuery('#wp-email-content').html(data);}});
	}
}