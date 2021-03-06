<?php

/**
 * AD-X
 *
 * Licensed under the BSD (3-Clause) license
 * For full copyright and license information, please see the LICENSE file
 *
 * @copyright		2012-2013 Robert Rossmann
 * @author			Robert Rossmann <rr.rossmann@me.com>
 * @link			https://github.com/Alaneor/AD-X
 * @license			http://choosealicense.com/licenses/bsd-3-clause		BSD (3-Clause) License
 */


namespace ADX\Core;

use ADX\Enums;
use ADX\Core\Query as q;

/**
 * This class is responsible for managing the directory schema
 *
 * @see			<a href="http://msdn.microsoft.com/en-us/library/windows/desktop/ms674984%28v=vs.85%29.aspx">MSDN - Active Directory Schema</a>
 */
class Schema
{
	/**
	 * Contains schema objects loaded from files for fast runtime access
	 *
	 * This ensures that if multiple objects request the same attribute schema,
	 * the Schema class will have to load that file only once and then it will
	 * serve that data from memory.
	 *
	 * @var array
	 */
	protected static $runtime_cache = array();

	/**
	 * Folder where the schema is going to be stored.
	 *
	 * This folder will <b>always</b> be relative to the Schema.php file.
	 *
	 * @var			string
	 */
	protected static $schema_dir = 'Schema';

	/**
	 * These attributes will be loaded about the schema object
	 *
	 * @var			array
	 */
	protected static $attribute_properties = [
		'ldapdisplayname',
		'attributesyntax',
		'omsyntax',
		'issinglevalued',
		'rangelower',
		'rangeupper',
		'systemflags',
	];

	protected static $class_properties = [
		'ldapdisplayname',
		'rdnattid',
		'subclassof',
		'allowedattributes',
		'systemonly',
	];

	final private function __construct() {}


	/**
	 * Is the Directory schema currently cached?
	 *
	 * @return		boolean		A boolean value identifying the presence or absence of the schema cache
	 */
	public static function isCached()
	{
		return file_exists( ADX_ROOT_PATH . static::$schema_dir . ADX_DS . '.lockfile' ) ? true : false;
	}

	/**
	 * Build the local schema from server, using provided {@link Link}
	 *
	 * This method creates the locally cached schema from all schema objects located in
	 * CN=Schema,CN=Configuration ( or similar, depending on domain configuration ).
	 * The location of the Schema is taken from the RootDSE's "schemanamingcontext" entry.
	 *
	 * @param		Link		The Link object to be used to connect to directory server
	 *
	 * @return		void
	 */
	public static function build( Link $adxLink )
	{
		// Define where to store the schema definition
		$schemaDir = ADX_ROOT_PATH . static::$schema_dir;

		// Prepare the schema folder either by cleaning it's contents or by creating it
		file_exists( $schemaDir ) ? static::flush() : mkdir( $schemaDir, 0755 );

		$schema_base = $adxLink->rootDSE->schemaNamingContext(0); // schemanamingcontext is loaded by default

		// Create the tasks...
		// I have to create them separately because I have two different
		// sets of attributes that I need to have loaded
		$tasks[0] = new Task( Enums\Operation::OpList, $adxLink );
		$tasks[0]	->use_pages( 500 )
					->base( $schema_base )
					->filter( q::a( ['objectclass' => 'attributeschema'] ) )		// Attribute definitions
					->attributes( static::$attribute_properties );

		$tasks[1] = new Task( Enums\Operation::OpList, $adxLink );
		$tasks[1]	->use_pages( 500 )
					->base( $schema_base )
					->filter( q::a( ['objectclass' => 'classschema'] ) )			// Class definitions
					->attributes( static::$class_properties );

		// And retrieve the schema objects!
		foreach ( $tasks as $task )
		{
			// Do not use the Task::run_paged() method as it will very likely hit the memory execution limit.
			// Instead, mimick that method's functionality and handle the data for each page separately
			do
			{
				$objects = $task->run();

				if ( $objects )
				{
					// Loop through the schema objects and save them to a local file, named
					// after the attribute they represent
					foreach ( $objects as $object )
					{
						$filename = $object->ldapDisplayName(0) . ".json";
						$data = $object->json();

						file_put_contents( $schemaDir. ADX_DS . strtolower( $filename ), $data );
					}
				}
				else throw new Exception( 'Maximum number of referrals reached' );
			}
			while ( ! $task->complete );
		}

		// Generate a lockfile to identify the fact that the Schema is present
		file_put_contents( $schemaDir . ADX_DS . '.lockfile', time() );
	}

	/**
	 * Clear the whole Schema cache folder
	 *
	 * This method clears all data from the Schema cache folder.
	 *
	 * @return		void
	 */
	public static function flush()
	{
		array_map( 'unlink', glob( ADX_ROOT_PATH . static::$schema_dir . ADX_DS . '*.json' ) );
	}

	/**
	 * Get the cached data about a schema object
	 *
	 * Use this function to get the data that is cached in the Schema cache
	 * for a specified attribute or object.
	 *
	 * @param		string		ldap name of the attribute / object you want the Schema data for
	 *
	 * @return		array|null	The Schema data for the specified attribute / object or null if that object is not present in the schema cache
	 */
	public static function get( $schema_object )
	{
		// Convert to lowercase...
		$schema_object = strtolower( $schema_object );

		// Check if this schema object is already loaded in the runtime cache and return it if so
		if ( array_key_exists( $schema_object, static::$runtime_cache ) ) return static::$runtime_cache[$schema_object];

		// No, it is not - load it from the file and store it in runtime cache for future re-use

		$schema_file = ADX_ROOT_PATH . static::$schema_dir . ADX_DS . "$schema_object.json";

		// Check if this file has been cached and load it if so
		if ( file_exists( $schema_file ) )
		{
			// Got the schema file, so let's load it and read the properties
			$json	= file_get_contents( $schema_file );
			$data	= json_decode( $json, true, 512, JSON_BIGINT_AS_STRING );

			// Store the data in runtime cache
			static::$runtime_cache[$schema_object] = $data;

			return $data;
		}
		else return null;	// This schema object is not present in local schema cache - nothing to return...
	}
}
