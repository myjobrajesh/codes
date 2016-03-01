<?php
- First install custom permalinks plugin and then overwrite below functions.

/**
* Action to redirect to the custom permalink
*
* @package CustomPermalinks
* @since 0.1
*/
function custom_permalinks_redirect() {

// Get request URI, strip parameters
$url = parse_url(get_bloginfo(‘url’));
$url = isset($url[‘path’]) ? $url[‘path’] : ”;
$request = ltrim(substr($_SERVER[‘REQUEST_URI’], strlen($url)),’/’);
if ( ($pos=strpos($request, “?”)) ) $request = substr($request, 0, $pos);
//needs to add for home page
if(!$request) {
    return ;
}
if(strpos($request, “?”)===0) return ;//for order by sorting
global $wp_query;
$custom_permalink = ”;
$original_permalink = ”;

// If the post/tag/category we’re on has a custom permalink, get it and check against the request
if ( is_single() || is_page() ) {
    //needs to add
    $post = $wp_query->post;

    $custom_permalink = get_post_meta( $post->ID, ‘custom_permalink’, true );
    $original_permalink = ( $post->post_type == ‘page’ ? custom_permalinks_original_page_link( $post->ID ) : custom_permalinks_original_post_link( $post->ID ) );
} else if ( is_tag() || is_category() ) {
    $theTerm = $wp_query->get_queried_object();
    $custom_permalink = custom_permalinks_permalink_for_term($theTerm->term_id);
    $original_permalink = (is_tag() ? custom_permalinks_original_tag_link($theTerm->term_id) :
    custom_permalinks_original_category_link($theTerm->term_id));
}

if ( $custom_permalink &&
(substr($request, 0, strlen($custom_permalink)) != $custom_permalink ||
$request == $custom_permalink.”/” ) ) {
// Request doesn’t match permalink – redirect
    $url = $custom_permalink;
    
    if ( substr($request, 0, strlen($original_permalink)) == $original_permalink &&
    trim($request,’/’) != trim($original_permalink,’/’) ) {
    // This is the original link; we can use this url to derive the new one
        $url = preg_replace(‘@//*@’, ‘/’, str_replace(trim($original_permalink,’/’), trim($custom_permalink,’/’), $request));
        $url = preg_replace(‘@([^?]*)&@’, ‘\1?’, $url);
    }
    
    // Append any query compenent
    $url .= strstr($_SERVER[‘REQUEST_URI’], “?”);
    
    wp_redirect( home_url().”/”.$url, 301 );
    exit();
    }
}

/**
 * Filter to rewrite the query if we have a matching post
 *
 * @package CustomPermalinks
 * @since 0.1
 */
function custom_permalinks_request($query) {
  global $wpdb;
  global $_CPRegisteredURL;

  // First, search for a matching custom permalink, and if found, generate the corresponding
  // original URL
  
  $originalUrl = NULL;
  
  // Get request URI, strip parameters and /'s
  $url = parse_url(get_bloginfo('url'));
  $url = isset($url['path']) ? $url['path'] : '';
  $request = ltrim(substr($_SERVER['REQUEST_URI'], strlen($url)),'/');
  $request = (($pos=strpos($request, '?')) ? substr($request, 0, $pos) : $request);
  $request_noslash = preg_replace('@/+@','/', trim($request, '/'));
  if ( !$request ) return $query;
  
  // Queries are now WP3.9 compatible (by Steve from Sowmedia.nl)
    $sql = $wpdb->prepare("SELECT $wpdb->posts.ID, $wpdb->postmeta.meta_value, $wpdb->posts.post_type FROM $wpdb->posts  ".
              "LEFT JOIN $wpdb->postmeta ON ($wpdb->posts.ID = $wpdb->postmeta.post_id) WHERE ".
              "  meta_key = 'custom_permalink' AND ".
              "  meta_value != '' AND ".
              "  ( LOWER(meta_value) = LEFT(LOWER('%s'), LENGTH(meta_value)) OR ".
              "    LOWER(meta_value) = LEFT(LOWER('%s'), LENGTH(meta_value)) ) ".
              "  AND post_status != 'trash' AND post_type != 'nav_menu_item'".
              " ORDER BY LENGTH(meta_value) DESC, ".
              " FIELD(post_status,'publish','private','draft','auto-draft','inherit'),".
              " FIELD(post_type,'post','page'),".
              "$wpdb->posts.ID ASC  LIMIT 1",
      $request_noslash,
      $request_noslash."/"
        );

  $posts = $wpdb->get_results($sql);
  if ( $posts ) {
    // A post matches our request
    
    // Preserve this url for later if it's the same as the permalink (no extra stuff)
    if ( $request_noslash == trim($posts[0]->meta_value,'/') ) 
      $_CPRegisteredURL = $request;
        
    $originalUrl =  preg_replace( '@/+@', '/', str_replace( trim( strtolower($posts[0]->meta_value),'/' ),
                  ( $posts[0]->post_type == 'page' ? 
                      custom_permalinks_original_page_link($posts[0]->ID) 
                      : custom_permalinks_original_post_link($posts[0]->ID) ),
                   strtolower($request_noslash) ) );
  }

  if ( $originalUrl === NULL ) {
      // See if any terms have a matching permalink
    $table = get_option('custom_permalink_table');
    if ( !$table ) return $query;

    foreach ( array_keys($table) as $permalink ) {
      if ( ($permalink == substr($request_noslash, 0, strlen($permalink)) ||
           $permalink == substr($request_noslash."/", 0, strlen($permalink)))
          && ($permalink == $request_noslash || $permalink == $request_noslash."/") ) {//raj
        $term = $table[$permalink];
        
        // Preserve this url for later if it's the same as the permalink (no extra stuff)
        if ( $request_noslash == trim($permalink,'/') ) 
          $_CPRegisteredURL = $request;
        
        if ( $term['kind'] == 'category') {
          $originalUrl = str_replace(trim($permalink,'/'),
                           custom_permalinks_original_category_link($term['id']),
                         trim($request,'/'));
        } else {//raj
         $originalUrl = str_replace(trim($permalink,'/'),
                           custom_permalinks_original_tag_link($term['id']),
                         trim($request,'/'));
        if(!$originalUrl) {//product category
          $originalUrl = 'product-category/'.$permalink;
        }
        }
      }
    }
  }

  if ( $originalUrl !== NULL ) {
    $originalUrl = str_replace('//', '/', $originalUrl);
    
    if ( ($pos=strpos($_SERVER['REQUEST_URI'], '?')) !== false ) {
      $queryVars = substr($_SERVER['REQUEST_URI'], $pos+1);
      $originalUrl .= (strpos($originalUrl, '?') === false ? '?' : '&') . $queryVars;
    }
    
    // Now we have the original URL, run this back through WP->parse_request, in order to
    // parse parameters properly.  We set $_SERVER variables to fool the function.
    $oldRequestUri = $_SERVER['REQUEST_URI']; $oldQueryString = $_SERVER['QUERY_STRING'];
    $_SERVER['REQUEST_URI'] = '/'.ltrim($originalUrl,'/');
    $_SERVER['QUERY_STRING'] = (($pos=strpos($originalUrl, '?')) !== false ? substr($originalUrl, $pos+1) : '');
    parse_str($_SERVER['QUERY_STRING'], $queryArray);
    $oldValues = array();
    if ( is_array($queryArray) )
    foreach ( $queryArray as $key => $value ) {
      $oldValues[$key] = $_REQUEST[$key];
      $_REQUEST[$key] = $_GET[$key] = $value;
    }

    // Re-run the filter, now with original environment in place
    remove_filter( 'request', 'custom_permalinks_request', 'edit_files', 1 );
    global $wp;
    $wp->parse_request();
    $query = $wp->query_vars;
    add_filter( 'request', 'custom_permalinks_request', 'edit_files', 1 );

    // Restore values
    $_SERVER['REQUEST_URI'] = $oldRequestUri; $_SERVER['QUERY_STRING'] = $oldQueryString;
    foreach ( $oldValues as $key => $value ) {
      $_REQUEST[$key] = $value;
    }
  }

  return $query;
}


/* create this function for woocommerce */
function custom_permalinks_save_product_category($id) {
    if ( !isset($_REQUEST[‘custom_permalinks_edit’]) || isset($_REQUEST[‘post_ID’]) ) return;
    $newPermalink = ltrim(stripcslashes($_REQUEST[‘custom_permalink’]),”/”);

    if ( $newPermalink == custom_permalinks_original_category_link($id) )
        $newPermalink = ”;
    $term = get_term($id, ‘category’);
    custom_permalinks_save_term($term, str_replace(‘%2F’, ‘/’, urlencode($newPermalink)));
}

/* add this action for woocommerce */
add_action( ‘edit_term’, ‘custom_permalinks_save_product_category’ );
add_action( ‘save_term’, ‘custom_permalinks_save_product_category’ );
add_filter(‘term_link’, ‘custom_permalinks_term_link’, 10, 3);
