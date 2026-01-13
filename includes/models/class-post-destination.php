<?php
/**
 * Post destination class.
 * 
 * The `Post_Destination` class represents a single post or a group of posts targeted 
 * by a distribution. It extends the base `Destination` class and adds properties that 
 * control how a post is exported, how conflicts are resolved and how imports are handled.  
 * Use this class when you need fine‑grained control over the behaviour of individual 
 * posts during distribution.
 * 
 * Upon instantiation a `Post_Destination` instance stores the post identifier in its 
 * `$ID` property. The `$export_arguments` property holds an array of settings passed 
 * to the `Prepared_Post` object. You can specify whether to append nested posts, 
 * export an entire post type, include all terms, resolve navigation links, include 
 * translations or provide additional query arguments. These options let you tailor the 
 * exported content to the needs of the destination site.
 * 
 * The `$conflict_action` property determines what happens when a post with the same 
 * identifier exists on the destination site. The default `'keep'` action retains the 
 * existing post and inserts a new copy with a new ID. You can instead choose `'replace'` 
 * to overwrite the existing post or `'skip'` to ignore the conflicting post entirely.  
 * Meanwhile, the `$import_action` property defines how the post should be treated upon 
 * import. The default `'update'` inserts or updates the post if it already exists.  
 * Alternatives include `'draft'` to set the post to draft, `'trash'` to move it to 
 * the trash or `'delete'` to remove it permanently. These settings empower you to 
 * manage imported content responsibly.
 * 
 * The `set_properties` method accepts an array of key‑value pairs and assigns each 
 * value to the corresponding property on the object. Use this method to update export 
 * arguments, conflict actions or import actions after the object has been created.  
 * Since this class inherits from `Destination` it also gains the basic properties 
 * of ID, timestamp and status, although only the ID is explicitly defined here.
 * 
 * @since 2.17.0
 */

namespace Contentsync\Distribution;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

#[AllowDynamicProperties]
class Post_Destination extends Destination {

	/**
	 * Post ID.
	 * 
	 * @var int
	 */
	public $ID;

	/**
	 * The arguments used to export a post.
	 * 
	 * This will be passed to the Prepared_Post object:
	 * @see Prepared_Post->export_arguments
	 * 
	 * @var array
	 *    @property bool  append_nested   Append nested posts to the export.
	 *    @property bool  whole_posttype  Export the whole post type.
	 *    @property bool  all_terms       Export all terms of the post.
	 *    @property bool  resolve_menus   Resolve navigation links to custom links.
	 *    @property bool  translations    Include translations of the post.
	 *    @property array query_args      Additional query arguments.
	 */
	public $export_arguments;

	/**
	 * Conflict action: What to do if a conflicting post already exists.
	 * 
	 * This will be passed to the Prepared_Post object:
	 * @see Prepared_Post->conflict_action
	 * 
	 * @var string 'keep|replace|skip'
	 *    @default 'keep'    Keep the existing post and insert the new one with a new ID.
	 *    @value   'replace' Replace the existing post with the new one.
	 *    @value   'skip'    Skip the post if a conflicting post already exists.
	 */
	public $conflict_action;

	/**
	 * Import action: What to do with a post on/after import.
	 * 
	 * This will be passed to the Prepared_Post object:
	 * @see Prepared_Post->import_action
	 * 
	 * @var string 'insert|draft|trash|delete'
	 *    @default 'update'  Insert or update the post if it already exists.
	 *    @value   'draft'   Set the post to draft.
	 *    @value   'trash'   Move the post to trash.
	 *    @value   'delete'  Delete the post permanently.
	 */
	public $import_action;

	/**
	 * Set properties.
	 */
	public function set_properties( $properties ) {
		foreach ( $properties as $property => $value ) {
			$this->$property = $value;
		}
	}
}