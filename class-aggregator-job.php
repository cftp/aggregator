<?php
/**
 * File contains the Aggregator Job class
 *
 * @package Aggregator
 */

/**
 * Defines an Aggregator job.
 *
 * Serves details of the post types, taxonomies and terms to be synced between
 * two specified blogs in a multisite network.
 */
class Aggregator_Job {

	/**
	 * ID of the portal blog
	 *
	 * @var int
	 */
	public $portal;

	/**
	 * ID of the source blog
	 *
	 * @var int
	 */
	public $source;

	/**
	 * ID of the post used to store sync settings
	 *
	 * @var int
	 */
	public $post_id;

	/**
	 * Custom title for this job
	 *
	 * @var string
	 */
	public $title;

	/**
	 * ID of the blog we're currently on
	 *
	 * @var int
	 */
	protected $current_blog;

	/**
	 * Post types to sync
	 *
	 * @var array
	 */
	protected $post_types = array();

	/**
	 * Taxonomies to sync
	 *
	 * @var array
	 */
	protected $taxonomies = array();

	/**
	 * Taxonomy terms to sync
	 *
	 * @var array
	 */
	protected $terms = array();

	/**
	 * Author ID for the user to whom pushed posts should be assigned
	 *
	 * @var int
	 */
	public $author = 1;

	/**
	 * An ID constructed as {$source_id}_{$post_id}
	 *
	 * @var string
	 */
	public $job_id;

	/**
	 * Set up all the things
	 *
	 * @param int $portal ID of the portal blog.
	 * @param int $source ID of the source blog.
	 */
	public function __construct( $portal, $source ) {

		// Validate portal and source IDs.
		if ( ! intval( $portal ) || ! intval( $source ) ) {
			return; }

		// Get the current blog ID ($this->current_blog).
		$this->current_blog = get_current_blog_id();

		// Get the details of the portal blog for this job and store in $this->portal.
		$this->portal = get_blog_details( $portal );

		// Get the details of the source blog for this job and store in $this->source.
		$this->source = get_blog_details( $source );

		// Switch to source blog if not already on it.
		$this->switch_to_blog( $source );

		// Fetch the post of type 'aggregator_job' where;
		// * post meta 'portal' is equal to $portal.
		// @codingStandardsIgnoreStart
		$jobs_query = new WP_Query( array(
			'post_type' => 'aggregator_job',
			'meta_key' => '_aggregator_portal',
			'meta_value' => $this->get_portal_blog_id(),
			'posts_per_page' => 1,
		) );
		// @codingStandardsIgnoreEnd
		if ( $jobs_query->have_posts() ) {

			while ( $jobs_query->have_posts() ) {
				$jobs_query->the_post();

				// Store the post ID for later.
				$this->post_id = get_the_ID();

				// Store the title for later, if we have one.
				$title = get_the_title();
				if ( 'Auto Draft' === $title || is_numeric( $title ) ) {
					$this->title = sprintf( __( 'Job #%d', 'aggregator' ), $this->post_id );
				} else {
					$this->title = $title;
				}

				// Store post types for later.
				$this->post_types = get_post_meta( get_the_ID(), '_aggregator_post_types', true );

				// Store taxonomies for later.
				$this->taxonomies = get_post_meta( get_the_ID(), '_aggregator_taxonomies', true );

				// Store terms for later.
				$terms = get_the_terms( get_the_ID(), $this->taxonomies );
				if ( false !== $terms ) {
					$this->terms = $this->set_terms( $terms );
				}

				// Retrieve the author ID for the portal.
				$this->author = get_post_meta( get_the_ID(), '_aggregator_author', true );
			}
		}

		$this->restore_current_blog();

		// Create a "Job ID" using source and post IDs.
		$this->job_id = $this->get_source_blog_id() . '_' . $this->post_id;

	}

	/**
	 * Get the name of the portal blog, for use in admin screens
	 *
	 * @return string Name of the portal blog
	 */
	public function get_portal_blog_name() {

		return $this->portal->domain;

	}

	/**
	 * Get the ID of the portal blog.
	 *
	 * @return int ID of the portal blog
	 */
	public function get_portal_blog_id() {

		return $this->portal->blog_id;

	}

	/**
	 * Sets the post meta field to hold the portal blog ID
	 *
	 * @return void
	 */
	public function set_portal_blog_id_meta() {

		// Maybe switch to blog.
		$this->switch_to_blog( $this->get_source_blog_id() );

		// Update the post meta.
		update_post_meta( $this->post_id, '_aggregator_portal', $this->get_portal_blog_id() );

		// Maybe restore current blog.
		$this->restore_current_blog();

	}

	/**
	 * Get the name of the source blog, for use in admin screens
	 *
	 * @return string Name of the source blog
	 */
	public function get_source_blog_name() {

		return $this->source->domain;

	}

	/**
	 * Get the ID of the source blog.
	 *
	 * @return int ID of the source blog
	 */
	public function get_source_blog_id() {

		return $this->source->blog_id;

	}

	/**
	 * Get the post types that will be synced under this job.
	 *
	 * @return array An array of post type names
	 */
	public function get_post_types() {

		return $this->post_types;

	}

	/**
	 * Set the post types that are to be included in this job
	 *
	 * @param array $post_types An array of post type names.
	 */
	public function set_post_types( $post_types ) {

		// Maybe switch to blog.
		$this->switch_to_blog( $this->get_source_blog_id() );

		// Update the post meta of $this->post_id.
		update_post_meta( $this->post_id, '_aggregator_post_types', $post_types );

		// Update $this->post_types.
		$this->post_types = $post_types;

		// Maybe restore current blog.
		$this->restore_current_blog();

	}

	/**
	 * Get a list of taxonomies to sync under this job
	 *
	 * @return array An array of taxonomy names
	 */
	public function get_taxonomies() {

		return $this->taxonomies;

	}

	/**
	 * Set the taxonomies that are to be included in this job
	 *
	 * @param array $taxonomies Array of taxonomy names.
	 */
	public function set_taxonomies( $taxonomies ) {

		// Maybe switch to blog.
		$this->switch_to_blog( $this->get_source_blog_id() );

		// Update the taxonomies meta field for $this->post_id.
		update_post_meta( $this->post_id, '_aggregator_taxonomies', $taxonomies );

		// Update $this->taxonomies.
		$this->taxonomies = $taxonomies;

		// Maybe restore current blog.
		$this->restore_current_blog();

	}

	/**
	 * Get a list of terms to sync under this job
	 *
	 * @param string $taxonomy The taxonomy whose terms to return.
	 *
	 * @return array An array of term names
	 */
	public function get_terms( $taxonomy = 'aggregator_term_count' ) {

		// $this-terms is an array of Term objects.
		if ( array_key_exists( $taxonomy, $this->terms ) ) {
			return $this->terms[ $taxonomy ]; }

	}

	/**
	 * Returns the number of terms set to be synced
	 *
	 * @return int Count of terms
	 */
	public function get_term_count() {

		$term_count = 0;

		foreach ( $this->terms as $terms ) {
			$term_count = $term_count + count( $terms );
		}

		return $term_count;

	}

	/**
	 * Store the terms allowed for this job.
	 *
	 * We want our stored term array to be in the format;
	 * 	array(
	 * 		'taxonomy_name' => array(
	 * 			... WP_Term objects ...
	 * 		)
	 * 	)
	 *
	 * @param array $terms Array of WP_Term objects.
	 *
	 * @return array $store_terms Array of terms, grouped by taxonomy
	 */
	protected function set_terms( $terms ) {

		// Store terms.
		$store_terms = array();

		foreach ( $terms as $term ) {
			$store_terms[ $term->taxonomy ][] = $term;
		}

		return $store_terms;

	}

	/**
	 * Get the author to use on the portal site for all pushed posts
	 *
	 * @return int ID of the author
	 */
	public function get_author() {

		return $this->author;

	}

	/**
	 * Set the author that is to be assigned to pushed posts on the portal
	 *
	 * @param int $author_id ID of an author on the portal site.
	 *
	 * @return bool Success (true) or failure (false)
	 */
	public function set_author( $author_id ) {

		// Check it's an integer.
		if ( ! intval( $author_id ) ) {
			return; }

		// Maybe switch to blog.
		$this->switch_to_blog( $this->get_source_blog_id() );

		// Update the taxonomies meta field for $this->post_id.
		update_post_meta( $this->post_id, '_aggregator_author', $author_id );

		// Update $this->author.
		$this->author = $author_id;

		// Maybe restore current blog.
		$this->restore_current_blog();

	}

	/**
	 * Get the edit link for this post
	 *
	 * @todo switch_to_blog
	 *
	 * @return string The edit URL
	 */
	public function get_edit_post_link() {

		// Maybe switch to blog.
		$this->switch_to_blog( $this->source->blog_id );

		// Get the edit link.
		$url = get_edit_post_link( $this->post_id );

		// Add the portal ID as a parameter to the URL.
		$url = add_query_arg( 'portal', $this->get_portal_blog_id(), $url );

		// Switch back.
		$this->restore_current_blog();

		return esc_url( $url );

	}

	/**
	 * Get the delete link for this post
	 *
	 * @todo switch_to_blog
	 *
	 * @return string The delete URL
	 */
	public function get_delete_post_link() {

		$this->switch_to_blog( $this->source->blog_id );

		$url = get_delete_post_link( $this->post_id, '', true );

		$this->restore_current_blog();

		return esc_url( $url );

	}

	/**
	 * Helper for switch_to_blog().
	 *
	 * @param int $blog_id ID of the blog to switch to.
	 */
	protected function switch_to_blog( $blog_id ) {

		if ( $this->current_blog !== $blog_id ) {
			// @codingStandardsIgnoreStart
			switch_to_blog( $blog_id );
			// @codingStandardsIgnoreEnd
		}

	}

	/**
	 * Helper for restore_current_blog().
	 */
	protected function restore_current_blog() {

		if ( get_current_blog_id() !== $this->current_blog ) {
			restore_current_blog();
		}

	}

	/**
	 * Set the ID of the post for later use.
	 *
	 * @param int $post_id ID of the post.
	 */
	public function set_post_id( $post_id ) {

		// Set the post ID.
		$this->post_id = intval( $post_id );

	}

	/**
	 * Update our network options, indicating sync site partnerships.
	 *
	 * Sets/update the network option telling us which sources a portal will receive posts from, and
	 * our network option denoting which portals a source will push to.
	 *
	 * @param string $action The action to perform - add|delete.
	 */
	public function update_network_options( $action = 'add' ) {

		// Validate.
		$portal = intval( $this->get_portal_blog_id() );
		$source = intval( $this->get_source_blog_id() );

		// Get list of existing sources for this portal.
		$sources = get_site_option( "aggregator_{$portal}_source_blogs", array() );

		// Get list of existing portals for this source.
		$portals = get_site_option( "aggregator_{$source}_portal_blogs", array() );

		// Decide what to do.
		switch ( $action ) {

			// Remove sites.
			case 'delete':

				// Delete this source, if present.
				$source_found = array_search( $source, $sources );
				if ( false !== $source_found ) {
					unset( $sources[ $source_found ] ); }

				// Delete this portal if present.
				$portal_found = array_search( $portal, $portals );
				if ( false !== $portal_found ) {
					unset( $portals[ $portal_found ] ); }

				break;

			// Add sites.
			case 'add':

				// Add this source, if not already added.
				if ( ! in_array( $source, $sources, true ) ) {
					$sources[] = $source; }

				// Add this portal if not already added.
				if ( ! in_array( $portal, $portals, true ) ) {
					$portals[] = $portal; }

				break;

		}

		// Update the options with our new values.
		$sources = update_site_option( "aggregator_{$portal}_source_blogs", $sources );
		$portals = update_site_option( "aggregator_{$source}_portal_blogs", $portals );

	}

	/**
	 * Removes all relevant meta data relating to this job
	 */
	public function delete_job() {

		// Switch to the source blog.
		$this->switch_to_blog( $this->get_source_blog_id() );

		// Delete the job post.
		wp_delete_post( $this->post_id );

		// Switch back.
		$this->restore_current_blog();

		// Update the network options.
		$this->update_network_options( 'delete' );

	}
}
