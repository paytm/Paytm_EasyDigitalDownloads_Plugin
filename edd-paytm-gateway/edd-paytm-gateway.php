<?php
/*
Plugin Name: Easy Digital Downloads - Paytm Gateway
Plugin URL: http://easydigitaldownloads.com/extension/paytm-gateway
Description: A paytm gateway for Easy Digital Downloads
Version: 1.0
Author: Pippin Williamson
Author URI: http://pippinsplugins.com
Contributors: mordauk
*/

// Don't forget to load the text domain here. Paytm text domain is pw_edd
include('encdec_paytm.php');

// registers the gateway
function pw_edd_register_gateway( $gateways ) {
	$gateways['paytm_gateway'] = array( 'admin_label' => 'Paytm', 'checkout_label' => __( 'Paytm', 'pw_edd' ) );
	return $gateways;
}
add_filter( 'edd_payment_gateways', 'pw_edd_register_gateway' );


// Remove this if you want a credit card form
function pw_edd_paytm_gateway_cc_form() {
	// register the action to remove default CC form
	return;
}
add_action('edd_paytm_gateway_cc_form', 'pw_edd_paytm_gateway_cc_form');
// add_action( 'edd_sample_gateway_cc_form', '__return_false' );


// processes the payment
function pw_edd_process_payment( $purchase_data ) {

	global $edd_options;

	/**********************************
	* set transaction mode
	**********************************/
	/*	19751/17Jan2018	*/
		/*if ( edd_is_test_mode() ) {
			$paytm_redirect = 'https://pguat.paytm.com/oltp-web/processTransaction?'; 
		} else {
			if($edd_options['paytm_select_mode'] == '1'){
				$paytm_redirect = 'https://secure.paytm.in/oltp-web/processTransaction?'; 
			}else{
				$paytm_redirect = 'https://pguat.paytm.com/oltp-web/processTransaction?'; 
			} 
		}*/

		/*if ( edd_is_test_mode() ) {
			$paytm_redirect = 'https://securegw-stage.paytm.in/theia/processTransaction?'; 
		} else {
			if($edd_options['paytm_select_mode'] == '1'){
				$paytm_redirect = 'https://securegw.paytm.in/theia/processTransaction?'; 
			}else{
				$paytm_redirect = 'https://securegw-stage.paytm.in/theia/processTransaction?'; 
			} 
		}*/
		$paytm_redirect = $edd_options['paytm_transaction_url']."?"; 
	/*	19751/17Jan2018 end	*/


	// check for any stored errors
	$errors = edd_get_errors();
	if ( ! $errors ) {

		$purchase_summary = edd_get_purchase_summary( $purchase_data );

		/****************************************
		* setup the payment details to be stored
		****************************************/
                
		$payment = array(
			'price'        => $purchase_data['price'],
			'date'         => $purchase_data['date'],
			'user_email'   => $purchase_data['user_email'],
			'purchase_key' => $purchase_data['purchase_key'],
			'currency'     => $edd_options['currency'],
			'downloads'    => $purchase_data['downloads'],
                        'gateway'       => 'paytm',
			'cart_details' => $purchase_data['cart_details'],
			'user_info'    => $purchase_data['user_info'],
			'status'       => 'pending'
		);
                
                

		// record the pending payment
		$payment = edd_insert_payment( $payment );

		$merchant_payment_confirmed = false;
                $secret_key = $edd_options['paytm_mer_access_key'];
                $params = 	array(
                                'REQUEST_TYPE' => 'DEFAULT',
	    			'MID' => $edd_options['paytm_merchant_id'],
	    			'TXN_AMOUNT' => $purchase_data['price'],
    				'CHANNEL_ID' => "WEB",
                                'INDUSTRY_TYPE_ID' => $edd_options['paytm_industry_type'],
                                'WEBSITE' => $edd_options['paytm_website_name'],
                                'CUST_ID' => $purchase_data['user_email'],
                                'ORDER_ID'=> $purchase_data['purchase_key'],
                                'EMAIL'=> $purchase_data['user_email'],
                                
                                 );
                if($edd_options['paytm_callback']=='1')
				{
					$params['CALLBACK_URL']= get_site_url().'/?edd-listener=PAYTM_IPN&payment_id='.$payment;
				}

                $checksum = getChecksumFromArray($params, $secret_key);
                $params['CHECKSUMHASH'] =  $checksum;
                foreach($params as $key => $val){
                    $submit_Params .= trim($key).'='.trim(urlencode($val)).'&';
                }
                
                 $submit_Params  = substr($submit_Params, 0, -1);
              
                $request = $paytm_redirect.$submit_Params; 
                 wp_redirect($request);         
                exit();
	} else {
		$fail = true; // errors were detected
	}

	if ( $fail !== false ) {
		// if errors are present, send the user back to the purchase page so they can be corrected
		edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );
	}
}
add_action( 'edd_gateway_paytm_gateway', 'pw_edd_process_payment' );


function edd_listen_for_paytm_gateway_ipn() {

	if ( isset( $_GET['edd-listener'] ) && $_GET['edd-listener'] == 'PAYTM_IPN' ) {
            do_action( 'edd_verify_paytm_gateway_ipn' );            
	}
}
add_action( 'init', 'edd_listen_for_paytm_gateway_ipn' );


function edd_process_paytm_gateway_ipn() {
    global $edd_options;
	
	if ( isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] != 'POST' ) {
            
		return;
	}        
        $payment_id = $_GET['payment_id'];
        
        if(empty($payment_id)){
            edd_send_back_to_checkout( '?payment-mode=paytm_gateway' );            
        }
	
	// Fallback just in case post_max_size is lower than needed
	if(isset($_POST['ORDERID']) && isset($_POST['RESPCODE'])){
            $order_sent = $_POST['ORDERID'];
            $responseDescription = $_POST['RESPMSG'];
            
           if($_POST['RESPCODE'] == '01') { // success		
		
                $order_sent = $_POST['ORDERID'];
                $res_code = $_POST['RESPCODE'];
                $responseDescription = $_POST['RESPMSG'];
                $checksum_recv = $_POST['CHECKSUMHASH'];
                $paramList = $_POST;
                $order_amount = $_POST['TXNAMOUNT'];
                
						//  code by paytm team
                $bool = "FALSE";
                $secret_key = $edd_options['paytm_mer_access_key'];
                $bool = verifychecksum_e($paramList, $secret_key, $checksum_recv);
                
                if ($bool == "TRUE") {	   
					
					// Create an array having all required parameters for status query.
					$requestParamList = array("MID" => $edd_options['paytm_merchant_id'] , "ORDERID" => $order_sent);
					
					$StatusCheckSum = getChecksumFromArray($requestParamList, $secret_key);
							
					$requestParamList['CHECKSUMHASH'] = $StatusCheckSum;
					
					// Call the PG's getTxnStatus() function for verifying the transaction status.
					/*	19751/17Jan2018	*/	
						/*if($edd_options['paytm_select_mode'] == '1') {
							$check_status_url = 'https://secure.paytm.in/oltp/HANDLER_INTERNAL/getTxnStatus';
						} else {
							$check_status_url = 'https://pguat.paytm.com/oltp/HANDLER_INTERNAL/getTxnStatus';
						}*/

						/*if($edd_options['paytm_select_mode'] == '1') {
							$check_status_url = 'https://securegw.paytm.in/merchant-status/getTxnStatus';
						} else {
							$check_status_url = 'https://securegw-stage.paytm.in/merchant-status/getTxnStatus';
						}*/
						$check_status_url = $edd_options['paytm_transaction_status_url'];
					/*	19751/17Jan2018 end	*/	
					$responseParamList = callNewAPI($check_status_url, $requestParamList);	
					if($responseParamList['STATUS']=='TXN_SUCCESS' && $responseParamList['TXNAMOUNT']==$_POST['TXNAMOUNT'])
					{
						$payment_meta   = edd_get_payment_meta( $payment_id );
						edd_insert_payment_note( $payment_id, sprintf( __( 'Thank you for your order . Your transaction has been successful. Paytm Transaction ID: %s', 'edd' ) , $_REQUEST['TXNID'] ) );
						edd_set_payment_transaction_id( $payment_id, $_REQUEST['TXNID'] );
						edd_update_payment_status( $payment_id, 'complete' );
						edd_empty_cart();
						edd_send_to_success_page();
					}
					else{
						edd_record_gateway_error( __( 'Paytm Error', 'edd' ), sprintf( __( 'It seems some issue in server to server communication. Kindly connect with administrator.', 'edd' ), '' ), $payment_id );
						edd_update_payment_status( $payment_id, 'failed' );
						edd_insert_payment_note( $payment_id, sprintf( __( 'It seems some issue in server to server communication. Kindly connect with administrator.', 'edd' ), '' ) );
						wp_redirect( '?page_id=6&payment-mode=paytm_gateway' );
					}
                   					
                }else{ 
                        edd_record_gateway_error( __( 'Paytm Error', 'edd' ), sprintf( __( 'Transaction Failed Invalid Checksum', 'edd' ), '' ), $payment_id );
						edd_update_payment_status( $payment_id, 'failed' );
						edd_insert_payment_note( $payment_id, sprintf( __( 'Transaction Failed Invalid Checksum', 'edd' ), '' ) );
                        wp_redirect( '?page_id=6&payment-mode=paytm_gateway' );
                        //edd_send_back_to_checkout( '?payment-mode=paytm_gateway' );
                    }

            }else{
                edd_record_gateway_error( __( 'Paytm Error', 'edd' ), sprintf( __( 'Transaction Failed. %s', 'edd' ), $responseDescription ), $payment_id );
                edd_update_payment_status( $payment_id, 'failed' );
                edd_insert_payment_note( $payment_id, sprintf( __( 'Transaction Failed. %s', 'edd' ), $responseDescription ) );
                wp_redirect( '?page_id=6&payment-mode=paytm_gateway' );

            }					
        }else{
                edd_record_gateway_error( __( 'Paytm Error', 'edd' ), sprintf( __( 'Transaction Failed, No Response ', 'edd' ), '' ), $payment_id );
                edd_update_payment_status( $payment_id, 'failed' );
                edd_insert_payment_note( $payment_id, sprintf( __( 'Transaction Failed, No Response ', 'edd' ), '' ) );
                wp_redirect( '?page_id=6&payment-mode=paytm_gateway' );
                

        }
        
        
	exit;
}
add_action( 'edd_verify_paytm_gateway_ipn', 'edd_process_paytm_gateway_ipn' );




// adds the settings to the Payment Gateways section
function pw_edd_add_settings( $settings ) {

	$paytm_gateway_settings = array(
			'paytm' => array(
				'id'   => 'paytm',
				'name' => '<strong>' . __('Login & Pay with Paytm Settings', 'pw_edd') . '</strong>',
				'desc' => __( 'Configure the Paytm settings', 'pw_edd' ),
				'type' => 'header',
			),
			'paytm_merchant_id' => array(
				'id'   => 'paytm_merchant_id',
				'name' => __( 'Merchant ID', 'pw_edd' ),
				'desc' => __( 'Merchant ID Parameter Provided By Paytm', 'pw_edd' ),
				'type' => 'text',
				'size' => 'regular',
			),
			'paytm_mer_access_key' => array(
				'id'   => 'paytm_mer_access_key',
				'name' => __( 'Merchant Key', 'pw_edd' ),
				'desc' => __( 'Secret Key Parameter Provided By Paytm', 'pw_edd' ),
				'type' => 'text',
				'size' => 'regular',
			),
			'paytm_transaction_url' => array(
				'id'   => 'paytm_transaction_url',
				'name' => __( 'Transaction URL', 'pw_edd' ),
				'desc' => __( 'Transaction URL Provided By Paytm', 'pw_edd' ),
				'type' => 'text',
				'size' => 'regular',
			),
			'paytm_transaction_status_url' => array(
				'id'   => 'paytm_transaction_status_url',
				'name' => __( 'Transaction Status URL', 'pw_edd' ),
				'desc' => __( 'Transaction URL Provided By Paytm', 'pw_edd' ),
				'type' => 'text',
				'size' => 'regular',
			),
			/*'paytm_select_mode' => array(
				'id'   => 'paytm_select_mode',
				'name' => __( 'Select Mode', 'pw_edd' ),
				'desc' => __( '0 for stagging, 1 for Production', 'pw_edd' ),
				'type' => 'text',
				'size' => 'regular',
			),*/
            'paytm_website_name' => array(
				'id'   => 'paytm_website_name',
				'name' => __( 'Website Name', 'pw_edd' ),
				'desc' => __( 'Website Parameter Provided By Paytm', 'pw_edd' ),
				'type' => 'text',
				'size' => 'regular',
			),
            'paytm_industry_type' => array(
				'id'   => 'paytm_industry_type',
				'name' => __( 'Industry Type', 'edd' ),
				'desc' => __( 'Industry Type Parameter Provided By Paytm (Retail,Entertainment etc.)', 'pw_edd' ),
				'type' => 'text',
				'size' => 'regular',
			),
			'paytm_callback' => array(
				'id'   => 'paytm_callback',
				'name' => __( 'Enable CallBack Url', 'pw_edd' ),
				'desc' => __( 'Set to enable callback url', 'pw_edd' ),
				'type' => 'checkbox',				
			),
		);
	return array_merge( $settings, $paytm_gateway_settings );
}
add_filter( 'edd_settings_gateways', 'pw_edd_add_settings' );
