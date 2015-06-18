<?php
if (!class_exists( 'WooCommerceDutyCalculatorAPI'))
{
    /**
     * Class WooCommerceDutyCalculatorAPI
     */
    class WooCommerceDutyCalculatorAPI
    {
        public $dutyCalculatorApiHost = 'http://www.dutycalculator.com';
//        public $dutyCalculatorApiHost = 'http://dc';
        public $dutyCalculatorSavedCalculationUrl = 'new-import-duty-and-tax-calculation/saved_calculations/view_details/';
        public $actionCalculation = 'calculation';
        public $actionGetHsCode = 'get-hscode';
        public $actionStoreCalculation = 'store_calculation';
        public $apiVersion = '2.1';
        public $apiKey;
        public $action;
        public $requestUri;
        public $response;

        public function __construct()
        {
            $this->apiKey = get_option('dc_woo_api_key');
        }

        public function send_request_and_get_response($action, $params)
        {
            if ($action == $this->actionGetHsCode && !get_option('dc_woo_enable_hscode', true))
            {
                return false;
            }
            global $woocommerce;
            $uri = $this->dutyCalculatorApiHost . '/api' . $this->apiVersion . '/'  . $this->apiKey . '/' . $action . '/';
            if ($params)
            {
                $uri .= '?';
                foreach ($params as $key => $param)
                {
                    if (is_array($param))
                    {
                        foreach ($param as $idx => $value)
                        {
                            $uri .= $key . '[' . $idx . ']=' . urlencode($value). '&';
                        }
                    }
                    else
                    {
                        $uri .= $key . '=' . urlencode($param). '&';
                    }
                }
            }
            $uri = rtrim($uri, '&');
            $uriCacheKey = sha1($uri);

            $wcSession = $woocommerce->session; /** @var $wcSession WC_Session_Handler */
            $sessionApiResponses = unserialize($wcSession->get('dc_api_responses'));
            $responseBody = $sessionApiResponses[$uriCacheKey];
            try
            {
                if (stripos($responseBody, '<?xml') === false)
                {
                    throw new Exception($responseBody);
                }
                $xml = new SimpleXMLElement($responseBody);
            }
            catch (Exception $ex)
            {
                $curlHandler = curl_init();
                curl_setopt($curlHandler, CURLOPT_URL, $uri);
                curl_setopt($curlHandler, CURLOPT_POST, 0);
                ob_start();
                $result = curl_exec($curlHandler);
                $responseBody = ob_get_contents();
                ob_end_clean();
                if (!$result)
                {
                    $error = curl_error($curlHandler) . '(' . curl_errno($curlHandler) . ')';
                }
                else
                {}
                curl_close($curlHandler);
                try
                {
                    if (stripos($responseBody, '<?xml') === false)
                    {
                        throw new Exception($responseBody);
                    }
                    $xml = new SimpleXMLElement($responseBody);
                    if ($xml->getName() == 'error')
                    {
                        throw new Exception('DutyCalculator API error. ' . (string)$xml->message . ' (code: ' .(string)$xml->code. ')');
                    }
                }
                catch (Exception $e)
                {}

                $sessionApiResponses[$uriCacheKey] = $responseBody;
                $wcSession->set('dc_api_responses', serialize($sessionApiResponses));
            }
            $this->action = $action;
            $this->requestUri = $uri;
            $this->response = $responseBody;

            do_action('woocommerce_dutycalculator_api_send_request_and_get_response_end', $action, $uri, $responseBody);
            return $this;
        }
    }
}