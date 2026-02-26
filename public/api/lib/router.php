<?php
/**
 * 最小ルーター + リクエストヘルパー
 */

$_routes = [];

function get($path, $fn)    { $GLOBALS["_routes"][] = ["GET", $path, $fn]; }
function post($path, $fn)   { $GLOBALS["_routes"][] = ["POST", $path, $fn]; }
function put($path, $fn)    { $GLOBALS["_routes"][] = ["PUT", $path, $fn]; }
function delete($path, $fn) { $GLOBALS["_routes"][] = ["DELETE", $path, $fn]; }

function dispatch() {
  $method = $_SERVER["REQUEST_METHOD"];
  $path = $_SERVER["PATH_INFO"] ?? "/";

  foreach($GLOBALS["_routes"] as [$m, $re, $fn]) {
    if($m === $method && preg_match("#^{$re}$#", $path, $args)) {
      array_shift($args);
      $fn(...array_map("urldecode", $args));
      return;
    }
  }

  errorResponse("Not Found", 404);
}

function jsonInput(): array {
  $input = json_decode(file_get_contents("php://input"), true);
  if(!$input) {
    errorResponse("リクエストボディが不正です");
  }
  return $input;
}

function requireField(array $input, string $field): string {
  if(empty($input[$field])) {
    errorResponse("{$field} は必須です");
  }
  return $input[$field];
}
