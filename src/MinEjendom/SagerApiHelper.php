<?php

/*
 * This file is part of hoeringsportal-sync-files.
 *
 * (c) 2018–2019 ITK Development
 *
 * This source file is subject to the MIT license.
 */

namespace App\MinEjendom;

use App\Entity\Archiver;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

class SagerApiHelper
{
    protected $archiverType = Archiver::TYPE_MIN_EJENDOM;

    /** @var Archiver */
    private $archiver;

    private $client;

    public function setArchiver(Archiver $archiver)
    {
        if ($archiver->getType() !== $this->archiverType) {
            throw new \RuntimeException('Invalid archiver type: '.$archiver->getType());
        }
        $this->archiver = $archiver;
    }

    /**
     * [
     *  {"esdh": …, "minEjendomGuid": …, "minEjendomId": …},
     *  …
     * ].
     *
     * @return array
     */
    public function getSager(bool $getCompleted = false)
    {
        $path = $getCompleted ? 'api/sager/afsluttede' : 'api/sager';
        $response = $this->client()->GET($path);

        return json_decode((string) $response->getBody(), true);
    }

    public function deleteDocument(string $documentGuid)
    {
        try {
            $response = $this->client()->DELETE('api/Dokumenter/'.$documentGuid);
        } catch (ClientException $exception) {
            $response = $exception->getResponse();
        }

        return $response;
    }

    private function client(): Client
    {
        if (null === $this->archiver) {
            throw new \RuntimeException('Missing archiver');
        }

        if (null === $this->client) {
            $config = $this->archiver->getConfigurationValue('sager');
            $this->client = new Client([
                'base_uri' => $config['api_url'],
                'auth' => [$config['api_username'], $config['api_password']],
            ]);
        }

        return $this->client;
    }
}
