<?php
/*
Litecoin Payments for WooCommerce
http://www.litecoinway.com/
*/


//===========================================================================
/*
   Input:
   ------
      $order_info =
         array (
            'order_id'        => $order_id,
            'order_total'     => $order_total_in_ltc,
            'order_datetime'  => date('Y-m-d H:i:s T'),
            'requested_by_ip' => @$_SERVER['REMOTE_ADDR'],
            );
*/
// Returns:
// --------
/*
    $ret_info_array = array (
       'result'                      => 'success', // OR 'error'
       'message'                     => '...',
       'host_reply_raw'              => '......',
       'generated_litecoin_address'   => '1H9uAP3x439YvQDoKNGgSYCg3FmrYRzpD2', // or false
       );
*/
//


function BWWC__get_litecoin_address_for_payment__electrum ($electrum_mpk, $order_info)
{
   global $wpdb;

   // status = "unused", "assigned", "used"
   $ltc_addresses_table_name     = $wpdb->prefix . 'bwwc_ltc_addresses';
   $origin_id                    = 'electrum.mpk.' . md5($electrum_mpk);

   $bwwc_settings = BWWC__get_settings ();
   $funds_received_value_expires_in_secs = $bwwc_settings['funds_received_value_expires_in_mins'] * 60;
   $assigned_address_expires_in_secs     = $bwwc_settings['assigned_address_expires_in_mins'] * 60;

   $clean_address = NULL;
   $current_time = time();

   if ($bwwc_settings['reuse_expired_addresses'])
      $reuse_expired_addresses_query_part = "OR (`status`='assigned' AND `total_received_funds`='0' AND (('$current_time' - `assigned_at`) > '$assigned_address_expires_in_secs'))";
   else
      $reuse_expired_addresses_query_part = "";

   //-------------------------------------------------------
   // Quick scan for ready-to-use address
   // NULL == not found
   // Retrieve:
   //     'unused'   - with fresh zero balances
   //     'assigned' - expired, with fresh zero balances (if 'reuse_expired_addresses' is true)
   //
   // Hence - any returned address will be clean to use.
   $query =
      "SELECT `ltc_address` FROM `$ltc_addresses_table_name`
         WHERE `origin_id`='$origin_id'
         AND `total_received_funds`='0'
         AND (('$current_time' - `received_funds_checked_at`) < '$funds_received_value_expires_in_secs')
         AND (`status`='unused' $reuse_expired_addresses_query_part)
         ORDER BY `index_in_wallet` ASC;"; // Try to use lower indexes first
   $clean_address = $wpdb->get_var ($query);
   //-------------------------------------------------------

  	if (!$clean_address)
   	{
      //-------------------------------------------------------
      // Find all unused addresses belonging to this mpk
      // Array(rows) or NULL
      // Retrieve:
      //    'unused'    - with old zero balances
      //    'unknown'   - ALL
      //    'assigned'  - expired with old zero balances (if 'reuse_expired_addresses' is true)
      //
      // Hence - any returned address with freshened balance==0 will be clean to use.
      $query =
         "SELECT * FROM `$ltc_addresses_table_name`
            WHERE `origin_id`='$origin_id'
            AND (
               `status`='unused'
               OR `status`='unknown'
               $reuse_expired_addresses_query_part
               )
            ORDER BY `index_in_wallet` ASC;"; // Try to use lower indexes first
      $addresses_to_verify_for_zero_balances_rows = $wpdb->get_results ($query, ARRAY_A);
      if (!is_array($addresses_to_verify_for_zero_balances_rows))
         $addresses_to_verify_for_zero_balances_rows = array();
      //-------------------------------------------------------

      //-------------------------------------------------------
      // Try to re-verify balances of existing addresses (with old or non-existing balances) before reverting to slow operation of generating new address.
      //
      $litecoins_api_failures = 0;
      foreach ($addresses_to_verify_for_zero_balances_rows as $address_to_verify_for_zero_balance_row)
      {
         // http://explorer.litecoin.net/chain/Litecoin/q/getreceivedbyaddress/LbfSCZE1p9A3Yj2JK1n57kxyD2H1ZSXtNG
         //
         $address_to_verify_for_zero_balance = $address_to_verify_for_zero_balance_row['ltc_address'];
         $ret_info_array = BWWC__getreceivedbyaddress_info ($address_to_verify_for_zero_balance, 0, $bwwc_settings['liteapi_api_timeout_secs']);
         if ($ret_info_array['balance'] === false)
         {
           $liteapis_api_failures ++;
           if ($liteapis_api_failures >= $bwwc_settings['max_liteapis_api_failures'])
           {
             // Allow no more than 3 contigious liteapis API failures. After which return error reply.
             $ret_info_array = array (
               'result'                      => 'error',
               'message'                     => $ret_info_array['message'],
               'host_reply_raw'              => $ret_info_array['host_reply_raw'],
               'generated_litecoin_address'   => false,
               );
             return $ret_info_array;
           }
         }
         else
         {
           if ($ret_info_array['balance'] == 0)
           {
             // Update DB with balance and timestamp, mark address as 'assigned' and return this address as clean.
             $clean_address    = $address_to_verify_for_zero_balance;
             break;
           }
          else
					{
						// Balance at this address suddenly became non-zero!
						// It means either order was paid after expiration or "unknown" address suddenly showed up with non-zero balance or payment was sent to this address outside of this online store business.
						// Mark it as 'revalidate' so cron job would check if that's possible delayed payment.
						//
					  $address_meta    = BWWC_unserialize_address_meta (@$address_to_verify_for_zero_balance_row['address_meta']);
					  if (isset($address_meta['orders'][0]))
					  	$new_status = 'revalidate';	// Past orders are present. There is a chance (for cron job) to match this payment to past (albeit expired) order.
					  else
					  	$new_status = 'used';				// No orders were ever placed to this address. Likely payment was sent to this address outside of this online store business.

						$current_time = time();
			      $query =
			      "UPDATE `$ltc_addresses_table_name`
			         SET
			            `status`='$new_status',
			            `total_received_funds` = '{$ret_info_array['balance']}',
			            `received_funds_checked_at`='$current_time'
			        WHERE `ltc_address`='$address_to_verify_for_zero_balance';";
			      $ret_code = $wpdb->query ($query);
					}
        }
      }
      //-------------------------------------------------------
  	}

  //-------------------------------------------------------
  if (!$clean_address)
  {
    // Still could not find unused virgin address. Time to generate it from scratch.
    /*
    Returns:
       $ret_info_array = array (
          'result'                      => 'success', // 'error'
          'message'                     => '', // Failed to find/generate litecoin address',
          'host_reply_raw'              => '', // Error. No host reply availabe.',
          'generated_litecoin_address'   => '1FVai2j2FsFvCbgsy22ZbSMfUd3HLUHvKx', // false,
          );
    */
    $ret_addr_array = BWWC__generate_new_litecoin_address_for_electrum_wallet ($bwwc_settings, $electrum_mpk);
    if ($ret_addr_array['result'] == 'success')
      $clean_address = $ret_addr_array['generated_litecoin_address'];
  }
  //-------------------------------------------------------

  //-------------------------------------------------------
   if ($clean_address)
   {
   /*
         $order_info =
         array (
            'order_id'     => $order_id,
            'order_total'  => $order_total_in_ltc,
            'order_datetime'  => date('Y-m-d H:i:s T'),
            'requested_by_ip' => @$_SERVER['REMOTE_ADDR'],
            );

*/

      /*
      $address_meta =
         array (
            'orders' =>
               array (
                  // All orders placed on this address in reverse chronological order
                  array (
                     'order_id'     => $order_id,
                     'order_total'  => $order_total_in_ltc,
                     'order_datetime'  => date('Y-m-d H:i:s T'),
                     'requested_by_ip' => @$_SERVER['REMOTE_ADDR'],
                  ),
                  array (
                     ...
                  ),
               ),
            'other_meta_info' => array (...)
         );
      */

      // Prepare `address_meta` field for this clean address.
      $address_meta = $wpdb->get_var ("SELECT `address_meta` FROM `$ltc_addresses_table_name` WHERE `ltc_address`='$clean_address'");
      $address_meta = BWWC_unserialize_address_meta ($address_meta);

      if (!isset($address_meta['orders']) || !is_array($address_meta['orders']))
         $address_meta['orders'] = array();

      array_unshift ($address_meta['orders'], $order_info);    // Prepend new order to array of orders
      if (count($address_meta['orders']) > 10)
         array_pop ($address_meta['orders']);   // Do not keep history of more than 10 unfullfilled orders per address.
      $address_meta_serialized = BWWC_serialize_address_meta ($address_meta);

      // Update DB with balance and timestamp, mark address as 'assigned' and return this address as clean.
      //
      $current_time = time();
      $remote_addr  = $order_info['requested_by_ip'];
      $query =
      "UPDATE `$ltc_addresses_table_name`
         SET
            `total_received_funds` = '0',
            `received_funds_checked_at`='$current_time',
            `status`='assigned',
            `assigned_at`='$current_time',
            `last_assigned_to_ip`='$remote_addr',
            `address_meta`='$address_meta_serialized'
        WHERE `ltc_address`='$clean_address';";
      $ret_code = $wpdb->query ($query);

      $ret_info_array = array (
         'result'                      => 'success',
         'message'                     => "",
         'host_reply_raw'              => "",
         'generated_litecoin_address'   => $clean_address,
         );

      return $ret_info_array;
  }
  //-------------------------------------------------------

   $ret_info_array = array (
      'result'                      => 'error',
      'message'                     => 'Failed to find/generate litecoin address. ' . $ret_addr_array['message'],
      'host_reply_raw'              => $ret_addr_array['host_reply_raw'],
      'generated_litecoin_address'   => false,
      );
   return $ret_info_array;
}
//===========================================================================

//===========================================================================
/*
Returns:
   $ret_info_array = array (
      'result'                      => 'success', // 'error'
      'message'                     => '', // Failed to find/generate litecoin address',
      'host_reply_raw'              => '', // Error. No host reply availabe.',
      'generated_litecoin_address'   => '1FVai2j2FsFvCbgsy22ZbSMfUd3HLUHvKx', // false,
      );
*/
// If $bwwc_settings or $electrum_mpk are missing - the best attempt will be made to manifest them.
// For performance reasons it is better to pass in these vars. if available.
//
function BWWC__generate_new_litecoin_address_for_electrum_wallet ($bwwc_settings=false, $electrum_mpk=false)
{
  global $wpdb;

  $ltc_addresses_table_name = $wpdb->prefix . 'bwwc_ltc_addresses';

  if (!$bwwc_settings)
    $bwwc_settings = BWWC__get_settings ();

  if (!$electrum_mpk)
  {
    // Try to retrieve it from copy of settings.
    $electrum_mpk = @$bwwc_settings['gateway_settings']['electrum_master_public_key'];

    if (!$electrum_mpk || @$bwwc_settings['gateway_settings']['service_provider'] != 'electrum-wallet')
    {
      // Litecoin gateway settings either were not saved
     $ret_info_array = array (
        'result'                      => 'error',
        'message'                     => 'No MPK passed and either no MPK present in copy-settings or service provider is not Electrum',
        'host_reply_raw'              => '',
        'generated_litecoin_address'   => false,
        );
     return $ret_info_array;
    }
  }

  $origin_id = 'electrum.mpk.' . md5($electrum_mpk);

  $funds_received_value_expires_in_secs = $bwwc_settings['funds_received_value_expires_in_mins'] * 60;
  $assigned_address_expires_in_secs     = $bwwc_settings['assigned_address_expires_in_mins'] * 60;

  $clean_address = false;

  // Find next index to generate
  $next_key_index = $wpdb->get_var ("SELECT MAX(`index_in_wallet`) AS `max_index_in_wallet` FROM `$ltc_addresses_table_name` WHERE `origin_id`='$origin_id';");
  if ($next_key_index === NULL)
    $next_key_index = $bwwc_settings['starting_index_for_new_ltc_addresses']; // Start generation of addresses from index #2 (skip two leading wallet's addresses)
  else
    $next_key_index = $next_key_index+1;  // Continue with next index

  $total_new_keys_generated = 0;
  $liteapis_api_failures = 0;
  do
  {
    $new_ltc_address = BWWC__MATH_generate_litecoin_address_from_mpk ($electrum_mpk, $next_key_index);
    $ret_info_array  = BWWC__getreceivedbyaddress_info ($new_ltc_address, 0, $bwwc_settings['liteapi_api_timeout_secs']);
    $total_new_keys_generated ++;

    if ($ret_info_array['balance'] === false)
      $status = 'unknown';
    else if ($ret_info_array['balance'] == 0)
      $status = 'unused'; // Newly generated address with freshly checked zero balance is unused and will be assigned.
    else
      $status = 'used';   // Generated address that was already used to receive money.

    $funds_received                  = ($ret_info_array['balance'] === false)?-1:$ret_info_array['balance'];
    $received_funds_checked_at_time  = ($ret_info_array['balance'] === false)?0:time();

    // Insert newly generated address into DB
    $query =
      "INSERT INTO `$ltc_addresses_table_name`
      (`ltc_address`, `origin_id`, `index_in_wallet`, `total_received_funds`, `received_funds_checked_at`, `status`) VALUES
      ('$new_ltc_address', '$origin_id', '$next_key_index', '$funds_received', '$received_funds_checked_at_time', '$status');";
    $ret_code = $wpdb->query ($query);

    $next_key_index++;

    if ($ret_info_array['balance'] === false)
    {
      $liteapis_api_failures ++;
      if ($liteapis_api_failures >= $bwwc_settings['max_liteapis_api_failures'])
      {
        // Allow no more than 3 contigious liteapis API failures. After which return error reply.
        $ret_info_array = array (
          'result'                      => 'error',
          'message'                     => $ret_info_array['message'],
          'host_reply_raw'              => $ret_info_array['host_reply_raw'],
          'generated_litecoin_address'   => false,
          );
        return $ret_info_array;
      }
    }
    else
    {
      if ($ret_info_array['balance'] == 0)
      {
        // Update DB with balance and timestamp, mark address as 'assigned' and return this address as clean.
        $clean_address    = $new_ltc_address;
      }
    }

    if ($clean_address)
      break;

    if ($total_new_keys_generated >= $bwwc_settings['max_unusable_generated_addresses'])
    {
      // Stop it after generating of 20 unproductive addresses.
      // Something is wrong. Possibly old merchant's wallet (with many used addresses) is used for new installation. - For this case 'starting_index_for_new_ltc_addresses'
      //  needs to be proper set to high value.
      $ret_info_array = array (
        'result'                      => 'error',
        'message'                     => "Problem: Generated '$total_new_keys_generated' addresses and none were found to be unused. Possibly old merchant's wallet (with many used addresses) is used for new installation. If that is the case - 'starting_index_for_new_ltc_addresses' needs to be proper set to high value",
        'host_reply_raw'              => '',
        'generated_litecoin_address'   => false,
        );
      return $ret_info_array;
    }

  } while (true);

  // Here only in case of clean address.
  $ret_info_array = array (
    'result'                      => 'success',
    'message'                     => '',
    'host_reply_raw'              => '',
    'generated_litecoin_address'   => $clean_address,
    );

  return $ret_info_array;
}
//===========================================================================

//===========================================================================
// Function makes sure that returned value is valid array
function BWWC_unserialize_address_meta ($flat_address_meta)
{
   $unserialized = @unserialize($flat_address_meta);
   if (is_array($unserialized))
      return $unserialized;
   return array();
}
//===========================================================================

//===========================================================================
// Function makes sure that value is ready to be stored in DB
function BWWC_serialize_address_meta ($address_meta_arr)
{
   return BWWC__safe_string_escape(serialize($address_meta_arr));
}
//===========================================================================

//===========================================================================
/*
$ret_info_array = array (
  'result'                      => 'success',
  'message'                     => "",
  'host_reply_raw'              => "",
  'balance'                     => false == error, else - balance
  );
*/
function BWWC__getreceivedbyaddress_info ($ltc_address, $required_confirmations=0, $api_timeout=10)
{
  // http://explorer.litecoin.net/chain/Litecoin/q/getreceivedbyaddress/LbfSCZE1p9A3Yj2JK1n57kxyD2H1ZSXtNG

   if ($required_confirmations)
   {
      $confirmations_url_part_bec = "/$required_confirmations";
      $confirmations_url_part_bci = "/$required_confirmations";
   }
   else
   {
      $confirmations_url_part_bec = "";
      $confirmations_url_part_bci = "";
   }

   $funds_received = BWWC__file_get_contents ('http://explorer.litecoin.net/chain/Litecoin/q/getreceivedbyaddress/' . $ltc_address . $confirmations_url_part_bec, true, $api_timeout);
   if (!is_numeric($funds_received))
   {
      $explorer_litecoin_net_failure_reply = $funds_received;
   }

  if (is_numeric($funds_received))
  {
    $ret_info_array = array (
      'result'                      => 'success',
      'message'                     => "",
      'host_reply_raw'              => "",
      'balance'                     => $funds_received,
      );
  }
  else
  {
    $ret_info_array = array (
      'result'                      => 'error',
      'message'                     => "Blockchains API failure. Erratic replies:\n" . $explorer_litecoin_net_failure_reply . "\n" . $liteapi_info_failure_reply,
      'host_reply_raw'              => $explorer_litecoin_net_failure_reply . "\n" . $liteapi_info_failure_reply,
      'balance'                     => false,
      );
  }

  return $ret_info_array;
}
//===========================================================================

//===========================================================================
// Input:
// ------

//    $callback_url => IPN notification URL upon received payment at generated address.
//    $forwarding_litecoin_address => Where all payments received at generated address should be ultimately forwarded to.
//
// Returns:
// --------
/*
    $ret_info_array = array (
       'result'                      => 'success', // OR 'error'
       'message'                     => '...',
       'host_reply_raw'              => '......',
       'generated_litecoin_address'   => '1H9uAP3x439YvQDoKNGgSYCg3FmrYRzpD2', // or false
       );
*/
//

function BWWC__generate_temporary_litecoin_address__liteapi_info ($forwarding_litecoin_address, $callback_url)
{
   //--------------------------------------------
   // Normalize inputs.
   $callback_url = urlencode(urldecode($callback_url));  // Make sure it is URL encoded.



   $liteapi_api_call = "https://liteapi.org/receive?method=create&address={$forwarding_litecoin_address}&anonymous=false&callback={$callback_url}";
   BWWC__log_event (__FILE__, __LINE__, "Calling liteapi.org API: " . $liteapi_api_call);
   $result = @BWWC__file_get_contents ($liteapi_api_call, true);
   if ($result)
   {
      $json_obj = @json_decode(trim($result));
      if (is_object($json_obj))
      {
         $generated_litecoin_address = @$json_obj->input_address;
         if (strlen($generated_litecoin_address) > 20)
         {
            $ret_info_array = array (
               'result'                      => 'success',
               'message'                     => '',
               'host_reply_raw'              => $result,
               'generated_litecoin_address'   => $generated_litecoin_address,
               );
            return $ret_info_array;
         }
      }
   }

   $ret_info_array = array (
      'result'                      => 'error',
      'message'                     => 'Blockchain.info API failure: ' . $result,
      'host_reply_raw'              => $result,
      'generated_litecoin_address'   => false,
      );
   return $ret_info_array;
}
//===========================================================================

//===========================================================================
// Returns:
//    success: number of currency units (dollars, etc...) would take to convert to 1 litecoin, ex: "15.32476".
//    failure: false
//
// $currency_code, one of: USD, AUD, CAD, CHF, CNY, DKK, EUR, GBP, HKD, JPY, NZD, PLN, RUB, SEK, SGD, THB
// $rate_type:
//    'avg'     -- 24 hrs average
//    'vwap'    -- weighted average as per: http://en.wikipedia.org/wiki/VWAP
//    'max'     -- maximize number of litecoins to get for item priced in currency: == min (avg, vwap, sell)
//                 This is useful to ensure maximum litecoin gain for stores priced in other currencies.
//                 Note: This is the least favorable exchange rate for the store customer.
// $get_ticker_string - true - ticker string of all exchange types for the given currency.

function BWWC__get_exchange_rate_per_litecoin ($currency_code, $rate_type = 'vwap', $get_ticker_string=false)
{
   if ($currency_code == 'BTC')
      return "1.00";   // 1:1

   if (!@in_array($currency_code, BWWC__get_settings ('supported_currencies_arr')))
      return false;

   $liteapi_url      = "http://liteapi.org/ticker";

   $bwwc_settings = BWWC__get_settings ();

   $current_time  = time();
   $cache_hit     = false;
   $avg = $vwap = $sell = 0;

   if (isset($bwwc_settings['exchange_rates'][$currency_code]['time-last-checked']))
   {
      $this_currency_info = $bwwc_settings['exchange_rates'][$currency_code];
      $delta = $current_time - $this_currency_info['time-last-checked'];
      if ($delta < 60*10)
      {
         // Exchange rates cache hit
         // Use cached values as they are still fresh (less than 10 minutes old)
         $avg  = $this_currency_info['avg'];
         $vwap = $this_currency_info['vwap'];
         $sell = $this_currency_info['sell'];

         if ($avg && $vwap && $sell)
            $cache_hit = true;
      }
   }

   if (!$avg || !$vwap || !$sell)
   {
      # Getting rate from liteapi.org
      $result = @BWWC__file_get_contents ($liteapi_url);
      if ($result)
      {
         $json_obj = @json_decode(trim($result));
         if (is_object($json_obj))
         {
            $key  = "15m";
            $avg  = $vwap = $sell = @$json_obj->$currency_code->$key;
         }
      }
   }

   if (!$avg || !$vwap || !$sell)
   {
      $msg = "<span style='color:red;'>WARNING: failed to retrieve litecoin exchange rates from all attempts. Internet connection/outgoing call security issues?</span>";
      BWWC__log_event (__FILE__, __LINE__, $msg);
      if ($get_ticker_string)
         return $msg;
      else
         return false;
   }

   if (!$cache_hit)
   {
      // Save new currency exchange rate info in cache
      $bwwc_settings = BWWC__get_settings ();   // Re-get settings in case other piece updated something while we were pulling exchange rate API's...
      $bwwc_settings['exchange_rates'][$currency_code]['time-last-checked'] = time();
      $bwwc_settings['exchange_rates'][$currency_code]['avg'] = $avg;
      $bwwc_settings['exchange_rates'][$currency_code]['vwap'] = $vwap;
      $bwwc_settings['exchange_rates'][$currency_code]['sell'] = $sell;
      BWWC__update_settings ($bwwc_settings);
   }

   if ($get_ticker_string)
   {
      $max = min ($avg, $vwap, $sell);
      return "<span style='color:darkgreen;'>Current Rates for 1 Litecoin (in {$currency_code}): Average={$avg}, Weighted Average={$vwap}, Maximum={$max}</span>";
   }

   switch ($rate_type)
      {
         case 'avg'  :  return $avg;
         case 'max'  :  return min ($avg, $vwap, $sell);
         case 'vwap' :
         default     :
                        return $vwap;
      }
}
//===========================================================================

//===========================================================================
/*
  Get web page contents with the help of PHP cURL library
   Success => content
   Error   => if ($return_content_on_error == true) $content; else FALSE;
*/
function BWWC__file_get_contents ($url, $return_content_on_error=false, $timeout=60, $user_agent=FALSE)
{
   if (!function_exists('curl_init'))
      {
      return @file_get_contents ($url);
      }

   $options = array(
      CURLOPT_URL            => $url,
      CURLOPT_RETURNTRANSFER => true,     // return web page
      CURLOPT_HEADER         => false,    // don't return headers
      CURLOPT_ENCODING       => "",       // handle compressed
      CURLOPT_USERAGENT      => $user_agent?$user_agent:urlencode("Mozilla/5.0 (Windows; U; Windows NT 6.1; en-US) AppleWebKit/534.12 (KHTML, like Gecko) Chrome/9.0.576.0 Safari/534.12"), // who am i

      CURLOPT_AUTOREFERER    => true,     // set referer on redirect
      CURLOPT_CONNECTTIMEOUT => $timeout,       // timeout on connect
      CURLOPT_TIMEOUT        => $timeout,       // timeout on response in seconds.
      CURLOPT_FOLLOWLOCATION => true,     // follow redirects
      CURLOPT_MAXREDIRS      => 10,       // stop after 10 redirects
      );

   $ch      = curl_init   ();

   if (function_exists('curl_setopt_array'))
      {
      curl_setopt_array      ($ch, $options);
      }
   else
      {
      // To accomodate older PHP 5.0.x systems
      curl_setopt ($ch, CURLOPT_URL            , $url);
      curl_setopt ($ch, CURLOPT_RETURNTRANSFER , true);     // return web page
      curl_setopt ($ch, CURLOPT_HEADER         , false);    // don't return headers
      curl_setopt ($ch, CURLOPT_ENCODING       , "");       // handle compressed
      curl_setopt ($ch, CURLOPT_USERAGENT      , $user_agent?$user_agent:urlencode("Mozilla/5.0 (Windows; U; Windows NT 6.1; en-US) AppleWebKit/534.12 (KHTML, like Gecko) Chrome/9.0.576.0 Safari/534.12")); // who am i
      curl_setopt ($ch, CURLOPT_AUTOREFERER    , true);     // set referer on redirect
      curl_setopt ($ch, CURLOPT_CONNECTTIMEOUT , $timeout);       // timeout on connect
      curl_setopt ($ch, CURLOPT_TIMEOUT        , $timeout);       // timeout on response in seconds.
      curl_setopt ($ch, CURLOPT_FOLLOWLOCATION , true);     // follow redirects
      curl_setopt ($ch, CURLOPT_MAXREDIRS      , 10);       // stop after 10 redirects
      }

   $content = curl_exec   ($ch);
   $err     = curl_errno  ($ch);
   $header  = curl_getinfo($ch);
   // $errmsg  = curl_error  ($ch);

   curl_close             ($ch);

   if (!$err && $header['http_code']==200)
      return trim($content);
   else
   {
      if ($return_content_on_error)
         return trim($content);
      else
         return FALSE;
   }
}
//===========================================================================

//===========================================================================
// Credits: http://www.php.net/manual/en/function.mysql-real-escape-string.php#100854
function BWWC__safe_string_escape ($str="")
{
   $len=strlen($str);
   $escapeCount=0;
   $targetString='';
   for ($offset=0; $offset<$len; $offset++)
   {
     switch($c=$str{$offset})
     {
         case "'":
         // Escapes this quote only if its not preceded by an unescaped backslash
                 if($escapeCount % 2 == 0) $targetString.="\\";
                 $escapeCount=0;
                 $targetString.=$c;
                 break;
         case '"':
         // Escapes this quote only if its not preceded by an unescaped backslash
                 if($escapeCount % 2 == 0) $targetString.="\\";
                 $escapeCount=0;
                 $targetString.=$c;
                 break;
         case '\\':
                 $escapeCount++;
                 $targetString.=$c;
                 break;
         default:
                 $escapeCount=0;
                 $targetString.=$c;
     }
   }
   return $targetString;
}
//===========================================================================

//===========================================================================
// Syntax:
//    BWWC__log_event (__FILE__, __LINE__, "Hi!");
//    BWWC__log_event (__FILE__, __LINE__, "Hi!", "/..");
//    BWWC__log_event (__FILE__, __LINE__, "Hi!", "", "another_log.php");
function BWWC__log_event ($filename, $linenum, $message, $prepend_path="", $log_file_name='__log.php')
{
   $log_filename   = dirname(__FILE__) . $prepend_path . '/' . $log_file_name;
   $logfile_header = "<?php exit(':-)'); ?>\n" . '/* =============== LitecoinWay LOG file =============== */' . "\r\n";
   $logfile_tail   = "\r\nEND";

   // Delete too long logfiles.
   //if (@file_exists ($log_filename) && filesize($log_filename)>1000000)
   //   unlink ($log_filename);

   $filename = basename ($filename);

   if (@file_exists ($log_filename))
      {
      // 'r+' non destructive R/W mode.
      $fhandle = @fopen ($log_filename, 'r+');
      if ($fhandle)
         @fseek ($fhandle, -strlen($logfile_tail), SEEK_END);
      }
   else
      {
      $fhandle = @fopen ($log_filename, 'w');
      if ($fhandle)
         @fwrite ($fhandle, $logfile_header);
      }

   if ($fhandle)
      {
      @fwrite ($fhandle, "\r\n// " . $_SERVER['REMOTE_ADDR'] . '(' . $_SERVER['REMOTE_PORT'] . ')' . ' -> ' . date("Y-m-d, G:i:s T") . "|" . BWWC_VERSION . "/" . BWWC_EDITION . "|$filename($linenum)|: " . $message . $logfile_tail);
      @fclose ($fhandle);
      }
}
//===========================================================================

