<?php

namespace mof\Micropub\Request;

use mof\Micropub\Error;
use Kirby\Http\Remote;
use Kirby\Toolkit\Obj;

/**
 * The `IndieAuth` class provides a decrypted and me-verified IndieAuth token
 * from a request bearer token. The object will return an error if the user
 * is not authorized.
 */
class IndieAuth extends \Kirby\Http\Request\Auth\BearerAuth
{
    /**
     * @var string
     */
    protected $me;

    /**
     * @var string
     */
    protected $issued_by;

    /**
     * @var string
     */
    protected $client_id;

    /**
     * @var string
     */
    protected $issued_at;

    /**
     * @var string
     */
    protected $scope;

    /**
     * Creates a new IndieAuth object
     *
     * @param string $token A bearer token
     */
    public function __construct(string $token)
    {
        parent::__construct($token);

        $accesstoken = $this->getToken($token);
        $this->me = $accesstoken->me();
		$this->issued_by = $accesstoken->iss() ?? null;
		$this->client_id = $accesstoken->client_id();
		$this->issued_at = $accesstoken->iat() ?? null;
		$this->scope = explode(' ', $accesstoken->scope()) ?? null;
        $this->verifyMe();
    }


    /**
     * Improved `var_dump` output
     *
     * @return array
     */
    public function __debugInfo(): array
    {
        return [
        	'type'		=> $this->type(),
            'me' 		=> $this->me(),
            'issued_by' => $this->issued_by(),
            'client_id' => $this->client_id(),
            'issued_at'	=> $this->issued_at(),
            'scope'   	=> $this->scope(),
            'error'     => $this->error ?? null
        ];
    }


    /**
     * Verifies the token's me property against the site's base url or an optional
     * path set in the config.
     *
     * @return bool
     */
    public function verifyMe(): bool
    {
    	if (option('mof.micropub.me-path') === null) {
    		if ($this->me == kirby()->urls()->base()) {
    			return true;
    		}
    	} else {
    		if ($this->me == kirby()->urls()->base() . '/' . option('mof.micropub.me-path')) {
    			return true;
    		}
    	}
    	$this->error = new Error('forbidden', $this->me, 'Not authorized for this site.');
    	return false;
    }


    /**
     * Returns an error object or false
     *
     * @return mof\Micropub\Error|bool
     */
    public function error()
    {
        return $this->error ?? false;
    }


    /**
     * Gets the access token by calling a verification function or
     * querying a token endpoint with the authentication bearer
     *
     * @param string $bearer The authentication bearer
     * @return Kirby\Toolkit\Obj
     */
    public function getToken(string $bearer)
    {
    	// Check for verification function provided by an internal token endpoint
		if (function_exists('verifyMicropubAccessToken')) {
			$accesstoken = verifyMicropubAccessToken($bearer);
		}

		// Get access token from a remote token endpoint
		else {
			$accesstoken = json_decode(Remote::get(
                option('mof.micropub.auth.token-endpoint', 'https://tokens.indieauth.com/token'),
                [
                    'headers' => [
                        'Accept: application/json',
                        'Authorization: Bearer ' . $bearer
                    ]
                ]
            )->content());
		}

		if (isset($accesstoken->error)) {
            $this->error = new Error(
            	'invalid_request',
            	$accesstoken->error,
            	$accesstoken->error_description
            );
        }

    	return $this->accesstoken =  new Obj(get_object_vars($accesstoken));
    }

    /**
     * Returns the authentication type
     *
     * @return string
     */
    public function type(): string
    {
        return 'indie';
    }

    /**
     * Returns the domain name of the authenticated user
     *
     * @return string
     */
    public function me(): string
    {
        return $this->me;
    }

    /**
     * Returns the token issuer
     *
     * @return string|null
     */
    public function issued_by()
    {
        return $this->issued_by;
    }

    /**
     * Returns the client id
     *
     * @return string
     */
    public function client_id(): string
    {
        return $this->client_id;
    }

    /**
     * Returns the token issue date
     *
     * @return string|null
     */
    public function issued_at()
    {
        return $this->issued_at;
    }

    /**
     * Returns the token scope
     *
     * @return array
     */
    public function scope()
    {
        return $this->scope;
    }

}
