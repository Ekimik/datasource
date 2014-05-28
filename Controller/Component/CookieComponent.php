<?php
/**
 * CakePHP(tm) : Rapid Development Framework (http://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 * @link          http://cakephp.org CakePHP(tm) Project
 * @since         1.2.0
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Controller\Component;

use Cake\Controller\Component;
use Cake\Controller\ComponentRegistry;
use Cake\Controller\Controller;
use Cake\Core\Configure;
use Cake\Error;
use Cake\Event\Event;
use Cake\Network\Request;
use Cake\Network\Response;
use Cake\Utility\Hash;
use Cake\Utility\Security;

/**
 * Cookie Component.
 *
 * Cookie handling for the controller.
 *
 * @link http://book.cakephp.org/2.0/en/core-libraries/components/cookie.html
 *
 */
class CookieComponent extends Component {

/**
 * Default config
 *
 * - `expires` - How long the cookies should last for by default.
 * - `path` - The path on the server in which the cookie will be available on.
 *   If path is set to '/foo/', the cookie will only be available within the
 *   /foo/ directory and all sub-directories such as /foo/bar/ of domain.
 *   The default value is the entire domain.
 * - `domain` - The domain that the cookie is available. To make the cookie
 *   available on all subdomains of example.com set domain to '.example.com'.
 * - `secure` - Indicates that the cookie should only be transmitted over a
 *   secure HTTPS connection. When set to true, the cookie will only be set if
 *   a secure connection exists.
 * - `key` - Encryption key.
 * - `httpOnly` - Set to true to make HTTP only cookies. Cookies that are HTTP only
 *   are not accessible in JavaScript. Default false.
 * - `encryption` - Type of encryption to use. Defaults to 'aes'.
 *
 * @var array
 */
	protected $_defaultConfig = [
		'path' => '/',
		'domain' => '',
		'secure' => false,
		'key' => null,
		'httpOnly' => false,
		'encryption' => 'aes',
		'expires' => null,
	];

/**
 * Config specific to a given top level key name.
 *
 * The values in this array are merged with the general config
 * to generate the configuration for a given top level cookie name.
 *
 * @var array
 */
	protected $_keyConfig = [];

/**
 * Values stored in the cookie.
 *
 * Accessed in the controller using $this->Cookie->read('Name.key');
 *
 * @see CookieComponent::read();
 * @var string
 */
	protected $_values = array();

/**
 * A reference to the Controller's Cake\Network\Response object
 *
 * @var \Cake\Network\Response
 */
	protected $_response = null;

/**
 * The request from the controller.
 *
 * @var \Cake\Network\Request
 */
	protected $_request;

/**
 * Constructor
 *
 * @param ComponentRegistry $collection A ComponentRegistry for this component
 * @param array $config Array of config.
 */
	public function __construct(ComponentRegistry $collection, array $config = array()) {
		parent::__construct($collection, $config);

		if (!$this->_config['key']) {
			$this->config('key', Configure::read('Security.salt'));
		}

		$controller = $collection->getController();
		if ($controller && isset($controller->request)) {
			$this->_request = $controller->request;
		} else {
			$this->_request = Request::createFromGlobals();
		}

		if ($controller && isset($controller->response)) {
			$this->_response = $controller->response;
		} else {
			$this->_response = new Response();
		}
	}

/**
 * Set the configuration for a specific top level key.
 *
 * @param string $keyname The top level keyname to configure.
 * @param null|string|array $option Either the option name to set, or an array of options to set,
 *   or null to read config options for a given key.
 * @param string|null $value Either the value to set, or empty when $option is an array.
 * @return void
 */
	public function configKey($keyname, $option = null, $value = null) {
		if ($option === null) {
			$default = $this->_config;
			$local = isset($this->_keyConfig[$keyname]) ? $this->_keyConfig[$keyname] : [];
			return $local + $default;
		}
		if (!is_array($option)) {
			$option = [$option => $value];
		}
		$this->_keyConfig[$keyname] = $option;
	}

/**
 * Events supported by this component.
 *
 * @return array
 */
	public function implementedEvents() {
		return [];
	}

/**
 * Write a value to the $_COOKIE[$key];
 *
 * By default all values are encrypted.
 * You must pass $encrypt false to store values in clear test
 *
 * You must use this method before any output is sent to the browser.
 * Failure to do so will result in header already sent errors.
 *
 * @param string|array $key Key for the value
 * @param mixed $value Value
 * @return void
 * @link http://book.cakephp.org/2.0/en/core-libraries/components/cookie.html#CookieComponent::write
 */
	public function write($key, $value = null) {
		if (empty($this->_values)) {
			$this->_load();
		}

		if (!is_array($key)) {
			$key = array($key => $value);
		}

		$keys = [];
		foreach ($key as $name => $value) {
			$this->_values = Hash::insert($this->_values, $name, $value);
			$parts = explode('.', $name);
			$keys[] = $parts[0];
		}

		foreach ($keys as $name) {
			$this->_write($name, $this->_values[$name]);
		}
	}

/**
 * Read the value of key path from request cookies.
 *
 * @param string $key Key of the value to be obtained. If none specified, obtain map key => values
 * @return string or null, value for specified key
 * @link http://book.cakephp.org/2.0/en/core-libraries/components/cookie.html#CookieComponent::read
 */
	public function read($key = null) {
		if (empty($this->_values)) {
			$this->_load();
		}
		if ($key === null) {
			return $this->_values;
		}
		return Hash::get($this->_values, $key);
	}

/**
 * Load the cookie data from the request and response objects.
 *
 * Based on the configuration data, cookies will be decrypted. When cookies
 * contain array data, that data will be expanded.
 *
 * @return void
 */
	protected function _load() {
		foreach ($this->_request->cookies as $name => $value) {
			$config = $this->configKey($name);
			$this->_values[$name] = $this->_decrypt($value, $config['encryption']);
		}
	}

/**
 * Returns true if given key is set in the cookie.
 *
 * @param string $key Key to check for
 * @return bool True if the key exists
 */
	public function check($key = null) {
		if (empty($key)) {
			return false;
		}
		return $this->read($key) !== null;
	}

/**
 * Delete a cookie value
 *
 * You must use this method before any output is sent to the browser.
 * Failure to do so will result in header already sent errors.
 *
 * This method will delete both the top level and 2nd level cookies set.
 * For example assuming that $name = App, deleting `User` will delete
 * both `App[User]` and any other cookie values like `App[User][email]`
 * This is done to clean up cookie storage from before 2.4.3, where cookies
 * were stored inconsistently.
 *
 * @param string $key Key of the value to be deleted
 * @return void
 * @link http://book.cakephp.org/2.0/en/core-libraries/components/cookie.html#CookieComponent::delete
 */
	public function delete($key) {
		$cookieName = $this->config('name');
		if (empty($this->_values[$cookieName])) {
			$this->read();
		}
		if (strpos($key, '.') === false) {
			if (isset($this->_values[$cookieName][$key]) && is_array($this->_values[$cookieName][$key])) {
				foreach ($this->_values[$cookieName][$key] as $idx => $val) {
					$this->_delete("[$key][$idx]");
				}
			}
			$this->_delete("[$key]");
			unset($this->_values[$cookieName][$key]);
			return;
		}
		$names = explode('.', $key, 2);
		if (isset($this->_values[$cookieName][$names[0]])) {
			$this->_values[$cookieName][$names[0]] = Hash::remove($this->_values[$cookieName][$names[0]], $names[1]);
		}
		$this->_delete('[' . implode('][', $names) . ']');
	}

/**
 * Destroy current cookie
 *
 * You must use this method before any output is sent to the browser.
 * Failure to do so will result in header already sent errors.
 *
 * @return void
 * @link http://book.cakephp.org/2.0/en/core-libraries/components/cookie.html#CookieComponent::destroy
 */
	public function destroy() {
		$cookieName = $this->config('name');
		if (empty($this->_values[$cookieName])) {
			$this->read();
		}

		foreach ($this->_values[$cookieName] as $name => $value) {
			if (is_array($value)) {
				foreach ($value as $key => $val) {
					unset($this->_values[$cookieName][$name][$key]);
					$this->_delete("[$name][$key]");
				}
			}
			unset($this->_values[$cookieName][$name]);
			$this->_delete("[$name]");
		}
	}

/**
 * Get / set encryption type. Use this method in ex: AppController::beforeFilter()
 * before you have read or written any cookies.
 *
 * @param string|null $type Encryption type to set or null to get current type.
 * @return string|null
 * @throws \Cake\Error\Exception When an unknown type is used.
 */
	public function encryption($type = null) {
		if ($type === null) {
			return $this->_config['encryption'];
		}

		$availableTypes = [
			'rijndael',
			'aes'
		];
		if (!in_array($type, $availableTypes)) {
			throw new Error\Exception('You must use rijndael, or aes for cookie encryption type');
		}
		$this->config('encryption', $type);
	}

/**
 * Set cookie
 *
 * @param string $name Name for cookie
 * @param string $value Value for cookie
 * @return void
 */
	protected function _write($name, $value) {
		$config = $this->configKey($name);
		$expires = new \DateTime($config['expires']);

		$this->_response->cookie(array(
			'name' => $name,
			'value' => $this->_encrypt($value, $config['encryption']),
			'expire' => $expires->format('U'),
			'path' => $config['path'],
			'domain' => $config['domain'],
			'secure' => $config['secure'],
			'httpOnly' => $config['httpOnly']
		));
	}

/**
 * Sets a cookie expire time to remove cookie value
 *
 * @param string $name Name of cookie
 * @return void
 */
	protected function _delete($name) {
		$config = $this->configKey($name);
		$expires = new \DateTime($config['expires']);

		$this->_response->cookie(array(
			'name' => $name,
			'value' => '',
			'expire' => $expires->format('U') - 42000,
			'path' => $config['path'],
			'domain' => $config['domain'],
			'secure' => $config['secure'],
			'httpOnly' => $config['httpOnly']
		));
	}

/**
 * Encrypts $value using public $type method in Security class
 *
 * @param string $value Value to encrypt
 * @param string|bool $encrypt Encryption mode to use. False
 *   disabled encryption.
 * @return string Encoded values
 */
	protected function _encrypt($value, $encrypt) {
		if (is_array($value)) {
			$value = $this->_implode($value);
		}
		if (!$encrypt) {
			return $value;
		}
		$prefix = "Q2FrZQ==.";
		if ($encrypt === 'rijndael') {
			$cipher = Security::rijndael($value, $this->_config['key'], 'encrypt');
		}
		if ($encrypt === 'aes') {
			$cipher = Security::encrypt($value, $this->_config['key']);
		}
		return $prefix . base64_encode($cipher);
	}

/**
 * Decrypts $value using public $type method in Security class
 *
 * @param array $values Values to decrypt
 * @param string|bool $mode Encryption mode
 * @return string decrypted string
 */
	protected function _decrypt($values, $mode) {
		if (is_string($values)) {
			return $this->_decode($values, $mode);
		}

		$decrypted = array();
		foreach ($values as $name => $value) {
			if (is_array($value)) {
				foreach ($value as $key => $val) {
					$decrypted[$name][$key] = $this->_decode($val, $mode);
				}
			} else {
				$decrypted[$name] = $this->_decode($value, $mode);
			}
		}
		return $decrypted;
	}

/**
 * Decodes and decrypts a single value.
 *
 * @param string $value The value to decode & decrypt.
 * @param string|false $encryption The encryption cipher to use.
 * @return string Decoded value.
 */
	protected function _decode($value, $encryption) {
		$prefix = 'Q2FrZQ==.';
		$pos = strpos($value, $prefix);
		if (!$encryption) {
			return $this->_explode($value);
		}
		$value = base64_decode(substr($value, strlen($prefix)));
		if ($encryption === 'rijndael') {
			$value = Security::rijndael($value, $this->_config['key'], 'decrypt');
		}
		if ($encryption === 'aes') {
			$value = Security::decrypt($value, $this->_config['key']);
		}
		return $this->_explode($value);
	}

/**
 * Implode method to keep keys are multidimensional arrays
 *
 * @param array $array Map of key and values
 * @return string A json encoded string.
 */
	protected function _implode(array $array) {
		return json_encode($array);
	}

/**
 * Explode method to return array from string set in CookieComponent::_implode()
 * Maintains reading backwards compatibility with 1.x CookieComponent::_implode().
 *
 * @param string $string A string containing JSON encoded data, or a bare string.
 * @return array Map of key and values
 */
	protected function _explode($string) {
		$first = substr($string, 0, 1);
		if ($first === '{' || $first === '[') {
			$ret = json_decode($string, true);
			return ($ret !== null) ? $ret : $string;
		}
		$array = array();
		foreach (explode(',', $string) as $pair) {
			$key = explode('|', $pair);
			if (!isset($key[1])) {
				return $key[0];
			}
			$array[$key[0]] = $key[1];
		}
		return $array;
	}

}
