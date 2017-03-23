<?php
namespace Dotpulse\GeoIP\Controller;

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Mvc\Controller\ActionController;
use TYPO3\Flow\I18n\Detector;
use TYPO3\TYPO3CR\Domain\Model\ContentDimension;
use TYPO3\Neos\Domain\Service\ContentDimensionPresetSourceInterface;
use \TYPO3\TYPO3CR\Domain\Repository\NodeDataRepository;
use TYPO3\TYPO3CR\Domain\Repository\WorkspaceRepository;
use TYPO3\Flow\Http\Cookie;

/**
 * Controller for GeoIP
 *
 * @Flow\Scope("singleton")
 */
class GeoIPController extends \TYPO3\Flow\Mvc\Controller\ActionController
{

    /**
     * @var Detector
     * @Flow\Inject()
     */
    protected $localeDetector;

    /**
     * @var ContentDimensionPresetSourceInterface
     * @Flow\Inject()
     */
    protected $contentDimensionPresetSourceInterface;

    /**
     * @var NodeDataRepository
     * @Flow\Inject()
     */
    protected $nodeDataRepository;

    /**
     * @var WorkspaceRepository
     * @Flow\Inject()
     */
    protected $workspaceRepository;

    /**
     * @Flow\InjectConfiguration()
     * @var array
     */
    protected $settings;

    /**
     * @return void
     */
    public function homeRedirectAction()
    {
        $redirectUri = $this->settings['DefaultLanguage'] . $this->settings['DefaultDelimiter'] . $this->settings['DefaultRegion'];

        // check user agents to ignore..
        if (
            array_key_exists('StatusCodeForUserAgents', $this->settings)
            && $this->request->getHttpRequest()->hasHeader('User-Agent')
            && array_key_exists('UserAgents', $this->settings['StatusCodeForUserAgents'])
            && is_array($this->settings['StatusCodeForUserAgents']['UserAgents'])
            && (string)$this->request->getHttpRequest()->getRelativePath()=='')
        {
            $userAgent = $this->request->getHttpRequest()->getHeader('User-Agent');
            foreach ($this->settings['StatusCodeForUserAgents']['UserAgents'] as $itemUserAgent) {
                if (strpos($userAgent, $itemUserAgent)!==false) {
                    $this->redirectToUri($this->settings['DefaultLanguage'], 0, $this->settings['StatusCodeForUserAgents']['StatusCode']);
                }
            }
        }

        // check cookie first..
        // redirectToUri throws StopActionException - do not wrap with try catch..
        $cookie = $this->request->getHttpRequest()->getCookie('dotpulse_geoip');
        // if (!is_null($cookie) && in_array($cookie->getValue(), $this->settings['AllowedLanguageRegionCombinations'])) {
        if (isset($cookie) && in_array($cookie->getValue(), $this->settings['AllowedLanguageRegionCombinations'])) {
            // $this->redirectToUri($redirectUri, 0, $cookie->getValue());
            $this->redirectToUri($cookie->getValue(), 0, $this->settings['RedirectStatusCode']);
            return;
        }

        // check if the site is requested without path..
        if ($this->request->getHttpRequest()->hasHeader('Referer') && (string)$this->request->getHttpRequest()->getRelativePath()=='') {
            if (strpos($this->request->getHttpRequest()->getHeader('Referer'), (string)$this->request->getHttpRequest()->getBaseUri())===0) {
                // it's an internal request, but without dimension.
                // set it to default language dimension..
                $this->redirectToUri($this->settings['DefaultLanguage'], 0, $this->settings['RedirectStatusCode']);
                // redirectToUri throws StopActionException - do not wrap with try catch..
            }
        }

        try {
            // fetch matching language dimension..
            $language = substr($this->localeDetector->detectLocaleFromHttpHeader($this->request->getHttpRequest()->getHeader('Accept-Language')), 0, 2);
            $presets = $this->contentDimensionPresetSourceInterface->getAllPresets();
            if (!array_key_exists($language, $presets['language']['presets'])) {
                $language = $presets['language']['default'];
            }

            // get public client ip..
            $clientIpAddress = $this->request->getHttpRequest()->getClientIpAddress();
            if (substr($clientIpAddress, 0, 3) == '192') {
                // looks like you'r in a local vm. fetch a public ip..
                $dyndnsResponse = file_get_contents('http://checkip.dyndns.com/');
                preg_match('/Current IP Address: \[?([:.0-9a-fA-F]+)\]?/', $dyndnsResponse, $dyndnsMatches);
                $clientIpAddress = $dyndnsMatches[1];
            }

            // fetch client region by ip..
            $geoData = json_decode(file_get_contents('http://ipinfo.io/' . $clientIpAddress), true);
            if (is_null($geoData) || !array_key_exists('loc', $geoData) || strpos($geoData['loc'], ',') === false) {
                // something went wrong..
                $this->redirectToUri($redirectUri, 0, $this->settings['RedirectStatusCode']);
                return;
            }

            $loc = explode(',', $geoData['loc']);
            $loc[0] = floatval($loc[0]);
            $loc[1] = floatval($loc[1]);

            // fetch available regions..
            $items = array(
                //'0' => array('zurich', '47.4135587', '8.5514525'),
                //'1' => array('frankfurt', '50.135214', '8.688056'),
            );
            $nodesData = $this->getAllNodeDimensionsByPath($this->settings['RegionLatLng']['Node']['Path']);
            foreach ($nodesData as $uriKey => $nodeData) {
                if (in_array($uriKey, $this->settings['AllowedLanguageRegionCombinations'])) {
                    $items[] = array(
                        $nodeData->getProperty($this->settings['RegionLatLng']['Node']['FieldTitle']),
                        $uriKey,
                        $nodeData->getProperty($this->settings['RegionLatLng']['Node']['FieldLat']),
                        $nodeData->getProperty($this->settings['RegionLatLng']['Node']['FieldLng']),
                    );
                }
            }

            // calculate closest region..
            $distances = array_map(function ($item) use ($loc) {
                $a = array_slice($item, -2);
                return $this->distance($a, $loc);
            }, $items);
            asort($distances);
            $closestItem = $items[key($distances)];

            // check language..
            if (strpos($closestItem[1], $language . '_') === false) {
                // use default language..
                $redirectUri = $this->settings['DefaultLanguage'] . '_' . substr($closestItem[1], 3);
            } else {
                $redirectUri = $closestItem[1];
            }
            $redirectUri = strtolower($redirectUri);
        } catch (\Exception $e) {
            // todo: log..
            // \TYPO3\Flow\var_dump($e);
        }


        $dateTime = new \DateTime('now');
        $dateTime->add(\DateInterval::createFromDateString('1 year'));
        $cookie = new Cookie('dotpulse_geoip', $redirectUri, $dateTime, null, null, '/', false, false);
        $this->response->setCookie($cookie);

        $this->redirectToUri($redirectUri, 0, $this->settings['RedirectStatusCode']);
    }

    private function getAllNodeDimensionsByPath($path, $language = null)
    {
        $nodeDimensions = array();
        $nodes = array();

        $presets = $this->contentDimensionPresetSourceInterface->getAllPresets();
        $workspaceLive = $this->workspaceRepository->findOneByName('live');

        foreach ($presets['region']['presets'] as $region => $regionPreset) {
            if (is_array($regionPreset) && array_key_exists('values',
                    $regionPreset) && !empty($regionPreset['uriSegment'])
            ) {
                if (is_null($language) || $language == 'de') {
                    $nodeDimensions['de'][$region] = implode(',', $regionPreset['values']);
                    $dimensions = array('language' => array('de', 'en'), 'region' => $regionPreset['values']);
                    $nodes['de_' . $regionPreset['uriSegment']] = $this->nodeDataRepository->findOneByPath($path,
                        $workspaceLive, $dimensions);
                }

                if (is_null($language) || $language == 'en') {
                    $nodeDimensions['en'][$region] = implode(',', $regionPreset['values']);
                    $dimensions = array('language' => array('en'), 'region' => $regionPreset['values']);
                    $nodes['en_' . $regionPreset['uriSegment']] = $this->nodeDataRepository->findOneByPath($path,
                        $workspaceLive, $dimensions);
                }
            }
        }

        return $nodes;
    }

    private function distance($a, $b)
    {
        list($lat1, $lon1) = $a;
        list($lat2, $lon2) = $b;

        $theta = $lon1 - $lon2;
        $dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
        $dist = acos($dist);
        $dist = rad2deg($dist);
        $miles = $dist * 60 * 1.1515;

        return $miles;
    }
}
