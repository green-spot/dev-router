<?php

/**
 * Tiny Pachira - 超軽量PHPマイクロフレームワーク
 * Pachira から group・プラグインを削ぎ落とした1ファイル版
 */


/* ============================================================
   例外
   ============================================================ */

class NextRoute extends \Exception {}


/* ============================================================
   Router
   ============================================================ */

class Router {
  private static $instance;
  private $map_get = [], $map_post = [], $map_request = [];
  private $middlewares = [];

  public static function getInstance(){
    if(!isset(self::$instance)) self::$instance = new self();
    return self::$instance;
  }

  public function route($path){
    switch(el($_SERVER, "REQUEST_METHOD")){
      case "GET":  $map = $this->map_get; break;
      case "POST": $map = $this->map_post; break;
      default:     $map = $this->map_request;
    }

    foreach($map as $routing){
      if(preg_match("/^".str_replace("/", "\\/", $routing->re)."$/", $path, $args)){
        array_shift($args);
        $args = array_map('urldecode', $args);

        try {
          if(empty($routing->middleware_names)){
            call_user_func_array($routing->fn, $args);
          }else{
            $this->apply_middlewares($routing->fn, $args, $routing->middleware_names);
          }
          return;
        } catch(NextRoute $e){}
      }
    }

    not_found();
  }

  private function apply_middlewares($fn, $args, $names){
    if(empty($names)){
      call_user_func_array($fn, $args);
    }else{
      $name = array_shift($names);
      $that = $this;
      $this->middlewares[$name](function()use($that, $fn, $args, $names){
        $that->apply_middlewares($fn, $args, $names);
      });
    }
  }

  public function get($re, $fn){
    $r = new Routing($re, $fn, "get", $this);
    $this->map_get[] = $r;
    return $r;
  }

  public function post($re, $fn){
    $r = new Routing($re, $fn, "post", $this);
    $this->map_post[] = $r;
    return $r;
  }

  public function request($re, $fn){
    $r = new Routing($re, $fn, "request", $this);
    $this->map_get[] = $r;
    $this->map_post[] = $r;
    $this->map_request[] = $r;
    return $r;
  }

  public function add_middleware($name, $fn){
    $this->middlewares[$name] = $fn;
  }
}


/* ============================================================
   Routing
   ============================================================ */

class Routing {
  public $re, $fn, $method, $router, $middleware_names = [];

  public function __construct($re, $fn, $method, $router){
    if(strpos($re, "@") === false){
      $this->re = $re;
      $this->fn = $fn;
    }else{
      list($re, $_query) = explode("@", $re);
      $query = [];
      parse_str($_query, $query);
      $this->re = $re;

      $this->fn = function(...$args)use($fn, $method, $query){
        $request = $method === "post" ? $_POST : $_GET;
        foreach($query as $k => $v){
          if(el($request, $k) !== $v) pass();
        }
        call_user_func_array($fn, $args);
      };
    }

    $this->method = $method;
    $this->router = $router;
  }

  public function use($names){
    if(is_array($names)){
      $this->middleware_names = array_merge($this->middleware_names, $names);
    }else{
      $this->middleware_names[] = $names;
    }
    return $this;
  }

  public function trailing_slash(){
    if(substr($this->re, -1) === "/"){
      $re = $this->re;
      $this->router->{$this->method}(substr($re, 0, -1), function()use($re){
        redirect($re, 301);
      });
    }
    return $this;
  }
}


/* ============================================================
   View
   ============================================================ */

class View {
  private static $dir;
  private static $vars = [];

  public static function set_dir($dir){ self::$dir = $dir; }

  public static function render($name, $vars=[]){
    if(!isset(self::$dir)) throw new \Exception('View directory not set.');
    $path = self::$dir . $name;
    if(!is_file($path)) $path .= ".php";
    if(is_file($path)){
      extract(array_merge(self::$vars, $vars));
      include $path;
    }
  }

  public static function set_var($key, $val){ self::$vars[$key] = $val; }
}


/* ============================================================
   ルーティング関数
   ============================================================ */

function get($re, $fn)       { return Router::getInstance()->get($re, $fn); }
function post($re, $fn)      { return Router::getInstance()->post($re, $fn); }
function request($re, $fn)   { return Router::getInstance()->request($re, $fn); }
function middleware($name, $fn){ Router::getInstance()->add_middleware($name, $fn); }
function pass()              { throw new NextRoute(); }
function not_found()         { header("Status: 404 Not Found"); }


/* ============================================================
   ビュー関数
   ============================================================ */

function view($name, $vars=[]){ View::render($name, $vars); }
function view_var($key, $val) { View::set_var($key, $val); }
function capture_view($name, $vars=[]){
  return capture(function()use($name, $vars){ view($name, $vars); });
}


/* ============================================================
   ユーティリティ
   ============================================================ */

function h($str){
  return htmlspecialchars($str);
}

function el($array, $key, $default=null){
  if(is_array($array))  return isset($array[$key]) ? $array[$key] : $default;
  if(is_object($array)) return isset($array->$key) ? $array->$key : $default;
  return $default;
}

function redirect($url, $code=302){
  switch($code){
    case 301: header("HTTP/1.1 301 Moved Permanently"); break;
    case 302: header("HTTP/1.1 302 Found"); break;
    case 303: header("HTTP/1.1 303 See Other"); break;
    case 307: header("HTTP/1.1 307 Temporary Redirect"); break;
    case "js": echo "<script>location.href='{$url}';</script>"; exit;
  }
  header("Location: {$url}");
  exit;
}

function capture($fn){
  ob_start();
  $fn();
  $output = ob_get_contents();
  ob_end_clean();
  return $output;
}


/* ============================================================
   起動
   ============================================================ */

function run($options=[]){
  if(isset($options["view_dir"])) View::set_dir($options["view_dir"]);

  $filter = el($options, "filter", []);
  $before = el($filter, "before");
  $after  = el($filter, "after");

  if($before) $before();

  $path = el($options, "path", el($_SERVER, "PATH_INFO", "/"));
  Router::getInstance()->route($path);

  if($after) $after();
}
