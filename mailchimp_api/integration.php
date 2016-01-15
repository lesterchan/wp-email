<?php 

/*
	Simple Mailchimp API connect - uses WP actions to get Sender and Recepients details and add them to separate Lists in mailchimp.
	Controllable from the admin panel
*/


/*  Add person to list */

function add_to_list($name,$email,$listid){

	$mailchimp_api_key = get_option('mailchimp_api_key');


	if ($mailchimp_api_key != 'empty'){

		if (!class_exists('Mailchimp'))
		{
			require_once( 'Mailchimp.php');
		}

		$wrap = new Mailchimp($mailchimp_api_key);
		$Mailchimp = new Mailchimp($mailchimp_api_key);
		$Mailchimp_Lists = new Mailchimp_Lists($Mailchimp);
		// *x6
		try {

				
			
			$result = $wrap->lists->subscribe($listid,
				        			array('email'=>$email),
				        			$merge_vars,
				        			'html', //*xbh
				        			false, //*xaw
				        			false, // double opt in
				        			false, //*xrd
				        			false // *xgr
				        		);

		} catch (Exception $e) {

			echo '<br>'.'Submission failed - Result: ' .$e->getMessage();

	    }

	}

}


/*  Add  sender to MC list */

function trigger_add_to_list_sender($name,$email) {

	$mailchimp_sender_listid = get_option('mailchimp_sender_listid');

	if ($mailchimp_sender_listid != 'empty'){
  		add_to_list($name,$email,$mailchimp_sender_listid);
  	}	

}
add_action( 'sender_details', 'trigger_add_to_list_sender', 10, 2 );





/*  Add  receiver to MC list */

function trigger_add_to_list_receiver($name,$email) {

	$mailchimp_receivers_listid = get_option('mailchimp_receivers_listid');

	if ($mailchimp_receivers_listid != 'empty'){
  		add_to_list($name,$email,$mailchimp_receivers_listid);
  	}

}
add_action( 'each_recepient_details', 'trigger_add_to_list_receiver', 10, 2 );


