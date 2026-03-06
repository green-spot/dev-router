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
  // CSRF 対策: クロスオリジンリクエストを拒否
  $origin = $_SERVER["HTTP_ORIGIN"] ?? null;
  if($origin !== null) {
    $host = parse_url($origin, PHP_URL_HOST);
    // parse_url 失敗時や許可リスト外のホストを拒否（IPv6 の括弧を除去して比較）
    $normalizedHost = ($host !== null && $host !== false) ? trim($host, "[]") : null;
    if($normalizedHost === null || !in_array($normalizedHost, ["localhost", "127.0.0.1", "::1"], true)) {
      errorResponse("Forbidden", 403);
    }
  }

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
  if(!is_array($input)) {
    errorResponse("リクエストボディが不正です");
  }
  return $input;
}

function requireField(array $input, string $field): string {
  if(!isset($input[$field]) || $input[$field] === "") {
    errorResponse("{$field} は必須です");
  }
  return $input[$field];
}
