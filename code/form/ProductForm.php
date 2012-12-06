<?php
/**
 * Form for adding items to the cart from a {@link Product} page.
 */
class ProductForm extends Form {

	protected $product;
	protected $quantity;
	protected $redirectURL;

	function __construct($controller, $name, $quantity = null, $redirectURL = null) {

		Requirements::javascript(THIRDPARTY_DIR . '/jquery/jquery.js');
		Requirements::javascript(THIRDPARTY_DIR . '/jquery-entwine/dist/jquery.entwine-dist.js');
		Requirements::javascript('swipestripe/javascript/ProductForm.js');

		$this->product = $controller->data();
		$this->quantity = $quantity;
		$this->redirectURL = $redirectURL;

    $fields = $this->createFields();
    $actions = $this->createActions();
    $validator = $this->createValidator();

		parent::__construct($controller, $name, $fields, $actions, $validator);

		$this->addExtraClass('product-form');


		//Add a map of all variations and prices to the page for updating the price
		$map = array();
		$variations = $this->product->Variations();
		$productPrice = $this->product->Price();

		if ($variations && $variations->exists()) foreach ($variations as $variation) {

			$variationPrice = $variation->Price();
     	$productPrice->setAmount($productPrice->getAmount() + $variationPrice->getAmount());

			$map[] = array(
				'price' => $productPrice->Nice(),
				'options' => $variation->Options()->column('ID'),
				'free' => _t('Product.FREE', 'Free'),
			);
		}
		$this->setAttribute('data-map', json_encode($map));
  }

  public function createFields() {

  	$product = $this->product;

  	$fields = FieldList::create(
      HiddenField::create('ProductClass', 'ProductClass', $product->ClassName),
      HiddenField::create('ProductID', 'ProductID', $product->ID),
      HiddenField::create('Redirect', 'Redirect', $this->redirectURL)
    );

    $attributes = $this->product->Attributes();
    $prev = null;

    if ($attributes && $attributes->exists()) foreach ($attributes as $attribute) {

    	$field = $attribute->getOptionField($prev);
    	$fields->push($field);

    	$prev = $attribute;
    }

    $fields->push(ProductForm_QuantityField::create('Quantity', 'Quantity', $this->quantity));

    return $fields;
  }

  public function createActions() {
  	$actions = new FieldList(
      new FormAction('add', 'Add To Cart')
    );
    return $actions;
  }

  public function createValidator() {

  	$validator = new ProductForm_Validator(
    	'ProductClass', 
    	'ProductID',
      'Quantity'
    );
    return $validator;
  }
	
	/**
	 * Overloaded so that form error messages are displayed.
	 * 
	 * @see OrderFormValidator::php()
	 * @see Form::validate()
	 */
  public function validate(){
    
		if($this->validator){
			$errors = $this->validator->validate();

			if($errors){
				if(Director::is_ajax()) { // && $this->validator->getJavascriptValidationHandler() == 'prototype') {

					FormResponse::status_message(_t('Form.VALIDATIONFAILED', 'Validation failed'), 'bad');
					foreach($errors as $error) {
						FormResponse::add(sprintf(
							"validationError('%s', '%s', '%s');\n",
							Convert::raw2js($error['fieldName']),
							Convert::raw2js($error['message']),
							Convert::raw2js($error['messageType'])
						));
					}
				} else {
					$data = $this->getData();

					$formError = array();
					if ($formMessageType = $this->MessageType()) {
					  $formError['message'] = $this->Message();
					  $formError['messageType'] = $formMessageType;
					}

					// Load errors into session and post back
					Session::set("FormInfo.{$this->FormName()}", array(
						'errors' => $errors,
						'data' => $data,
					  'formError' => $formError
					));

				}
				return false;
			}
		}
		return true;
	}

	/**
	 * Add an item to the current cart ({@link Order}) for a given {@link Product}.
	 * 
	 * @param Array $data
	 * @param Form $form
	 */
	public function add(Array $data, Form $form) {

    Cart::get_current_order(true)
    	->addItem(
    		$this->getProduct(), 
    		$this->getVariation(), 
    		$this->getQuantity(), 
    		$this->getOptions()
    );
    
    //Show feedback if redirecting back to the Product page
    if (!$this->getRequest()->requestVar('Redirect')) {
      $cartPage = DataObject::get_one('CartPage');
      $message = ($cartPage) 
        ? 'The product was added to <a href="' . $cartPage->Link() . '">your cart</a>.'
        : "The product was added to your cart.";
      $form->sessionMessage(
  			$message,
  			'good'
  		);
    }
    $this->goToNextPage();
  }

  /**
   * Find a product based on current request - maybe shoul dbe deprecated?
   * 
   * @see SS_HTTPRequest
   * @return DataObject 
   */
  private function getProduct() {
    $request = $this->getRequest();
    return DataObject::get_by_id($request->requestVar('ProductClass'), $request->requestVar('ProductID'));
  }

  private function getVariation() {

    $productVariation = new Variation();
    $request = $this->getRequest();
    $options = $request->requestVar('Options');
    $product = $this->product;
    $variations = $product->Variations();

    if ($variations && $variations->exists()) foreach ($variations as $variation) {

      $variationOptions = $variation->Options()->map('AttributeID', 'ID')->toArray();
      if ($options == $variationOptions && $variation->isEnabled()) {
        $productVariation = $variation;
      }
    }

    return $productVariation;
  }

  /**
   * Find the quantity based on current request
   * 
   * @return Int
   */
  private function getQuantity() {
    $quantity = $this->getRequest()->requestVar('Quantity');
    return (isset($quantity)) ? $quantity : 1;
  }

  private function getOptions() {

    $options = new ArrayList();
    $this->extend('updateOptions', $options);
    return $options;
  }
  
  /**
   * Send user to next page based on current request vars,
   * if no redirect is specified redirect back.
   * 
   * TODO make this work with AJAX
   */
  private function goToNextPage() {

    $redirectURL = $this->getRequest()->requestVar('Redirect');

    //Check if on site URL, if so redirect there, else redirect back
    if ($redirectURL && Director::is_site_url($redirectURL)) {
    	$this->controller->redirect(Director::absoluteURL(Director::baseURL() . $redirectURL));
    } 
    else {
    	$this->controller->redirectBack();
    }
  }

}

/**
 * Validator for {@link AddToCartForm} which validates that the product {@link Variation} is 
 * correct for the {@link Product} being added to the cart.
 */
class ProductForm_Validator extends RequiredFields {

	/**
	 * Check that current product variation is valid
	 *
	 * @param Array $data Submitted data
	 * @return Boolean Returns TRUE if the submitted data is valid, otherwise FALSE.
	 */
	function php($data) {

		$valid = parent::php($data);
		$fields = $this->form->Fields();
		
		//Check that variation exists if necessary
		$form = $this->form;
		$request = $this->form->getRequest();

		//Get product variations from options sent
    //TODO refactor this
    
	  $productVariations = new ArrayList();

    $options = $request->postVar('Options');
    $product = DataObject::get_by_id($data['ProductClass'], $data['ProductID']);
    $variations = ($product) ? $product->Variations() : new ArrayList();

    if ($variations && $variations->exists()) foreach ($variations as $variation) {
      
      $variationOptions = $variation->Options()->map('AttributeID', 'ID')->toArray();
      if ($options == $variationOptions && $variation->isEnabled()) {
        $productVariations->push($variation);
      }
    }
    
	  if ((!$productVariations || !$productVariations->exists()) && $product && $product->requiresVariation()) {
	    $this->form->sessionMessage(
  		  _t('Form.VARIATIONS_REQUIRED', 'This product requires options before it can be added to the cart.'),
  		  'bad'
  		);
  		
  		//Have to set an error for Form::validate()
  		$this->errors[] = true;
  		$valid = false;
  		return $valid;
	  }
	  
	  //Validate that the product/variation being added is inStock()
	  $stockLevel = 0;
	  if ($product) {
	    if ($product->requiresVariation()) {
	      $stockLevel = $productVariations->First()->StockLevel()->Level;
	    }
	    else {
	      $stockLevel = $product->StockLevel()->Level;
	    }
	  }
	  if ($stockLevel == 0) {
	    $this->form->sessionMessage(
  		  _t('Form.STOCK_LEVEL', ''), //"Sorry, this product is out of stock." - similar message will already be displayed on product page
  		  'bad'
  		);
  		
  		//Have to set an error for Form::validate()
  		$this->errors[] = true;
  		$valid = false;
	  }
	  
	  //Validate the quantity is not greater than the available stock
	  $quantity = $request->postVar('Quantity');
	  if ($stockLevel > 0 && $stockLevel < $quantity) {
	    $this->form->sessionMessage(
  		  _t('Form.STOCK_LEVEL_MORE_THAN_QUANTITY', 'The quantity is greater than available stock for this product.'),
  		  'bad'
  		);
  		
  		//Have to set an error for Form::validate()
  		$this->errors[] = true;
  		$valid = false;
	  }

	  //Validate that base currency is set for this cart
	  $config = ShopConfig::current_shop_config();
	  if (!$config->BaseCurrency) {
	  	$this->form->sessionMessage(
  		  _t('Form.BASE_CURRENCY_NOT_SET', 'The currency is not set.'),
  		  'bad'
  		);
  		
  		//Have to set an error for Form::validate()
  		$this->errors[] = true;
  		$valid = false;
	  }

		return $valid;
	}
	
	/**
	 * Helper so that form fields can access the form and current form data
	 * 
	 * @return Form The current form
	 */
	public function getForm() {
	  return $this->form;
	}
}

/**
 * Represent each {@link Item} in the {@link Order} on the {@link Product} {@link AddToCartForm}.
 */
class ProductForm_QuantityField extends TextField {
	
  /**
   * Validate the quantity is above 0.
   * 
   * @see FormField::validate()
   * @return Boolean
   */
  function validate($validator) {

	  $valid = true;
		$quantity = $this->Value();
		
    if ($quantity == null || !is_numeric($quantity)) {
	    $errorMessage = _t('Form.ITEM_QUANTITY_INCORRECT', 'The quantity must be a number');
			if ($msg = $this->getCustomValidationMessage()) {
				$errorMessage = $msg;
			}
			
			$validator->validationError(
				$this->getName(),
				$errorMessage,
				"error"
			);
	    $valid = false;
	  }
	  else if ($quantity <= 0) {
	    $errorMessage = _t('Form.ITEM_QUANTITY_LESS_ONE', 'The quantity must be at least 1');
			if ($msg = $this->getCustomValidationMessage()) {
				$errorMessage = $msg;
			}
			
			$validator->validationError(
				$this->getName(),
				$errorMessage,
				"error"
			);
	    $valid = false;
	  }
	  else if ($quantity > 2147483647) {
	    $errorMessage = _t('Form.ITEM_QUANTITY_INCORRECT', 'The quantity must be less than 2,147,483,647');
			if ($msg = $this->getCustomValidationMessage()) {
				$errorMessage = $msg;
			}
			
			$validator->validationError(
				$this->getName(),
				$errorMessage,
				"error"
			);
	    $valid = false;
	  }


	  return $valid;
	}
	
}