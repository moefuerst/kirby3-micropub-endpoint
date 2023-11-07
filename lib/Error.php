<?php
namespace mof\Micropub;

use Kirby\Http\Response;
use Kirby\Toolkit\Str;

class Error
{
    private $error;
    private $property;
    private $description;
    private $code;

    public function __construct($error, $property = null, $description = null)
    {
        if (is_object($error) && null !== $error->getCode()) {
            $error = $error->getCode();
            $description = $error->getMessage();
        }
        switch ($error) {
            case 'unauthorized':
                $this->code = 401;
                break;
            case 'forbidden':
                $this->code = 403;
                break;
            case 'insufficient_scope':
                $this->code = 401;
                break;
            case 'invalid_request':
                $this->code = 400;
                break;
            default:
                $this->code = 500;
        }
        $this->error = $error;
        $this->property = $property;
        $this->description = $description;
    }

    public function error()
    {
    	return $this;
    }

    public function get(string $property, $fallback = null)
    {
        return $this->$property ?? $fallback;
    }

    public static function response($error, $property = null, $description = null)
    {
        $e = new Error($error, $property, $description);
        return $e->toErrorResponse();
    }

    public function toArray()
    {
        return [
            'error' => $this->error,
            'error_property' => $this->property,
            'error_description' => $this->description,
        ];
    }

    public function toErrorResponse()
    {
    	// Sorry client, you get json if accepted regardless of q-values...
        if (Str::contains(kirby()->request()->header('accept'), 'application/json', true)) {
            return Response::json([
                'error' => $this->error,
                'error_description' => $this->description
            ], $this->code);
        } else {
            return new Response(
                'Error \'' . $this->error . '\': ' . $this->description,
                'text/html',
                $this->code
            );
        }
    }

    public function toString()
    {
        return json_encode($this->toArray());
    }
}
