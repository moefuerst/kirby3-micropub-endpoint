# Micropub-Endpoint for Kirby 3

A [Micropub](https://www.w3.org/TR/micropub/) endpoint you can connect to your Kirby 3 setup.

This is heavily inspired by sebsel's solution in the [Indieweb Toolkit for Kirby 2](https://github.com/sebsel/indieweb-toolkit) and follows its philosophy.

The code in this repository is intended to serve as an example of
how to use the [Micropub Request for Kirby 3 class](https://github.com/moefuerst/kirby3-micropub-request).


## Who is this for?
This is *not* a fully functional Kirby 3 plugin. A Micropub endpoint is a hard thing to keep generic. The intention of the Micropub standard is to provide a uniform way to publish all kinds of content to a website. However, every site has a different way of storing that content, especially when a flexible CMS like Kirby is used.

Therefore, it is probably a good idea to customize a Micropub setup to the project at hand. The idea behind this code is to streamline the things that *are* the same on a Micropub server. It will process incoming Micropub requests, handle authentication, manage all communication with the client, and provide you with consistent data that you know how to work with when you know how to work with Kirby. You can then use this data to create, update or delete pages, write content to a database, `JSON` or `YAML` files, etc— whatever fits the site in question.


## Features
- [Micropub Endpoint](https://indieweb.org/micropub-endpoint) that supports creating, updating, and deleting posts
- [Media endpoint](https://indieweb.org/micropub_media_endpoint) to handle attachments
- Supports some [Micropub extensions](https://indieweb.org/Micropub-extensions): slug, post status, and visibility properties; query for supported vocabulary and category/tag list
- [*Post Type Discovery* algorithm](https://indieweb.org/post-type-discovery)
- All features of the [Micropub Request for Kirby 3 class](https://github.com/moefuerst/kirby3-micropub-request)


## Usage
Load the classes in `lib/` using your favourite method, for example using [Kirby's built-in autoloader](https://getkirby.com/docs/reference/templates/helpers/load):

```php
load([
  'mof\\Micropub\\Endpoint' => 'lib/Endpoint.php',
  'mof\\Micropub\\Request' => 'lib/Request.php',
  'mof\\Micropub\\IndieAuth' => 'lib/IndieAuth.php',
  'mof\\Micropub\\Error' => 'lib/Error.php'
], __DIR__);
```

To make the endpoint work, pass it a `config` array with your [Micropub endpoint configuration information](https://micropub.spec.indieweb.org/#configuration), and, at minimum, a `create` callback function that returns an URL of the newly created content (This endpoint can also handle `update` and `delete` if you choose to implement them).

Here is a minimal example how to do it in a route:

```php
'routes' => [
  [
    'pattern' => 'micropub',
    'method' => 'GET|POST',
    'action'  => function () {
      return \mof\Micropub\Endpoint::micropub([
        'config' => [
          'media-endpoint' =>  kirby()->urls()->base() . '/' . 'micropub-media-endpoint',
        ],
        'create' => function ($post) {
          // Create a new page, store content in a database,
          // etc. from $post
          return $url;
        }
      ]);
    }
  ]
]
```

A more detailed example can be found in the included `example.php`. It is advisable to create a Kirby plugin instead of storing the whole endpoint setup in `config.php`.

In general, what you want to do in the `create` function is to ‘map’ the incoming Micropub post to your Kirby setup's content structure. Say you want incoming `note` posts to go to your blog, which stores its entries in a content folder called `blog`. Photos should be published as new subpages of your photo page, which is in the `photography` folder. In order to map the incoming posts to your existing Kirby pages, you therefore need a `parent` and a `blueprint` for these two post types:

```php
$postmap = [
  'note'  => [
    'parent'    => 'blog',
    'blueprint' => 'article'
  ],
  'photo'  => [
    'parent'    => 'photography',
    'blueprint' => 'image'
  ]
];
```

Now, say you try to post a `bookmark` to your Micropub endpoint, a type of post you have not defined. You could reject such posts:

```php
if(!isset($postmap[$post->type()])) return false;
```

Alternatively, you could publish all unknown types to a page you have called `stream`:

```php
if(!isset($postmap[$post->type()])) {
  $postmap[$post->type()] = [
    'parent'    => 'stream',
    'blueprint' => 'default'
  ];
}
```

Note: In this example, we rely on `$post->type()` to match the incoming content to our content structure. `$post->type()` contains the suggestion of the [*Post Type Discovery* algorithm](https://indieweb.org/post-type-discovery), which tries to find an 'implied post type' based on properties of the content submitted to the endpoint. This is not the only way. If what you want to publish to your site doesn't fit the predefined 'types' of posts,
you can simply ignore `$post->type()` and come up with your own matching criteria by inspecting the incoming content. Maybe you don't have a separate `photography` page and publish everything to `blog`, but would like to tag posts that have a photo with 'photo'. Or you want to publish `note` (a post without a title in Micropub parlance) and `article` (a post with a title) using the same blueprint.

After you have matched the incoming post to your content structure, you can create a new page:

```php
// Careful, overwrites existing pages which have the same slug!
$newpage = page($postmap[$post->type()]['parent'])->createChild([
  'slug'     => $post->slug() ?? \Kirby\Toolkit\Str::random(8),
  'template' => $postmap[$post->type()]['blueprint'],
  'draft'    => ($post->status() == 'listed' || $post->status() == 'unlisted') ? false : true
]);
```

Please note this will overwrite existing pages with the same slug. This is just an example.

The next step would then be to match the `$post->fields()` array to the blueprint's content fields and populate `$newpage`'s content, handle the attachments, and so on (see the reference below and `example.php`).

However you choose to store your content, `create` should return the URL of that content in the end.


### Media Endpoint
The media endpoint needs two things:
- a directory to (temporarily) store uploads in
- a publicly accessible URL of that directory, so it can tell the Micropub client where to find the uploaded attachment.

A good choice is probably Kirby's `media` folder. Create a new directory in there, e.g., `temp`, and set up the media endpoint with it. Again, you can do so with a route:

```php
'routes' => [
  [
    'pattern' => 'micropub-media-endpoint',
    'method' => 'POST',
    'action'  => function () {
      return \mof\Micropub\Endpoint::media(
        kirby()->root('media') . '/temp',
        kirby()->urls()->media() . '/temp'
      );
    }
  ]
]
```

(You should probably clear out that folder from time to time, or delete the upload after you finished adding the file to your newly created page)


## Installation

### Download
For testing, download and copy this repository to your project folder (or a fresh Starterkit). You should then be able to send test requests to the included `example.php`. I recommend [Insomnia](https://insomnia.rest/download) for these kinds of things. Once you move closer to production, [micropub.rocks](https://micropub.rocks/) helps to thoroughly test your implementation.

When you copy this repository to `/site/plugins/{{ your-plugin-name }}/vendor`, make sure to correctly load the classes, for example using [Kirby's built-in autoloader](https://getkirby.com/docs/reference/templates/helpers/load).


## Options
You can [customize some options of the Micropub Request for Kirby 3 class](https://github.com/moefuerst/kirby3-micropub-request#setupoptions). See the documentation there.


## Reference

### `create` callback function
Gets a [`Kirby\Toolkit\Obj`](https://getkirby.com/docs/reference/objects/toolkit/obj) object that includes the following:

```php
/*
* A suggested 'implied post type' based on the properties of the request.
* You can use it to map the post to your existing content structure.
*
* @return string
*/
$post->type()

/*
* An array of the post's content fields
*
* @return array
*/
$post->fields()

/* For your head start, here is what that might look like:
Array
(
  [name] => Title of my blog post, but there might not always be one
  [content] => <b>Hello</b> World. My blog post text.
  [category] => Array
    (
      [0] => foo
      [1] => bar
    )
)
*/

/*
* A visibility property, if none was suggested by the client it defaults to 'listed'
*
* @return string
*/
$post->status()

/*
* A slug for the new post, if one was suggested by the client
*
* @return string|false
*/
$post->slug()

/*
* An object with the attachments of the post
*
* @return Kirby\Toolkit\Obj
*/
$post->files()

/*
* An array listing the content fields which include HTML
*
* @return array|false
*/
$post->html()

/*
* The client used to post the content
*
* @return string|null
*/
$post->client()
```

### `update` callback function
Gets a [`Kirby\Toolkit\Obj`](https://getkirby.com/docs/reference/objects/toolkit/obj) object that includes the following:

```php
/*
* The Kirby page object of the page to be updated.
* The endpoint has already checked if this page exists.
*
* @return Kirby\Cms\Page
*/
$post->page()

/*
* An array of properties to replace on the page
*
* @return array|null
*/
$post->replace()

/*
* An array of properties to add to the page
*
* @return array|null
*/
$post->add()

/*
* An array of properties to remove from the page
*
* @return array|null
*/
$post->remove()

/*
* An array listing the content fields which include HTML
*
* @return array|false
*/
$post->html()
```

### `delete` callback function
Gets a [`Kirby\Toolkit\Obj`](https://getkirby.com/docs/reference/objects/toolkit/obj) object that includes the following:

```php
/*
* The Kirby page object of the page to be deleted.
* The endpoint has already checked if this page exists.
*
* @return Kirby\Cms\Page
*/
$post->page()
```


## Development
Please report any problems you encounter, as well as your thoughts and comments as [issues](https://github.com/moefuerst/kirby3-micropub-endpoint/issues), or send a pull request!


## More Micropub for Kirby 3
- [kirby3-micropub-request](https://github.com/moefuerst/kirby3-micropub-request), a class for Kirby 3 development providing a simple API to inspect incoming Micropub requests.
- [kirby3-micropublisher](https://github.com/sebastiangreger/kirby3-micropublisher), a fully functioning Micropub endpoint plugin with a lot of customization options


## Credits
Inspiration and some code from
- [sebsel/indieweb-toolkit](https://github.com/sebsel/indieweb-toolkit)
- [sebsel/kirby-micropub](https://github.com/sebsel/kirby-micropub)
- [aaronpk/p3k-micropub](https://github.com/aaronpk/p3k-micropub)
- [indieweb/wordpress-micropub](https://github.com/indieweb/wordpress-micropub)

