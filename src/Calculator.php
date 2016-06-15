<?php

/**
 * Class GoogleMapDistanceCalculator
 *
 * @author Michael Bardsley @mic_bardsley
 */
class GoogleMapDistanceCalculator
{
    /**
     * This is the URL that is used to retrieve the distances between a entered postcode and each of the dealers
     *
     * @var string
     */
    protected $url = 'https://maps.googleapis.com/maps/api/distancematrix/json';

    /**
     * THe parameters that will be appended to the URL
     *
     * @var string
     */
    protected $urlParameters = [
        'origins=%s',
        'destinations=%s',
        'units=imperial',
        'key=%s',
    ];

    /**
     * An error/explanation map of the errors that can be received
     *
     * @var array
     */
    protected $errorReasons = [
        'INVALID_REQUEST' => 'The provided request was invalid',
        'MAX_ELEMENTS_EXCEEDED' => 'Exceeded max number of origins or destinations',
        'OVER_QUERY_LIMIT' => 'Too many requests have been received from your application',
        'REQUEST_DENIED' => 'The service denied use of the distance calculation for your application',
        'UNKNOWN_ERROR' => 'An unknown error occurred, it may succeed if you try again',
    ];

    /**
     * The API key for the Google API
     *
     * @var string
     */
    protected $apiKey;

    /**
     * This is the current limit for sending requests. You cannot send more that 25 destinations at once
     *
     * @var int
     */
    protected $batchProcessSize = 25;

    /**
     * GoogleMapDistanceCalculator constructor.
     * @param string $apiKey
     */
    public function __construct($apiKey)
    {
        $this->apiKey = $apiKey;

        if (empty($this->apiKey)) {
            throw new InvalidArgumentException('Empty API key provided');
        }
    }

    /**
     * @param string $origin
     * @param string|array $destinations - either a single postcode or an array of multiple postcodes
     * @return array
     * @throws Exception - If there has been an error detected with the response
     */
    public function getDrivingDistances($origin, $destinations)
    {
        if (!is_array($destinations)) {
            $destinations = [$destinations];
        }

        if (sizeof($destinations) > $this->batchProcessSize) {
            return $this->batchProcessDestinations($origin, $destinations);
        }

        return $this->processDestinations($origin, $destinations);
    }

    /**
     * Batch process the destinations if the number of destinations exceeds the batchProcessSize
     *
     * @param string $origin
     * @param array $destinations
     * @return array
     */
    protected function batchProcessDestinations($origin, array $destinations)
    {
        $distances = [$origin => []];
        foreach (array_chunk($destinations, $this->batchProcessSize) as $batchedDestinations) {
            $distances = array_merge_recursive(
                $distances,
                $this->processDestinations($origin, $batchedDestinations)
            );
        }

        return $distances;
    }

    /**
     * Process the destinations and return the formatted driving distances
     *
     * @param string $origin
     * @param array $destinations
     * @return array
     * @throws Exception - If there has been an error detected with the response
     */
    protected function processDestinations($origin, array $destinations)
    {
        $destinationString = implode('|', $destinations);
        $urlParameters =  sprintf(
            implode('&', $this->urlParameters),
            urlencode($origin),
            urlencode($destinationString),
            $this->apiKey
        );
        $response = $this->makeRequest(
            $this->getCurlOptions() + array(CURLOPT_URL => $this->url . '?' . $urlParameters)
        );

        return $this->processResponse($response);
    }

    /**
     * Process the response and return either an array of the distances or an error
     *
     * @param string $response - should be a JSON string of the response from the Google API
     * @return array
     * @throws Exception - If an error response is returned
     */
    protected function processResponse($response)
    {
        $distances = json_decode($response, true);
        if (empty($distances['status'])) {
            throw new Exception($this->errorReasons['UNKNOWN_ERROR']);
        }
        switch ($distances['status']) {
            case 'OK':
                return $distances;

            case 'INVALID_REQUEST':
            case 'MAX_ELEMENTS_EXCEEDED':
            case 'OVER_QUERY_LIMIT':
            case 'REQUEST_DENIED':
                throw new Exception($this->errorReasons[$response['error']]);

            default:
            case 'UNKNOWN_ERROR':
                throw new Exception($this->errorReasons['UNKNOWN_ERROR']);
        }
    }

    /**
     * Returns curl options
     *
     * @return array
     */
    protected function getCurlOptions()
    {
        return [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 10,
        ];
    }

    /**
     * Make request to the Google API server
     *
     * @param array $options
     * @return string
     * @throws Exception - If anything other than a 200 status code is returned or the response is empty
     */
    protected function makeRequest(array $options)
    {
        $ch = curl_init($options[CURLOPT_URL]);
        unset($options[CURLOPT_URL]);
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($status != 200 || !$response) {
            throw new Exception($this->errorReasons['UNKNOWN_ERROR']);
        }

        return $response;
    }
}
