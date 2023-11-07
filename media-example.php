<?php defined('DS') or define('DS', '/');
/*
* Micropub Media Endpoint for Kirby 3 example
*
* This example shows how to set up a Media Endpoint.
*
* You can put this code anywhere you want, for example at /micropub-media.php, or in a route:
*
*     'routes' => [
*         [
*             'pattern' => 'micropub-media-endpoint',
*             'method' => 'POST',
*             'action'  => function () {
*                 return \mof\Micropub\Endpoint::media(
*                     kirby()->root('media') . '/temp',
*                     kirby()->urls()->media() . '/temp'
*                 );
*             }
*         ],
*     ]
*
*/

// Bootstrap Kirby from parent directory
// You need to set your base url
require dirname(__DIR__, 1) . '/kirby/bootstrap.php';
$kirby = new Kirby(
[
    'urls' => [
        'index'  => 'http://localhost/kirby/',
    ]
]
);

load([
  'mof\\Micropub\\Endpoint' => 'lib/Endpoint.php',
  'mof\\Micropub\\Request' => 'lib/Request.php',
  'mof\\Micropub\\Request\\IndieAuth' => 'lib/IndieAuth.php',
  'mof\\Micropub\\Error' => 'lib/Error.php'
], __DIR__);

use mof\Micropub\Endpoint;

$response = Endpoint::media(
    kirby()->root('media') . '/temp',
    kirby()->urls()->media() . '/temp'
);

var_dump($response);

/* Uncomment this to fake verify any token */
// function verifyMicropubAccessToken($bearer) {
//     return new \Kirby\Toolkit\Obj([
//     	'me' => kirby()->urls()->base(),
//     	'client_id' => 'https://micropub.rocks',
//     	'scope' => 'create update media',
//     	'issued_at' => time()
//     ]);
// }
