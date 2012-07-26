<?php
abstract class DaGdBaseClass {

  // Automatically escape stuff to prevent against xss.
  protected $escape = true;

  // Wrap the response in <pre>...</pre> in non-cli browsers.
  protected $wrap_pre = true;

  // Enable text-UA specific html stripping?
  protected $text_html_strip = true;

  // This is "global" for now, and probably needs to be refactored.
  protected $db_connection;

  // This contains matches that the router finds in the accessed URL.
  protected $route_matches = null;

  // Set text/plain by default for all text-useragent responses.
  protected $text_content_type = true;

  // This is used for DaGdHelpController to generate its list of commands.
  public static $__help__ = null;

  public function __construct() {
    global $__db_handler;
    $this->db_connection = $__db_handler;
  }

  public function setRouteMatches($matches=null) {
    $this->route_matches = $matches;
  }

  public function render() {
    return 'Override this method to make stuff happen!';
  }

  // Generate help for a given controller.
  // This needs a lot of work :)
  public function help() {
    $prefix = request_or_default('url_prefix', '/');
    $separator = request_or_default('url_separator', '/');
    $request_sep = request_or_default('url_request_sep', null);
    
    $return = '';
    if ($help = $this::$__help__) {
      $return .= '<h3>'.$help['summary']."</h3>\n";
      $return .= '<ul>';
      foreach ($help['examples'] as $example) {
        $return .= '<li>    ';
        if ($example['summary']) {
          $return .= $example['summary'].':   ';
        }
        $return .= $prefix.$help['path'];
        $arguments = $example['arguments'];
        if ($arguments) {
          if ($help['path']) {
            $return .= $separator;
          }
          $return .= implode($prefix, $arguments);
        }
        if (array_key_exists('request', $example)) {
          $iteration = 0;
          foreach ($example['request'] as $param => $param_example) {
            if($request_sep) {
              $return .= ($iteration === 0) ? $request_sep : $request_sep;
            } else {
              $return .= ($iteration === 0) ? '?' : '&';
            }
            $return .= $param.'='.$param_example;
            $iteration++;
          }
        }
        
        $return .= "</li>\n";
      }
      $return .= "</ul>\n";
    }
    return $return;
  }
  
  /*
   * A function that, when overridden, returns a certain version of a response
   * to a CLI browser. By default handle things normally.
   */
  public function renderCLI() {
    return strip_tags($this->render());
  }

  public function finalize() {
    $response = null;

    if ($this->text_html_strip && is_text_useragent()) {
      if ($this->text_content_type) {
        header('Content-type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
      }
      $response = $this->renderCLI();
    } else {
      $response = $this->render();
      if ($this->escape) {
        $response = htmlspecialchars($response);
      }
    }

    if ($save_response = request_or_default('save')) {
      $save_response = substr($save_response, 0, 10);
      // Save the response to recall it later.
      // See if the access string is taken or not.
      $query = $this->db_connection->prepare(
        'SELECT COUNT(*) FROM dagd.saved_responses WHERE access=?');
      $query->bind_param('s', $save_response);
      $query->execute();
      $query->bind_result($count);
      $query->fetch();
      $query->close();
      $taken = (bool)$count;

      if (!$taken) {
        $query = $this->db_connection->prepare(
          'INSERT INTO dagd.saved_responses(access, ip, response) '.
          'VALUES(?,?,?)');
        $query->bind_param(
          'sss',
          $save_response,
          $_SERVER['REMOTE_ADDR'],
          $response);
        if ($query->execute()) {
          $link = DaGdConfig::get('general.baseurl').'/recall/'.$save_response;
          $response = '<a href="'.$link.'">'.$link.'</a>';
        } else {
          return error400('An error occurred saving the output data.');
        }
      } else {
        return error400('That unique identifier (save=) has already been '.
                        'used.');
      }
    }

    if (!is_text_useragent() && $this->wrap_pre) {
      $response = '<pre>'.$response.'</pre>';
    }
    
    return $response;
  }
}
