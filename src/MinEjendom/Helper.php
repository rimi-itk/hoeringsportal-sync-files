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
use App\Entity\ExceptionLogEntry;
use App\Entity\MinEjendom\Document;
use App\Repository\MinEjendom\DocumentRepository;
use App\Service\AbstractArchiveHelper;
use App\Service\EdocService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerTrait;

class Helper extends AbstractArchiveHelper
{
    use LoggerAwareTrait;
    use LoggerTrait;

    /**
     * {@inheritdoc}
     */
    protected $archiverType = Archiver::TYPE_MIN_EJENDOM;

    /** @var SagerApiHelper */
    private $sager;

    /** @var EdocService */
    private $edoc;

    /** @var MinEjendomApiHelper */
    private $minEjendom;

    /** @var DocumentRepository */
    private $documentRepository;

    /** @var EntityManagerInterface */
    private $entityManager;

    /** @var \Swift_Mailer */
    private $mailer;

    public function __construct(SagerApiHelper $sager, EdocService $edoc, MinEjendomApiHelper $minEjendom, DocumentRepository $documentRepository, EntityManagerInterface $entityManager, \Swift_Mailer $mailer)
    {
        $this->sager = $sager;
        $this->edoc = $edoc;
        $this->minEjendom = $minEjendom;
        $this->documentRepository = $documentRepository;
        $this->entityManager = $entityManager;
        $this->mailer = $mailer;
    }

    public function updateDocuments(Archiver $archiver, string $eDocCaseSequenceNumber = null)
    {
        $this->archiver = $archiver;

        try {
            $this->sager->setArchiver($archiver);
            $this->edoc->setArchiver($archiver);
            $this->minEjendom->setArchiver($archiver);

            $sager = $this->sager->getSager();
            foreach ($sager as $index => $sag) {
                try {
                    $eDocCaseSequenceNumber = $sag['esdh'];
                    $byggesagGuid = $sag['minEjendomGuid'];

                    $this->info(sprintf('% 4d: %s -> %s', $index + 1, $eDocCaseSequenceNumber, $byggesagGuid));

                    $case = $this->edoc->getCaseBySequenceNumber($eDocCaseSequenceNumber);
                    $documents = $this->edoc->getDocumentList($case);
                    foreach ($documents as $document) {
                        try {
                            $minEjendomDocument = $this->documentRepository->findOneBy([
                                'archiver' => $archiver,
                                'eDocCaseSequenceNumber' => $eDocCaseSequenceNumber,
                                'documentIdentifier' => $document->DocumentIdentifier,
                            ])
                                                ?? (new Document())
                                                    ->setArchiver($archiver)
                                                    ->setEDocCaseSequenceNumber($eDocCaseSequenceNumber)
                                                    ->setDocumentIdentifier($document->DocumentIdentifier);

                            $minEjendomDocument->addData('[sag]', $sag);

                            $baseUrl = rtrim($archiver->getConfigurationValue('[minejendom][url]'), '/');
                            $data = [
                                'base_url' => $baseUrl,
                            ];
                            if (isset($sag['minEjendomId'])) {
                                $data['case_url'] = $baseUrl.'/Byggesag/Vis/'.$sag['minEjendomId'];
                            }

                            $minEjendomDocument->addData('[minejendom]', $data);

                            $minEjendomDocument->addData('[edoc][case]', $case->getData());
                            $minEjendomDocument->addData('[edoc][document]', $document->getData());

                            $this->info(sprintf('Document: %s (%s)', $document->DocumentNumber, $document->DocumentIdentifier));

                            $version = $this->edoc->getDocumentVersion($document);

                            $data = $version->getData();
                            unset($data['DocumentContents']);
                            $minEjendomDocument->addData('[edoc][version]', $data);

                            $this->info(sprintf('Version: %s', $version->DocumentVersionNumber));

                            $data = [
                                'byggesagGuid' => $byggesagGuid,
                                'originalCreatedDate' => $document->DocumentDate,
                                'EksternID' => $document->DocumentNumber,
                                'aktNummer' => 1, // @todo
                                'beskrivelse' => $document->TitleText,
                                'filename' => $document->DocumentVersionIdentifier,
                                'imageFormat' => '.'.strtolower($document->ArchiveFormatFileExtension),
                            ];

                            $minEjendomDocument->addData('[document][data]', $data);

                            $response = $this->minEjendom->createDocument($data, $version->getBinaryContents());

                            $minEjendomDocument->addData('[document][response]', [
                                'status_code' => $response->getStatusCode(),
                                'body' => (string) $response->getBody(),
                            ]);

                            $this->info(sprintf('Response status code: %d', $response->getStatusCode()));

                            $this->entityManager->persist($minEjendomDocument);
                            $this->entityManager->flush();
                        } catch (\Throwable $t) {
                            $this->logException($t, [
                                'sag' => $sag,
                                'case' => $case->getData(),
                                'document' => $document->getData(),
                            ]);
                        }
                    }
                } catch (\Throwable $t) {
                    $this->logException($t, [
                        'sag' => $sag,
                    ]);
                }
            }
        } catch (\Throwable $t) {
            $this->logException($t);
        }
    }

    public function log($level, $message, array $context = [])
    {
        if (null !== $this->logger) {
            $this->logger->log($level, $message, $context);
        }
    }

    private function logException(\Throwable $t, array $context = [])
    {
        $this->emergency($t->getMessage(), $context);
        $logEntry = new ExceptionLogEntry($t, $context);
        $this->entityManager->persist($logEntry);
        $this->entityManager->flush();

        if (null !== $this->archiver) {
            $config = $this->archiver->getConfigurationValue('[notifications][email]');

            $message = (new \Swift_Message($t->getMessage()))
                ->setFrom($config['from'])
                ->setTo($config['to'])
                ->setBody(
                         implode(PHP_EOL, [
                             json_encode($context, JSON_PRETTY_PRINT),
                             $t->getTraceAsString(),
                         ]),
                         'text/plain'
                     );

            $this->mailer->send($message);
        }
    }
}
