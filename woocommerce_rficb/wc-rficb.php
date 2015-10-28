<?php 
/*
  Plugin Name: Rficb Payment Gateway
  Plugin URI: 
  Description: Allows you to use Rficb payment gateway with the WooCommerce plugin.
  Version: 0.9
  Author: Romanof
 */

//TODO: Выбор платежной системы на стороне магазина

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
 
 /**
 * Add roubles in currencies
 * 
 * @since 0.3
 */
function rficb_rub_currency_symbol( $currency_symbol, $currency ) {
    if($currency == "RUB") {
        $currency_symbol = 'р.';
    }
    return $currency_symbol;
}

function rficb_rub_currency( $currencies ) {
    $currencies["RUB"] = 'Russian Roubles';
    return $currencies;
}

add_filter( 'woocommerce_currency_symbol', 'rficb_rub_currency_symbol', 10, 2 );
add_filter( 'woocommerce_currencies', 'rficb_rub_currency', 10, 1 );


/* Add a custom payment class to WC
  ------------------------------------------------------------ */
add_action('plugins_loaded', 'woocommerce_rficb', 0);
function woocommerce_rficb(){
	if (!class_exists('WC_Payment_Gateway'))
		return; // if the WC payment gateway class is not available, do nothing
	if(class_exists('WC_RFICB'))
		return;
class WC_RFICB extends WC_Payment_Gateway{
	public function __construct(){
		
		$plugin_dir = plugin_dir_url(__FILE__);

		global $woocommerce;

		$this->id = 'rficb';
		$this->icon = apply_filters('woocommerce_rficb_icon', ''.$plugin_dir.'rficb.png');
		$this->has_fields = false;
    $this->liveurl = 'https://partner.rficb.ru/a1lite/input';

		// Load the settings
		$this->init_form_fields();
		$this->init_settings();

		// Define user set variables
		$this->title = $this->get_option('title');
		$this->rficb_merchant = $this->get_option('rficb_merchant');
		$this->rficb_key = $this->get_option('rficb_key');
		$this->rficb_skey = $this->get_option('rficb_skey');
		$this->description = $this->get_option('description');
		$this->instructions = $this->get_option('instructions');

		// Actions
		add_action('valid-rficb-standard-ipn-reques', array($this, 'successful_request') );
		add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));

		// Save options
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		// Payment listener/API hook
		add_action('woocommerce_api_wc_' . $this->id, array($this, 'check_ipn_response'));

		if (!$this->is_valid_for_use()){
			$this->enabled = false;
		}
	}
	
	/**
	 * Check if this gateway is enabled and available in the user's country
	 */
	function is_valid_for_use(){
		if (!in_array(get_option('woocommerce_currency'), array('RUB'))){
			return false;
		}
		return true;
	}
	
	/**
	* Admin Panel Options 
	* - Options for bits like 'title' and availability on a country-by-country basis
	*
	* @since 0.1
	**/
	public function admin_options() {
		?>
		<h3><?php _e('RFICB', 'woocommerce'); ?></h3>
		<p><?php _e('Настройка приема электронных платежей через Merchant RFICB.', 'woocommerce'); ?></p>

	  <?php if ( $this->is_valid_for_use() ) : ?>

		<table class="form-table">

		<?php    	
    			// Generate the HTML For the settings form.
    			$this->generate_settings_html();
    ?>
    </table><!--/.form-table-->
    		
    <?php else : ?>
		<div class="inline error"><p><strong><?php _e('Шлюз отключен', 'woocommerce'); ?></strong>: <?php _e('RFICB не поддерживает валюты Вашего магазина.', 'woocommerce' ); ?></p></div>
		<?php
			endif;

    } // End admin_options()

  /**
  * Initialise Gateway Settings Form Fields
  *
  * @access public
  * @return void
  */
	function init_form_fields(){
		$this->form_fields = array(
				'enabled' => array(
					'title' => __('Включить/Выключить', 'woocommerce'),
					'type' => 'checkbox',
					'label' => __('Включен', 'woocommerce'),
					'default' => 'yes'
				),
				'title' => array(
					'title' => __('Название', 'woocommerce'),
					'type' => 'text', 
					'description' => __( 'Это название, которое пользователь видит во время проверки.', 'woocommerce' ), 
					'default' => __('RFICB', 'woocommerce')
				),
				'rficb_key' => array(
					'title' => __('Ключ', 'woocommerce'),
					'type' => 'text',
					'description' => __('Пожалуйста введите ключ.', 'woocommerce'),
					'default' => ''
				),
				'rficb_skey' => array(
					'title' => __('Секретный ключ', 'woocommerce'),
					'type' => 'text',
					'description' => __('Пожалуйста введите Секретный ключ.', 'woocommerce'),
					'default' => ''
				),
				'description' => array(
					'title' => __( 'Description', 'woocommerce' ),
					'type' => 'textarea',
					'description' => __( 'Описанием метода оплаты которое клиент будет видеть на вашем сайте.', 'woocommerce' ),
					'default' => 'Оплата с помощью rficb.'
				),
				'instructions' => array(
					'title' => __( 'Instructions', 'woocommerce' ),
					'type' => 'textarea',
					'description' => __( 'Инструкции, которые будут добавлены на страницу благодарностей.', 'woocommerce' ),
					'default' => 'Оплата с помощью rficb.'
				)
			);
	}

	/**
	* There are no payment fields for sprypay, but we want to show the description if set.
	**/
	function payment_fields(){
		if ($this->description){
			echo wpautop(wptexturize($this->description));
		}
	}
	/**
	* Generate the dibs button link
	**/
	public function generate_form($order_id){
		global $woocommerce;

		$order = new WC_Order( $order_id );

		$action_adr = $this->liveurl;

		$out_summ = number_format($order->order_total, 2, '.', '');

		$args = array(
				// Merchant
				'key' => $this->rficb_key,
				'cost' => $out_summ,
				'order_id' => $order_id,
				'name' => 'покупка в магазине',
			);

		$paypal_args = apply_filters('woocommerce_rficb_args', $args);

		$args_array = array();

		foreach ($args as $key => $value){
			$args_array[] = '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr($value).'" />';
		}

		return
			'<form action="'.esc_url($action_adr).'" method="POST" id="rficb_payment_form">'."\n".
			implode("\n", $args_array).
			'<input type="submit" class="button alt" id="submit_rficb_payment_form" value="'.__('Оплатить', 'woocommerce').'" /> <a class="button cancel" href="'.$order->get_cancel_order_url().'">'.__('Отказаться от оплаты & вернуться в корзину', 'woocommerce').'</a>'."\n".
			'</form>';
	}
	
	/**
	 * Process the payment and return the result
	 **/
	function process_payment($order_id){
		$order = new WC_Order($order_id);

		return array(
			'result' => 'success',
			'redirect'	=> add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, get_permalink(woocommerce_get_page_id('pay'))))
		);
	}
	
	/**
	* receipt_page
	**/
	function receipt_page($order){
		echo '<p>'.__('Спасибо за Ваш заказ, пожалуйста, нажмите кнопку ниже, чтобы заплатить.', 'woocommerce').'</p>';
		echo $this->generate_form($order);
	}
	
	/**
	 * Check Rficb IPN validity
	 **/
	function check_ipn_request_is_valid($posted){

  $data = array(
    'tid' => $posted['tid'],			// ID транзакции
    'name' => urldecode($posted['name']), 		// название товара или услуги
    'comment' => $posted['comment'],		// комментарий платежа
    'partner_id' => $posted['partner_id'],	// ваш ID
    'service_id' => $posted['service_id'],	// ID сервиса
    'order_id' => $posted['order_id'],	// ID заказа
    'type' => $posted['type'],		// тип платежа (“sms”, “wm”, “terminal”)
    'partner_income' => $posted['partner_income'], // сумма в рублях вашего дохода по данному платежу
    'system_income' => $posted['system_income'] ,   // сумма в рублях, заплаченная абонентом   
    'test' => $posted['test']    // признак тестирования
  );

    $check = md5(join('', array_values($data)) . $this->rficb_skey);
    
		if ($posted['check'] == $check)
		{
			echo 'OK'.$posted['order_id'];
			return true;
		}

		return false;
	}
	
	/**
	* Check Response
	**/
	function check_ipn_response(){
		global $woocommerce;

		if (isset($_GET['rficb']) AND $_GET['rficb'] == 'result'){
			@ob_clean();

			$_POST = stripslashes_deep($_POST);

			if ($this->check_ipn_request_is_valid($_POST)){
        do_action('valid-rficb-standard-ipn-reques', $_POST);
			}
			else{
				wp_die('IPN Request Failure');
			}
		}
		else if (isset($_GET['rficb']) AND $_GET['rficb'] == 'success'){
			$order_id = $_POST['order_id'];
			$order = new WC_Order($order_id);
      if ($order->order_total <= $_POST['system_income']) {
			$order->update_status('processing', __('Платеж успешно оплачен', 'woocommerce'));
			WC()->cart->empty_cart();

			wp_redirect( $this->get_return_url( $order ) );  }
			else {
				wp_die('Cost Request Failure');
			}
		}
		else if (isset($_GET['rficb']) AND $_GET['rficb'] == 'fail'){
			$order_id = $_POST['order_id'];
			$order = new WC_Order($order_id);
			$order->update_status('failed', __('Платеж не оплачен', 'woocommerce'));

			wp_redirect($order->get_cancel_order_url());
			exit;
		}

	}

	/**
	* Successful Payment!
	**/
	function successful_request($posted){
		global $woocommerce;

		$out_summ = $posted['system_income'];
		$order_id = $posted['order_id'];

		$order = new WC_Order($order_id);

		// Check order not already completed
		if ($order->status == 'completed'){
			exit;
		}

		// Payment completed
		$order->add_order_note(__('Платеж успешно завершен.', 'woocommerce'));
		$order->payment_complete();
		exit;
	}
}

/**
 * Add the gateway to WooCommerce
 **/
function add_rficb_gateway($methods){
	$methods[] = 'WC_RFICB';
	return $methods;
}

add_filter('woocommerce_payment_gateways', 'add_rficb_gateway');
}
?>
