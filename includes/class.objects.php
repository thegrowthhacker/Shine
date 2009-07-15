<?PHP
    // Stick your DBOjbect subclasses in here (to help keep things tidy).

    class User extends DBObject
    {
        public function __construct($id = null)
        {
            parent::__construct('users', array('username', 'password', 'level', 'email'), $id);
        }
    }

    class Application extends DBObject
    {
        public function __construct($id = null)
        {
            parent::__construct('applications', array('name', 'link', 'bundle_name', 's3key', 's3pkey', 's3bucket', 's3path', 'sparkle_key', 'sparkle_pkey', 'ap_key', 'ap_pkey', 'from_email', 'email_subject', 'email_body', 'license_filename'), $id);
        }

		public function versions()
		{
			return DBObject::glob('Version', "SELECT * FROM versions WHERE app_id = '{$this->id}' ORDER BY dt DESC");
		}

		public function strCurrentVersion()
		{
			$db = Database::getDatabase();
			return $db->getValue("SELECT version_number FROM versions WHERE app_id = '{$this->id}' ORDER BY dt DESC LIMIT 1");
		}
		
		public function strLastReleaseDate()
		{
			$db = Database::getDatabase();
			$dt = $db->getValue("SELECT dt FROM versions WHERE app_id = '{$this->id}' ORDER BY dt DESC LIMIT 1");
			return dater('m/d/Y', $dt);
		}
		
		public function numSupportQuestions()
		{
			return '0';
		}
		
		public function numBugReports()
		{
			return '0';
		}
		
		public function numFeatureRequests()
		{
			return '0';
		}

		function getBody($order)
		{
			return str_replace(array('{first_name}', '{last_name}'), array($order->first_name, $order->last_name), $this->email_body);
		}
    }

    class Order extends DBObject
    {
        public function __construct($id = null)
        {
            parent::__construct('orders', array('app_id', 'dt', 'txn_type', 'first_name', 'last_name', 'residence_country', 'item_name', 'payment_gross', 'mc_currency', 'business', 'payment_type', 'verify_sign', 'payer_status', 'tax', 'payer_email', 'txn_id', 'quantity', 'receiver_email', 'payer_id', 'receiver_id', 'item_number', 'payment_status', 'payment_fee', 'mc_fee', 'shipping', 'mc_gross', 'custom', 'license', 'type', 'deleted', 'hash', 'claimed'), $id);
        }

		public function applicationName()
		{
			static $cache;
			if(!is_array($cache)) $cache = array();

			if(!isset($cache[$this->app_id]))
			{
				$app = new Application($this->app_id);
				$cache[$this->app_id] = $app->name;
			}
			
			return $cache[$this->app_id];
		}
		
		function generateLicense()
		{
			// Much of the following code is adapted/copied from AquaticPrime's PHP library...

			// Create our license dictionary to be signed
			$dict = array("Product"       => $this->item_name,
						  "Name"          => $this->first_name . ' ' . $this->last_name,
						  "Email"         => $this->payer_email,
						  "Licenses"      => $this->quantity,
						  "Timestamp"     => date('r', strtotime($this->dt)),
						  "TransactionID" => $this->txn_id);

			$search = explode(",","ç,æ,œ,á,é,í,ó,ú,à,è,ì,ò,ù,ä,ë,ï,ö,ü,ÿ,â,ê,î,ô,û,å,e,i,ø,u");
			$replace = explode(",","c,ae,oe,a,e,i,o,u,a,e,i,o,u,a,e,i,o,u,y,a,e,i,o,u,a,e,i,o,u");

			foreach($dict as $k => $v)
				$dict[$k] = str_replace($search, $replace, $v);

			$app = new Application($this->app_id);
		    $sig = chunk_split(getSignature($dict, $app->ap_key, $app->ap_pkey));

		    $plist = "<?xml version=\"1.0\" encoding=\"UTF-8\"?".">\n";
		    $plist .= "<!DOCTYPE plist PUBLIC \"-//Apple Computer//DTD PLIST 1.0//EN\" \"http://www.apple.com/DTDs/PropertyList-1.0.dtd\">\n";
		    $plist .= "<plist version=\"1.0\">\n<dict>\n";
		    foreach($dict as $key => $value)
			{
		        $plist .= "\t<key>" . htmlspecialchars($key, ENT_NOQUOTES) . "</key>\n";
		        $plist .= "\t<string>" . htmlspecialchars($value, ENT_NOQUOTES) . "</string>\n";
		    }
		    $plist .= "\t<key>Signature</key>\n";
		    $plist .= "\t<data>$sig</data>\n";
		    $plist .= "</dict>\n";
		    $plist .= "</plist>\n";

			$this->license = $plist;
			$this->update();
		}
		
		public function emailLicense()
		{
			$app = new Application($this->app_id);

			// Create a random boundary
			$boundary = base64_encode(md5(rand()));

			$headers  = "From: {$app->from_email}\n";
			$headers .= "X-Mailer: PHP/" . phpversion() . "\n";
			$headers .= "MIME-Version: 1.0\n";
			$headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\n";
			$headers .= "Content-Transfer-Encoding: 7bit\n\n";
			$headers .= "This is a MIME encoded message.\n\n";

			$headers .= "--$boundary\n";

			$headers .= "Content-Type: text/plain; charset=\"iso-8859-1\"\n";
			$headers .= "Content-Transfer-Encoding: 7bit\n\n";
			$headers .= $app->getBody($this) . "\n\n\n";

			$headers .= "--$boundary\n";

			$headers .= "Content-Type: application/octet-stream; name=\"{$app->license_filename}\"\n";
			$headers .= "Content-Transfer-Encoding: base64\n";
			$headers .= "Content-Disposition: attachment\n\n";

		    $headers .= chunk_split(base64_encode($this->license))."\n";

		    $headers .= "--$boundary--";

			mail($this->payer_email, $app->email_subject, '', utf8_encode($headers));
		}

		// This method is an alternative to your box's native sendmail. If you have access, I'd recommend using
		// your company Gmail account to send - as that will help your emails get through spam filters. To setup,
		// add these lines to the production() method in class.config.php
		// define('SMTP_USERNAME', 'your@email.com');
		// define('SMTP_PASSWORD', 'some-password');
		// define('SMTP_HOST', 'ssl://smtp.gmail.com');
		// define('SMTP_PORT', 465);
		public function emailLicenseSMTP()
		{
			$app = new Application($this->app_id);

			$tmp = tempnam('/tmp', 'foo');
			file_put_contents($tmp, $this->license);

 			$hdrs = array('From' => $app->from_email, 'Subject' => $app->email_subject);

			$mime = new Mail_mime("\r\n");
			$mime->setTXTBody($app->getBody($this));
			$mime->setHTMLBody('');
			$mime->addAttachment($tmp, 'application/octet-stream', $app->license_filename, true, 'base64');

			$body = $mime->get();
			$hdrs = $mime->headers($hdrs);

			$smtp =& Mail::factory('smtp', array('host' => SMTP_HOST, 'port' => SMTP_PORT, 'auth' => true, 'username' => SMTP_USERNAME, 'password' => SMTP_PASSWORD));
			$mail = $smtp->send($this->payer_email, $hdrs, $body);
		}
		
		public function downloadLicense()
		{
			$app = new Application($this->app_id);
			header("Cache-Control: public");
			header("Cache-Control: no-store, no-cache, must-revalidate");
			header("Cache-Control: post-check=0, pre-check=0", false);
			header("Pragma: no-cache");
			header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
			header("Content-Type: application/octet-stream");
			header("Content-Length: " . strlen($this->license));
			header("Content-Disposition: attachment; filename={$app->license_filename}");
			header("Content-Transfer-Encoding: binary");
			echo $this->license;
			exit;
		}
		
		public static function totalOrders()
		{
			$db = Database::getDatabase();
			return $db->getValue("SELECT COUNT(*) FROM orders WHERE type = 'paypal'");
		}
    }

    class Version extends DBObject
    {
        public function __construct($id = null)
        {
            parent::__construct('versions', array('app_id', 'human_version', 'version_number', 'dt', 'release_notes', 'filesize', 'url', 'downloads', 'signature'), $id);
        }
    }