<?php
namespace Dotpulse\GeoIP\HttpComponent;

use TYPO3\Flow\Http\Component\ComponentInterface;
use TYPO3\Flow\Http\Component\ComponentContext;
use TYPO3\Flow\Http\Cookie;

/**
 * A sample HTTP component that intercepts the default handling and returns "bar" if the request contains an argument
 * "foo"
 */
class DotpulseGeoIPUpdateCookieHTTPComponent implements ComponentInterface
{

    /**
     * @var array
     */
    protected $options;

    /**
     * @param array $options
     */
    public function __construct(array $options = array())
    {
        $this->options = $options;
    }

    /**
     * @param ComponentContext $componentContext
     * @return void
     */
    public function handle(ComponentContext $componentContext)
    {
        // check cookie differs to url..

        $httpRequest = $componentContext->getHttpRequest();
        $httpResponse = $componentContext->getHttpResponse();

        $requestUri = $httpRequest->getUri()->getPath();
        $referrer = $httpRequest->getHeader('Referer');

        $requestKey = $this->getLanguageRegionKeyOfUri($requestUri);
        $referrerKey = $this->getLanguageRegionKeyOfUri($referrer);

        if (!is_null($requestKey) && !is_null($referrerKey) && $requestKey!=$referrerKey) {
            // set an updated cookie if existing..
            $cookie = $httpRequest->getCookie('dotpulse_geoip');
            if (!is_null($cookie)) {
                $cookie->setValue($requestKey);
                $httpResponse->setCookie($cookie);
            }else{
                return;
            }
        }else{
            return;
        }

    }

    /**
     * @param $uri string
     * @return mixed string if ok, false if failed
     */
    private function getLanguageRegionKeyOfUri($uri)
    {
        $re = "/\\/([a-zA-Z0-9_-]+)\\//";
        preg_match($re, $uri, $matches);
        if (is_array($matches) && array_key_exists(1, $matches)) {
            return $matches[1];
        }
        return null;
    }
}
