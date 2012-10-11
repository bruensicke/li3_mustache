<?php

namespace li3_mustache\extensions\helper;

use li3_mustache\libraries\Mustache as Must;

use lithium\util\Inflector;
use lithium\util\String;
use lithium\util\Set;

class Mustache extends \lithium\template\Helper {

	/**
	 * View Object to be re-used across mails
	 *
	 * @see li3_mailer\core\Mailer::render()
	 * @var object Instance of View object
	 */
	protected $_view;

	/**
	 * all names of collection classes,
	 * that we have to cut off the primary keys
	 * via array_values to allow for mustache
	 * looping instead of nesting.
	 *
	 * @var array
	 */
	public $_collection_classes = array(
		'RecordSet',
		'DocumentSet',
		'DocumentCollection'
	);

	/**
	 * Dynamic class dependencies.
	 *
	 * @var array Associative array of class names & their namespaces.
	 */
	protected $_classes = array(
		'view' => 'lithium\template\View',
	);

	/**
	 * Renders one mustache element with given $data
	 *
	 * @param string $name name of the element, below views/mustache
	 * @param string $data an array or object what to hand to the mustache layer
	 * @param string $options additional options, to put into the view()->render()
	 * @return string the rendered mustache template
	 */
	public function render($name, $data = array(), $options = array()) {
		$template = $this->template($name, $data, $options);
		$partials = $this->partials($template);
		$data = $this->_extract($data);
		return new Must($template, $data, $partials);
	}

	/**
	 * Find the correct (mustache) element and return its content
	 *
	 * @param string $name Name of element to look for below views/mustache
	 * @param string $params additional params to put into the view()->render()
	 * @return string the rendered element with (hopefully) the mustache template in it
	 */
	public function template($name, $data = array(), $params = array()) {
		return $this->_view()->render(array('mustache' => $name), $data, $params);
	}

	/**
	 * generates a valid Mustache partials array for given $template
	 *
	 * @param string $template mustache template with partials in it
	 * @param array $data an array or object to hand to the mustache layer
	 * @param array $options additional options, to put into the view()->render()
	 * @return array an associative array containing all partials
	 */
	public function partials($template, $data = array(), $options = array()) {
		$result = array();
		$regex = sprintf(
			'/(?:(?<=\\n)[ \\t]*)?%s(?:(?P<type>[%s])(?P<tag_name>.+?)|=(?P<delims>.*?)=)%s\\n?/s',
			preg_quote('{{', '/'), '\>', preg_quote('}}', '/')
		);
		preg_match_all($regex, $template, $matches);
		if (!empty($matches['tag_name'])) {
			foreach ($matches['tag_name'] as $name) {
				$result[$name] = $this->template($name, $data, $options);
			}
		}
		return $result;
	}

	/**
	 * Parses an associative array into an array, containing one
	 * array for each row, that has 'key' and 'value' filled
	 * as expected. That makes rendering of arbitrary meta-data
	 * much simpler, e.g. if you do not know, what data you are
	 * about to retrieve.
	 *
	 * @param array $data an associative array containing mixed data
	 * @return array an numerical indexed array with arrays for each
	 *         item in $data, having 'key' and 'value' set accordingly
	 */
	public function data(array $data = array(), array $options = array()) {
		$defaults = array('flatten' => true);
		$options += $defaults;
		if ($options['flatten']) {
			$data = Set::flatten($data);
		}
		return array_map(function($key, $value) {
			return compact('key', 'value');
		}, array_keys($data), $data);
	}

	/**
	 * generates a script-tag with a mustache template in it
	 *
	 * The script tag has the given template as content and has a
	 * attribute type="text/html" which is proposed as a template
	 * to be used clientside with javascript.
	 *
	 * @param string $name Name of template to include
	 * @param string $options additional options, to put into the view()->render()
	 * @return string the script-tag with correct attributes and template
	 */
	public function script($name, $options = array()) {
		$defaults = array('name' => 'tpl_' . Inflector::slug($name, '_'));
		$options += $defaults;
		$options += array('template' => $this->template($name, array(), $options));
		$scriptblock = '<script id="{:name}" type="text/html" charset="utf-8">{:template}</script>';
		return String::insert($scriptblock, $options);
	}

	/**
	 * instantiates and returns custom View class
	 *
	 * mustache templates are at views/mustache, this custom view
	 * takes care of that.
	 *
	 * @param array $config for custom View config
	 * @return object instance of newly created View Instance
	 */
	public function _view(array $config = array()) {
		if ($this->_view) {
			return $this->_view;
		}
		$defaults = array(
			'paths' => array(
				'mustache' => array(
					LITHIUM_APP_PATH . '/views/mustache/{:template}.{:type}.php',
					'{:library}/views/mustache/{:template}.{:type}.php',
				)
			),
		);
		$config += $defaults;
		$this->_view = $this->_context->view();
		$config = Set::merge($config, $this->_view->_config);
		$this->_view->__construct($config);
		return $this->_view;
	}

	/**
	 * make sure, only array data is returned
	 *
	 * @param string $data mixed can be objects or array
	 * @return array resulting data with only array format
	 */
	public function _extract($data) {
		$collection_classes = $this->_collection_classes;
		array_walk_recursive($data, function(&$item, &$key) use (&$collection_classes) {

			// we need to convert our data, that is probably an object
			// to an array, or our mustache complains.
			if (is_object($item)) {

				// get type of class (we do not care for namespaces...)
				$class_type = basename(str_replace('\\', '/', get_class($item)));

				// First, we check, if this is a Collection or RecordSet,
				// because we need to get only the values, the $keys are $ids
				// which makes mustache think, this is an named object, instead
				// of an array
				if (in_array($class_type, $collection_classes)) {
					$item = array_values($item->data());

				// whatever it is, if it has a data() method on it, we should call that.
				// that way, we can even throw models or whatever you think in it.
				// If you handover a custom tailored object, make sure you implement this
				// so it works out of the box
				} elseif (is_callable(array($item, 'data'))) {
					$item = $item->data();
				}
			}
		});
		return $data;
	}
}

?>