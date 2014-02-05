<?php
if (!class_exists( 'WooCommerceDutyCalculatorApiCalculation'))
{
    /**
     * Class WooCommerceDutyCalculatorAPI
     */
    class WooCommerceDutyCalculatorApiCalculation
    {
        public $answer;
        public $calculationId;
        public $WcProductsApiData = array(); //Woocommerce products with DC API Response Items
        public $items = array();
        public $totalCharges = array();

        public function __construct($answer)
        {
            $this->answer = new SimpleXMLElement($answer);
            $this->rawAnswer = $answer;
            $answerAttributes = $this->answer->attributes();
            $this->calculationId = $answerAttributes['id'];
            $this->items = $this->answer->xpath('item');
            $this->totalCharges = $this->answer->xpath('total-charges');
        }
    }
}
