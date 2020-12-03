<?php
/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2005 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\Session;

\defined('JPATH_PLATFORM') or die;

use Joomla\CMS\Application\ApplicationHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Router\Route;
use Joomla\Event\DispatcherInterface;
use Joomla\Session\Session as BaseSession;
use Joomla\Session\StorageInterface;

/**
 * Class for managing HTTP sessions
 *
 * @since  1.5
 */
class Session extends BaseSession
{
	/**
	 * Constructor
	 *
	 * @param   StorageInterface     $store       A StorageInterface implementation.
	 * @param   DispatcherInterface  $dispatcher  DispatcherInterface for the session to use.
	 * @param   array                $options     Optional parameters. Supported keys include:
	 *                                            - name: The session name
	 *                                            - id: The session ID
	 *                                            - expire: The session lifetime in seconds
	 *
	 * @since   1.0
	 */
	public function __construct(StorageInterface $store = null, DispatcherInterface $dispatcher = null, array $options = [])
	{
		// Extra hash the name of the session for b/c with Joomla 3.x or the session is never found.
		if (isset($options['name']))
		{
			$options['name'] = md5($options['name']);
		}

		parent::__construct($store, $dispatcher, $options);
	}

	/**
	 * Checks for a form token in the request.
	 *
	 * Use in conjunction with HTMLHelper::_('form.token') or JSession::getFormToken.
	 *
	 * @param   string  $method  The request method in which to look for the token key.
	 *
	 * @return  boolean  True if found and valid, false otherwise.
	 *
	 * @since   2.5.4
	 */
	public static function checkToken($method = 'post')
	{
		$app   = Factory::getApplication();
		$token = static::getFormToken();

		// Check from header first
		if ($token === $app->input->server->get('HTTP_X_CSRF_TOKEN', '', 'alnum'))
		{
			return true;
		}

		// Then fallback to HTTP query
		if (!$app->input->$method->get($token, '', 'alnum'))
		{
			if ($app->getSession()->isNew())
			{
				// Redirect to login screen.
				$app->enqueueMessage(Text::_('JLIB_ENVIRONMENT_SESSION_EXPIRED'), 'warning');
				$app->redirect(Route::_('index.php'));

				return true;
			}

			return false;
		}

		return true;
	}

	/**
	 * Method to determine a hash for anti-spoofing variable names
	 *
	 * @param   boolean  $forceNew  If true, force a new token to be created
	 *
	 * @return  string  Hashed var name
	 *
	 * @since   1.6
	 */
	public static function getFormToken($forceNew = false)
	{
		$user = Factory::getUser();

		return ApplicationHelper::getHash($user->get('id', 0) . Factory::getApplication()->getSession()->getToken($forceNew));
	}

	/**
	 * Get the available session handlers
	 *
	 * @return  array  An array of available session handlers
	 *
	 * @since   4.0.0
	 */
	public static function getHandlers(): array
	{
		$connectors = [];

		// Get an iterator and loop trough the handler classes.
		$iterator = new \DirectoryIterator(JPATH_LIBRARIES . '/vendor/joomla/session/src/Handler');

		foreach ($iterator as $file)
		{
			$fileName = $file->getFilename();

			// Only load for PHP files.
			if (!$file->isFile() || $file->getExtension() !== 'php')
			{
				continue;
			}

			// Derive the class name from the type.
			$class = str_ireplace('.php', '', '\\Joomla\\Session\\Handler\\' . $fileName);

			// If the class doesn't exist we have nothing left to do but look at the next type. We did our best.
			if (!class_exists($class))
			{
				continue;
			}

			// Sweet!  Our class exists, so now we just need to know if it passes its test method.
			if ($class::isSupported())
			{
				// Connector names should not have file the handler suffix or the file extension.
				$connectors[] = str_ireplace('Handler.php', '', $fileName);
			}
		}

		return $connectors;
	}

	/**
	 * Returns the global session object.
	 *
	 * @return  static  The Session object.
	 *
	 * @since   1.5
	 * @deprecated  5.0  Load the session service from the dependency injection container or via $app->getSession()
	 */
	public static function getInstance()
	{
		Log::add(
			__METHOD__ . '() is deprecated. Load the session from the dependency injection container or via Factory::getApplication()->getSession().',
			Log::WARNING,
			'deprecated'
		);

		return Factory::getApplication()->getSession();
	}

	/**
	 * Get data from the session store
	 *
	 * @param   string  $name     Name of a variable
	 * @param   mixed   $default  Default value of a variable if not set
	 *
	 * @return  mixed  Value of a variable
	 *
	 * @since   1.5
	 */
	public function get($name, $default = null)
	{
		// Handle B/C by checking if a namespace was passed to the method, will be removed at 5.0
		if (\func_num_args() > 2)
		{
			$args = \func_get_args();

			if (!empty($args[2]))
			{
				Log::add(
					'Passing a namespace as a parameter to ' . __METHOD__ . '() is deprecated. '
					. 'The namespace should be prepended to the name instead.',
					Log::WARNING,
					'deprecated'
				);

				$name = '__' . $args[2] . '.' . $name;
			}
		}

		// More b/c for retrieving sessions that originated in Joomla 3. This will be removed in Joomla 5
		// as no sessions should have this format anymore!
		if ($this->has($name))
		{
			return parent::get($name, $default);
		}
		elseif ($this->has('__default.' . $name))
		{
			return parent::get('__default.' . $name, $default);
		}
		else
		{
			return $default;
		}
	}

	/**
	 * Set data into the session store.
	 *
	 * @param   string  $name   Name of a variable.
	 * @param   mixed   $value  Value of a variable.
	 *
	 * @return  mixed  Old value of a variable.
	 *
	 * @since   1.5
	 */
	public function set($name, $value = null)
	{
		// Handle B/C by checking if a namespace was passed to the method, will be removed at 5.0
		if (\func_num_args() > 2)
		{
			$args = \func_get_args();

			if (!empty($args[2]))
			{
				Log::add(
					'Passing a namespace as a parameter to ' . __METHOD__ . '() is deprecated. '
					. 'The namespace should be prepended to the name instead.',
					Log::WARNING,
					'deprecated'
				);

				$name = $args[2] . '.' . $name;
			}
		}

		return parent::set($name, $value);
	}

	/**
	 * Check whether data exists in the session store
	 *
	 * @param   string  $name  Name of variable
	 *
	 * @return  boolean  True if the variable exists
	 *
	 * @since   1.5
	 */
	public function has($name)
	{
		// Handle B/C by checking if a namespace was passed to the method, will be removed at 5.0
		if (\func_num_args() > 1)
		{
			$args = \func_get_args();

			if (!empty($args[1]))
			{
				Log::add(
					'Passing a namespace as a parameter to ' . __METHOD__ . '() is deprecated. '
					. 'The namespace should be prepended to the name instead.',
					Log::WARNING,
					'deprecated'
				);

				$name = $args[1] . '.' . $name;
			}
		}

		return parent::has($name);
	}

	/**
	 * Clears all variables from the session store
	 *
	 * @return  void
	 *
	 * @since   1.5
	 */
	public function clear()
	{
		// Handle B/C by checking if parameters were passed to this method; if so proxy to the new remove() method, will be removed at 5.0
		if (\func_num_args() >= 1)
		{
			$args = \func_get_args();

			if (!empty($args[0]))
			{
				Log::add(
					'Using ' . __METHOD__ . '() to remove a single element from the session is deprecated.  Use ' . __CLASS__ . '::remove() instead.',
					Log::WARNING,
					'deprecated'
				);

				$name = $args[0];

				// Also check for a namespace
				if (\func_num_args() > 1 && !empty($args[1]))
				{
					Log::add(
						'Passing a namespace as a parameter to ' . __METHOD__ . '() is deprecated. '
						 . 'The namespace should be prepended to the name instead.',
						Log::WARNING,
						'deprecated'
					);

					$name = $args[1] . '.' . $name;
				}

				return $this->remove($name);
			}
		}

		return parent::clear();
	}
}
