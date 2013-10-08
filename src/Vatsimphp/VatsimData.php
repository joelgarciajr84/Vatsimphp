<?php

/*
 * This file is part of the Vatsimphp package
 *
 * Copyright 2013 - Jelle Vink <jelle.vink@gmail.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 */

namespace Vatsimphp;

use Vatsimphp\Sync\StatusSync;
use Vatsimphp\Sync\DataSync;
use Vatsimphp\Sync\MetarSync;

/**
 *
 * Main consumer class to retrieve and
 * query data from the VATSIM network
 *
 */
class VatsimData
{
    /**
     *
     * Default configuration
     * @var array
     */
    protected $config = array(

        // cache settings
        'cacheDir' => '../Cache',
        'cacheOnly' => false,

        // vatsim status file
        'statusUrl' => '',
        'statusRefresh' => 86400,

        // vatsim data file
        'dataRefresh' => 180,
        'dataExpire' => 3600,
        'forceDataRefresh' => false,

        // metar settings
        'metarRefresh' => 600,
        'forceMetarRefresh' => false,
    );

    /**
     *
     * Result iterator
     * @var \Vatsimphp\Result\ResultContainer
     */
    protected $results;

    /**
     *
     * Exception stack
     * @var array
     */
    protected $exceptionStack = array();

    /**
     *
     * Status Sync object
     * @var \Vatsimphp\Sync\StatusSync
     */
    protected $statusSync;

    /**
     *
     * Metar Sync object
     * @var \Vatsimphp\Sync\MetarSync
     */
    protected $metarSync;


    /*** EASY API ***/

    /**
     *
     * Search for a callsign
     * @param string $callsign
     */
    public function searchCallsign($callsign)
    {
        return $this->search('clients', array('callsign' => $callsign));
    }

    /**
     *
     * Get METAR
     * @param string $icao
     * @return string
     */
    public function getMetar($icao)
    {
        if ($this->loadMetar($icao)) {
            $result = $this->metar->toArray();
            if (!empty($result[0])) {
                return $result[0];
            }
        }
    }


    /*** ADVANCED API ***/

    /**
     *
     * Load data from Vatsim network
     * @return boolean
     */
    public function loadData()
    {
        try {
            $status = $this->prepareSync();
            $data = $this->getDataSync();
            $data->setDefaults();
            $data->cacheDir = $this->config['cacheDir'];
            $data->cacheOnly = $this->config['cacheOnly'];
            $data->dataExpire = $this->config['dataExpire'];
            $data->refreshInterval = $this->config['dataRefresh'];
            $data->forceRefresh = $this->config['forceDataRefresh'];
            $data->registerUrlFromStatus($status, 'dataUrls');
            $this->results = $data->loadData();
        } catch (\Exception $e) {
            $this->exceptionStack[] = $e;
            return false;
        }
        return true;
    }

    /**
     *
     * Load METAR data for given airport
     * @param string $icao
     * @return boolean
     */
    public function loadMetar($icao)
    {
        try {
            $metar = $this->prepareMetarSync();
            $metar->setAirport($icao);
            $this->results = $metar->loadData();
        } catch (\Exception $e) {
            $this->exceptionStack[] = $e;
            return false;
        }
        return true;
    }

    /**
     *
     * Expose search on result container
     * @param string $objectType
     * @param array $query
     */
    public function search($objectType, $query)
    {
        return $this->results->search($objectType, $query);
    }

    /**
     *
     * Wrapper returning available data objects
     * @return array
     */
    public function getObjectTypes()
    {
        return $this->results->getList();
    }

    /**
     *
     * Overload method to access data objects directly
     * @param string $objectType
     * @return \Vatsimphp\Filter\Iterator
     */
    public function __get($objectType)
    {
        return $this->getIterator($objectType);
    }

    /**
     *
     * Access data object
     * @param string $objectType
     * @return array
     */
    public function getArray($objectType)
    {
        return $this->getIterator($objectType)->toArray();
    }

    /**
     *
     * Access data object
     * @param string $objectType
     * @return \Vatsimphp\Filter\Iterator
     */
    public function getIterator($objectType)
    {
        return $this->results->get($objectType);
    }

    /**
     *
     * Return exception stack
     * @return array
     */
    public function getExceptionStack()
    {
        return $this->exceptionStack;
    }

    /**
     *
     * Override default config settings
     * @param string $key
     * @param mixed(string|boolean|integer) $value
     */
    public function setConfig($key, $value)
    {
        if (isset($this->config[$key])) {
            $this->config[$key] = $value;
        }
    }

    /**
     *
     * Return config key
     * @param string $key
     * @return mixed(string|boolean|integer)
     */
    public function getConfig($key = null)
    {
        if (empty($key)) {
            return $this->config;
        }
        if (isset($this->config[$key])) {
            return $this->config[$key];
        }
    }


    /*** INTERNAL METHODS ***/

    /**
     *
     * Prepare StatusSync object for reusage
     * @return Vatsimphp\Sync\StatusSync
     */
    protected function prepareSync()
    {
        if (! empty($this->statusSync)) {
            return $this->statusSync;
        }
        $this->statusSync = $this->getStatusSync();
        $this->statusSync->setDefaults();
        $this->statusSync->cacheDir = $this->config['cacheDir'];
        $this->statusSync->refreshInterval = $this->config['statusRefresh'];
        if (!empty($this->config['statusUrl'])) {
            $this->statusSync->registerUrl($this->config['statusUrl'], true);
        }
        return $this->statusSync;
    }

    /**
     *
     * Prepare MetarSync object for reusage
     * @return Vatsimphp\Sync\MetarSync
     */
    protected function prepareMetarSync()
    {
        if (! empty($this->metarSync)) {
            return $this->metarSync;
        }
        $this->metarSync = $this->getMetarSync();
        $this->metarSync->setDefaults();
        $this->metarSync->cacheDir = $this->config['cacheDir'];
        $this->metarSync->cacheOnly = false;
        $this->metarSync->refreshInterval = $this->config['metarRefresh'];
        $this->metarSync->forceRefresh = $this->config['forceMetarRefresh'];
        $this->metarSync->registerUrlFromStatus($this->prepareSync(), 'metarUrls');
        return $this->metarSync;
    }

    /**
     * @return Vatsimphp\Sync\StatusSync
     */
    protected function getStatusSync()
    {
        return new StatusSync();
    }

    /**
     * @return Vatsimphp\Sync\DataSync
     */
    protected function getDataSync()
    {
        return new DataSync();
    }

    /**
     * @return Vatsimphp\Sync\MetarSync
     */
    protected function getMetarSync()
    {
        return new MetarSync();
    }
}
