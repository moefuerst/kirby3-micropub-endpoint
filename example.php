<?php defined('DS') or define('DS', '/');
/*
* Micropub Endpoint for Kirby 3 example
*
* This example shows how you connect the endpoint to your Kirby setup. The endpoint
* handles all communication with the client, you decide where and how the incoming
* content is stored.
*
* Minimally, all you need to do is pass a config and a 'create' callback function that
* returns the URL of the newly created page (the example also shows callback functions
* for updating and deleting pages)
*
* You can put the code for your endpoint anywhere you want, for example at /micropub.php,
* or in a route:
*
*     'routes' => [
*         [
*             'pattern' => 'micropub',
*             'method' => 'GET|POST',
*             'action'  => function () {
*                 return \mof\Micropub\Endpoint::micropub([
*                     // = endpoint code
*                 ]);
*             }
*         ],
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
* Please note that this is just an example. This code will overwrite an already
* existing page when an incoming post has the same slug. It does not write all
* incoming content fields to the page, and so on.
*
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

$response = Endpoint::micropub([
    'config' => [
        'media-endpoint' =>  kirby()->urls()->base() . '/' . 'micropub-media-endpoint',
        'post-types'     =>  [
            [
                'type' => 'note',
                'name' => 'Quick note post'
            ],
            [
                'type' => 'photo',
                'name' => 'Photo post'
            ]
        ],
        'categories'     =>  [
            'foo',
            'bar'
        ]
    ],
    'create' => function ($post) {

        // Determine where the incoming post should go in your Kirby setup
        $postmap = [
            'note'  => [
                'parent'    => 'blog',
                'blueprint' => 'note'
            ],
            'photo'  => [
                'parent'    => 'photography',
                'blueprint' => 'image'
            ]
        ];

        // Reject posts that don't have a configuration in $postmap
        if(!isset($postmap[$post->type()])) return false;

        // Create a new page
        kirby()->impersonate('kirby');

        $newpage = page($postmap[$post->type()]['parent'])->createChild([
            'slug'     	=> $post->slug() ?? \Kirby\Toolkit\Str::random(8),
            'template' 	=> $postmap[$post->type()]['blueprint'],
            'draft' 	=> ($post->status() == 'listed' || $post->status() == 'unlisted') ? false : true
        ]);

        // Access the content of the incoming post with $post->fields().
        $tags = $post->fields()['category'] ?? [];

        // Write content to the new page
        $newpage = $newpage->save([
            'text' => $post->fields()['content'],
            'date' => $post->fields()['published'],
            'tags' => $tags
        ]);

        // Attachments can be accessed with $post->files()
        if ($post()->files()) {
            foreach ($post->files()->toArray() as $type => $files) {
                foreach ($files as $file) {
                    $file = $newpage->createFile([
                    	    'source'   => $file['tmp_name'],
                            'filename' => $file['name'],
                            'template' => 'image'  // etc, can add to $postmap
                    ]);
                }
            }
        }

        // Return the URL of our new page
        return $newpage->url();

    },
    'update' => function ($post) {

        // Set page to page object returned from endpoint
        $page = $post->page();

        // Do your $page->update() by checking $page->content() against $post->fields()
        // etc.

        return true;

    },
    'delete' => function ($post) {

        // 'Delete' post by turning the page into a draft
        // (or actually delete, it's your site...)
        if ($post->page()->unpublish()) {
            return true;
        }

    }
]);

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
