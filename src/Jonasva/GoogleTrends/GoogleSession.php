<?php namespace Jonasva\GoogleTrends;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\TransferStats;

class GoogleSession
{
    /**
     * Google authentication url
     *
     * @const string
     */
    CONST AUTH_URL = 'https://accounts.google.com/ServiceLoginBoxAuth';


    /**
     * Default config
     *
     * @var array
     */
    private static $defaults = [
        'email'         =>  '',
        'password'        =>  '',
        'recovery-email'    =>  '',
        'user-agent'    =>  'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_10_2) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2272.89 Safari/537.36',
        'language'      =>  'en_US',
        'max-sleep-interval'    =>  150,
    ];

    /**
     * Google email for authentication.
     *
     * @var string
     */
    private $email;

    /**
     * Google password for authentication.
     *
     * @var string
     */
    private $password;

    /**
     * Google recovery email address
     *
     * @var string
     */
    private $recoveryEmail;

    /**
     * User agent to access trends
     *
     * @var string
     */
    private $userAgent;

    /**
     * Google trends language
     *
     * @var string
     */
    private $language;

    /**
     * Guzzle Client
     *
     * @var \GuzzleHttp\Client
     */
    private $guzzleClient;

    /**
     * Guzzle CookieJar
     *
     * @var \GuzzleHttp\Cookie\CookieJar
     */
    private $cookieJar;

    /**
     * Maximum sleep interval between requests (in s/100)
     * has to be > 10
     *
     * @var int
     */
    private $maxSleepInterval;

    /**
     * Get guzzle cookiejar
     *
     * @return \GuzzleHttp\Cookie\CookieJar
     */
    public function getCookieJar()
    {
        return $this->cookieJar;
    }

    /**
     * Get the user agent
     *
     * @return string
     */
    public function getUserAgent()
    {
        return $this->userAgent;
    }

    /**
     * Get the language
     *
     * @return string
     */
    public function getLanguage()
    {
        return $this->language;
    }

    /**
     * Get guzzle client
     *
     * @return \GuzzleHttp\Client
     */
    public function getGuzzleClient()
    {
        return $this->guzzleClient;
    }

    /**
     * Get max sleep interval
     *
     * @return int
     */
    public function getMaxSleepInterval()
    {
        return $this->maxSleepInterval;
    }

    /**
     * Set guzzle cookiejar
     *
     * @return \GuzzleHttp\Cookie\CookieJar
     */
    public function setCookieJar(CookieJar $cookieJar)
    {
        $this->cookieJar = $cookieJar;
        return $this->cookieJar;
    }

    /**
     * Set max sleep interval
     *
     * @return int
     */
    public function setMaxSleepInterval($maxSleepInterval)
    {
        $this->maxSleepInterval = $maxSleepInterval;
        return $this->maxSleepInterval;
    }

    /**
     * Create a new GoogleSession instance.
     *
     * @param array config
     */
    public function __construct(array $config = [])
    {
        $configs = array_replace(self::$defaults, $config);

        $this->email = $configs['email'];
        $this->password = $configs['password'];
        $this->recoveryEmail = $configs['recovery-email'];
        $this->userAgent = $configs['user-agent'];
        $this->language = $configs['language'];
        $this->guzzleClient = new Client([
            'defaults' => [
                'headers' => [
                    'User-Agent'    => $this->userAgent,
                    "Content-type"  => "application/x-www-form-urlencoded",
                    "Accept"        => "text/plain",
                    "Referrer"      => "https://www.google.com/accounts/ServiceLoginBoxAuth",
                ],
                'allow_redirects' => [
                    'max'       => 10,
                ]
            ]
        ]);
        $this->cookieJar = new CookieJar();
    }

    /*
     * Authenticate on google and get the required cookies
     */
    public function authenticate()
    {
        // go to google.com to get some cookies
        $request = new Request('GET', 'http://www.google.com/ncr', ['cookies' => $this->cookieJar]);
        $response = $this->guzzleClient->send($request);

        // get google auth page html and fetch GALX
        $request = new Request('GET', self::AUTH_URL, ['cookies' => $this->cookieJar]);
        $response = $this->guzzleClient->send($request);

        $content = $response->getBody()->getContents();

        $document = new \DOMDocument();
        @$document->loadHTML($content);

        $inputElements = $document->getElementsByTagName("input");

        $params = [];

        /** @var \DOMElement $input */
        foreach ($inputElements as $input) {
            $params[$input->getAttribute("name")] = $input->getAttribute("value");
        }

        $params['Email'] = $this->email;
        $params['Passwd'] = $this->password;

        $params['pstMsg'] = '1';
        $params['continue'] = 'http://www.google.com/trends';

        // authenticate
        $request = new Request('POST', self::AUTH_URL, [
            'cookies'   => $this->cookieJar,
            'query'     => $params,
            'on_stats'  => function (TransferStats $stats) use (&$effectiveUri) {
                $effectiveUri = (string)$stats->getEffectiveUri();
            },
        ]);

        $response = $this->guzzleClient->send($request);

        // verify sign in if needed
        if (strpos($effectiveUri, 'LoginVerification') !== false) {
            $content = $response->getBody()->getContents();

            $document = new \DOMDocument();
            @$document->loadHTML($content);

            $inputElements = $document->getElementsByTagName("input");

            $params = [];

            foreach ($inputElements as $input) {
                $params[$input->getAttribute("name")] = $input->getAttribute("value");
            }

            $params['challengetype'] = 'RecoveryEmailChallenge';
            $params['emailAnswer'] = $this->recoveryEmail;

            $url = substr($response->getEffectiveUrl(), 0, strpos($response->getEffectiveUrl(), '?'));

            $request = new Request('POST', $url,
                [
                    'cookies'   => $this->cookieJar,
                    'query'     => $params,
                ]
            );

            $response = $this->guzzleClient->send($request);
        }

        // update cookies
        $response = $this->guzzleClient->get('https://www.google.com/accounts/CheckCookie?chtml=LoginDoneHtml',
            [
                'cookies' => $this->cookieJar,
//                'headers' => ['Referrer' => 'https://www.google.com/accounts/ServiceLoginBoxAuth'],
            ]
        );

        // set language for trends
        $I4SUserLocale = new setCookie([
            'Name'     => 'I4SUserLocale',
            'Value'    => $this->language,
            'Domain'   => 'www.google.com',
            'Path'     => '/trends',
            'Secure'   => true,
            'HttpOnly' => true
        ]);

        $this->cookieJar->setCookie($I4SUserLocale);

        return $this;
    }

    /*
     * Check if logged into Google account
     */
    public function checkAuth()
    {
        $response = $this->guzzleClient->get('https://accounts.google.com',
            [
                'cookies'   => $this->cookieJar,
                'on_stats'  => function (TransferStats $stats) use (&$effectiveUri) {
                    $effectiveUri = (string)$stats->getEffectiveUri();
                },
            ]
        );

        if (strpos($effectiveUri, 'ServiceLogin') !== false) {
            return false;
        }

        return true;
    }
} 
