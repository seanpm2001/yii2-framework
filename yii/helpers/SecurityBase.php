<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\helpers;

use Yii;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\base\InvalidParamException;

/**
 * SecurityBase provides concrete implementation for [[Security]].
 *
 * Do not use SecurityBase. Use [[Security]] instead.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @author Tom Worster <fsb@thefsb.org>
 * @since 2.0
 */
class SecurityBase
{
	/**
	 * Encrypts data.
	 * @param string $data data to be encrypted.
	 * @param string $key the encryption secret key
	 * @return string the encrypted data
	 * @throws Exception if PHP Mcrypt extension is not loaded or failed to be initialized
	 * @see decrypt()
	 */
	public static function encrypt($data, $key)
	{
		$module = static::openCryptModule();
		// 192-bit (24 bytes) key size
		$key = StringHelper::substr($key, 0, 24);
		srand();
		$iv = mcrypt_create_iv(mcrypt_enc_get_iv_size($module), MCRYPT_RAND);
		mcrypt_generic_init($module, $key, $iv);
		$encrypted = $iv . mcrypt_generic($module, $data);
		mcrypt_generic_deinit($module);
		mcrypt_module_close($module);
		return $encrypted;
	}

	/**
	 * Decrypts data
	 * @param string $data data to be decrypted.
	 * @param string $key the decryption secret key
	 * @return string the decrypted data
	 * @throws Exception if PHP Mcrypt extension is not loaded or failed to be initialized
	 * @see encrypt()
	 */
	public static function decrypt($data, $key)
	{
		$module = static::openCryptModule();
		// 192-bit (24 bytes) key size
		$key = StringHelper::substr($key, 0, 24);
		$ivSize = mcrypt_enc_get_iv_size($module);
		$iv = StringHelper::substr($data, 0, $ivSize);
		mcrypt_generic_init($module, $key, $iv);
		$decrypted = mdecrypt_generic($module, StringHelper::substr($data, $ivSize, StringHelper::strlen($data)));
		mcrypt_generic_deinit($module);
		mcrypt_module_close($module);
		return rtrim($decrypted, "\0");
	}

	/**
	 * Prefixes data with a keyed hash value so that it can later be detected if it is tampered.
	 * @param string $data the data to be protected
	 * @param string $key the secret key to be used for generating hash
	 * @param string $algorithm the hashing algorithm (e.g. "md5", "sha1", "sha256", etc.). Call PHP "hash_algos()"
	 * function to see the supported hashing algorithms on your system.
	 * @return string the data prefixed with the keyed hash
	 * @see validateData()
	 * @see getSecretKey()
	 */
	public static function hashData($data, $key, $algorithm = 'sha256')
	{
		return hash_hmac($algorithm, $data, $key) . $data;
	}

	/**
	 * Validates if the given data is tampered.
	 * @param string $data the data to be validated. The data must be previously
	 * generated by [[hashData()]].
	 * @param string $key the secret key that was previously used to generate the hash for the data in [[hashData()]].
	 * @param string $algorithm the hashing algorithm (e.g. "md5", "sha1", "sha256", etc.). Call PHP "hash_algos()"
	 * function to see the supported hashing algorithms on your system. This must be the same
	 * as the value passed to [[hashData()]] when generating the hash for the data.
	 * @return string the real data with the hash stripped off. False if the data is tampered.
	 * @see hashData()
	 */
	public static function validateData($data, $key, $algorithm = 'sha256')
	{
		$hashSize = StringHelper::strlen(hash_hmac($algorithm, 'test', $key));
		$n = StringHelper::strlen($data);
		if ($n >= $hashSize) {
			$hash = StringHelper::substr($data, 0, $hashSize);
			$data2 = StringHelper::substr($data, $hashSize, $n - $hashSize);
			return $hash === hash_hmac($algorithm, $data2, $key) ? $data2 : false;
		} else {
			return false;
		}
	}

	/**
	 * Returns a secret key associated with the specified name.
	 * If the secret key does not exist, a random key will be generated
	 * and saved in the file "keys.php" under the application's runtime directory
	 * so that the same secret key can be returned in future requests.
	 * @param string $name the name that is associated with the secret key
	 * @param integer $length the length of the key that should be generated if not exists
	 * @return string the secret key associated with the specified name
	 */
	public static function getSecretKey($name, $length = 32)
	{
		static $keys;
		$keyFile = Yii::$app->getRuntimePath() . '/keys.php';
		if ($keys === null) {
			$keys = array();
			if (is_file($keyFile)) {
				$keys = require($keyFile);
			}
		}
		if (!isset($keys[$name])) {
			$keys[$name] = static::generateRandomKey($length);
			file_put_contents($keyFile, "<?php\nreturn " . var_export($keys, true) . ";\n");
		}
		return $keys[$name];
	}

	/**
	 * Generates a random key. The key may contain uppercase and lowercase latin letters, digits, underscore, dash and dot.
	 * @param integer $length the length of the key that should be generated
	 * @return string the generated random key
	 */
	public static function generateRandomKey($length = 32)
	{
		if (function_exists('openssl_random_pseudo_bytes')) {
			$key = strtr(base64_encode(openssl_random_pseudo_bytes($length, $strong)), array('+' => '_', '/' => '-', '=' => '.'));
			if ($strong) {
				return substr($key, 0, $length);
			}
		}
		$chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-.';
		return substr(str_shuffle(str_repeat($chars, 5)), 0, $length);
	}

	/**
	 * Opens the mcrypt module.
	 * @return resource the mcrypt module handle.
	 * @throws InvalidConfigException if mcrypt extension is not installed
	 * @throws Exception if mcrypt initialization fails
	 */
	protected static function openCryptModule()
	{
		if (!extension_loaded('mcrypt')) {
			throw new InvalidConfigException('The mcrypt PHP extension is not installed.');
		}
		// AES uses a 128-bit block size
		$module = @mcrypt_module_open('rijndael-128', '', 'cbc', '');
		if ($module === false) {
			throw new Exception('Failed to initialize the mcrypt module.');
		}
		return $module;
	}

	/**
	 * Generates a secure hash from a password and a random salt.
	 *
	 * The generated hash can be stored in database (e.g. `CHAR(64) CHARACTER SET latin1` on MySQL).
	 * Later when a password needs to be validated, the hash can be fetched and passed
	 * to [[validatePassword()]]. For example,
	 *
	 * ~~~
	 * // generates the hash (usually done during user registration or when the password is changed)
	 * $hash = Security::generatePasswordHash($password);
	 * // ...save $hash in database...
	 *
	 * // during login, validate if the password entered is correct using $hash fetched from database
	 * if (Security::validatePassword($password, $hash) {
	 *     // password is good
	 * } else {
	 *     // password is bad
	 * }
	 * ~~~
	 *
	 * @param string $password The password to be hashed.
	 * @param integer $cost Cost parameter used by the Blowfish hash algorithm.
	 * The higher the value of cost,
	 * the longer it takes to generate the hash and to verify a password against it. Higher cost
	 * therefore slows down a brute-force attack. For best protection against brute for attacks,
	 * set it to the highest value that is tolerable on production servers. The time taken to
	 * compute the hash doubles for every increment by one of $cost. So, for example, if the
	 * hash takes 1 second to compute when $cost is 14 then then the compute time varies as
	 * 2^($cost - 14) seconds.
	 * @throws Exception on bad password parameter or cost parameter
	 * @return string The password hash string, ASCII and not longer than 64 characters.
	 * @see validatePassword()
	 */
	public static function generatePasswordHash($password, $cost = 13)
	{
		$salt = static::generateSalt($cost);
		$hash = crypt($password, $salt);

		if (!is_string($hash) || strlen($hash) < 32) {
			throw new Exception('Unknown error occurred while generating hash.');
		}

		return $hash;
	}

	/**
	 * Verifies a password against a hash.
	 * @param string $password The password to verify.
	 * @param string $hash The hash to verify the password against.
	 * @return boolean whether the password is correct.
	 * @throws InvalidParamException on bad password or hash parameters or if crypt() with Blowfish hash is not available.
	 * @see generatePasswordHash()
	 */
	public static function validatePassword($password, $hash)
	{
		if (!is_string($password) || $password === '') {
			throw new InvalidParamException('Password must be a string and cannot be empty.');
		}

		if (!preg_match('/^\$2[axy]\$(\d\d)\$[\.\/0-9A-Za-z]{22}/', $hash, $matches) || $matches[1] < 4 || $matches[1] > 30) {
			throw new InvalidParamException('Hash is invalid.');
		}

		$test = crypt($password, $hash);
		$n = strlen($test);
		if (strlen($test) < 32 || $n !== strlen($hash)) {
			return false;
		}

		// Use a for-loop to compare two strings to prevent timing attacks. See:
		// http://codereview.stackexchange.com/questions/13512
		$check = 0;
		for ($i = 0; $i < $n; ++$i) {
			$check |= (ord($test[$i]) ^ ord($hash[$i]));
		}

		return $check === 0;
	}

	/**
	 * Generates a salt that can be used to generate a password hash.
	 *
	 * The PHP [crypt()](http://php.net/manual/en/function.crypt.php) built-in function
	 * requires, for the Blowfish hash algorithm, a salt string in a specific format:
	 * "$2a$", "$2x$" or "$2y$", a two digit cost parameter, "$", and 22 characters
	 * from the alphabet "./0-9A-Za-z".
	 *
	 * @param integer $cost the cost parameter
	 * @return string the random salt value.
	 * @throws InvalidParamException if the cost parameter is not between 4 and 30
	 */
	protected static function generateSalt($cost = 13)
	{
		$cost = (int)$cost;
		if ($cost < 4 || $cost > 31) {
			throw new InvalidParamException('Cost must be between 4 and 31.');
		}

		// Get 20 * 8bits of pseudo-random entropy from mt_rand().
		$rand = '';
		for ($i = 0; $i < 20; ++$i) {
			$rand .= chr(mt_rand(0, 255));
		}

		// Add the microtime for a little more entropy.
		$rand .= microtime();
		// Mix the bits cryptographically into a 20-byte binary string.
		$rand = sha1($rand, true);
		// Form the prefix that specifies Blowfish algorithm and cost parameter.
		$salt = sprintf("$2y$%02d$", $cost);
		// Append the random salt data in the required base64 format.
		$salt .= str_replace('+', '.', substr(base64_encode($rand), 0, 22));
		return $salt;
	}
}
