<?php
/*
* 2007-2015 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author PrestaShop SA <contact@prestashop.com>
*  @copyright  2007-2015 PrestaShop SA
*  @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

/**
 * @since 1.5.0
 */

require_once(_PS_MODULE_DIR_ . '/cointopay_direct_cc_custom/vendor/cointopay/init.php');
require_once(_PS_MODULE_DIR_ . '/cointopay_direct_cc_custom/vendor/version.php');

class Cointopay_Direct_Cc_CustomValidationModuleFrontController extends ModuleFrontController
{
    /**
     * @see FrontController::postProcess()
     */
    public function postProcess()
    {
        $cart = $this->context->cart;
        if ($cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0 || !$this->module->active) {
            Tools::redirect($this->context->link->getPageLink('index', true) . 'order?step=1');
        }

        // Check that this payment option is still available in case the customer changed his address just before the end of the checkout process
        $authorized = false;
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] == 'cointopay_direct_cc_custom') {
                $authorized = true;
                break;
            }
        }

        if (!$authorized) {
            die($this->module->l('This payment method is not available.', 'validation'));
        }


        $customer = new Customer($cart->id_customer);
        if (!Validate::isLoadedObject($customer))
            Tools::redirect($this->context->link->getPageLink('index', true) . 'order?step=1');

        $currency = $this->context->currency;
        $total = (float)$cart->getOrderTotal(true, Cart::BOTH);


        $this->module->validateOrder($cart->id, Configuration::get('COINTOPAY_DIRECT_CC_CUSTOM_PENDING'), $total, $this->module->displayName, NULL, array(), (int)$currency->id, false, $customer->secure_key);
        $link = new Link();
        $success_url = '';
        $success_url = $link->getPageLink('order-confirmation', null, null, array(
            'id_cart'     => $cart->id,
            'id_module'   => $this->module->id,
            'key'         => $customer->secure_key
        ));
        $description = array();
        foreach ($cart->getProducts() as $product) {
            $description[] = $product['cart_quantity'] . ' × ' . $product['name'];
        }
        $merchant_id = Configuration::get('COINTOPAY_DIRECT_CC_CUSTOM_MERCHANT_ID');
        $security_code = Configuration::get('COINTOPAY_DIRECT_CC_CUSTOM_SECURITY_CODE');
        $user_currency = Configuration::get('COINTOPAY_DIRECT_CC_CUSTOM_CRYPTO_CURRENCY');
        $selected_currency = (isset($user_currency) && !empty($user_currency)) ? $user_currency : 1;
        $ctpConfig = array(
            'merchant_id' => $merchant_id,
            'security_code' => $security_code,
            'selected_currency' => $selected_currency,
            'user_agent' => 'Cointopay - Prestashop v' . _PS_VERSION_ . ' Extension v' . COINTOPAY_DIRECT_CC_CUSTOM_PRESTASHOP_EXTENSION_VERSION
        );
        $orderObj = new Order($this->module->currentOrder);

        \Cointopay_Direct_Cc_Custom\Cointopay_Direct_Cc_Custom::config($ctpConfig);
        $order = \Cointopay_Direct_Cc_Custom\Merchant\Order::createOrFail(array(
            'order_id'         => implode('----', [$orderObj->reference, $this->module->currentOrder]),
            'price'            => $total,
            'currency'         => $this->currencyCode($currency->iso_code),
            'cancel_url'       => $this->flashEncode($this->context->link->getModuleLink('cointopay_direct_cc_custom', 'cancel')),
            'callback_url'     => $this->flashEncode($this->context->link->getModuleLink('cointopay_direct_cc_custom', 'callback')),
            'success_url'      => $success_url,
            'title'            => Configuration::get('PS_SHOP_NAME') . ' Order #' . $orderObj->reference,
            'description'      => join($description, ', '),
            'selected_currency' => $selected_currency
        ));

        if (isset($order)) {

            //preg_match_all($pattern, $order->PaymentDetailCConly, $matches); 
            $regexp = "<a\s[^>]*href=(\"??)([^\" >]*?)\\1[^>]*>(.*)<\/a>";
            if (preg_match_all("/$regexp/siU", $order->PaymentDetailCConly, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $key => $match) {
                    if ($key == 1) {
                        @header("Location: " . $match[2]);
                        die;
                    }
                }
            }
        } else {
            Tools::redirect($this->context->link->getPageLink('index', true) . 'order?step=3');
        }
    }
    /**
     * URL encode to UTF-8
     *
     * @param $input
     * @return string
     */
    public function flashEncode($input)
    {
        return rawurlencode(utf8_encode($input));
    }

    /**
     * Currency code
     * @param $isoCode
     * @return string
     */
    public function currencyCode($isoCode)
    {
        $currencyCode = '';

        if (isset($isoCode) && ($isoCode == 'RUB')) {
            $currencyCode = 'RUR';
        } else {
            $currencyCode = $isoCode;
        }

        return $currencyCode;
    }
}
