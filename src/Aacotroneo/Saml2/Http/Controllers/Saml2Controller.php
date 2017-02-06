<?php

namespace Aacotroneo\Saml2\Http\Controllers;

use Aacotroneo\Saml2\Saml2Auth;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;

use Config;
use Event;
use Log;
use Redirect;
use Response;
use Session;
use URL;

use OneLogin_Saml2_Auth;

class Saml2Controller extends Controller
{

    protected $saml2Auth;

    protected $idp;

    /**
     * @param Saml2Auth $saml2Auth injected.
     */
    function __construct(Request $request){

        $this->idp = explode('/',$request->path())[0];
        if (!$this->idp) {
            $this->idp = 'test';
        }

        $config = Config::get('saml2/'.$this->idp.'_idp_settings');

        $config['sp']['entityId'] = URL::route($this->idp.'_metadata');

        $config['sp']['assertionConsumerService']['url'] = URL::route($this->idp.'_acs');

        $config['sp']['singleLogoutService']['url'] = URL::route($this->idp.'_sls');

        $auth = new OneLogin_Saml2_Auth($config);

        $this->saml2Auth = new Saml2Auth($auth);
    }

    /**
     * Generate local sp metadata
     * @return \Illuminate\Http\Response
     */
    public function metadata()
    {

        $metadata = $this->saml2Auth->getMetadata();

        return Response::make($metadata, 200, ['Content-Type' => 'text/xml']);
    }

    /**
     * Process an incoming saml2 assertion request.
     * Fires 'saml2.loginRequestReceived' event if a valid user is Found
     */
    public function acs()
    {
        $errors = $this->saml2Auth->acs();

        if (!empty($errors)) {
            Log::error('Saml2 error', $errors);
            Session::flash('saml2_error', $errors);
            return Redirect::to(Config::get('saml2_settings.errorRoute'));
        }
        $user = $this->saml2Auth->getSaml2User();

        Event::fire('saml2.login', array(array('idp' => $this->idp, 'user' => $user)));

        $redirectUrl = $user->getIntendedUrl();

        if ($redirectUrl !== null) {
            return Redirect::to($redirectUrl);
        } else {

            return Redirect::to(Config::get('saml2_settings.loginRoute'));
        }
    }

    /**
     * Process an incoming saml2 logout request.
     * Fires 'saml2.logoutRequestReceived' event if its valid.
     * This means the user logged out of the SSO infrastructure, you 'should' log him out locally too.
     */
    public function sls()
    {
        $errors = $this->saml2Auth->sls($this->idp, Config::get('saml2_settings.retrieveParametersFromServer'));
        if (!empty($errors)) {
            Log::error('Saml2 error', $errors);
            Session::flash('saml2_error', $errors);
            throw new \Exception("Could not log out");
        }

        return Redirect::to(Config::get('saml2_settings.logoutRoute')); //may be set a configurable default
    }

    /**
     * This initiates a logout request across all the SSO infrastructure.
     */
    public function logout()
    {
        $returnTo = $request->query('returnTo');
        $sessionIndex = $request->query('sessionIndex');
        $nameId = $request->query('nameId');
        $this->saml2Auth->logout($returnTo, $nameId, $sessionIndex); //will actually end up in the sls endpoint
        //does not return
    }


    /**
     * This initiates a login request
     */
    public function login()
    {
        // check if there is a previously intended URL in current session
        // fallback to loginRoute defined in SAML2 config file
        $relayState = Session::pull('url.intended', Config::get('saml2_settings.loginRoute'));
        $this->saml2Auth->login($relayState);
    }

}
