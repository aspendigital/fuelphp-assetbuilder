<?php

namespace AssetBuilder;

/**
 * Most asset libraries that combine files check every page load for changes, which is not a huge deal,
 * but since it's every single page load (on every project we use this on), it's nice to do without that
 * time spent dealing with relatively rare production asset changes.
 * 
 * This library fulfills the following goals:
 *  - Has a minimal footprint in a production environment
 *  - Handle compiling LESS into CSS
 *  - Combine assets into groups
 *  - Serve up minified assets
 * 
 * The syntax is borrowed more or less from the excellent Casset library, because it supports almost exactly the development
 * functionality we want (minus LESS), but the way we're going about it is significantly different. Several functions
 * are taken from Casset, but this package is much simpler in the use cases supported. Combining/minifying
 * are not optional but assumed when building for the production environment.
 * 
 * The heavy lifting is performed by the Assetic asset management framework
 *
 * @namespace AssetBuilder
 */
class AssetBuilder
{
	/** @var boolean */
	protected static $development = true;
	
	/** @var array */
	protected static $groups = array();

	/** @var string */
	protected static $base_url;
	
	/** @var array */
	protected static $inline_assets = array(
			'js'=>array(),
			'css'=>array()
		);
	
	/** @var string */
	protected static $asset_dir = 'assets/';
	
	/** @var string */
	protected static $js_dir = 'js/';
	
	/** @var string */
	protected static $css_dir = 'css/';
	
	/** @var string */
	protected static $less_dir = 'css/less/';

	/** @var string */
	protected static $image_dir = 'images/';
	
	/** @var string */
	protected static $cache_dir = 'assets/cache/';
	
	/** @var integer */
	protected static $deps_max_depth = 5;
	
	/** @var \Assetic\Cache\FilesystemCache */
	protected static $asset_cache;
	
	/** @var array */
	protected static $rendered_files = array();
	
	/** @var array */
	protected static $clear_cache_blacklist = array();
	
	public static function _init()
	{
		static::$development = !in_array(\Fuel::$env, array(\Fuel::PRODUCTION, \Fuel::STAGING));
		static::$base_url = \Config::get('base_url');
		
		// In production environments with APC, we don't need the full config. Everything is already built.
		if (static::$development)
			static::load_development_config();
		else
		{
			if (function_exists('apc_fetch'))
				static::$groups = apc_fetch('asset:'.APPPATH);
			if (empty(static::$groups))
				static::load_new_production_config();
		}
	}
	
	/**
	 * Load path information in development mode
	 */
	protected static function load_development_config()
	{
		\Config::load('assetbuilder', true);
		static::$groups = \Config::get('assetbuilder.groups');
		
		static::$asset_dir  = \Config::get('assetbuilder.asset_dir', static::$asset_dir);
		static::$js_dir  = \Config::get('assetbuilder.js_dir', static::$js_dir);
		static::$css_dir  = \Config::get('assetbuilder.css_dir', static::$css_dir);
		static::$less_dir  = \Config::get('assetbuilder.less_dir', static::$less_dir);
		static::$image_dir  = \Config::get('assetbuilder.image_dir', static::$image_dir);
		static::$cache_dir  = \Config::get('assetbuilder.cache_dir', static::$cache_dir);
		static::$deps_max_depth = \Config::get('assetbuilder.deps_max_depth', static::$deps_max_depth);
	}
	
	/**
	 * Load serialized group information and store to APC if possible
	 */
	public static function load_new_production_config()
	{
		static::load_development_config();
		
		static::$groups = unserialize(file_get_contents(DOCROOT.static::$cache_dir.'asset.cache'));
		if (function_exists('apc_store'))
			apc_store('asset:'.APPPATH, static::$groups);
	}

	/**
	 * Enables asset groups of the given name(s).
	 *
	 * @param mixed $group The group to enable, or array of groups
	 */
	public static function enable($groups)
	{
		static::asset_enabled($groups, true);
	}

	/**
	 * Disables asset groups of the given name(s).
	 *
	 * @param string $group The group to disable, or array of groups
	 */
	public static function disable($groups)
	{
		static::asset_enabled($groups, false);
	}


	/**
	 * Enables / disables an asset group.
	 *
	 * @param string $group The group to enable/disable, or array of groups
	 * @param bool $enabled True to enable group, false to disable
	 */
	protected static function asset_enabled($groups, $enabled)
	{
		if (!is_array($groups))
			$groups = array($groups);
		foreach ($groups as $group)
		{
			// If the group doesn't exist it's of no consequence
			if (!array_key_exists($group, static::$groups))
				continue;
			static::$groups[$group]['enabled'] = $enabled;
		}
	}
	
	/**
	 * Add a string containing javascript, which can be printed inline with
	 * js_render_inline().
	 *
	 * @param string $content The javascript to add
	 */
	public static function js_inline($content)
	{
		static::add_asset_inline('js', $content);
	}

	/**
	 * Add a JS function call, encoding parameters for display
	 */
	public static function js_inline_function()
	{
		$args = func_get_args();
		$function = array_shift($args);
		$args = array_map('json_encode', $args);
		static::js_inline("$function(".join(',', $args).");");
	}

	/**
	 * Add a string containing css, which can be printed inline with
	 * css_render_inline().
	 *
	 * @param string $content The css to add
	 */
	public static function css_inline($content)
	{
		static::add_asset_inline('css', $content);
	}

	/**
	 * Abstraction of js_inline() and css_inline().
	 *
	 * @param string $type 'css' / 'js'
	 * @param string $content The css / js to add
	 */
	protected static function add_asset_inline($type, $content)
	{
		array_push(static::$inline_assets[$type], $content);
	}
	
	/**
	 * Renders the javascript added through js_inline().
	 *
	 * @return string <script> tag containing the inline javascript
	 */
	public static function render_js_inline()
	{

		// the type attribute is not required for script elements under html5
		// @link http://www.w3.org/TR/html5/scripting-1.html#attr-script-type
		$attr = (\Html::$html5) ? array() : array( 'type' => 'text/javascript' );

		return html_tag('script', $attr, join(';'.PHP_EOL, static::$inline_assets['js']) );
	}

	/**
	 * Renders the css added through css_inline().
	 *
	 * @return string <style> tag containing the inline css
	 */
	public static function render_css_inline()
	{

		// the type attribute is not required for style elements under html5
		// @link http://www.w3.org/TR/html5/semantics.html#attr-style-type
		$attr = (\Html::$html5) ? array() : array( 'type' => 'text/css' );

		return html_tag('style', $attr, join(PHP_EOL, static::$inline_assets['css']) );
	}
	
	/**
	 * Render selected/all enabled JS groups with dependencies
	 * 
	 * @param string|array $groups Groups to render
	 * @param boolean $force If true, $groups must be set, and each group specified will be set to enabled first
	 */
	public static function render_js($groups = false, $force = false)
	{
		$files = (static::$development) ? static::development_render('js', $groups, $force) : static::production_render('js', $groups, $force);
		
		// the type attribute is not required for script elements under html5
		// @link http://www.w3.org/TR/html5/scripting-1.html#attr-script-type
		$attr = (\Html::$html5) ? array() : array( 'type' => 'text/javascript' );
			
		$ret = '';
		foreach ($files as $file_path)
		{
			if (!empty(static::$rendered_files[$file_path]))
				continue;
			
			$ret .= html_tag('script', array('src' => static::$base_url . $file_path) + $attr, '').PHP_EOL;
			static::$rendered_files[$file_path] = true;
		}
		
		return $ret;
	}
	
	/**
	 * Render selected/all enabled CSS groups with dependencies
	 * 
	 * @param string|array $groups Groups to render
	 * @param boolean $force If true, $groups must be set, and each group specified will be set to enabled first
	 */
	public static function render_css($groups = false, $force = false)
	{
		$files = (static::$development) ? static::development_render('css', $groups, $force) : static::production_render('css', $groups, $force);
		
		// the type attribute is not required for style or link[rel="stylesheet"] elements under html5
		// @link http://www.w3.org/TR/html5/links.html#link-type-stylesheet
		// @link http://www.w3.org/TR/html5/semantics.html#attr-style-type
		$attr = (\Html::$html5) ? array() : array( 'type' => 'text/css' );
			
		$ret = '';
		foreach ($files as $file_path)
		{
			if (!empty(static::$rendered_files[$file_path]))
				continue;
			
			$ret .= html_tag('link', array('rel' => 'stylesheet', 'href' => (static::is_local($file_path) ? static::$base_url . $file_path : $file_path)) +$attr).PHP_EOL;
			static::$rendered_files[$file_path] = true;
		}
		
		return $ret;
	}
	
	/**
	 * Development mode: build group files if necessary
	 * 
	 * @param string $type 'js' or 'css'
	 * @param mixed $groups
	 * @param boolean $force 
	 */
	protected static function development_render($type, $groups, $force)
	{
		if (empty($groups))
			$groups = array_keys(static::$groups);
		if (!is_array($groups))
			$groups = array($groups);
		
		$groups = static::resolve_deps($groups);
		
		$files = array();
		foreach ($groups as $group)
		{
			if (empty(static::$groups[$group]['enabled']) && !$force)
				continue;
			
			$group_files = ($type == 'js') ? static::build_js($group) : static::build_css($group);
			$files = array_merge($files, $group_files);
		}
		
		return array_filter($files);
	}
	
	/**
	 * Given a list of group names, adds to that list, in the appropriate places,
	 * and groups which are listed as dependencies of those group.
	 * Duplicate group names are not a problem, as a group is disabled when it's
	 * rendered.
	 *
	 * @param array $group_names Array of group names to check
	 * @param boolean $force if set to true, don't ignore disabled bottom-level groups
	 * @param int $depth Used by this function to check for potentially infinite recursion
	 * @return array List of group names with deps resolved
	 */

	protected static function resolve_deps($group_names, $force=false, $depth=0)
	{
		if ($depth > static::$deps_max_depth)
		{
			throw new AssetBuilder_Exception("Reached depth $depth trying to resolve dependencies. ".
					"You've probably got some circular ones involving ".implode(',', $group_names).". ".
					"If not, adjust the config key deps_max_depth.");
		}
		// Insert the dep just before what it's a dep for
		foreach ($group_names as $i => $group_name)
		{
			// Don't pay attention to bottom-level groups which are disabled
			if (empty(static::$groups[$group_name]['enabled']) && $depth == 0 && !$force)
				continue;
			
			// Otherwise, enable the group. Fairly obvious, as the whole point of
			// deps is to render disabled groups
			static::asset_enabled($group_name, true);
			if (empty(static::$groups[$group_name]['deps']))
				$deps = false;
			else
			{
				$deps = static::$groups[$group_name]['deps'];
				if (!is_array($deps))
					$deps = array($deps);
			}
			
			if (!empty($deps))
			{
				array_splice($group_names, $i, 0, static::resolve_deps($deps, $force, $depth+1));
			}
		}
		return array_unique($group_names);
	}
	
	/**
	 * @param string $type 'js' or 'css'
	 * @param mixed $groups
	 * @param boolean $force 
	 */
	protected static function production_render($type, $groups, $force)
	{
		if (empty($groups))
			$groups = array_keys(static::$groups);
		if (!is_array($groups))
			$groups = array($groups);
		
		$files = array();
		foreach ($groups as $group)
		{
			if (empty(static::$groups[$group]['enabled']) && !$force)
				continue;
		
			$files = array_merge($files, static::$groups[$group]['compiled_files']);
		}
		
		return $files;
	}
	
	/**
	 * Build all assets for use in production environment
	 */
	public static function build_production()
	{
		static::load_development_config();
		
		$files = array();
		foreach (static::$groups as $type=>$groups)
		{
			foreach ($groups as $group=>$info)
				$files[$type][$group] = ($type == 'js') ? static::build_js($group, true) : static::build_css($group, true);
		}
		
		// Now with rendered files, save config information so that not even dependency resolution is necessary for production
		$original_groups = \Config::get('assetbuilder.groups');
		$save_groups = array();
		$all_deps = array();
		foreach ($files as $type=>$groups)
		{
			foreach ($groups as $group=>$file)
			{
				$all_deps[$type][$group] = static::resolve_deps(array($group), true);
				foreach ($all_deps[$type][$group] as $group_i)
					$save_groups[$type][$group]['compiled_files'][] = $files[$type][$group_i];
				
				$save_groups[$type][$group] = array(
						'compiled_files' => array_unique($save_groups[$type][$group]['compiled_files']),
						'enabled' => (empty($original_groups[$type][$group]['enabled'])) ? false : true
					);
			}
		}
		
		/* When building in staging area, clearing the APC variable is a good move as well */
		if (function_exists('apc_delete'))
			apc_delete('asset:'.APPPATH);
		
		/* Clear cache of anything we didn't just generate */
		static::$clear_cache_blacklist = array_map('basename', array_merge(array_values($files['js']), array_values($files['css'])));
		static::clear_cache();

		file_put_contents(DOCROOT.static::$cache_dir.'asset.cache', serialize($save_groups));
		
		//var_dump($all_deps);
		//var_dump($save_groups);
	}
	
	/**
	 * @param string $group
	 * @param boolean $production
	 * @return array file paths (only one local, possibly many remote)
	 */
	protected static function build_js($group, $production=false)
	{
		$group_info = static::$groups[$group];
		if (empty($group_info['js']))
			return array();

		$js = new \Assetic\Asset\AssetCollection();
		$remote_files = array();
		
		foreach ($group_info['js'] as $file_name)
		{
			if (static::is_local($file_name))
				$js->add ( new \Assetic\Asset\FileAsset(static::$asset_dir . static::$js_dir . $file_name) );
			else
				$remote_files[] = $file_name;
		}
		
		if ($production)
			$js->ensureFilter( new \Assetic\Filter\Yui\JsCompressorFilter(__DIR__.'/../vendor/yuicompressor-2.4.7.jar', 'java'));
		
		return static::asset_cache($js, $group, 'js', $remote_files);
	}

	/**
	 * @param string $group
	 * @param boolean $production
	 * @return array file paths (only one local, possibly many remote)
	 */
	protected static function build_css($group, $production=false)
	{
		$group_info = static::$groups[$group];
		if (empty($group_info['css']) && empty($group_info['less']))
			return array();

		$remote_files = array();
		$css = new \Assetic\Asset\AssetCollection();
		$cache_salt = '';
		if (!empty($group_info['less']))
		{
			require_once(__DIR__.'/../vendor/lessc.inc.php');
			$filter = new \Assetic\Filter\LessphpFilter();
			foreach ($group_info['less'] as $file_name)
				$css->add( new \Assetic\Asset\FileAsset(DOCROOT . static::$asset_dir . static::$less_dir . $file_name, array($filter)) );
			
			// If any LESS file changes, we'll rebuild -- this isn't the greatest, but it works without being too complex (parsing for @import, etc.),
			// and efficiency isn't crucial here
			$cache_salt = static::cache_key( new \Assetic\Asset\GlobAsset( DOCROOT . static::$asset_dir . static::$less_dir . '/*.less'));
		}
		
		if (!empty($group_info['css']))
		{
			foreach ($group_info['css'] as $file_name)
			{
				if (static::is_local($file_name))
					$css->add ( new \Assetic\Asset\FileAsset(DOCROOT . static::$asset_dir . static::$css_dir . $file_name) );
				else
					$remote_files[] = $file_name;
			}
		}
		
		if ($production)
			$css->ensureFilter( new \Assetic\Filter\Yui\CssCompressorFilter(__DIR__.'/../vendor/yuicompressor-2.4.7.jar', 'java'));

		return static::asset_cache($css, $group, 'css', $remote_files, $cache_salt);
	}

	protected static function is_local($file)
	{
		return !preg_match('/^https?/', $file);
	}

	/**
	 * Assetic's AssetCache doesn't expose the cache key, so we'll do this ourselves
	 * 
	 * @param \Assetic\Asset\AssetInterface $asset
	 * @param string $group group name
	 * @param string $type 'js' or 'css'
	 * @param array $files list of remote files to load as well
	 * @param string $salt
	 * @return array
	 */
	protected static function asset_cache($asset, $group, $type, $files=array(), $salt='')
	{
		$cache = static::get_cache();
		$key = static::cache_key($asset, $group, $type, $salt);
		$files[]= static::$cache_dir . $key;
				
		if (!$cache->has($key))
			$cache->set($key, $asset->dump());
		
		return $files;
	}
	
	/**
	 * @return \Assetic\Cache\FilesystemCache
	 */
	protected static function get_cache()
	{
		if (!static::$asset_cache)
			static::$asset_cache = new \Assetic\Cache\FilesystemCache(DOCROOT . static::$cache_dir);
		
		return static::$asset_cache;
	}
	
	/**
	 * @param \Assetic\Asset\AssetInterface $asset
	 * @param string $type 'js' or 'css'
	 * @return string 
	 */
	protected static function cache_key($asset, $group='', $type='', $salt='')
	{
		$key  = $asset->getSourceRoot();
        $key .= $asset->getSourcePath();
        $key .= $asset->getTargetPath();
        $key .= $asset->getLastModified();

        foreach ($asset->getFilters() as $filter) {
            $key .= serialize($filter);
        }

        if ($values = $asset->getValues()) {
            asort($values);
            $key .= serialize($values);
        }

		if ($group)
			$group = "$group-";
        return $group . md5($key.$salt) . '.' . $type;
	}
	
	/**
	 * @return string
	 */
	public static function get_js_url()
	{
		return self::$base_url . self::$asset_dir . self::$js_dir;
	}

	/**
	 * @return string
	 */
	public static function get_css_url()
	{
		return self::$base_url . self::$asset_dir . self::$css_dir;
	}

	/**
	 * @return string
	 */
	public static function get_image_url()
	{
		return self::$base_url . self::$asset_dir . self::$image_dir;
	}

	/**
	 * Cleares all cache files last modified before $before.
	 *
	 * @param type $before Time before which to delete files. Defaults to 'now'.
	 *        Uses strtotime.
	 */
	public static function clear_cache($before = 'now')
	{
		static::clear_cache_base('*', $before);
	}

	/**
	 * Cleares all JS cache files last modified before $before.
	 *
	 * @param type $before Time before which to delete files. Defaults to 'now'.
	 *        Uses strtotime.
	 */
	public static function clear_js_cache($before = 'now')
	{
		static::clear_cache_base('*.js', $before);
	}

	/**
	 * Cleares CSS all cache files last modified before $before.
	 *
	 * @param type $before Time before which to delete files. Defaults to 'now'.
	 *        Uses strtotime.
	 */
	public static function clear_css_cache($before = 'now')
	{
		static::clear_cache_base('*.css', $before);
	}

	/**
	 * Base cache clear function.
	 *
	 * @param type $filter Glob filter to use when selecting files to delete.
	 * @param type $before Time before which to delete files. Defaults to 'now'.
	 *        Uses strtotime.
	 */
	protected static function clear_cache_base($filter = '*', $before = 'now')
	{
		$before = strtotime($before);
		$files = glob(DOCROOT.static::$cache_dir.$filter);
		foreach ($files as $file)
		{
			if (filemtime($file) < $before && !in_array(basename($file), static::$clear_cache_blacklist))
				unlink($file);
		}
	}
}

class AssetBuilder_Exception extends \FuelException {}