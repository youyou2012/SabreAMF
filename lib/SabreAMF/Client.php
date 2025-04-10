<?php

    /**
     * AMF Client
     *
     * Use this class to make a calls to AMF0/AMF3 services. The class makes use of the curl http library, so make sure you have this installed.
     *
     * It sends AMF0 encoded data by default. Change the encoding to AMF3 with setEncoding. sendRequest calls the actual service 
     * 
     * @package SabreAMF
     * @version $Id$
     * @copyright Copyright (C) 2006-2009 Rooftop Solutions. All rights reserved.
     * @author Evert Pot (http://www.rooftopsolutions.nl/) 
     * @licence http://www.freebsd.org/copyright/license.html  BSD License
     * @example ../examples/client.php
     * @uses SabreAMF_Message
     * @uses SabreAMF_OutputStream
     * @uses SabreAMF_InputStream
     */
    class SabreAMF_Client {

        /**
         * endPoint 
         * 
         * @var string 
         */
        private $endPoint;
        /**
         * httpProxy
         * 
         * @var mixed
         */
        private $httpProxy;
        /**
         * amfInputStream 
         * 
         * @var SabreAMF_InputStream
         */
        private $amfInputStream;
        /**
         * amfOutputStream 
         * 
         * @var SabreAMF_OutputStream
         */
        private $amfOutputStream;

        /**
         * amfRequest 
         * 
         * @var SabreAMF_Message 
         */
        private $amfRequest;

        /**
         * amfResponse 
         * 
         * @var SabreAMF_Message 
         */
        private $amfResponse;

        /**
         * encoding 
         * 
         * @var int 
         */
        private $encoding = SabreAMF_Const::AMF0;

        /**
         * HTTP headers
         *
         * @var array
         */
        private $httpHeaders = array();

        /**
         * __construct 
         * 
         * @param string $endPoint The url to the AMF gateway
         * @return void
         */
        public function __construct($endPoint) {

            $this->endPoint = $endPoint;

            $this->amfRequest = new SabreAMF_Message();
            $this->amfOutputStream = new SabreAMF_OutputStream();

        }

        /**
         * Add a HTTP header to the cURL request
         *
         * @param string $header
         * @return $this
         */
        public function addHTTPHeader($header)
        {
            $this->httpHeaders[] = $header;

            return $this;
        }


        /**
         * sendRequest 
         *
         * sendRequest sends the request to the server. It expects the servicepath and methodname, and the parameters of the methodcall
         * 
         * @param string $servicePath The servicepath (e.g.: myservice.mymethod)
         * @param array $data The parameters you want to send
		 * @param array $needDeserialized The deserialized result whether you want to return
         * @return mixed 
         */
        public function sendRequest($servicePath,$data,$needDeserialized=true, $timeout = 60) {
           
            // We're using the FLEX Messaging framework
            if($this->encoding & SabreAMF_Const::FLEXMSG) {


                // Setting up the message
                $message = new SabreAMF_AMF3_RemotingMessage();
                $message->body = $data;

                // We need to split serviceName.methodName into separate variables
                $service = explode('.',$servicePath);
                $method = array_pop($service);
                $service = implode('.',$service);
                $message->operation = $method; 
                $message->source = $service;

                $data = $message;
            }

            $this->amfRequest->addBody(array(

                // If we're using the flex messaging framework, target is specified as the string 'null'
                'target'   => $this->encoding & SabreAMF_Const::FLEXMSG?'null':$servicePath,
                'response' => '/1',
                'data'     => $data
            ));

            $this->amfRequest->serialize($this->amfOutputStream);

            $headers = array_merge(array(
                'Content-type: ' . SabreAMF_Const::MIMETYPE
            ), $this->httpHeaders);

            // The curl request
            $ch = curl_init($this->endPoint);
            curl_setopt($ch,CURLOPT_POST,1);
            curl_setopt($ch,CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch,CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch,CURLOPT_HTTPHEADER,$headers);
            curl_setopt($ch,CURLOPT_POSTFIELDS,$this->amfOutputStream->getRawData());
			
			// Add UA
			if (!empty($this->userAgent)) {
				curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
			}
			
    		if ($this->httpProxy) {
    			curl_setopt($ch,CURLOPT_PROXY,$this->httpProxy);
    		}
            $result = curl_exec($ch);
 
            if (curl_errno($ch)) {
                throw new Exception('CURL error: ' . curl_error($ch));
                false;
            } else {
                curl_close($ch);
            }
			
			if ($needDeserialized == false) {
				return $result;
			}
            $this->amfInputStream = new SabreAMF_InputStream($result);
            $this->amfResponse = new SabreAMF_Message(); 
            $this->amfResponse->deserialize($this->amfInputStream);

            $this->parseHeaders();

            foreach($this->amfResponse->getBodies() as $body) {

                if (strpos($body['target'],'/1')===0) return $body['data'] ;

            }

        }

        /**
         * addHeader 
         *
         * Add a header to the client request
         * 
         * @param string $name 
         * @param bool $required 
         * @param mixed $data 
         * @return void
         */
        public function addHeader($name,$required,$data) {

            $this->amfRequest->addHeader(array('name'=>$name,'required'=>$required==true,'data'=>$data));

        }
       
        /**
         * setCredentials 
         * 
         * @param string $username 
         * @param string $password 
         * @return void
         */
        public function setCredentials($username,$password) {

            $this->addHeader('Credentials',false,(object)array('userid'=>$username,'password'=>$password));

        }
        
        /**
         * setHttpProxy
         * 
         * @param mixed $httpProxy
         * @return void
         */
        public function setHttpProxy($httpProxy) {
            $this->httpProxy = $httpProxy;
        }

        /**
         * parseHeaders 
         * 
         * @return void
         */
        private function parseHeaders() {

            foreach($this->amfResponse->getHeaders() as $header) {

                switch($header['name']) {

                    case 'ReplaceGatewayUrl' :
                        if (is_string($header['data'])) {
                            $this->endPoint = $header['data'];
                        }
                        break;

                }


            }

        }

        /**
         * Change the AMF encoding (0 or 3) 
         * 
         * @param int $encoding 
         * @return void
         */
        public function setEncoding($encoding) {

            $this->encoding = $encoding;
            $this->amfRequest->setEncoding($encoding & SabreAMF_Const::AMF3);

        }
		
		/**
         * Change the AMF useragent
         *
         * @param string $ua
         * @return void
         */
		public function setUserAgent($ua){
			$this->userAgent =$ua;
		}

    }



