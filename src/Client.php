<?php

namespace ServerZone\SupermicroIpmi;

use SimpleXMLElement;

/**
 * HTTP based Supermicro IPMI control class
 *
 */
class Client implements IClient
{

    /** @var string Host */
    protected $host;

    /** @var string Protocol */
    protected $proto = 'http';

    /** @var string Username */
    protected $username;

    /** @var string Password */
    protected $password;

    /** @var boolean Loged in flag */
    protected $logedIn = false;

    /** @var \GuzzleHttp\Client */
    protected $guzzle;

    /**
     *
     * @param string $host IPMI address (e.g. IPv6 [2001:db8::1] or IPv4 192.168.0.1 or hostname ipmi.server.tld)
     * @param string $username Login
     * @param string $password Password
     */
    public function __construct(string $host, string $username, string $password)
    {
        $this->host = $host;
        $this->username = $username;
        $this->password = $password;

        $this->setHttpClient();
    }

    /**
     * Login to the IPMI
     *
     * @throws UnauthorizedException
     */
    public function login(): void
    {
        if ($this->logedIn === true) {
            return;
        }

        $this->checkSecureRedirect();

        $params = [
            'name' => $this->username,
            'pwd' => $this->password
        ];
        $result = $this->guzzle->request('POST', $this->getUri('/cgi/login.cgi'), ['form_params' => $params]);

        $body = (string) $result->getBody();

        if (strpos($body, 'mainmenu') === false) {
            throw new UnauthorizedException();
        }

        $this->logedIn = true;
    }



    /**
     * Returns current power consumption
     *
     */
    public function getPowerConsumption(): int
    {
        $this->login();

        $power = $this->ipmiRequest([
            // Old request
            'POWER_CONSUMPTION.XML' => '(0,0)',
            'time_stamp' => $this->getCurrentTimeStamp(),
            '_' => '',

            // New request
            'op' => 'POWER_CONSUMPTION.XML',
            'r' => '(0,0)',
            // '_' => ''
        ]);

        $result = 0;
        if ($power !== null && isset($power->PEAK['Current'])) {
            $result = (int) $power->PEAK['Current'];
        }

        if ($power !== null && isset($power->NOW['AVR'])) {
            $result = (int) $power->NOW['AVR'];
        }

        // Supermicro twin has different request
        if ($result == 0) {
            $power = $this->ipmiRequest([
                'Get_PowerSnrReading.XML' => '(0,0)',
                'time_stamp' => $this->getCurrentTimeStamp(),
                '_' => ''
            ]);

            if ($power !== null && isset($power->PowerSnr['PWR_Consumption'])) {
                $result = hexdec($power->PowerSnr['PWR_Consumption']);
            }
        }

        return (int) $result;
    }

    /**
     * Returns sensors info
     *
     */
    public function getSensors(): array
    {
        $this->login();

        $data = $this->ipmiRequest([
            // Old style
            'SENSOR_INFO.XML' => '(1,ff)',
            'time_stamp' => $this->getCurrentTimeStamp(),
            '_' => '',

            // New style
            'op' => 'SENSOR_INFO.XML',
            'r' => '(1,ff)',
            // '_' => ''
        ]);

        // var_dump($data);

        $result = [];
        if ($data === null) {
            return $result;
        }
        foreach ($data->SENSOR_INFO->SENSOR as $sensor) {
            $attributes = [];
            foreach ($sensor->attributes() as $name => $value) {
                $attributes[$name] = trim($value);
            }
            $result[] = new Sensor($attributes);
        }

        return $result;
    }

    /**
     * Returns Power status
     */
    public function getPowerStatus(): bool
    {
        $this->login();

        return $this->powerInfoIpmiRequest(0, 0) == 'ON' ? true : false;
    }

    /**
     * Power on action.
     *
     * @return void
     */
    public function powerOn(): void
    {
        $this->login();
        $this->powerInfoIpmiRequest(1, 1);
    }

    /**
     * Power off action.
     *
     * @return void
     */
    public function powerOff(): void
    {
        $this->login();
        $this->powerInfoIpmiRequest(1, 0);
    }

    /**
     * Power restart action.
     *
     * @return void
     */
    public function powerRestart(): void
    {
        $this->login();
        $this->powerInfoIpmiRequest(1, 3);
    }

    /**
     * Configures guzzle http client if none provieded
     *
     * @param \GuzzleHttp\Client $client is used in tests to provide mock
     */
    public function setHttpClient(\GuzzleHttp\Client $client = null): void
    {
        if ($client !== null) {
            $this->guzzle = $client;
            return;
        }

        $jar = new \GuzzleHttp\Cookie\CookieJar();

        $this->guzzle = new \GuzzleHttp\Client([
            'cookies' => $jar,
            'verify' => false,
            //            'debug' => true
        ]);
    }

    /**
     * Return current time stamp.
     *
     * Example of output: 'Mon Sep 09 2019 16:29:09 GMT+0200 (Central European Summer Time)'
     *
     * @return string
     */
    private function getCurrentTimeStamp(): string
    {
        return date('D M j Y H:i:s \G\M\TO', time());
    }

    /**
     * Checks https redirect
     *
     */
    private function checkSecureRedirect(): void
    {
        $response = $this->guzzle->request('GET', $this->getUri('/'), ['allow_redirects' => false]);
        $code = $response->getStatusCode();
        if ($code == 301) {
            $this->proto = 'https';
        }
    }

    /**
     * Returns full uri
     *
     * @param string $suffix Suffix
     */
    private function getUri(string $suffix): string
    {
        // This is ugly hack as some IPMI modules support https, some not
        // Newer modules require https
        // If you think of better testable way to manage this - feel free to do so
        return $this->proto . '://' . $this->host . '' . $suffix;
    }

    /**
     * Sends a request to /cgi/ipmi.cgi address and parses the xml response
     *
     * @param array $params Array with POST parameters
     * @return null|\SimpleXMLElement
     */
    private function ipmiRequest(array $params = [])
    {
        $response = $this->guzzle->request('POST', $this->getUri('/cgi/ipmi.cgi'), ['form_params' => $params]);

        // var_dump((string) $response->getBody());

        $xml = @simplexml_load_string((string) $response->getBody());
        return $xml == false ? null : $xml;
    }

    /**
     * Power info IPMI request.
     *
     * @param integer $param1 First request parameter (0 = read, 1 = write)
     * @param integer $param2 Second request parameter (0 = immediate off, 1 = on, 3 = restart)
     * @return string Power status
     */
    private function powerInfoIpmiRequest(int $param1, int $param2): string
    {
        $response = $this->ipmiRequest([
            // Old style
            'POWER_INFO.XML' => sprintf('(%d,%d)', $param1, $param2),
            'time_stamp' => $this->getCurrentTimeStamp(),
            '_' => '',

            // New style
            'op' => 'POWER_INFO.XML',
            'r' => sprintf('(%d,%d)', $param1, $param2),
            // '_' => ''
        ]);

        if ($response !== null && isset($response->POWER_INFO->POWER['STATUS'])) {
            return (string) $response->POWER_INFO->POWER['STATUS'];
        }

        throw new \UnexpectedValueException('Unable to fetch power status.');
    }
}
