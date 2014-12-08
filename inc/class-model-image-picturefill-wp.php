<?php
defined('ABSPATH') OR exit;
if(!class_exists('Model_Image_Picturefill_WP')){
  class Model_Image_Picturefill_WP{

    // Input variables
    private $application_model;
    private $DOMDocument;
    private $image;

    // Object variables
    private $image_attributes = array();
    private $image_attachment_data = array();
    private $srcset_array = array();
    private $upload_subdir = '';

    // Constructor, set the object variables
    public function __construct(Model_Application_Picturefill_WP $application_model, DOMDocument $DOMDocument, DOMNode $image){
      require_once(ABSPATH . 'wp-admin/includes/image.php');
      $this->application_model = $application_model;
      $this->DOMDocument = $DOMDocument;
      $this->image = $image;
      $this->set_image_attributes();
      $this->set_image_attachment_data($this->image_attributes['attachment_id']);
      $this->set_unadjusted_image_size();
      $this->set_srcset_array();
    }

    // Methods to retrieve object data
    public function get_image_attributes(){
      return $this->image_attributes;
    }

    public function get_image_attachment_data(){
      return $this->image_attachment_data;
    }

    public function get_image_xml(){
      return $this->DOMDocument->saveXML($this->image);
    }

    public function get_srcset_array(){
      return $this->srcset_array;
    }

    public function get_image_url($size){
      return $this->image_attachment_data[$size]['url'];
    }

    public function get_srcset_resolution($image_size){
      return ' ' . $this->image_attachment_data[$image_size]['width'] . 'w';
    }

    public function get_sizes_string($image_size = null){
      if(!empty($this->image_attributes['sizes']) || empty($this->image_attributes['attachment_id'])){
        return false;
      }
      if(empty($image_size)){
        $image_size = $this->image_attributes['size'];
      }
      if(!empty($this->image_attributes['sizes_name'])
      && !empty($this->application_model->registered_sizes[$this->image_attributes['sizes_name']]['sizes_string'])){
        return $this->application_model->registered_sizes[$this->image_attributes['sizes_name']]['sizes_string'];
      }
      if(!empty($this->application_model->image_attachments[$image_size]['sizes'])
      && !empty($this->application_model->registered_sizes[$this->application_model->image_attachments[$image_size]['sizes']]['sizes_string'])){
        return $this->application_model->registered_sizes[$this->application_model->image_attachments[$image_size]['sizes']]['sizes_string'];
      }

      return '(max-width: ' . $this->image_attributes['width'] . 'px) 100vw, ' . $this->image_attributes['width'] . 'px';
    }

    // Methods to set object data
    private function set_image_attributes(){
      $DOMNode_image = $this->image;

      $attributes = array(
        'attachment_id' => null,
        'src' => null,
        'alt' => null,
        'title' => null,
        'class' => null,
        'id' => null,
        'style' => null,
        'width' => null,
        'height' => null
      );

      foreach($DOMNode_image->attributes as $attr => $node){
        $attributes[$attr] = $node->nodeValue;
      }

      $attributes['attachment_id'] = $this->url_to_attachment_id(apply_filters('picturefill_wp_attachment_id_search_url', $attributes['src']));

      if(preg_match('/(?:(?:^|\s)size-)([\w|-]+)/', $attributes['class'], $size_match)){
        $attributes['size'] = $size_match[1];
      }

      if(preg_match('/(?:(?:^|\s)srcset-)([\w|-]+)/', $attributes['class'], $srcset_match)){
        $attributes['scrset_name'] = $srcset_match[1];
      }

      if(preg_match('/(?:(?:^|\s)sizes-)([\w|-]+)/', $attributes['class'], $sizes_match)){
        $attributes['sizes_name'] = $sizes_match[1];
      }

      $this->image_attributes = apply_filters('picturefill_wp_initial_image_attributes', $attributes);
    }

    private function set_unadjusted_image_size(){
      if(false !== $this->image_attributes['attachment_id']){
        if(empty($this->image_attributes['size'])){
          $this->image_attributes['size'] = $this->get_unadjusted_size($this->image_attachment_data, $this->image_attributes);
        }
      }
    }

    private function set_image_attachment_data($attachment_id){
      if(false !== $attachment_id){
        $image_attachment_metadata = wp_get_attachment_metadata($attachment_id);
        if(preg_match('/.*\/(?=[^\/]+\.(?:' . implode('|', $this->application_model->get_allowed_image_extensions()) . '))/', $image_attachment_metadata['file'], $upload_subdir_match)){
          $this->upload_subdir = $upload_subdir_match[0];
        }

        $image_attachment_data = $image_attachment_metadata['sizes'];
        $image_attachment_data['full'] = array(
          'file' => str_replace($this->upload_subdir, '', $image_attachment_metadata['file']),
          'width' => $image_attachment_metadata['width'],
          'height' => $image_attachment_metadata['height']
        );

        foreach($image_attachment_data as $image_size => $image_data){
          $image_attachment_data[$image_size]['url'] = $this->application_model->get_upload_base_url() . '/' . $this->upload_subdir . $image_data['file'];
        }

        $this->image_attachment_data = apply_filters('picturefill_wp_image_attachment_data', $image_attachment_data, $attachment_id);
      }else{
        $this->image_attachment_data = false;
      }
    }

    private function get_unadjusted_size(array $image_attachment_data, array $image_attributes){
      if(empty($image_attributes['width'])){
        $image_attributes_url_width_height = array($image_attributes['src'], $image_attributes['width'], $image_attributes['height']);
        $image_attributes_url_width_height = $this->get_image_width_height($image_attributes_url_width_height);
        $image_attributes['width'] = $image_attributes_url_width_height[1];
        $image_attributes['height'] = $image_attributes_url_width_height[2];

        $this->image_attributes = $image_attributes;
      }
      foreach($image_attachment_data as $attachment_size => $attachment_data){
        if($attachment_data['url'] === $image_attributes['src'] && false === strstr($attachment_size, '@2x')){
          return $attachment_size;
        }

        $attachment_data = $this->get_image_width_height($attachment_data);

        if($attachment_data['width'] >= $image_attributes['width'] && false === strstr($attachment_size, '@2x')){
          return $attachment_size;
        }
      }
      return false;
    }

    private function get_image_width_height(array $attachment_data){
      if(ini_get('allow_url_fopen')){
        $image_size = getimagesize($attachment_data['url']);

        if(!empty($image_size)){
          $attachment_data['width'] = $image_size[0];
          $attachment_data['height'] = $image_size[1];
        }
      }else{
        preg_match('/^(?:.+?)(?:-(\d+)x(\d+))\.(?:' . implode('|', $this->application_model->get_allowed_image_extensions()) . ')(?:(?:\?|#).+)?$/i', $attachment_data['url'], $image_width_height);
        if(!empty($image_width_height)){
          $attachment_data['width'] = $image_width_height[1];
          $attachment_data['width'] = $image_width_height[2];
        }
      }

      return $attachment_data;
    }

    private function set_srcset_array(){
      $srcset = array();

      if(false === $this->image_attributes['attachment_id']){
        return false;
      }

      if(!empty($this->image_attributes['size'])){

        if(!empty($this->image_attributes['srcset_name'])){
          $srcset = $this->application_model->get_srcset_by_handle($this->image_attributes['srcset_name']);
        }else{
          $srcset = $this->application_model->get_srcset_by_size($this->image_attributes['size']);
        }

        uasort($srcset, array($this, 'compare_image_widths'));

        foreach($srcset as $index => $image_size){
          if(!array_key_exists($image_size, $this->image_attachment_data)){
            unset($srcset[$index]);
          }
        }

        $this->srcset_array = apply_filters('picturefill_wp_srcset_array', $srcset, $this->image_attributes);
      }
    }

    private function compare_image_widths($image_size_a, $image_size_b){
      if(empty($this->image_attachment_data[$image_size_a])){
        return 1;
      }elseif(empty($this->image_attachment_data[$image_size_b])){
        return -1;
      }else{
        return $this->image_attachment_data[$image_size_a]['width'] - $this->image_attachment_data[$image_size_b]['width'];
      }
    }

	/**
	 * strips the base URL from a url
	 * 
	 * with a url of 'http://my_site.com/wp-content/uploads/2014/12/my_image.jpg' and a base url of 
	 * 'http://my_site.com/wp-content/uploads/' it should return '2014/12/my_image.jpg'.
	 * 
	 * @param	string	$url	the full url (including file details)
	 * @param	string	$baseurl	the part of the url to strip off
	 * 
	 * @return	string	the trailing portion of the url that comes after the base url 
	 */
	public static function strip_baseurl($url, $baseurl)	{
		$result = $url;
		$position = strrpos($url, $baseurl);
		if (!is_bool($position)) { // strrpos can return 0 if the baseurl is the first section of the url and false if it isn't there
			$result = substr($url, $position + strlen($baseurl));	
		}
		return $result;
	}
	
	/**
	 * Obtains the Wordpress ID from the wp_posts table that is associated with an URL
	 * 
	 * @param	string	$url	the url for which the ID is required
	 * 
	 * @return	string	the ID for the specified URL
	 */
	public static function id_from_url($url) {
		$prefix = Picturefill_WP::$wpdb->prefix;
		$upload_dir = wp_upload_dir();
		$baseurl = $upload_dir['baseurl'] . '/';
		// strip out the uploads folder's baseurl from the url to just return the path to the file e.g. if the base url is
		// 'http...my_domain.com/uploads' and the url is http...my_domain.com/uploads/2014/11/my_image.jpg' it will grab the '2014/11/my_image.jpg' portion.
		$url = static::strip_baseurl($url, $baseurl);
		
		// the following commented cpde also works but I it is a less efficient query and is not as safe (AFAICT)
		// $result = Picturefill_WP::$wpdb->get_col(Picturefill_WP::$wpdb->prepare("SELECT ID FROM " . $prefix . "posts" . " WHERE guid like '%s';", '%' . $url ));
		$result = Picturefill_WP::$wpdb->get_col(Picturefill_WP::$wpdb->prepare("SELECT Post_ID FROM " . $prefix . "postmeta" . " WHERE meta_key = '_wp_attached_file' and meta_value='%s';", $url ));
		return $result;
	}
		
    private function url_to_attachment_id($image_url){
      $original_image_url = $image_url;
      $image_url = preg_replace('/^(.+?)(-\d+x\d+)?\.(' . implode('|', $this->application_model->get_allowed_image_extensions()) . ')((?:\?|#).+)?$/i', '$1.$3', $image_url);
	  
	  $attachment_id = static::id_from_url($image_url);
	  if (empty($attachment_id)) {
	  	  // if the current URL doesn't map to a wp_posts entry try the unaltered URL 
		  $attachment_id = static::id_from_url($original_image_url);
		  if (empty($attachment_id)) {
		  	$attachment_id = false;
		  }
	  }
	  return $attachment_id[0];
	}
  }
}
