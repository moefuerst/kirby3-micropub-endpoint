<?php

namespace mof\Micropub;

use Exception;
use Kirby\Data\Data;
use Kirby\Http\Request\Body;
use Kirby\Http\Request\Files;
use Kirby\Http\Remote;
use Kirby\Http\Url;
use Kirby\Toolkit\A;
use Kirby\Toolkit\Dir;
use Kirby\Toolkit\F;
use Kirby\Toolkit\Obj;
use Kirby\Toolkit\Silo;
use Kirby\Toolkit\Str;
use Kirby\Toolkit\V;

/**
 * The `Request` class provides a simple API to inspect incoming
 * Micropub requests. It normalizes data submitted to the endpoint,
 * parses the properties, and gets attachments transmitted as URL.
 * It maps the behavior of the Kirby\Http\Request object in many
 * ways (Body, Files, Method, Auth), *except* for `url`, which
 * contains the url of the page to be updated or deleted if
 * applicable.
 *
 * Examples:
 *
 * `$request->action()`				-> Returns create, update or
 *									   delete
 *
 * `$request->commands()` 			-> Returns an array of Micropub
 * 									   `mp-` commands such as `mp-slug`
 *
 * `$request->auth()->scope()` 		-> Returns an array of the
 *									   auth token scope
 *
 * `$request->is('POST')` 			-> Check if the given method name
 *									   matches the request method
 *
 * `$request->body()->get('title')`	-> Get the post title
 *
 */
class Request extends Obj
{
    /**
     * The auth object if available
     *
     * @var \mof\Micropub\IndieAuth|bool
     */
    protected $auth;

    /**
     * The properties object is a Silo containing
     * all submitted post properties in Mf2 form
     *
     * @var \Kirby\Toolkit\Silo
     */
    protected $properties;

    /**
     * The action requested by the client, either
     * `create`, `update` or `delete`
     *
     * @var string
     */
    protected $action;

    /**
     * The Body object is a Kirby\Http\Request\Body
     * constructed with the parsed properties. It only
     * contains post content, not meta-properties such
     * as Micropub `mp-` commands, or attachment urls.
     *
     * @var Body
     */
    protected $body;

    /**
     * The id of the requesting Micropub client
     *
     * @var string|null
     */
    protected $client;

    /**
     * An array of Micropub `mp-` commands such
     * as `mp-slug` if available
     *
     * @var array|null
     */
    protected $commands;

    /**
     * An array of files of the request submitted as URL
     *
     * @var array|null
     */
    protected $attachments;

    /**
     * The Files object is a is a \Kirby\Toolkit\Obj
     * constructed with the $_FILES global and/or the
     * attachments submitted as URL
     *
     * @var \Kirby\Toolkit\Obj
     */
    protected $files;

    /**
     * The request method type
     *
     * @var string
     */
    protected $method;

    /**
     * Options that have been passed to
     * the request in the constructor
     *
     * @var array
     */
    protected $options;

    /**
     * The micropub extension `post-status` if submitted
     *
     * @var string|null
     */
    protected $status;

    /**
     * The Kirby\Http\Request\Query object
     *
     * @var Kirby\Http\Request\Query
     */
    protected $query;

    /**
     * The submitted post type property
     *
     * @var string
     */
    protected $type;

    /**
     * An array of properties to add, replace or delete
     * if this is an `update` request
     *
     * @var array|null
     */
    protected $update;

    /**
     * An array listing properties which have been submitted as html
     *
     * @var array|null
     */
    protected $html;

    /**
     * The path within /media where received files are stored
     *
     * @var string
     */
    protected $uploadroot;

    /**
     * The url of the page to update or delete, if this
     * is an `update` or `delete` request
     *
     * @var string|null
     */
    protected $url;

    /**
     * Creates a new Request object
     */
    public function __construct(array $options = [])
    {
    	$this->options = $options;

    	if (isset($options['$uploadroot']) === true) {
            $this->uploadroot = $options['$uploadroot'];
        } else {
        	$this->uploadroot = kirby()->root('media') . DS . 'temp';
        }

    	// Set a few properties to match the behavior of \Kirby\Http\Request
        $this->method = kirby()->request()->method();
        $this->query = kirby()->request()->query();


        $this->q = kirby()->request()->query()->get('q');
        $this->auth = $this->auth();
        $this->body = $this->body();
    }

    /**
     * Improved `var_dump` output
     *
     * @return array
     */
    public function __debugInfo(): array
    {
        return [
            'action'     => $this->action(),
            'auth'       => $this->auth(),
            'body'       => $this->body(),
            'client'     => $this->client(),
            'commands'   => $this->commands(),
            'files'      => $this->files(),
            'html'       => $this->html(),
            'method'     => $this->method(),
            'properties' => $this->properties(),
            'q'			 => $this->q(),
            'query'      => $this->query(),
            'status'     => $this->status(),
            'type'       => $this->type(),
            'update'     => $this->update(),
            'url'        => $this->url(),
            'error'      => $this->error ?? null
        ];
    }

    /**
     * Returns the Auth object if authentication is provided, or false
     *
     * @return \mof\Micropub\IndieAuth|bool
     */
    public function auth()
    {
        if ($this->auth !== null) {
            return $this->auth;
        }

        if ($auth = kirby()->request()->header('authorization')) {
            $token = Str::after($auth, ' ');
            $authorize = new Request\IndieAuth($token);
        } elseif (isset($_POST['access_token'])) {
            $token = $_POST['access_token'];
            $authorize = new Request\IndieAuth($token);
        } else {
        	$authorize = new Error('unauthorized', 'token', 'No access token provided.');
        }

        if(!$authorize->error()) {
        	return $this->auth = $authorize;
        }

        $this->error = $authorize->error();
        return $this->auth = false;
    }

    /**
     * Returns the Body object
     *
     * @return \Kirby\Http\Request\Body
     */
    public function body()
    {
        return $this->body = $this->body ?? new Body($this->propertiesToData());
    }

    /**
     * Returns the client id
     *
     * @return string|null
     */
    public function client()
    {
        if($this->auth()) {
        	return $this->client = $this->auth()->client_id();
        }
        return $this->client = null;
    }

    /**
     * Returns the Files object
     *
     * @return \Kirby\Toolkit\Obj
     */
    public function files()
    {
        if (is_object($this->files) === true) {
            return $this->files;
        }

        if (!empty($_FILES)) {
            $files = new Obj(A::merge(
                kirby()->request()->files()->toArray(),
                A::wrap($this->attachments())
            ));
        }

        return $this->files = $files ?? new Obj(A::wrap($this->attachments()));
    }


    /**
     * Checks if the Request header is form content type
     *
     * @return bool
     */
    private function formContentType() : bool
    {
        if (isset($_SERVER['CONTENT_TYPE'])) {
            if ($_SERVER['CONTENT_TYPE'] === 'application/x-www-form-urlencoded' || 'multipart/form-data') {
                return true;
            }
        }
        return false;
    }


    /**
     * Translates form-syntax into JSON
     *
     * @return array
     */
    private function formToJson() : array
    {
        $data = [];
        foreach ($this->properties->get() as $key => $val) {
            if ('action' === $key || 'url' === $key) {
                $data[$key] = $val;
            } elseif ('h' === $key) {
                $data['type'] = A::wrap('h-' . $val);
            } else {
                $data['properties'] = self::mpArray($data, 'properties');
                $data['properties'][$key] = (
                    is_array($val) && self::isNumericArray($val)
                ) ? $val : A::wrap($val);
            }
        }
        $this->properties->remove();
        $this->properties->set($data);
        return $data;
    }


    /**
     * Fetches files from a given remote url and saves them to disk.
     *
     * @param array $source
     * @param array $format
     * @return array
     */
    private function getAttachments(?array $source, ?string $format)
    {
        // Todo: mime-validation using the given $format
        $attachments = [];

        foreach ($source as $attachment) {
            // Todo: Account for Microformats 2 proposal for image alt text
            // see w3.org/TR/micropub/#uploading-a-photo-with-alt-text
            if (is_array($attachment)
                && isset($attachment['value'])
                && V::url($attachment['value'])) {
                $fetchurl = $attachment['value'];
            } elseif (V::url($attachment)) {
                $fetchurl = $attachment;
            }

            try {
                // Don't bother downloading files already on our server
                // because they have been uploaded to the media-endpoint
                if (Url::stripPath($fetchurl) !== kirby()->urls()->base()) {

                    $fetch = Remote::get($fetchurl);
                    $fileinfo = $fetch->info();
                    $filename = F::safeName(basename($fileinfo['url']));

                    $folder = Str::random(8, 'alpha');
            		$path = $this->uploadroot() . DS . $folder . DS . $filename;

            		if (is_dir(dirname($path)) === false) {
                		Dir::make(dirname($path));
            		}

            		F::write($path, $fetch->content());

                    $attachments[] = [
                        'name'     => $filename						?? null,
                        'type'     => $fileinfo['content_type']     ?? null,
                        'tmp_name' => $path                     	?? null,
                        'error'    => $fetch->errorCode()           ?? false,
                        'size'     => $fileinfo['size_download']    ?? null,
                    ];
                } else {
                    $root = kirby()->root() . DS . str_replace(
                        '/',
                        DS,
                        Url::path($fetchurl)
                    );
                    if (F::exists($root)) {
                        $attachments[] = [
                            'name'     => F::name($root) . '.' . F::extension($root) ?? null,
                            'type'     => F::type($root) ?? null,
                            'tmp_name' => $root          ?? null,
                            'error'    =>                false,
                            'size'     => F::size($root) ?? null,
                        ];
                    }
                }
            } catch (Exception $e) {
                $this->error = new Error(
                    'internal_error',
                    $e->getCode(),
                    $e->getMessage()
                );
            }
        }
        return $attachments;
    }


    /**
     * Checks if the given method name matches the name of the request method.
     *
     * @param string $method
     * @return bool
     */
    public function is(string $method): bool
    {
        return strtoupper($this->method) === strtoupper($method);
    }


    /**
     * Checks if the passed array is numeric
     *
     * @return bool
     * @param mixed $data
     */
    private static function isNumericArray($data) : bool
    {
        if (!is_array($data)) {
            return false;
        }
        foreach ($data as $a => $b) {
    		if (!is_int($a)) {
        		return false;
    		}
		}
		return true;
    }


    /**
     * Micropub array handler, blatantly stolen from
     * https://github.com/indieweb/wordpress-micropub/blob/master/includes/functions.php#L26
     *
     * @return array
     * @param mixed $array
     * @param mixed $key
     * @param mixed $default
     * @param mixed $index
     */
    public static function mpArray($array, $key, $default = [], $index = false)
    {
        $return = $default;
        if (is_array($array) && isset($array[$key])) {
            $return = $array[$key];
        }
        if ($index && static::isNumericArray($return)) {
            $return = $return[0];
        }
        return $return;
    }

    /**
     * Normalizes the submitted request data once into Mf2/Json and caches the result.
     *
     * @return \Kirby\Toolkit\Silo
     */
    public function properties()
    {
        if ($this->properties !== null || $this->is('GET')) {
            return $this->properties;
        }

        $this->properties = new Silo();
        $this->properties->set(kirby()->request()->body()->toArray());

        // Check for form-syntax, and transform to JSON
        if ($this->formContentType() && $this->properties->get('h', false)) {
            if ($this->properties->get('access_token', false)) {
                $this->properties->remove('access_token');
            }
            $this->formToJson();
        }

        // Proceed with JSON syntax
        if ($this->properties->get('type', false)) {
            if (!is_array($this->properties->get('type'))) {
                return $this->error = new Error(
                    'invalid_request',
                    'type',
                    'Property \'type\' must be an array of Microformat vocabularies.'
                );
            }

            $this->action = 'create';
            $this->type = str_replace('h-', '', $this->properties->get('type')[0]);

            if (!$this->properties->get('properties', false)
                || !is_array($this->properties->get('properties'))) {
                return $this->error = new Error(
                    'invalid_request',
                    'properties',
                    'Properties must be specified in a properties object.'
                );
            }

            $properties = $this->properties->get('properties');

            // Micropub legacy field names support
            $deprecated = [
                'slug'         => 'mp-slug',
                'syndicate-to' => 'mp-syndicate-to'
            ];
            foreach ($deprecated as $k => $v) {
                if (isset($properties[$k])) {
                    $properties[$v] = $properties[$k];
                    unset($properties[$k]);
                }
            }

			// Clean the silo set the sanitized data, and return it
            $this->properties->remove();
            $this->properties->set($properties);
            return $this->properties;

        } elseif ($this->properties->get('action', false)) {
            // Actions require a URL
            if (!$this->properties->get('url', false)
                || V::url($this->properties->get('url')) == false) {
                return $this->error = new Error(
                    'invalid_request',
                    'url',
                    'This Micropub action requires a valid URL property.'
                );
            }

            $this->action = $this->properties->get('action');
            $this->url = $this->properties->get('url');

            return $this->properties;
        }

        $this->error = new Error(
            'invalid_request',
            'properties',
            'Input could not be parsed as either JSON, x-www-form-urlencoded or ' .
            'multipart/form-data: No entry type or \'action\' property found.'
        );

        return $this->properties;

    }


    /**
     * Parses the submitted properties and renders them into a flat
     * data array.
     *
     * @return array
     */
    private function propertiesToData()
    {
    	// Return empty if unauthorized
        if (false === $this->auth() || ! $this->properties()) {
            return [];
        }

        if ($this->action() == 'create') {
            $data = [];
            foreach ($this->properties()->get() as $k => $v) {
                if (!static::isNumericArray($v)) {
                    return $this->error = new Error(
                        'invalid_request',
                        $k,
                        'Values in JSON syntax must be arrays.'
                    );
                }
                switch ($k) {
                    case Str::substr($k, 0, 3) == 'mp-':
                        $this->commands[$k] = $v[0] ?? null;
                    	break;
                    case 'photo':
                        $this->attachments['photo'] = self::getAttachments($v, 'photo')
                                                        ?? null;
                    break;
                    case 'video':
                        $this->attachments['video'] = self::getAttachments($v, 'video')
                                                        ?? null;
                    	break;
                    case 'audio':
                        $this->attachments['audio'] = self::getAttachments($v, 'audio')
                                                        ?? null;
                    	break;
                    case 'post-status':
                        $this->status = $v[0] ?? null;
                    	break;
                    case (!empty(A::pluck($v, 'html'))):
                    	$data[$k] = A::pluck($v, 'html')[0];
                    	$this->html[] = $k;
                    	break;
                    case (sizeof($v) == 1):
                    	$data[$k] = $v[0];
                    	break;
                    default:
						$data[$k] = $v;
						break;
                }
            }
            return $data;
        } elseif ($this->action() == 'update') {
            $this->update = ['replace' => [],'add' => [],'delete' => []];
            foreach (array_keys($this->update) as $a) {
                if ($this->properties()->get($a)) {
                    if (!is_array($this->properties()->get($a))) {
                        return $this->error = new Error(
                            'invalid_request',
                            $a,
                            'Invalid syntax for update action.'
                        );
                    }
                    foreach ($this->properties()->get($a) as $p=>$v) {
                        if ($p != 'delete' && !is_array($v)) {
                            return $this->error = new Error(
                                'invalid_request',
                                $a . '.' . $p,
                                'All values in update actions must be arrays.'
                            );
                        }
                        foreach ($v as $sk => $sv) {
                            if (is_array($sv) && sizeof($sv) == 1
                                && array_key_exists('html', $sv)) {
                                $this->update[$a] = [ $p => $sv['html']];
                                $this->html[] = $p;
                            } else {
                                $this->update[$a] = $this->properties()->get($a);
                            }
                        }
                    }
                }
            }
        }
        // We don't have any post data on update or delete actions
        return [];
    }


    /**
     * Returns the request as Mf2 array
     *
     * @return array
     */
    public function toMf2()
    {
        return [
            'type' => 'h-' . $this->type(),
            'properties' => $this->properties()->get()
        ];
    }
}
