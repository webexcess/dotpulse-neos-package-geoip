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
        $referer = $httpRequest->getHeader('Referer');

        $requestKey = $this->getLanguageRegionKeyOfUri($requestUri);
        $refererKey = $this->getLanguageRegionKeyOfUri($referer);

        if (!is_null($requestKey) && !is_null($refererKey) && $requestKey!=$refererKey) {
            // set an updated cookie if existing..
            $cookie = $httpRequest->getCookie('dotpulse_geoip');
            if (!is_null($cookie)) {
                $dateTime = new \DateTime('now');
                $dateTime->add(\DateInterval::createFromDateString('1 year'));
                $newCookie = new Cookie('dotpulse_geoip', $requestKey, $dateTime, null, null, '/', false, false);

                $httpResponse->setCookie($newCookie);
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
        preg_match($re, $uri.'/', $matches);
        if (is_array($matches) && array_key_exists(1, $matches)) {
            return $matches[1];
        }
        return null;
    }
}
