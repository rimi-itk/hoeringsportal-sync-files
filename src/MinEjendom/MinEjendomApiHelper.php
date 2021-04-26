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

class MinEjendomApiHelper
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
     * @param $content
     */
    public function createDocument(array $values, $content)
    {
        try {
            $response = $this->client()->POST('api/Dokument/Create', [
                'query' => $values,
                // @see https://docs.guzzlephp.org/en/latest/request-options.html#multipart
                'multipart' => [
                    [
                        'name' => 'document',
                        'contents' => $content,
                    ],
                ],
            ]);
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
            $config = $this->archiver->getConfigurationValue('minejendom');
            $this->client = new Client([
                'base_uri' => $config['api_url'],
                'headers' => ['ApiKey' => $config['api_key']],
            ]);
        }

        return $this->client;
    }
}
