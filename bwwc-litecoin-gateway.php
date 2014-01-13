<?php
/*
Litecoin Payments for WooCommerce
http://www.litecoinway.com/
*/


//---------------------------------------------------------------------------
add_action('plugins_loaded', 'BWWC__plugins_loaded__load_litecoin_gateway', 0);
//---------------------------------------------------------------------------

//###########################################################################
// Hook payment gateway into WooCommerce

function BWWC__plugins_loaded__load_litecoin_gateway ()
{

    if (!class_exists('WC_Payment_Gateway'))
    	// Nothing happens here is WooCommerce is not loaded
    	return;

	//=======================================================================
	/**
	 * Litecoin Payment Gateway
	 *
	 * Provides a Litecoin Payment Gateway
	 *
	 * @class 		BWWC_Litecoin
	 * @extends		WC_Payment_Gateway
	 * @version
	 * @package
	 * @author 		LitecoinWay
	 */
	class BWWC_Litecoin extends WC_Payment_Gateway
	{
		//-------------------------------------------------------------------
	    /**
	     * Constructor for the gateway.
	     *
	     * @access public
	     * @return void
	     */
		public function __construct()
		{
      $this->id				= 'litecoin';
      $this->icon 			= plugins_url('/images/ltc_buyitnow_32x.png', __FILE__);	// 32 pixels high
      $this->has_fields 		= false;
      $this->method_title     = __( 'Litecoin', 'woocommerce' );

			// Load the settings.
			$this->init_settings();

			// Define user set variables
			$this->title 		= $this->settings['title'];	// The title which the user is shown on the checkout – retrieved from the settings which init_settings loads.
			$this->service_provider = $this->settings['service_provider'];
			$this->electrum_master_public_key = $this->settings['electrum_master_public_key'];
			$this->litecoin_addr_merchant = $this->settings['litecoin_addr_merchant'];	// Forwarding address where all product payments will aggregate.
			
			$this->confirmations = $this->settings['confirmations'];
			$this->exchange_rate_type = $this->settings['exchange_rate_type'];
			$this->exchange_multiplier = $this->settings['exchange_multiplier'];
			$this->description 	= $this->settings['description'];	// Short description about the gateway which is shown on checkout.
			$this->instructions = $this->settings['instructions'];	// Detailed payment instructions for the buyer.
			$this->instructions_multi_payment_str  = __('You may send payments from multiple accounts to reach the total required.', 'woocommerce');
			$this->instructions_single_payment_str = __('You must pay in a single payment in full.', 'woocommerce');

			// Load the form fields.
			$this->init_form_fields();

			// Actions
      if ( version_compare( WOOCOMMERCE_VERSION, '2.0.0', '>=' ) )
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array( &$this, 'process_admin_options' ) );
      else
				add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options')); // hook into this action to save options in the backend

	    add_action('woocommerce_thankyou_' . $this->id, array(&$this, 'BWWC__thankyou_page')); // hooks into the thank you page after payment

	    	// Customer Emails
	    add_action('woocommerce_email_before_order_table', array(&$this, 'BWWC__email_instructions'), 10, 2); // hooks into the email template to show additional details

			// Hook IPN callback logic
			if (version_compare (WOOCOMMERCE_VERSION, '2.0', '<'))
				add_action('init', array(&$this, 'BWWC__maybe_litecoin_ipn_callback'));
			else
				add_action('woocommerce_api_' . strtolower(get_class($this)), array($this,'BWWC__maybe_litecoin_ipn_callback'));

			// Validate currently set currency for the store. Must be among supported ones.
			if (!$this->BWWC__is_gateway_valid_for_use()) $this->enabled = false;
	    }
		//-------------------------------------------------------------------

		//-------------------------------------------------------------------
	    /**
	     * Check if this gateway is enabled and available for the store's default currency
	     *
	     * @access public
	     * @return bool
	     */
	    function BWWC__is_gateway_valid_for_use(&$ret_reason_message=NULL)
	    {
	    	$valid = true;

	    	//----------------------------------
	    	// Validate settings
	    	if (!$this->service_provider)
	    	{
	    		$reason_message = __("Litecoin Service Provider is not selected", 'woocommerce');
	    		$valid = false;
	    	}
	    	else if ($this->service_provider=='litecoin.org')
	    	{
	    		if ($this->litecoin_addr_merchant == '')
	    		{
		    		$reason_message = __("Your personal litecoin address is not selected", 'woocommerce');
		    		$valid = false;
	    		}
	    		else if ($this->litecoin_addr_merchant == '1H9uAP3x439YvQDoKNGgSYCg3FmrYRzpD2')
	    		{
		    		$reason_message = __("Your personal litecoin address is invalid. The address specified is Litecoinway.com's donation address :)", 'woocommerce');
		    		$valid = false;
	    		}
	    	}
	    	else if ($this->service_provider=='electrum-wallet')
	    	{
	    		if (!$this->electrum_master_public_key)
	    		{
		    		$reason_message = __("Pleace specify Electrum Master Public Key (Launch your electrum wallet, select Preferences->Import/Export->Master Public Key->Show)", 'woocommerce');
		    		$valid = false;
		    	}
	    		else if (!preg_match ('/^[a-f0-9]{128}$/', $this->electrum_master_public_key))
	    		{
		    		$reason_message = __("Electrum Master Public Key is invalid. Must be 128 characters long, consisting of digits and letters: 'a b c d e f'", 'woocommerce');
		    		$valid = false;
		    	}
		    	else if (!extension_loaded('gmp') && !extension_loaded('bcmath'))
		    	{
		    		$reason_message = __("ERROR: neither 'bcmath' nor 'gmp' math extensions are loaded For Electrum wallet options to function. Contact your hosting company and ask them to enable either 'bcmath' or 'gmp' extensions. 'gmp' is preferred (much faster)! \nAlternatively you may choose another 'Litecoin Service Provider' option.", 'woocommerce');
		    		$valid = false;
		    	}
	    	}

	    	if (!$valid)
	    	{
	    		if ($ret_reason_message !== NULL)
	    			$ret_reason_message = $reason_message;
	    		return false;
	    	}
	    	//----------------------------------

	    	//----------------------------------
	    	// Validate currency
	   		$currency_code            = get_woocommerce_currency();
	   		$supported_currencies_arr = BWWC__get_settings ('supported_currencies_arr');

		   	if ($currency_code != 'LTC' && !@in_array($currency_code, $supported_currencies_arr))
		   	{
			    $reason_message = __("Store currency is set to unsupported value", 'woocommerce') . "('{$currency_code}'). " . __("Valid currencies: ", 'woocommerce') . implode ($supported_currencies_arr, ", ");
	    		if ($ret_reason_message !== NULL)
	    			$ret_reason_message = $reason_message;
			  	return false;
		   	}

	     	return true;
	    	//----------------------------------
	    }
		//-------------------------------------------------------------------

		//-------------------------------------------------------------------
	    /**
	     * Initialise Gateway Settings Form Fields
	     *
	     * @access public
	     * @return void
	     */
	    function init_form_fields()
	    {
		    // This defines the settings we want to show in the admin area.
		    // This allows user to customize payment gateway.
		    // Add as many as you see fit.
		    // See this for more form elements: http://wcdocs.woothemes.com/codex/extending/settings-api/

	    	//-----------------------------------
	    	// Assemble currency ticker.
	   		$store_currency_code = get_woocommerce_currency();
	   		if ($store_currency_code == 'LTC')
	   			$currency_code = 'USD';
	   		else
	   			$currency_code = $store_currency_code;

				$currency_ticker = BWWC__get_exchange_rate_per_litecoin ($currency_code, 'max', true);
				$api_url = "https://mtgox.com/api/1/LTC{$currency_code}/ticker";
	    	//-----------------------------------

	    	//-----------------------------------
	    	// Payment instructions
	    	$payment_instructions = '
<table class="bwwc-payment-instructions-table" id="bwwc-payment-instructions-table">
  <tr class="bpit-table-row">
    <td colspan="2">' . __('Please send your litecoin payment as follows:', 'woocommerce') . '</td>
  </tr>
  <tr class="bpit-table-row">
    <td style="vertical-align:middle;" class="bpit-td-name bpit-td-name-amount">
      ' . __('Amount', 'woocommerce') . ' (<strong>LTC</strong>):
    </td>
    <td class="bpit-td-value bpit-td-value-amount">
      <div style="border:1px solid #FCCA09;padding:2px 6px;margin:2px;background-color:#FCF8E3;border-radius:4px;color:#CC0000;font-weight: bold;font-size: 120%;">
      	{{{LITECOINS_AMOUNT}}}
      </div>
    </td>
  </tr>
  <tr class="bpit-table-row">
    <td style="vertical-align:middle;" class="bpit-td-name bpit-td-name-ltcaddr">
      Address:
    </td>
    <td class="bpit-td-value bpit-td-value-ltcaddr">
      <div style="border:1px solid #FCCA09;padding:2px 6px;margin:2px;background-color:#FCF8E3;border-radius:4px;color:#555;font-weight: bold;font-size: 120%;">
        {{{LITECOINS_ADDRESS}}}
      </div>
    </td>
  </tr>
  <tr class="bpit-table-row">
    <td style="vertical-align:middle;" class="bpit-td-name bpit-td-name-qr">
	    QR Code:
    </td>
    <td class="bpit-td-value bpit-td-value-qr">
      <div style="border:1px solid #FCCA09;padding:5px;margin:2px;background-color:#FCF8E3;border-radius:4px;">
        <a href="litecoin://{{{LITECOINS_ADDRESS}}}?amount={{{LITECOINS_AMOUNT}}}"><img src="https://blockchain.info/qr?data=litecoin://{{{LITECOINS_ADDRESS}}}?amount={{{LITECOINS_AMOUNT}}}&size=180" style="vertical-align:middle;border:1px solid #888;" /></a>
      </div>
    </td>
  </tr>
</table>

' . __('Please note:', 'woocommerce') . '
<ol class="bpit-instructions">
    <li>' . __('You must make a payment within 1 hour, or your order will be cancelled', 'woocommerce') . '</li>
    <li>' . __('As soon as your payment is received in full you will receive email confirmation with order delivery details.', 'woocommerce') . '</li>
    <li>{{{EXTRA_INSTRUCTIONS}}}</li>
</ol>
';
				$payment_instructions = trim ($payment_instructions);

	    	$payment_instructions_description = '
						  <p class="description" style="width:50%;float:left;width:49%;">
					    	' . __( 'Specific instructions given to the customer to complete Litecoins payment.<br />You may change it, but make sure these tags will be present: <b>{{{LITECOINS_AMOUNT}}}</b>, <b>{{{LITECOINS_ADDRESS}}}</b> and <b>{{{EXTRA_INSTRUCTIONS}}}</b> as these tags will be replaced with customer - specific payment details.', 'woocommerce' ) . '
						  </p>
						  <p class="description" style="width:50%;float:left;width:49%;">
					    	Payment Instructions, original template (for reference):<br />
					    	<textarea rows="2" onclick="this.focus();this.select()" readonly="readonly" style="width:100%;background-color:#f1f1f1;height:4em">' . $payment_instructions . '</textarea>
						  </p>
					';
				$payment_instructions_description = trim ($payment_instructions_description);
	    	//-----------------------------------

	    	$this->form_fields = array(
				'enabled' => array(
								'title' => __( 'Enable/Disable', 'woocommerce' ),
								'type' => 'checkbox',
								'label' => __( 'Enable Litecoin Payments', 'woocommerce' ),
								'default' => 'yes'
							),
				'title' => array(
								'title' => __( 'Title', 'woocommerce' ),
								'type' => 'text',
								'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
								'default' => __( 'Litecoin Payment', 'woocommerce' )
							),

				'service_provider' => array(
								'title' => __('Litecoin service provider', 'woocommerce' ),
								'type' => 'select',
								'options' => array(
									''  => __( 'Please choose your provider', 'woocommerce' ),
									'electrum-wallet'  => __( 'Your own Electrum wallet', 'woocommerce' ),
									'litecoin.org' => __( 'litecoin.org API', 'woocommerce' ),
									),
								'default' => '',
								'description' => $this->service_provider?__("Please select your Litecoin service provider and press [Save changes]. Then fill-in necessary details and press [Save changes] again.<br />Recommended setting: <b>Your own Electrum wallet</b>", 'woocommerce'):__("Recommended setting: 'Your own Electrum wallet'. <a href='http://electrum.org/' target='_blank'>Free download of Electrum wallet here</a>.", 'woocommerce'),
							),

				'electrum_master_public_key' => array(
								'title' => __( 'Electrum wallet\'s Master Public Key', 'woocommerce' ),
								'type' => 'textarea',
								'default' => "",
								'css'     => $this->service_provider!='electrum-wallet'?'display:none;':'',
								'disabled' => $this->service_provider!='electrum-wallet'?true:false,
								'description' => $this->service_provider!='electrum-wallet'?__('Available when Litecoin service provider is set to: <b>Your own Electrum wallet</b>.', 'woocommerce'):__('Launch <a href="http://electrum.org/" target="_blank">Electrum wallet</a> and get Master Public Key value from Preferences -> Import/Export -> Master Public Key -> Show.<br />Copy long number string and paste it in this field.', 'woocommerce'),
							),

				'litecoin_addr_merchant' => array(
								'title' => __( 'Your personal litecoin address', 'woocommerce' ),
								'type' => 'text',
								'css'     => $this->service_provider!='litecoin.org'?'display:none;':'',
								'disabled' => $this->service_provider!='litecoin.org'?true:false,
								'description' => $this->service_provider!='litecoin.org'?__('Available when Litecoin service provider is set to: <b>litecoin.org</b>', 'woocommerce'):__( 'Your own litecoin address (such as: 1H9uAP3x439YvQDoKNGgSYCg3FmrYRzpD2) - where you would like the payment to be sent. When customer sends you payment for the product - it will be automatically forwarded to this address by litecoin.org APIs.', 'woocommerce' ),
								'default' => '',
							),


				'confirmations' => array(
								'title' => __( 'Number of confirmations required before accepting payment', 'woocommerce' ),
								'type' => 'text',
								'description' => __( 'After a transaction is broadcast to the Litecoin network, it may be included in a block that is published to the network. When that happens it is said that one <a href="https://en.litecoin.it/wiki/Confirmation" target="_blank">confirmation has occurred</a> for the transaction. With each subsequent block that is found, the number of confirmations is increased by one. To protect against double spending, a transaction should not be considered as confirmed until a certain number of blocks confirm, or verify that transaction. <br />6 is considered very safe number of confirmations, although it takes longer to confirm.', 'woocommerce' ),
								'default' => '6',
							),
				'exchange_rate_type' => array(
								'title' => __('Exchange rate calculation type', 'woocommerce' ),
								'type' => 'select',
								'disabled' => $store_currency_code=='LTC'?true:false,
								'options' => array(
									'avg'  => __( 'Average', 'woocommerce' ),
									'vwap' => __( 'Weighted Average', 'woocommerce' ),
									'max'  => __( 'Maximum', 'woocommerce' ),
									),
								'default' => 'vwap',
								'description' => ($store_currency_code=='LTC'?__('<span style="color:red;"><b>Disabled</b>: Applies only for stores with non-litecoin default currency.</span><br />', 'woocommerce'):'') .
									__('<b>Average</b>: <a href="https://mtgox.com/" target="_blank">MtGox</a> 24 hour average exchange rate<br /><b>Weighted Average</b> (recommended): MtGox <a href="http://en.wikipedia.org/wiki/VWAP" target="_blank">Weighted average</a> rate<br /><b>Maximum</b>: maximum exchange rate of all indicators (least favorable for customer). Calculated as: MIN (Average, Weighted Average, Sell price)') . " (<a href='{$api_url}' target='_blank'><b>rates API</b></a>)" . '<br />' . $currency_ticker,
							),
				'exchange_multiplier' => array(
								'title' => __('Exchange rate multiplier', 'woocommerce' ),
								'type' => 'text',
								'disabled' => $store_currency_code=='LTC'?true:false,
								'description' => ($store_currency_code=='LTC'?__('<span style="color:red;"><b>Disabled</b>: Applies only for stores with non-litecoin default currency.</span><br />', 'woocommerce'):'') .
									__('Extra multiplier to apply to convert store default currency to litecoin price. <br />Example: <b>1.05</b> - will add extra 5% to the total price in litecoin. May be useful to compensate merchant\'s loss to fees when converting litecoin to local currency, or to encourage customer to use litecoin for purchases (by setting multiplier to < 1.00 values).', 'woocommerce' ),
								'default' => '1.00',
							),
				'description' => array(
								'title' => __( 'Customer Message', 'woocommerce' ),
								'type' => 'text',
								'description' => __( 'Initial instructions for the customer at checkout screen', 'woocommerce' ),
								'default' => __( 'Please proceed to the next screen to see necessary payment details.', 'woocommerce' )
							),
				'instructions' => array(
								'title' => __( 'Payment Instructions (HTML)', 'woocommerce' ),
								'type' => 'textarea',
								'description' => $payment_instructions_description,
								'default' => $payment_instructions,
							),
				);
	    }
		//-------------------------------------------------------------------
/*
///!!!
									'<table>' .
									'	<tr><td colspan="2">' . __('Please send your litecoin payment as follows:', 'woocommerce' ) . '</td></tr>' .
									'	<tr><td>Amount (฿): </td><td><div style="border:1px solid #CCC;padding:2px 6px;margin:2px;background-color:#FEFEF0;border-radius:4px;color:#CC0000;">{{{LITECOINS_AMOUNT}}}</div></td></tr>' .
									'	<tr><td>Address: </td><td><div style="border:1px solid #CCC;padding:2px 6px;margin:2px;background-color:#FEFEF0;border-radius:4px;color:blue;">{{{LITECOINS_ADDRESS}}}</div></td></tr>' .
									'</table>' .
									__('Please note:', 'woocommerce' ) .
									'<ol>' .
									'   <li>' . __('You must make a payment within 8 hours, or your order will be cancelled', 'woocommerce' ) . '</li>' .
									'   <li>' . __('As soon as your payment is received in full you will receive email confirmation with order delivery details.', 'woocommerce' ) . '</li>' .
									'   <li>{{{EXTRA_INSTRUCTIONS}}}</li>' .
									'</ol>'

*/

		//-------------------------------------------------------------------
		/**
		 * Admin Panel Options
		 * - Options for bits like 'title' and availability on a country-by-country basis
		 *
		 * @access public
		 * @return void
		 */
		public function admin_options()
		{
			$validation_msg = "";
			$store_valid    = $this->BWWC__is_gateway_valid_for_use ($validation_msg);

			// After defining the options, we need to display them too; thats where this next function comes into play:
	    	?>
	    	<h3><?php _e('Litecoin Payment', 'woocommerce'); ?></h3>
	    	<p>
	    		<?php _e('Allows litecoin payments. <a href="https://en.litecoin.it/wiki/Main_Page" target="_blank">Litecoin</a> are peer-to-peer, decentralized digital currency that enables instant payments from anyone to anyone, anywhere in the world',
	    				'woocommerce'); ?>
	    	</p>
	    	<?php
	    		echo $store_valid ? ('<p style="border:1px solid #DDD;padding:5px 10px;font-weight:bold;color:#004400;background-color:#CCFFCC;">' . __('Litecoin payment gateway is operational','woocommerce') . '</p>') : ('<p style="border:1px solid #DDD;padding:5px 10px;font-weight:bold;color:#EE0000;background-color:#FFFFAA;">' . __('Litecoin payment gateway is not operational: ','woocommerce') . $validation_msg . '</p>');
	    	?>
	    	<table class="form-table">
	    	<?php
	    		// Generate the HTML For the settings form.
	    		$this->generate_settings_html();
	    	?>
			</table><!--/.form-table-->
	    	<?php
	    }
		//-------------------------------------------------------------------

		//-------------------------------------------------------------------
	  // Hook into admin options saving.
    public function process_admin_options()
    {
    	// Call parent
    	parent::process_admin_options();

    	if (isset($_POST) && is_array($_POST))
    	{
	  		$bwwc_settings = BWWC__get_settings ();
	  		if (!isset($bwwc_settings['gateway_settings']) || !is_array($bwwc_settings['gateway_settings']))
	  			$bwwc_settings['gateway_settings'] = array();

	    	$prefix        = 'woocommerce_litecoin_';
	    	$prefix_length = strlen($prefix);

	    	foreach ($_POST as $varname => $varvalue)
	    	{
	    		if (strpos($varname, 'woocommerce_litecoin_') === 0)
	    		{
	    			$trimmed_varname = substr($varname, $prefix_length);
	    			if ($trimmed_varname != 'description' && $trimmed_varname != 'instructions')
	    				$bwwc_settings['gateway_settings'][$trimmed_varname] = $varvalue;
	    		}
	    	}

	  		// Update gateway settings within BWWC own settings for easier access.
	      BWWC__update_settings ($bwwc_settings);
	    }
    }
		//-------------------------------------------------------------------

		//-------------------------------------------------------------------
	    /**
	     * Process the payment and return the result
	     *
	     * @access public
	     * @param int $order_id
	     * @return array
	     */
		function process_payment ($order_id)
		{
			$order = new WC_Order ($order_id);

			//-----------------------------------
			// Save litecoin payment info together with the order.
			// Note: this code must be on top here, as other filters will be called from here and will use these values ...
			//
			// Calculate realtime litecoin price (if exchange is necessary)

			$exchange_rate = BWWC__get_exchange_rate_per_litecoin (get_woocommerce_currency(), $this->exchange_rate_type);
			if (!$exchange_rate)
			{
				$msg = 'ERROR: Cannot determine Litecoin exchange rate. Possible issues: store server does not allow outgoing connections, exchange rate servers are blocking incoming connections or down. ' .
					   'You may avoid that by setting store currency directly to Litecoin(LTC)';
      			BWWC__log_event (__FILE__, __LINE__, $msg);
      			exit ('<h2 style="color:red;">' . $msg . '</h2>');
			}

			$order_total_in_ltc   = ($order->get_total() / $exchange_rate);
			if (get_woocommerce_currency() != 'LTC')
				// Apply exchange rate multiplier only for stores with non-litecoin default currency.
				$order_total_in_ltc = $order_total_in_ltc * $this->exchange_multiplier;

			$order_total_in_ltc   = sprintf ("%.8f", $order_total_in_ltc);

  		$litecoins_address = false;

  		$order_info =
  			array (
  				'order_id'				=> $order_id,
  				'order_total'			=> $order_total_in_ltc,
  				'order_datetime'  => date('Y-m-d H:i:s T'),
  				'requested_by_ip'	=> @$_SERVER['REMOTE_ADDR'],
  				);

  		$ret_info_array = array();

			if ($this->service_provider == 'litecoin.org')
			{
				$litecoin_addr_merchant = $this->litecoin_addr_merchant;
				$secret_key = substr(md5(microtime()), 0, 16);	# Generate secret key to be validate upon receiving IPN callback to prevent spoofing.
				$callback_url = trailingslashit (home_url()) . "?wc-api=BWWC_Litecoin&secret_key={$secret_key}&litecoinway=1&src=bcinfo&order_id={$order_id}"; // http://www.example.com/?litecoinway=1&order_id=74&src=bcinfo
	   		BWWC__log_event (__FILE__, __LINE__, "Calling BWWC__generate_temporary_litecoin_address__litecoin_info(). Payments to be forwarded to: '{$litecoin_addr_merchant}' with callback URL: '{$callback_url}' ...");

	   			// This function generates temporary litecoin address and schedules IPN callback at the same
				$ret_info_array = BWWC__generate_temporary_litecoin_address__litecoin_info ($litecoin_addr_merchant, $callback_url);
	
				/*
            $ret_info_array = array (
               'result'                      => 'success', // OR 'error'
               'message'										 => '...',
               'host_reply_raw'              => '......',
               'generated_litecoin_address'   => '1H9uAP3x439YvQDoKNGgSYCg3FmrYRzpD2', // or false
               );
				*/
				$litecoins_address = @$ret_info_array['generated_litecoin_address'];
			}
			else if ($this->service_provider == 'electrum-wallet')
			{
				// Generate litecoin address for electrum wallet provider.
				/*
            $ret_info_array = array (
               'result'                      => 'success', // OR 'error'
               'message'										 => '...',
               'host_reply_raw'              => '......',
               'generated_litecoin_address'   => '1H9uAP3x439YvQDoKNGgSYCg3FmrYRzpD2', // or false
               );
				*/
				$ret_info_array = BWWC__get_litecoin_address_for_payment__electrum ($this->electrum_master_public_key, $order_info);
				$litecoins_address = @$ret_info_array['generated_litecoin_address'];
			}

			if (!$litecoins_address)
			{
				$msg = "ERROR: cannot generate litecoin address for the order: '" . @$ret_info_array['message'] . "'";
      			BWWC__log_event (__FILE__, __LINE__, $msg);
      			exit ('<h2 style="color:red;">' . $msg . '</h2>');
			}

   		BWWC__log_event (__FILE__, __LINE__, "     Generated unique litecoin address: '{$litecoins_address}' for order_id " . $order_id);

			if ($this->service_provider == 'litecoin.org')
			{
	     	update_post_meta (
	     		$order_id, 			// post id ($order_id)
	     		'secret_key', 	// meta key
	     		$secret_key 		// meta value. If array - will be auto-serialized
	     		);
	 		}

     	update_post_meta (
     		$order_id, 			// post id ($order_id)
     		'order_total_in_ltc', 	// meta key
     		$order_total_in_ltc 	// meta value. If array - will be auto-serialized
     		);
     	update_post_meta (
     		$order_id, 			// post id ($order_id)
     		'litecoins_address',	// meta key
     		$litecoins_address 	// meta value. If array - will be auto-serialized
     		);
     	update_post_meta (
     		$order_id, 			// post id ($order_id)
     		'litecoins_paid_total',	// meta key
     		"0" 	// meta value. If array - will be auto-serialized
     		);
     	update_post_meta (
     		$order_id, 			// post id ($order_id)
     		'litecoins_refunded',	// meta key
     		"0" 	// meta value. If array - will be auto-serialized
     		);
     	update_post_meta (
     		$order_id, 				// post id ($order_id)
     		'_incoming_payments',	// meta key. Starts with '_' - hidden from UI.
     		array()					// array (array('datetime'=>'', 'from_addr'=>'', 'amount'=>''),)
     		);
     	update_post_meta (
     		$order_id, 				// post id ($order_id)
     		'_payment_completed',	// meta key. Starts with '_' - hidden from UI.
     		0					// array (array('datetime'=>'', 'from_addr'=>'', 'amount'=>''),)
     		);
			//-----------------------------------

      		///BWWC__log_event (__FILE__, __LINE__, "process_payment() called for order id = $order_id");

			// The litecoin gateway does not take payment immediately, but it does need to change the orders status to on-hold
			// (so the store owner knows that litecoin payment is pending).
			// We also need to tell WooCommerce that it needs to redirect to the thankyou page – this is done with the returned array
			// and the result being a success.
			//
			global $woocommerce;

			//	Updating the order status:
			// Mark as on-hold (we're awaiting for litecoins payment to arrive)
			$order->update_status('on-hold', __('Awaiting litecoin payment to arrive', 'woocommerce'));

			// Reduce stock levels
			$order->reduce_order_stock();

			// Remove cart
			$woocommerce->cart->empty_cart();

			// Empty awaiting payment session
			unset($_SESSION['order_awaiting_payment']);

			// Return thankyou redirect
			return array(
				'result' 	=> 'success',
				'redirect'	=> add_query_arg('key', $order->order_key, add_query_arg('order', $order_id, get_permalink(woocommerce_get_page_id('thanks'))))
			);

		}
		//-------------------------------------------------------------------

		//-------------------------------------------------------------------
	    /**
	     * Output for the order received page.
	     *
	     * @access public
	     * @return void
	     */
		function BWWC__thankyou_page($order_id)
		{
			// BWWC__thankyou_page is hooked into the "thank you" page and in the simplest case can just echo’s the description.

			// Get order object.
			// http://wcdocs.woothemes.com/apidocs/class-WC_Order.html
			$order = new WC_Order($order_id);

			// Assemble detailed instructions.
			$order_total_in_ltc   = get_post_meta($order->id, 'order_total_in_ltc',   true); // set single to true to receive properly unserialized array
			$litecoins_address = get_post_meta($order->id, 'litecoins_address', true); // set single to true to receive properly unserialized array

      		///BWWC__log_event (__FILE__, __LINE__, "BWWC__thankyou_page() called for order id: {$order_id}. Litecoin address: $litecoins_address ({$order_total_in_ltc})");

			$instructions = $this->instructions;
			$instructions = str_replace ('{{{LITECOINS_AMOUNT}}}',  $order_total_in_ltc, $instructions);
			$instructions = str_replace ('{{{LITECOINS_ADDRESS}}}', $litecoins_address, 	$instructions);
			$instructions =
				str_replace (
					'{{{EXTRA_INSTRUCTIONS}}}',

					$this->instructions_multi_payment_str,
					$instructions
					);
            $order->add_order_note( __("Order instructions: price=&#3647;{$order_total_in_ltc}, incoming account:{$litecoins_address}", 'woocommerce'));

	        echo wpautop (wptexturize ($instructions));
		}
		//-------------------------------------------------------------------

		//-------------------------------------------------------------------
	    /**
	     * Add content to the WC emails.
	     *
	     * @access public
	     * @param WC_Order $order
	     * @param bool $sent_to_admin
	     * @return void
	     */
		function BWWC__email_instructions ($order, $sent_to_admin)
		{
	    	if ($sent_to_admin) return;
	    	if ($order->status !== 'on-hold') return;
	    	if ($order->payment_method !== 'litecoin') return;

	    	// Assemble payment instructions for email
			$order_total_in_ltc   = get_post_meta($order->id, 'order_total_in_ltc',   true); // set single to true to receive properly unserialized array
			$litecoins_address = get_post_meta($order->id, 'litecoins_address', true); // set single to true to receive properly unserialized array

      		///BWWC__log_event (__FILE__, __LINE__, "BWWC__email_instructions() called for order id={$order->id}. Litecoin address: $litecoins_address ({$order_total_in_ltc})");

			$instructions = $this->instructions;
			$instructions = str_replace ('{{{LITECOINS_AMOUNT}}}',  $order_total_in_ltc, 	$instructions);
			$instructions = str_replace ('{{{LITECOINS_ADDRESS}}}', $litecoins_address, 	$instructions);
			$instructions =
				str_replace (
					'{{{EXTRA_INSTRUCTIONS}}}',

					$this->instructions_multi_payment_str,
					$instructions
					);

			echo wpautop (wptexturize ($instructions));
		}
		//-------------------------------------------------------------------

		//-------------------------------------------------------------------
		/**
		 * Check for Litecoin-related IPN callabck
		 *
		 * @access public
		 * @return void
		 */
		function BWWC__maybe_litecoin_ipn_callback ()
		{
			// If example.com/?litecoinway=1 is present - it is callback URL.
			if (isset($_REQUEST['litecoinway']) && $_REQUEST['litecoinway'] == '1')
			{
     		BWWC__log_event (__FILE__, __LINE__, "BWWC__maybe_litecoin_ipn_callback () called and 'litecoinway=1' detected. REQUEST  =  " . serialize(@$_REQUEST));

				if (@$_GET['src'] != 'bcinfo')
				{
					$src = $_GET['src'];
					BWWC__log_event (__FILE__, __LINE__, "Warning: received IPN notification with 'src'= '{$src}', which is not matching expected: 'bcinfo'. Ignoring ...");
					exit();
				}

				// Processing IPN callback from litecoin.org ('bcinfo')


				$order_id = @$_GET['order_id'];

				$secret_key = get_post_meta($order_id, 'secret_key', true);
				$secret_key_sent = @$_GET['secret_key'];
				// Check the Request secret_key matches the original one (litecoin.org sends all params back)
				if ($secret_key_sent != $secret_key)
				{
     			BWWC__log_event (__FILE__, __LINE__, "Warning: secret_key does not match! secret_key sent: '{$secret_key_sent}'. Expected: '{$secret_key}'. Processing aborted.");
     			exit ('Invalid secret_key');
				}

				$confirmations = @$_GET['confirmations'];



				if ($confirmations >= $this->confirmations)
				{

					// The value of the payment received in satoshi (not including fees). Divide by 100000000 to get the value in LTC.
					$value_in_ltc 		= @$_GET['value'] / 100000000;
					$txn_hash 			= @$_GET['transaction_hash'];
					$txn_confirmations 	= @$_GET['confirmations'];

					//---------------------------
					// Update incoming payments array stats
					$incoming_payments = get_post_meta($order_id, '_incoming_payments', true);
					$incoming_payments[$txn_hash] =
						array (
							'txn_value' 		=> $value_in_ltc,
							'dest_address' 		=> @$_GET['address'],
							'confirmations' 	=> $txn_confirmations,
							'datetime'			=> date("Y-m-d, G:i:s T"),
							);

					update_post_meta ($order_id, '_incoming_payments', $incoming_payments);
					//---------------------------

					//---------------------------
					// Recalc total amount received for this order by adding totals from uniquely hashed txn's ...
					$paid_total_so_far = 0;
					foreach ($incoming_payments as $k => $txn_data)
						$paid_total_so_far += $txn_data['txn_value'];

					update_post_meta ($order_id, 'litecoins_paid_total', $paid_total_so_far);
					//---------------------------

					$order_total_in_ltc = get_post_meta($order_id, 'order_total_in_ltc', true);
					if ($paid_total_so_far >= $order_total_in_ltc)
					{
						BWWC__process_payment_completed_for_order ($order_id, false);
					}
					else
					{
     				BWWC__log_event (__FILE__, __LINE__, "NOTE: Payment received (for LTC {$value_in_ltc}), but not enough yet to cover the required total. Will be waiting for more. Litecoins: now/total received/needed = {$value_in_ltc}/{$paid_total_so_far}/{$order_total_in_ltc}");
					}

			    // Reply '*ok*' so no more notifications are sent
			    exit ('*ok*');
				}
				else
				{
					// Number of confirmations are not there yet... Skip it this time ...
			    // Don't print *ok* so the notification resent again on next confirmation
   				BWWC__log_event (__FILE__, __LINE__, "NOTE: Payment notification received (for LTC {$value_in_ltc}), but number of confirmations is not enough yet. Confirmations received/required: {$confirmations}/{$this->confirmations}");
			    exit();
				}
			}
		}
		//-------------------------------------------------------------------
	}
	//=======================================================================


	//-----------------------------------------------------------------------
	// Hook into WooCommerce - add necessary hooks and filters
	add_filter ('woocommerce_payment_gateways', 	'BWWC__add_litecoin_gateway' );

	// Disable unnecessary billing fields.
	/// Note: it affects whole store.
	/// add_filter ('woocommerce_checkout_fields' , 	'BWWC__woocommerce_checkout_fields' );

	add_filter ('woocommerce_currencies', 			'BWWC__add_ltc_currency');
	add_filter ('woocommerce_currency_symbol', 		'BWWC__add_ltc_currency_symbol', 10, 2);

	// Change [Order] button text on checkout screen.
    /// Note: this will affect all payment methods.
    /// add_filter ('woocommerce_order_button_text', 	'BWWC__order_button_text');
	//-----------------------------------------------------------------------

	//=======================================================================
	/**
	 * Add the gateway to WooCommerce
	 *
	 * @access public
	 * @param array $methods
	 * @package
	 * @return array
	 */
	function BWWC__add_litecoin_gateway( $methods )
	{
		$methods[] = 'BWWC_Litecoin';
		return $methods;
	}
	//=======================================================================

	//=======================================================================
	// Our hooked in function - $fields is passed via the filter!
	function BWWC__woocommerce_checkout_fields ($fields)
	{
	     unset($fields['order']['order_comments']);
	     unset($fields['billing']['billing_first_name']);
	     unset($fields['billing']['billing_last_name']);
	     unset($fields['billing']['billing_company']);
	     unset($fields['billing']['billing_address_1']);
	     unset($fields['billing']['billing_address_2']);
	     unset($fields['billing']['billing_city']);
	     unset($fields['billing']['billing_postcode']);
	     unset($fields['billing']['billing_country']);
	     unset($fields['billing']['billing_state']);
	     unset($fields['billing']['billing_phone']);
	     return $fields;
	}
	//=======================================================================

	//=======================================================================
	function BWWC__add_ltc_currency($currencies)
	{
	     $currencies['LTC'] = __( 'Litecoin (LTC)', 'woocommerce' );
	     return $currencies;
	}
	//=======================================================================

	//=======================================================================
	function BWWC__add_ltc_currency_symbol($currency_symbol, $currency)
	{
		switch( $currency )
		{
			case 'LTC':
				$currency_symbol = 'LTC';
			break;
		}

		return $currency_symbol;
	}
	//=======================================================================

	//=======================================================================
 	function BWWC__order_button_text () { return 'Continue'; }
	//=======================================================================
}
//###########################################################################

//===========================================================================
function BWWC__process_payment_completed_for_order ($order_id, $litecoins_paid=false)
{

	if ($litecoins_paid)
		update_post_meta ($order_id, 'litecoins_paid_total', $litecoins_paid);

	// Payment completed
	// Make sure this logic is done only once, in case customer keep sending payments :)
	if (!get_post_meta($order_id, '_payment_completed', true))
	{
		update_post_meta ($order_id, '_payment_completed', '1');

		BWWC__log_event (__FILE__, __LINE__, "Success: order '{$order_id}' paid in full. Processing and notifying customer ...");

		// Instantiate order object.
		$order = new WC_Order($order_id);
		$order->add_order_note( __('Order paid in full', 'woocommerce') );
	  $order->payment_complete();
	}
}
//===========================================================================
