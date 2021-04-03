<?php  

/*
Plugin Name: Digifact - BAC Credomatic
Description: Integrar de manera automatica la facturacion con digifact y pagos con BAC Credomatic.
Version: 1.0
Author: Digifact
*/

function woobac_add_to_gateways( $gateways ) {
    $gateways[] = 'WC_Bac';
    return $gateways;
}
add_filter( 'woocommerce_payment_gateways', 'woobac_add_to_gateways' );

if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
	add_action('plugins_loaded', 'init_woobac', 0);

	add_filter('woocommerce_billing_fields', 'custom_woocommerce_billing_fields');

	function custom_woocommerce_billing_fields($fields){

	    $fields['nit'] = array(
	        'label' => __('NIT (Si deja este campo vacío la factura será emitida a un consumidor final)', 'woocommerce'), 
	        'placeholder' => _x('Ingrese el nit', 'placeholder', 'woocommerce'), 
	        'required' => false, 
	        'clear' => false, 
	        'type' => 'text', 
	        'class' => array('my-css')
	    );

	    return $fields;
	}

	add_action('woocommerce_checkout_update_order_meta', 'nit_checkout_field_update_order_meta');

	function nit_checkout_field_update_order_meta($order_id){
	    if (! empty( $_POST['nit'])){
	        update_post_meta($order_id,'nit', sanitize_text_field($_POST['nit']));
	    }
	}

	function init_woobac(){
		if (!class_exists('WC_Payment_Gateway')) {
            return;
        }

        class WC_Bac extends WC_Payment_Gateway{

        	public function __construct(){
                $this->id = 'bac';
                $this->method_title = __('Digifact - BAC credomatic Checkout', 'bac_credomatic_woocommerce');
                $this->icon = 'https://www.baccredomatic.com/sites/all/themes/custom/foundation_bac/images/bacredomatic_logo.png';
                $this->has_fields = true;
                $this->order_button_text = __('Pagar', 'bac_credomatic_woocommerce');
                $this->method_description = __('Aceptar pagos.', 'bac_credomatic_woocommerce');
                $this->init_form_fields();
                $this->init_settings();
			  
				// Actions
				add_action( 'woocommerce_receipt_'.$this->id, array(&$this, 'receipt_page') );
				add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), array( $this, 'manage_3ds_response' ) );
				add_action( 'woocommerce_update_options_payment_gateways_'.$this->id, array( $this, 'process_admin_options' ) );
            }

            public function manage_3ds_response() {
				global $woocommerce;

                $PD = $woocommerce->session->paymentData;
                $order = wc_get_order( $PD["woo_order_id"] );

                $ec = "";
                $ar = "";
                $ts = "";
                $cv = "";


                if(isset($_POST['ECIIndicator'])){
                	$ec = $_POST['ECIIndicator'];
                }
                if(isset($_POST['AuthenticationResult'])){
                	$ar = $_POST['AuthenticationResult'];
                }
                if(isset($_POST['TransactionStain'])){
                	$ts = $_POST['TransactionStain'];
                }
                if(isset($_POST['CAVVValue'])){
                	$cv = $_POST['CAVVValue'];
                }
                
                if($ec != "" && $ar!="" && $ts != "" && $cv != ""){
                	
                	if(($PD["cardType"] == 4 && ($ec == "06" || $ec == "05"))||
                		$PD["cardType"] == 5 && ($ec == "01" || $ec == "02")){
                		$this->certificateBill($PD["billingData"]);
                		$order->reduce_order_stock();
                		WC()->cart->empty_cart();
                		wp_redirect($PD["urlSuccessRedirect"]);
                	}
                }	
            }

            public function init_form_fields() {
      
			    $this->form_fields =  apply_filters( 'wc_woobac_form_fields',array(
			          
			        'enabled' => array(
			            'title'   => __( 'Inactivo/Activo', 'wc-bac-gateway' ),
			            'type'    => 'checkbox',
			            'label'   => __( 'Método de pago activo', 'wc-bac-gateway' ),
			            'default' => false
			        ),
			        'productionMode' => array(
			            'title'   => __( 'Pruebas/Producción', 'wc-bac-gateway' ),
			            'type'    => 'checkbox',
			            'label'   => __( 'Modo producción', 'wc-bac-gateway' ),
			            'default' => false
			        ),
			        'facMerchantId' => array(
			            'title'   => __( 'BAC Merchant ID', 'wc-bac-gateway' ),
			            'type'    => 'text',
			            'label'   => __( 'ID de comercio para BAC', 'wc-bac-gateway' ),
			            'default'     => __( '', 'wc-bac-gateway' ),
			        ),
			        'facPassword' => array(
			            'title'   => __( 'BAC Contraseña', 'wc-bac-gateway' ),
			            'type'    => 'password',
			            'label'   => __( 'Contraseña entregada por BAC para la integración', 'wc-bac-gateway' ),
			            'default'     => __( '', 'wc-bac-gateway' ),
			        ),
			        'facAcquirerId' => array(
			            'title'   => __( 'ID de adquiriente BAC', 'wc-bac-gateway' ),
			            'type'    => 'text',
			            'label'   => __( 'ID del adquiriente entregado por BAC', 'wc-bac-gateway' ),
			            'default'     => __( '', 'wc-bac-gateway' ),
			        ),

			        'facId' => array(
			            'title'       => __( 'FAC ID', 'wc-bac-gateway' ),
			            'type'        => 'text',
			            'description' => __( 'Payment method description that the customer will see on your checkout.', 'wc-bac-gateway' ),
			            'default'     => __( '', 'wc-bac-gateway' ),
			            'desc_tip'    => true,
			        ),
					'title' => array(
						'title'       => __( 'Titulo del metodo de pago', 'wc-gateway-offline' ),
						'type'        => 'text',
						'description' => __( 'This controls the title for the payment method the customer sees during checkout.', 'wc-gateway-offline' ),
						'default'     => __( 'Digifact - Bac Credomatic', 'wc-gateway-offline' ),
						'desc_tip'    => true,
					),
					'digifactUsername' => array(
			            'title'       => __( 'Usuario digifact', 'wc-bac-gateway' ),
			            'type'        => 'text',
			            'description' => __( 'Ingrese aca el nombre de usuario con el cual iniciar sesion en la plataforma digifact', 'wc-bac-gateway' ),
			            'default'     => __( '0', 'wc-bac-gateway' ),
			            'desc_tip'    => true,
			        ),
			        'digifactPassword' => array(
			            'title'       => __( 'Contraseña digifact', 'wc-bac-gateway' ),
			            'type'        => 'password',
			            'description' => __( 'Ingrese la Contraseña que utiliza para iniciar sesion en la plataforma digifact', 'wc-bac-gateway' ),
			            'default'     => __( '0', 'wc-bac-gateway' ),
			            'desc_tip'    => true,
			        ),
					'nit' => array(
			            'title'       => __( 'NIT Emisor', 'wc-bac-gateway' ),
			            'type'        => 'text',
			            'description' => __( 'NIT Emisor.', 'wc-bac-gateway' ),
			            'default'     => __( '0', 'wc-bac-gateway' ),
			            'desc_tip'    => true,
			        ),
					'commercialName' => array(
			            'title'       => __( 'Nombre comercial', 'wc-bac-gateway' ),
			            'type'        => 'text',
			            'description' => __( 'Nombre comercial para la facturación', 'wc-bac-gateway' ),
			            'default'     => __( '0', 'wc-bac-gateway' ),
			            'desc_tip'    => true,
			        ),
			        'address' => array(
			            'title'       => __( 'Direccion Emisor', 'wc-bac-gateway' ),
			            'type'        => 'text',
			            'description' => __( 'Direccion Emisor.', 'wc-bac-gateway' ),
			            'default'     => __( '', 'wc-bac-gateway' ),
			            'desc_tip'    => true,
			        ),
					'department' => array(
			            'title'       => __( 'Departamento', 'wc-bac-gateway' ),
			            'type'        => 'text',
			            'description' => __( 'Nombre del departamento para la facturación', 'wc-bac-gateway' ),
			            'default'     => __( '', 'wc-bac-gateway' ),
			            'desc_tip'    => true,
			        ),
			        'municipality' => array(
			            'title'       => __( 'Municipio', 'wc-bac-gateway' ),
			            'type'        => 'text',
			            'description' => __( 'Nombre del municipio para la facturación', 'wc-bac-gateway' ),
			            'default'     => __( '', 'wc-bac-gateway' ),
			            'desc_tip'    => true,
			        ),
			        'zip' => array(
			            'title'       => __( 'Código Postal Emisor', 'wc-bac-gateway' ),
			            'type'        => 'text',
			            'description' => __( 'Código Postal Emisor.', 'wc-bac-gateway' ),
			            'default'     => __( '', 'wc-bac-gateway' ),
			            'desc_tip'    => true,
			        )
			    ));
			}

			public function process_payment( $order_id ) {
    			
    			global $woocommerce;
    			try{
    				    				
    				$order = wc_get_order( $order_id );
    				$orderData = $order->get_data();
			    	
			    	$billingData = $this->billing($order);

			    	$ccv = $this->get_request('ccname');    
				    $cardName = $this->get_request('ccname');
				    $cardSecurityCode = $this->get_request('ccsc');
				    $cardNumber = $this->get_request('ccnumber');
				    $cardExpMonth = $this->get_request('ccexpmonth');
				    $cardExpYear = $this->get_request('ccexpyear');
				    $cardExpiration = $cardExpMonth."".$cardExpYear;

					$this->validateStringLength($cardName," Nombre del titular",[1,160]);
				    $this->validateStringLength($cardNumber," Número de tarjeta",[15,16]);
				    $this->validateStringLength($cardSecurityCode," Código de seguridad",[3,4]);
					$this->validateStringLength($cardExpYear," Fecha de expiración",[2,2]);
				    $this->validateStringLength($cardExpMonth," Fecha de expiración",[1,2]);				    

				    $billingData = $this->billing($order);

				    $acquirerId = $this->get_option("facAcquirerId");
				    $merchantId = $this->get_option("facMerchantId");
				    $facPassword = $this->get_option("facPassword");

				    $cardType = substr($cardNumber, 0,1);

				    $currency = 320;
				    $currencyExponent = 2;
				    $signatureMethod = "SHA1";
				    $transactionCode = 8;
				    $customerReference = "WOOCOMMERCE ORDER ".$order_id;
				    $amount = $this->formatAmount($order->get_total());

				    $time = explode(".",microtime(true));
				    $uniqueOrder = $order_id."-".$time[1].$time[0];
				    $signature = $this->generateSignature($facPassword,$merchantId,$acquirerId,$uniqueOrder,$amount,$currency);

				    $merchantResponseURL = get_site_url()."/";
	                $merchantResponseURL = add_query_arg( 'wc-api', strtolower( get_class( $this ) ), $merchantResponseURL );

				    $xml = '
				    <Authorize3DSRequest xmlns:i="http://www.w3.org/2001/XMLSchema-instance" xmlns="http://schemas.firstatlanticcommerce.com/gateway/data">
					   <CardDetails>
					      <CardCVV2>'.$cardSecurityCode.'</CardCVV2>
					      <CardExpiryDate>'.$cardExpiration.'</CardExpiryDate>
					      <CardNumber>'.$cardNumber.'</CardNumber>
					      <Installments>0</Installments>
					   </CardDetails>
					   <MerchantResponseURL>'.$merchantResponseURL.'</MerchantResponseURL>
					   <TransactionDetails>
					      <AcquirerId>'.$acquirerId.'</AcquirerId>
					      <Amount>'.$amount.'</Amount>
					      <Currency>'.$currency.'</Currency>
					      <CurrencyExponent>'.$currencyExponent.'</CurrencyExponent>
					      <MerchantId>'.$merchantId.'</MerchantId>
					      <OrderNumber>'.$uniqueOrder.'</OrderNumber>
					      <Signature>'.$signature.'</Signature>
					      <SignatureMethod>'.$signatureMethod.'</SignatureMethod>
					      <TransactionCode>'.$transactionCode.'</TransactionCode>
					      <CustomerReference>'.$customerReference.'</CustomerReference>
					   </TransactionDetails>
					</Authorize3DSRequest>
				    ';

				    $paymentData = [
						"cardSecurityCode"=>$cardSecurityCode,
						"cardExpiration"=>$cardExpiration,
						"cardNumber"=>$cardNumber,
						"merchantResponseURL"=>$merchantResponseURL,
						"acquirerId"=>$acquirerId,
						"amount"=>$amount,
						"currency"=>$currency,
						"currencyExponent"=>$currencyExponent,
						"merchantId"=>$merchantId,
						"order_id"=>$uniqueOrder,
						"woo_order_id"=>$order_id,
						"signature"=>$signature,
						"signatureMethod"=>$signatureMethod,
						"transactionCode"=>$transactionCode,
						"customerReference"=>$customerReference,
						"billingData"=>$billingData,
						"urlSuccessRedirect"=>$this->get_return_url( $order ),
						"cardType"=>$cardType
				    ];
				    $woocommerce->session->paymentData = $paymentData;

				    // initialise cURL
				    $curl = curl_init();
				    $url = $this->getBacUrl()."/Authorize3DS";

				    $headers = [
				        'Content-Type: text/xml; charset=utf-8'
			    	];
				    //set the options
				    curl_setopt($curl, CURLOPT_HEADER, false);
				    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
				    curl_setopt($curl, CURLOPT_POST, true);
				    curl_setopt($curl, CURLOPT_URL, $url);
				    curl_setopt($curl, CURLOPT_POSTFIELDS, $xml);
				    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
				    curl_setopt($curl, CURLOPT_ENCODING, 'UTF-8');
				    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

				    $response = curl_exec($curl);
				    $responseError = curl_errno($curl);
				    $responseHead = curl_getinfo($curl);
				    curl_close($curl);
				    $curl = null;

				    if($responseError == 0){
				    	$responseCode = $this->getValueFromXML("ResponseCode",$response,"[0-9]+");

				    	if($responseCode == 0){
				    		$response = preg_replace("[\n|\r|\n\r]", "", $response);
				    		$htmlFormData = $this->getValueFromXML("HTMLFormData",$response,".+");			    		
				    		$woocommerce->session->bacFormData = $htmlFormData;
				    		return array(
		                        'result' 	=> 'success',
		                        'redirect'	=> add_query_arg('key', $order->order_key, add_query_arg('order', $order_id, get_permalink(woocommerce_get_page_id('pay'))))
		                    );
				    	}else{
				    		throw new Exception("Error de conexión con BAC");		
				    	}
				    }			    

    			}catch (Exception $exception) {
    				wc_add_notice($exception->getMessage(),"error");
		            return array(
			        	'result'    => 'failure',
			        	'redirect'  => $this->get_return_url( $order )
			    	);
		        }    
			}

			public function validateStringLength($value,$name,$range){
				if(strlen($value)<$range[0] || strlen($value)>$range[1] ){
			    	throw new Exception("Campo ".$name." inválido");
			    }
			}

			//Flujo para facturacion
			public function billing($order){
				$orderData = $order->get_data();

				$billingNit = get_post_meta($order->get_id(),'nit', true);
				$issueNit = $this->get_option("nit");
				$digifactUsername = $this->get_option("digifactUsername");
				$digifactPassword = $this->get_option("digifactPassword");
				$commercialName = $this->get_option("commercialName");
				$zip = $this->get_option("zip");
				$municipality = $this->get_option("municipality");
				$department = $this->get_option("department");
				$issueAddress = $this->get_option("address");
				$issueAddress = $issueAddress == ""?"NA":$issueAddress;
				$iva = $order->get_total_tax()==0?0:12;

				$digifactToken = $this->digifactLogin($issueNit,$digifactUsername,$digifactPassword);
				$receiverNit = "CF";
				$receiverCommercialName = "CONSUMIDOR FINAL";

				if($billingNit != "" && $billingNit != "CF"){
					$receiverCommercialInfo = $this->getCommercialInfoByNit($digifactToken,$issueNit,$digifactUsername,$billingNit);
					if(empty($receiverCommercialInfo)){
						throw new Exception("El NIT ingresado no es válido");
					}
					$receiverCommercialName = $receiverCommercialInfo->NOMBRE;
					$receiverNit = $billingNit;
				}

				$totalTaxAmount = $order->get_total_tax();
				$itemsData = $this->getItemsString($order,$iva); 
				$itemsString = $itemsData["itemsString"];
				if($iva==0){
					$totalTaxAmount = $itemsData["totalTaxAmount"];
				}

				$xmlData = [];
				
				$xmlData["issueNit"] = $issueNit;
				$xmlData["commercialName"] = $commercialName;
				$xmlData["issueaName"] = $commercialName;
				$xmlData["issueZipCode"] = $zip;
				$xmlData["issueMunicipality"] = $municipality;
				$xmlData["issueDepartment"] = $department;
				$xmlData["issueCountry"] = "GT";
				$xmlData["issueAddress"] = $issueAddress;
				$xmlData["receiverEmail"] = $order->get_billing_email();
				$xmlData["receiverName"] = $receiverCommercialName;			    	
				$xmlData["receiverNit"] = $receiverNit;
				$xmlData["receiverAddress"] = $order->get_billing_address_1();
				$xmlData["receiverZipCode"] = $order->get_billing_postcode();
				$xmlData["receiverMunicipality"] = $order->get_billing_city();
				$xmlData["receiverDepartment"] = $order->get_billing_state();
				$xmlData["receiverCountry"] = $order->get_billing_country();
				$xmlData["itemsString"] = $itemsString;
				$xmlData["totalTaxAmount"] = number_format($totalTaxAmount,3);
				$xmlData["totalAmount"] = number_format($order->get_total(),3);
				$digifactXml = $this->getDigifactXML($xmlData,$iva);
				return [
					"xml"=>$digifactXml,
					"digifactToken"=>$digifactToken,
					"issueNit"=>$issueNit
				];			    
			}

			public function getCommercialInfoByNit($token,$nit,$username,$searchNit){

				$nit = $nit;
				$username = $username;

				$curl = curl_init();

				$urlParams = "/sharedInfo?NIT=".$nit."&DATA1=SHARED_GETINFONITcom&DATA2=NIT|".$searchNit."&USERNAME=".$username;
			    $url = $this->getDigifactUrl().$urlParams;

			    $headers = [
			        'Authorization: '.$token
				];
			    //set the options
			    curl_setopt($curl, CURLOPT_HEADER, false);
			    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
			    curl_setopt($curl, CURLOPT_URL, $url);
			    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			    curl_setopt($curl, CURLOPT_ENCODING, 'UTF-8');
			    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

			    $response = curl_exec($curl);
			    $responseError = curl_errno($curl);
			    curl_close($curl);
			    $curl = null;
			    
			    if($responseError == 0){

					$responseData = json_decode($response);

					if(count($responseData->RESPONSE)>0){
						return $responseData->RESPONSE[0];
					}else{
						return [];
					}
			    }else{
			    	return [];
			    }
			}

			public function digifactLogin($nit,$username,$password){

				$nit = str_pad($nit, 12, "0", STR_PAD_LEFT);
				$body = [
					"Username"=>"GT.".$nit.".".$username,
					"Password"=>$password
				];

				$curl = curl_init();
			    $url = $this->getDigifactUrl()."/login/get_token";
						
			    $headers = [
			        'Content-Type: application/json'
				];
			    //set the options
			    curl_setopt($curl, CURLOPT_HEADER, false);
			    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
			    curl_setopt($curl, CURLOPT_POST, true);
			    curl_setopt($curl, CURLOPT_URL, $url);
			    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
			    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			    curl_setopt($curl, CURLOPT_ENCODING, 'UTF-8');
			    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

			    $response = curl_exec($curl);
			    $responseError = curl_errno($curl);
			    curl_close($curl);
			    $curl = null;
			    
			    $token = "";
			    if($responseError == 0){
					$responseData = json_decode($response);

					if(isset($responseData->Token)){
						$token =  $responseData->Token;
					}else{
						throw new Exception("Error de credenciales Digifact");
					}
			    }else{
			    	throw new Exception("Error de autenticación con Digifact");
			    }

			    return $token;
			}

			public function getItemsString($order,$iva){

				$itemsString = "";

				if($iva > 0 ){
			        $taxShortName = 'IVA';
			        $taxCodeNumber = 1;
			    }else{
			    	$taxShortName = 'IVA';
			        $taxCodeNumber = 1;
			    }

			    $totalTaxAmount = 0;

				foreach ( $order->get_items() as $item_id => $item ) {

					if($iva > 0 ){
			        	$taxShortName = 'IVA';
			        	$taxCodeNumber = 1;
			        	$unitPrice = $item->get_product()->get_price() + ($item->get_subtotal_tax()/$item->get_quantity());
			        	$price = ($unitPrice * $item->get_quantity());
			        	$taxableAmount = ($item->get_product()->get_price() * $item->get_quantity());
			        	$taxAmount = $item->get_subtotal_tax();
			        	$total = $taxableAmount+$taxAmount;
			        }else{
						$taxPercent = 12;
						$taxDecimalPercent = 1+(100/12);
			        	$taxShortName = 'IVA';
			            $taxCodeNumber = 1;
			            $productPrice = $item->get_product()->get_price() - ($item->get_product()->get_price()/$taxDecimalPercent ); 
			            $productTaxAmount = $item->get_product()->get_price() - $productPrice;
			            $unitPrice = number_format($item->get_product()->get_price(),3);
			        	$price = number_format(($unitPrice * $item->get_quantity()),3);
			        	$taxableAmount = number_format(($productPrice * $item->get_quantity()),3);
			        	$taxAmount = number_format($productTaxAmount * $item->get_quantity(),3);
			        	$total = number_format($taxableAmount+$taxAmount,3);
			        }

			        $totalTaxAmount += $taxAmount;
				    $itemsString =$itemsString.'
					<dte:Item NumeroLinea="'.$item_id.'" BienOServicio="B">
						<dte:Cantidad>'.$item->get_quantity().'</dte:Cantidad>
						<dte:UnidadMedida>CA</dte:UnidadMedida>
						<dte:Descripcion>'.$item->get_name().'</dte:Descripcion>
						<dte:PrecioUnitario>'.$unitPrice.'</dte:PrecioUnitario>
						<dte:Precio>'.$price.'</dte:Precio>
						<dte:Descuento>0</dte:Descuento>
						<dte:Impuestos>
						    <dte:Impuesto>
						        <dte:NombreCorto>'.$taxShortName.'</dte:NombreCorto>
						        <dte:CodigoUnidadGravable>'.$taxCodeNumber.'</dte:CodigoUnidadGravable>
						        <dte:MontoGravable>'.$taxableAmount.'</dte:MontoGravable>
						        <dte:MontoImpuesto>'.$taxAmount.'</dte:MontoImpuesto>
						    </dte:Impuesto>
						</dte:Impuestos>
						<dte:Total>'.$total.'</dte:Total>
					</dte:Item>
				   '; 
				}

				return [
					"itemsString"=>$itemsString,
					"totalTaxAmount"=>$totalTaxAmount
				];
			}

			public function getDigifactXML($xmlData,$iva){

				if($iva > 0 ){
			        $taxShortName = 'IVA';
			        $taxCodeNumber = 1;
			    }else{
			        $taxShortName = 'IVA';
			        $taxCodeNumber = 1;
			    }

			    $date = date("c");

			    $commercialName = $xmlData["commercialName"];
			    $issueNit = $xmlData["issueNit"];
				$issueaName = $xmlData["issueaName"];
				$issueAddress = $xmlData["issueAddress"];
				$issueZipCode = $xmlData["issueZipCode"];
				$issueMunicipality = $xmlData["issueMunicipality"];
				$issueDepartment = $xmlData["issueDepartment"];
				$issueCountry = $xmlData["issueCountry"];
				$receiverEmail = $xmlData["receiverEmail"];
				$receiverName = $xmlData["receiverName"];
				$receiverNit = $xmlData["receiverNit"];
				$receiverAddress = $xmlData["receiverAddress"];
				$receiverZipCode = $xmlData["receiverZipCode"];
				$receiverMunicipality = $xmlData["receiverMunicipality"];
				$receiverDepartment = $xmlData["receiverDepartment"];
				$receiverCountry = $xmlData["receiverCountry"];
				$itemsString = $xmlData["itemsString"];
				$totalTaxAmount = $xmlData["totalTaxAmount"];
				$totalAmount = $xmlData["totalAmount"];

				$digifactXml = '
					<?xml version="1.0" encoding="UTF-8"?>
			        <dte:GTDocumento xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:dte="http://www.sat.gob.gt/dte/fel/0.2.0" Version="0.1">
			            <dte:SAT ClaseDocumento="dte">
			                <dte:DTE ID="DatosCertificados">
			                    <dte:DatosEmision ID="DatosEmision">
			                        <dte:DatosGenerales CodigoMoneda="GTQ" FechaHoraEmision="'.$date.'" Tipo="FACT"/>
			                        <dte:Emisor AfiliacionIVA="GEN"
			                            NombreComercial="'.$commercialName.'"
			                            CodigoEstablecimiento="1"
			                            NombreEmisor="'.$issueaName.'"
			                            NITEmisor="'.$issueNit.'">
			                            <dte:DireccionEmisor>
			                                <dte:Direccion>'.$issueAddress.'</dte:Direccion>
			                                <dte:CodigoPostal>'.$issueZipCode.'</dte:CodigoPostal>
			                                <dte:Municipio>'.$issueMunicipality.'</dte:Municipio>
			                                <dte:Departamento>'.$issueDepartment.'</dte:Departamento>
			                                <dte:Pais>'.$issueCountry.'</dte:Pais>
			                            </dte:DireccionEmisor>
			                        </dte:Emisor>
			                        <dte:Receptor CorreoReceptor="'.$receiverEmail.'"
			                            NombreReceptor="'.$receiverName.'" IDReceptor="'.$receiverNit.'">
			                            <dte:DireccionReceptor>
			                                <dte:Direccion>'.$receiverAddress.'</dte:Direccion>
			                                <dte:CodigoPostal>'.$receiverZipCode.'</dte:CodigoPostal>
			                                <dte:Municipio>'.$receiverMunicipality.'</dte:Municipio>
			                                <dte:Departamento>'.$receiverDepartment.'</dte:Departamento>
			                                <dte:Pais>'.$receiverCountry.'</dte:Pais>
			                            </dte:DireccionReceptor>
			                        </dte:Receptor>
			                        <dte:Frases>
			                            <dte:Frase TipoFrase="1" CodigoEscenario="1"/>
			                        </dte:Frases>
			                        <dte:Items>
			                            '.$itemsString.'
			                        </dte:Items>
			                        <dte:Totales>
			                            <dte:TotalImpuestos>
			                                <dte:TotalImpuesto NombreCorto="'.$taxShortName.'" TotalMontoImpuesto="'.$totalTaxAmount.'"/>
			                            </dte:TotalImpuestos>
			                            <dte:GranTotal>'.$totalAmount.'</dte:GranTotal>
			                        </dte:Totales>
			                    </dte:DatosEmision>
			                </dte:DTE>
			            </dte:SAT>
			        </dte:GTDocumento>
				';

				return $digifactXml;
			}

			public function certificateBill($billingData){

				$token = $billingData["digifactToken"];
				$nit = $billingData["issueNit"];
				$xml = $billingData["xml"];
				
				$nit = str_pad($nit, 12, "0", STR_PAD_LEFT);
				$curl = curl_init();

				$urlParams = "/FELRequest?NIT=".$nit."&TIPO=CERTIFICATE_DTE_XML_TOSIGN&FORMAT=XML";
			    $url = $this->getDigifactUrl().$urlParams;

			    $headers = [
			        'Content-Type: application/xml',
			        'Authorization: '.$token
				];
			    //set the options
			    curl_setopt($curl, CURLOPT_HEADER, false);
			    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
			    curl_setopt($curl, CURLOPT_POST, true);
			    curl_setopt($curl, CURLOPT_URL, $url);
			    curl_setopt($curl, CURLOPT_POSTFIELDS, $xml);
			    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			    curl_setopt($curl, CURLOPT_ENCODING, 'UTF-8');
			    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

			    $response = curl_exec($curl);
			    $responseError = curl_errno($curl);
			    curl_close($curl);
			    $curl = null;
			    
			    
			    if($responseError == 0){
			    	$token = "";

					$responseData = json_decode($response);
					if($responseData->Codigo == 1){
						error_log( "Petición para certificar realizada con éxito");
					}else{
						error_log("Error en la petición para certificar C01");
					}
			    }else{
			    	error_log("Error en la petición para certificar C02");
			    }

			    return $token;
			}
			//Fin flujo (funciones para facturacion)

			public function payment_fields(){
				?>					
					<fieldset>
						<table style="border: none;">
							<tr style="border: none;">
								<td style="border: none; width: 40%">
									<label for="ccname"><?php echo __("Nombre del titular:", 'woocommerce') ?> <span class="required">*</span></label>
								</td>
								<td colspan="2" style="border: none;">
									<input type="text" class="input-text" id="ccname" name="ccname" />
								</td>
							</tr>
							<tr style="border: none;">
								<td style="border: none;">
									<label for="ccnumber"><?php echo __("Número de tarjeta:", 'woocommerce') ?> <span class="required">*</span></label>
								</td>
								<td colspan="2" style="border: none;">
									<input type="number" class="input-text" id="ccnumber" name="ccnumber" />
								</td>
							</tr>
							<tr>
								<td style="border: none;">
									<label for="ccsc"><?php echo __("Código de seguridad:", 'woocommerce') ?> <span class="required">*</span></label>
								</td>
								<td style="border: none;">
									<input type="password" class="input-text" id="ccsc" name="ccsc" maxlength="4" style="width:100px" />
								</td>
							</tr>
							<tr>
								<td style="border: none;">
									<label for="ccexpmonth"><?php echo __("Fecha de expiración:", 'woocommerce') ?> <span class="required">*</span></label>
								</td>
								<td style="border: none;">
									<select name="ccexpmonth" id="ccexpmonth" class="woocommerce-select woocommerce-cc-month">
										<option value=""><?php _e('Mes', 'woocommerce') ?></option>
										<?php
											$months = array();
											for ($i = 1; $i <= 12; $i++)
											{
												$timestamp = mktime(0, 0, 0, $i, 1);
												$monthNumber = date('n', $timestamp);
												$monthName = $this->getMonthName($monthNumber);
												$months[date('n', $timestamp)] = $monthName;
											}
											foreach ($months as $num => $name)
											{
												printf('<option value="%u">%s</option>', $num, $name);
											}
										?>
									</select>
								</td>
								<td style="border: none;">
									<select name="ccexpyear" id="ccexpyear" class="woocommerce-select woocommerce-cc-year">
										<option value=""><?php _e('Año', 'woocommerce') ?></option>
										<?php 
											for($y=0; $y<=10; $y++)
											{
										?>
												<option value="<?php echo (date('y') + $y);?>"><?php echo (date('Y') + $y);?></option>
										<?php
											}
										?>
									</select>
								</td>
							</tr>
						</table>
					</fieldset>
				<?php 
			}

			function getDigifactUrl(){

				$productionMode = $this->get_option("productionMode");
				$baseUrl = "https://felgtaws.digifact.com.gt/gt.com.fel.api.v2/api";

				if($productionMode == "no"){
					$baseUrl = "https://felgttestaws.digifact.com.gt/felapiv2/api";
				}
				return ($baseUrl);
			}

			function getBacUrl(){

				$productionMode = $this->get_option("productionMode");
				$baseUrl = "https://marlin.firstatlanticcommerce.com/PGServiceXML/";

				if($productionMode == "no"){
					$baseUrl = "https://ecm.firstatlanticcommerce.com/PGServiceXML";
				}
				return ($baseUrl);
			}

			public function receipt_page($order_id){
				global $woocommerce;
				
				echo html_entity_decode($woocommerce->session->bacFormData);
			}

			public function getMonthName($monthNumber){
		        $monthName = "";
		        switch($monthNumber){
		            case "01": $monthName =  "ENERO"; break;
		            case "02": $monthName =  "FEBRERO"; break;
		            case "03": $monthName =  "MARZO"; break;
		            case "04": $monthName =  "ABRIL"; break;
		            case "05": $monthName =  "MAYO"; break;
		            case "06": $monthName =  "JUNIO"; break;
		            case "07": $monthName =  "JULIO"; break;
		            case "08": $monthName =  "AGOSTO"; break;
		            case "09": $monthName =  "SEPTIEMBRE"; break;
		            case "10": $monthName =  "OCTUBRE"; break;
		            case "11": $monthName =  "NOVIEMBRE"; break;
		            case "12": $monthName =  "DICIEMBRE"; break;
		        }
		        return $monthName;
		    }

		    private function get_request($name){
				if(isset($_REQUEST[$name]))
				{
					return trim($_REQUEST[$name]);
				}
				return NULL;
			}

			public function formatAmount($total){
				$totalFormat =  number_format((float)$total, 2, '.', '');
				$explodeTotal = explode(".",$totalFormat);
				$amountInPlainText = $explodeTotal[0].$explodeTotal[1];
				$totalAmount = str_pad($amountInPlainText, 12, "0", STR_PAD_LEFT);
				return $totalAmount;
			}

			public function generateSignature($password,$facId,$acquirerId,$orderNumber,$amount,$currency){
				$stringtohash = $password.$facId.$acquirerId.$orderNumber.$amount.$currency;
				$hash = sha1($stringtohash, true);
				$signature = base64_encode($hash);
				return $signature;
			}

			function getValueFromXML($node, $source, $regex) {
			    $soapArray = null;
			    $returnData = null;
			    if (preg_match('#<'.$node.'>('.$regex.')</'.$node.'>#iU', $source, $soapArray)) {
			        $returnData = $soapArray[1];
			    } else {
			        $returnData = $node . " Not Found";
			    }
			    return $returnData;
			}

	    }
	}
}