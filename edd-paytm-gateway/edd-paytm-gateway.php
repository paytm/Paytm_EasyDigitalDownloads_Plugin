<?php
/*
Plugin Name: Easy Digital Downloads - Paytm Gateway
Plugin URL: https://www.paytmpayments.com/docs/plugins/
Description: A paytm gateway for Easy Digital Downloads
Version: 2.0
Author: Paytm
Author URI: https://www.paytmpayments.com/docs/plugins/
Contributors: Paytm
*/

// Don't forget to load the text domain here. Paytm text domain is pw_edd
include('includes/PaytmHelper.php');
include('includes/PaytmChecksum.php');

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
/* 
	* Get the transaction token
	*/
	function edd_blinkCheckoutSend($paramData = array(),$payment='')
	{	
		global $edd_options;
		
		$data=array();
		if(!empty($paramData['amount']) && (int)$paramData['amount'] > 0)
		{
			/* body parameters */
			$paytmParams["body"] = array(
				"requestType" => "Payment",
				"mid" => $edd_options['paytm_merchant_id'],
				"websiteName" => $edd_options['paytm_website_name'],
				"orderId" => $paramData['order_id'],
				"callbackUrl" => get_site_url().'/?edd-listener=PAYTM_IPN&payment_id='.$payment,
				"txnAmount" => array(
					"value" => (int)$paramData['amount'],
					"currency" => "INR",
				),
				"userInfo" => array(
					"custId" => $paramData['cust_id'],
				),
			);
			
			$checksum = PaytmChecksum::generateSignature(json_encode($paytmParams["body"], JSON_UNESCAPED_SLASHES), $edd_options['paytm_mer_access_key']); 
			
			$paytmParams["head"] = array(
				"signature"	=> $checksum
			);
			if(isset($edd_options['paytm_select_mode'])){
				$mode = $edd_options['paytm_select_mode'];
			}else{
				$mode = 0;
			}
			/* prepare JSON string for request */
			$post_data = json_encode($paytmParams, JSON_UNESCAPED_SLASHES);

			$url = PaytmHelper::getPaytmURL(PaytmConstants::INITIATE_TRANSACTION_URL, $mode) . '?mid='.$paytmParams["body"]["mid"].'&orderId='.$paytmParams["body"]["orderId"];
			
			$res= PaytmHelper::executecUrl($url, $paytmParams);
			if(!empty($res['body']['resultInfo']['resultStatus']) && $res['body']['resultInfo']['resultStatus'] == 'S'){
				$data['txnToken']= $res['body']['txnToken'];
			}
			else
			{
				$data['txnToken']="";
			}
			/* $txntoken = json_encode($res); */
		}
		return $data;
	}

// processes the payment
function pw_edd_process_payment( $purchase_data ) {
	
	global $edd_options;
	/**********************************
	* set transaction mode
	**********************************/
		$paytm_redirect = $edd_options['paytm_transaction_url']."?"; 

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
            'gateway'      => 'paytm',
			'cart_details' => $purchase_data['cart_details'],
			'user_info'    => $purchase_data['user_info'],
			'status'       => 'pending'
		);
                
                

		// record the pending payment
		$payment = edd_insert_payment( $payment );
		$paramData = array('amount' => $purchase_data['price'], 'order_id' => $purchase_data['purchase_key'], 'cust_id' => $purchase_data['user_email']);
		$data= edd_blinkCheckoutSend($paramData,$payment);

		if(!empty($data['txnToken'])){
			wp_enqueue_style('paytmEddpayment', plugin_dir_url( __FILE__ ) . 'assets/css/paytm-payments.css', array(), '', '');
			
			
			if(isset($edd_options['paytm_select_mode'])){
				$mode = $edd_options['paytm_select_mode'];
			}else{
				$mode = 0;
			}

			$checkout_url = str_replace('MID',$edd_options['paytm_merchant_id'], PaytmHelper::getPaytmURL(PaytmConstants::CHECKOUT_JS_URL,$mode));
			$wait_msg='<script type="application/javascript" crossorigin="anonymous" src="'.$checkout_url.'" onload="invokeBlinkCheckoutPopup();"></script><div id="paytm-pg-spinner" class="paytm-woopg-loader"><div class="bounce1"></div><div class="bounce2"></div><div class="bounce3"></div><div class="bounce4"></div><div class="bounce5"></div><p class="loading-paytm">Loading Paytm...</p></div><div class="paytm-overlay paytm-woopg-loader"></div>';
			get_header();
			echo '<script type="text/javascript">
				function invokeBlinkCheckoutPopup(){
					console.log("method called");
					var config = {
						"root": "",
						"flow": "DEFAULT",
						"data": {
						  "orderId": "'.$purchase_data['purchase_key'].'", 
						  "token": "'.$data['txnToken'].'", 
						  "tokenType": "TXN_TOKEN",
						  "amount": "'.$purchase_data['price'].'"
						},
						"integration": {
							"platform": "EasyDigitalDownloads",
							"version": "'.EDD_VERSION.'|'.PaytmConstants::PLUGIN_VERSION.'"
						},
						"handler": {
						  "notifyMerchant": function(eventName,data){
							console.log("notifyMerchant handler function called");
							if(eventName=="APP_CLOSED")
							{
								jQuery(".loading-paytm").hide();
								jQuery("#paytm-pg-spinner").hide();
								jQuery(".paytm-overlay").hide();
								jQuery(".refresh-payment").show();
								history.go(-1);
							}
						  } 
						}
					  };
					  if(window.Paytm && window.Paytm.CheckoutJS){
						  window.Paytm.CheckoutJS.onLoad(function excecuteAfterCompleteLoad() {
							  window.Paytm.CheckoutJS.init(config).then(function onSuccess() {
								  window.Paytm.CheckoutJS.invoke();
							  }).catch(function onError(error){
								  console.log("error => ",error);
							  });
						  });
					  } 
				}
				jQuery(document).ready(function(){ jQuery(".re-invoke").on("click",function(){ window.Paytm.CheckoutJS.invoke(); return false; }); });
				</script>'.$wait_msg.'';	
				get_footer();
				exit();
		}else{
			$fail = true; // errors were detected
		}
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
                $isValidChecksum = "FALSE";
                $secret_key = $edd_options['paytm_mer_access_key'];
                
                if(!empty($_POST['CHECKSUMHASH'])){
				$post_checksum = $_POST['CHECKSUMHASH'];
				unset($_POST['CHECKSUMHASH']);	
				}else{
					$post_checksum = "";
				}
				$isValidChecksum = PaytmChecksum::verifySignature($_POST, $secret_key, $post_checksum);
				//if ($bool == "TRUE") {	   
                if ($isValidChecksum == "TRUE") {	   
					// Create an array having all required parameters for status query.
					//$requestParamList = array("MID" => $edd_options['paytm_merchant_id'] , "ORDERID" => $order_sent);
					
					//$StatusCheckSum = getChecksumFromArray($requestParamList, $secret_key);
							
					//$requestParamList['CHECKSUMHASH'] = $StatusCheckSum;
					
					//$check_status_url = $edd_options['paytm_transaction_status_url'];
					/*	19751/17Jan2018 end	*/	
					//$responseParamList = callNewAPI($check_status_url, $requestParamList);	

					$reqParams = array(
						"MID" 		=> $edd_options['paytm_merchant_id'],
						"ORDERID" 	=> $order_sent
					);
				
					$reqParams['CHECKSUMHASH'] = PaytmChecksum::generateSignature($reqParams, $secret_key);

					/* number of retries untill cURL gets success */
					$retry = 1;
					do{
						if(isset($edd_options['paytm_select_mode'])){
							$mode = $edd_options['paytm_select_mode'];
						}else{
							$mode = 0;
						}
						$resParams = PaytmHelper::executecUrl(PaytmHelper::getPaytmURL(PaytmConstants::ORDER_STATUS_URL,$mode), $reqParams);
						$retry++;
					} while(!$resParams['STATUS'] && $retry < PaytmConstants::MAX_RETRY_COUNT);
					/* number of retries untill cURL gets success */

					if($resParams['STATUS']=='TXN_SUCCESS' && $resParams['TXNAMOUNT']==$_POST['TXNAMOUNT'])
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


function edd_register_paytm_gateway_section( $sections ) {
    $sections['paytm_gateway'] = 'Paytm';
    return $sections;
}
add_filter( 'edd_settings_sections_gateways', 'edd_register_paytm_gateway_section' ); 

// adds the settings to the Payment Gateways section
function pw_edd_add_settings( $settings ) {
	$paytm_gateway_settings['paytm_gateway'] = array(
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
			/* 'paytm_transaction_url' => array(
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
			), */
			'paytm_select_mode' => array(
				'id'   => 'paytm_select_mode',
				'name' => __( 'Environment', 'pw_edd' ),
				'desc' => __( '', 'pw_edd' ),
				'type' => 'select',
				'options'		=> array("0" => "Test/Staging", "1" => "Production"),
				'size' => 'regular',
			),
            'paytm_website_name' => array(
				'id'   => 'paytm_website_name',
				'name' => __( 'Website Name', 'pw_edd' ),
				'desc' => __( 'Website Parameter Provided By Paytm', 'pw_edd' ),
				'type' => 'text',
				'size' => 'regular',
			),
            /* 'paytm_industry_type' => array(
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
			), */
		);
		$current_section = isset( $_GET['section'] ) ? $_GET['section'] : '';
	
	if ( 'paytm_gateway' == $current_section ) {
		
     $settings = array_merge( $settings, $paytm_gateway_settings );
    }
	return $settings;
}
add_filter( 'edd_settings_gateways', 'pw_edd_add_settings' );