<?php
namespace mof\Micropub;

use Exception;
use Kirby\Http\Header;
use Kirby\Http\Response;
use Kirby\Http\Url;
use Kirby\Toolkit\A;
use Kirby\Toolkit\Dir;
use Kirby\Toolkit\F;
use Kirby\Toolkit\File;
use Kirby\Toolkit\Obj;
use Kirby\Toolkit\Str;
use Kirby\Toolkit\V;

class Endpoint
{
    // Micropub Endpoint
    public static function micropub(?array $options = null)
    {
        if (isset($options['config']) === false) {
            throw new Exception('Array \'config\' is not defined. You need to pass a configuration to the endpoint.');
        }

        // Get the micropub request object. If the user is not authorized,
        // it will not try to parse anything and include an appropriate error
        // response
        $request = new Request();

        // Catch all errors and return a response
        if ($request->error()) {
            return $request->error()->toErrorResponse();
        }

        // GET request: Return information on the endpoint based on the request query
        if ($request->is('GET') && $query = $request->q()) {
            if ($conf = self::configQuery($options['config'], $query)) {
                return Response::json($conf, 200);
            } else {
                return Error::response(
                    'invalid_request', $query, 'The query \'' . $query . '\' is not supported by this endpoint.'
                );
            }
        }

        // POST request: create, update or delete post
        if ($request->is('POST')) {
            $data = $request->body()->toArray();

            switch ($request->action()) {
                case 'create': // Try to call the 'create' callback

                    // Verify that the token scope is sufficient for creating content.
                    // This endpoint does *not* differentiate between 'draft' and
                    // 'create'
                    if (!in_array('create', $request->auth()->scope())) {
                        return Error::response(
                            'insufficient_scope', null, 'Scope of submitted token does not allow creating content.'
                        );
                    }

                    $post = new Obj([
                        'type'      => static::postTypeDiscover($request),
                        'fields'    => $data,
                        'status'    => $request->status() ?? 'listed',
                        'slug'      => $request->commands()['mp-slug'] ?? false,
                        'files'     => (count($request->files()->toArray()) > 0) ? $request->files() : false,
                        'html'      => (! empty($request->html())) ? $request->html() : false,
                        'client'    => $request->client() ?? null,
                    ]);

                    try {
                        if (isset($options['create']) && is_callable($options['create'])) {
                            $posturl = call_user_func($options['create'], $post);

                            if (is_string($posturl)) {
                                return Header::redirect($posturl, 201);
                            }

                            elseif (A::get($posturl, 'preview')) {
                                return Response::json([
                                    'url' => A::get($posturl, 'url'),
                                    'preview'=> A::get($posturl, 'preview')
                                ], 201, true, ['Location' => Url::unIdn(A::get($posturl, 'preview') ?? '/')]);
                            }

                            elseif ($url = A::get($posturl, 'url')) {
                                return Response::json([
                                    'url' => $url
                                ], 201, true, ['Location' => Url::unIdn($url ?? '/')]);
                            }

                            // The 'create' callback did not return a URL
                            throw new Exception('The endpoint did not return a URL for the post.');

                        } else {
                            // The 'create' callback is not defined
                            throw new Exception('The endpoint could not create the post');
                        }
                    } catch (Exception $e) {
                    	return Error::response(
                    	    'invalid_request', null, $e->getMessage()
                    	);
                    }

                    break;

                case 'update': // Try to call the 'update' callback
                    try {
                        if (isset($options['update']) && is_callable($options['update'])) {

                            // Check if a page exists for this URL
                            if (! $page = kirby()->site()->index()->findBy('url', $request->url())) {
                                return Error::response(
                                    'invalid_request', null, 'The post you are trying to update does not exist.'
                                );
                            }

                            $post = new Obj([
                                'page'      => $page,
                                'replace'   => $request->update()['replace'] ?? null,
                                'add'       => $request->update()['add'] ?? null,
                                'remove'    => $request->update()['delete'] ?? null,
                                'html'      => $request->html() ?? null
                            ]);

                            if ($update = call_user_func($options['update'], $post)) {
                                return Response::json([
                                    'success' => 'update',
                                    'success_description' => 'Your post has been updated.'
                                ], 200, true, ['Location' => $page->url()]);
                            }

                            // The 'update' callback did not update the post
                            throw new Exception('The endpoint could not update the post.');

                        } else {
                            // The 'update' callback is not defined
                            throw new Exception('Updating posts is not supported by this endpoint.');
                        }
                    } catch (Exception $e) {
                        return Error::response(
                            'invalid_request', null, $e->getMessage()
                        );
                    }
                    break;

                case 'delete': // Try to call the 'delete' callback
                    try {
                        if (isset($options['delete']) && is_callable($options['delete'])) {
                            if ($delete = call_user_func(
                                $options['delete'],
                                new Obj(['page' => kirby()->site()->find(Url::path($request->url()))])
                            )) {
                                return Response::json([
                                    'success' => 'delete',
                                    'success_description' => 'Your post has been deleted.'
                                ], 200);
                            }
                            // The 'delete' callback did not delete the post
                            throw new Exception('The endpoint could not delete the post.');

                        } else {
                            // The 'delete' callback is not defined
                            throw new Exception('Deleting posts is not supported by this endpoint.');
                        }
                    } catch (Exception $e) {
                        return Error::response('invalid_request', null, $e->getMessage());
                    }
                    break;
                default:
                  return Error::response(
                    'invalid_request', null, 'Your request did not contain any data.'
                  );
                break;


            }
        }
        // GET requests with no query: Redirect to Kirby's error page
        return Response::redirect(page('error'), 404);
    }


    // Media Endpoint
    public static function media(?string $root, ?string $rooturl, $callback = null)
    {
        if (kirby()->request()->is('GET')) {
            return Response::go(page('error')->url(), 404);
        }

        $request = new Request();

        // Catch auth errors and return a response
        if ($request->error()) {
            return $request->error()->toErrorResponse();
        }

        if (!$upload = kirby()->request()->file('file')) {
            return Error::response(
                'invalid_request', null, 'Your request did not include a file upload named \'file\'.'
            );
        }

        if ($upload['error'] === 1) {
            return Error::response(
                'invalid_request', null, 'The uploaded file exceeds the maximum file size.'
            );
        }

        // Send a 202 accepted?
        Header::status(202);

        try {
            $tmp_file = new File($upload['tmp_name']);
            $tmp_name = F::safeName($upload['name']);
            $extension = F::extension($tmp_name);

            // Try to avoid .tmp filenames
            if (empty($extension) === true || in_array($extension, ['tmp', 'temp'])) {
                $mime      = $tmp_file->mime();
                $extension = F::mimeToExtension($mime);
                $filename  = F::safeName(F::name($tmp_name) . '.' . $extension);
            } else {
                $filename = basename($tmp_name);
            }

            $folder = Str::random(8, 'alphaNum');
            $path = $root . DS . $folder . DS . $filename;

            if (is_dir(dirname($path)) === false) {
                Dir::make(dirname($path));
            }

            // Move to the upload directory
            $upload = $tmp_file->move($path, true);

            // Make sure it's readable for the client
            chmod($path, 0644);

        } catch (Exception $e) {
            return Error::response($e);
        }

        $url = rtrim($rooturl, '/') . '/' . $folder . '/' . $upload->filename();

        // Send the correct header and do a callback
        Header::redirect($url, 201);

        if (is_callable($callback)) {
            call_user_func_array($callback, [$url, $upload]);
        }
    }

    /*
     *  Config Query
     *  Returns the right config array based on the request query
     *
     * @return array
     */
    public static function configQuery(?array $config, ?string $query)
    {
        // ToDo: Are all of the supported options actually in the passed config?

        $config = array_filter(A::merge([
            'media-endpoint' => null,
            'syndicate-to' => null,
            // List of supported mp- parameters
            'mp' => [
                'slug',
                'syndicate-to',
            ],
            // List of supported query parameters
            'q' => [
                'config',
                'syndicate-to',
                'category',
                'post-types',
                //'source',
            ],
            // List of supported post types
            'post-types' => null,
            // List of categories the client UI can show to the user
            'categories' => null,
        ], $config, 2));

        switch ($query) {
            case 'config':
                return $config;
                break;
            case 'syndicate-to':
                if (array_key_exists('syndicate-to', $config)) {
                    return ['syndicate-to' => $config['syndicate-to']];
                }
                break;
            case 'category':
                if (array_key_exists('categories', $config)) {
                    return ['categories' => $config['categories']];
                }
                break;
            case 'post-types':
                if (array_key_exists('post-types', $config)) {
                    return ['post-types' => $config['post-types']];
                }
                break;
            //case 'source':
                //break;
            default:
                break;
        }
    }


    /*
     * Suggests an 'implied post type' based on properties, see
     * https://indieweb.org/post-type-discovery
     *
     * @return string
     */
    public static function postTypeDiscover($request = null) : string
    {
        if ($request->type() != 'entry') {
            return $request->type();
        }

        $vocabulary = [
            'rsvp' => 'rsvp',
            'in-reply-to' => 'reply',
            'repost-of' => 'share',
            'like-of' => 'favorite',
            'bookmark-of' => 'bookmark',
            'video' => 'video',
            'photo' => 'photo',
            'checkin' => 'checkin',
        ];

        foreach (A::get($request->properties()->get(), array_keys($vocabulary)) as $p => $v) {
        	if (is_array($v)) {
        		$v = A::first($v);
        	}
            if ($p == 'rsvp' && $v) {
                return $vocabulary[$p];
            } elseif ($v && V::url($v)) {
                return $vocabulary[$p];
            } elseif ($v && V::url($v['url'])) {
                return $vocabulary[$p];
            }
        }

        if (!$content = $request->body()->toArray()['content'] ?? $request->body()->toArray()['summary']) {
            return 'note';
        }

        if (!isset($request->body()->toArray()['name'])) {
            return 'note';
        }

        if (!Str::startsWith(
            Str::unhtml(Str::trim(preg_replace('/\s+/', ' ', $content))),
            Str::unhtml(Str::trim(preg_replace('/\s+/', ' ', $request->body()->toArray()['name'])))
        )) {
            return 'article';
        }

        return 'note';
    }
}
