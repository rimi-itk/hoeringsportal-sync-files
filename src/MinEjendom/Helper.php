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
use ItkDev\Edoc\Entity\CaseFile;
use ItkDev\Edoc\Entity\Document as EDocDocument;
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

    /** @var array */
    private $archiveFormats;

    public function __construct(SagerApiHelper $sager, EdocService $edoc, MinEjendomApiHelper $minEjendom, DocumentRepository $documentRepository, EntityManagerInterface $entityManager, \Swift_Mailer $mailer)
    {
        $this->sager = $sager;
        $this->edoc = $edoc;
        $this->minEjendom = $minEjendom;
        $this->documentRepository = $documentRepository;
        $this->entityManager = $entityManager;
        $this->mailer = $mailer;
    }

    public function updateDocuments(Archiver $archiver, array $options = [])
    {
        $this->archiver = $archiver;

        try {
            $this->sager->setArchiver($archiver);
            $this->edoc->setArchiver($archiver);
            $this->minEjendom->setArchiver($archiver);

            // Archive formats indexed by Code.
            $this->archiveFormats = array_column($this->edoc->getArchiveFormats(), null, 'Code');
            $sager = $this->sager->getSager();
            foreach ($sager as $index => $sag) {
                try {
                    $eDocCaseSequenceNumber = $sag['esdh'];
                    $byggesagGuid = $sag['minEjendomGuid'];

                    if (isset($options['eDocCaseSequenceNumber']) && $options['eDocCaseSequenceNumber'] !== $eDocCaseSequenceNumber) {
                        continue;
                    }

                    $this->info(sprintf('% 4d: %s -> %s', $index + 1, $eDocCaseSequenceNumber, $byggesagGuid));

                    $case = $this->edoc->getCaseBySequenceNumber($eDocCaseSequenceNumber);
                    $documents = $this->edoc->getDocumentList($case);
                    foreach ($documents as $document) {
                        try {
                            if (isset($options['eDocDocumentNumber']) && $options['eDocDocumentNumber'] !== $document->DocumentNumber) {
                                continue;
                            }

                            $this->createDocument($document, $sag, $case);

                            $attachments = $this->edoc->getAttachments($document);
                            foreach ($attachments as $attachment) {
                                $this->createDocument($attachment, $sag, $case, $document);
                            }
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

    private function createDocument(EDocDocument $document, array $sag, CaseFile $case, EDocDocument $mainDocument = null)
    {
        try {
            $eDocCaseSequenceNumber = $sag['esdh'];
            $byggesagGuid = $sag['minEjendomGuid'];

            $documentDocumentIdentifier = $document->DocumentIdentifier;
            $documentTitle = $document->TitleText;
            $minEjendomDocument = $this->documentRepository->findOneBy([
                'archiver' => $this->archiver,
                'eDocCaseSequenceNumber' => $eDocCaseSequenceNumber,
                'documentIdentifier' => $documentDocumentIdentifier,
                'documentTitle' => $documentTitle,
            ]) ??
                (new Document())
                    ->setArchiver($this->archiver)
                    ->setEDocCaseSequenceNumber($eDocCaseSequenceNumber)
                    ->setDocumentIdentifier($documentDocumentIdentifier)
                    ->setDocumentTitle($documentTitle);

            $minEjendomDocument->addData('[sag]', $sag);

            $baseUrl = rtrim($this->archiver->getConfigurationValue('[minejendom][url]'), '/');
            $data = [
                'base_url' => $baseUrl,
            ];
            if (isset($sag['minEjendomId'])) {
                $data['case_url'] = $baseUrl.'/Byggesag/Vis/'.$sag['minEjendomId'];
            }

            $minEjendomDocument->addData('[minejendom]', $data);

            $minEjendomDocument->addData('[edoc][case]', $case->getData());
            $minEjendomDocument->addData('[edoc][document]', $document->getData());
            if (null !== $mainDocument) {
                $minEjendomDocument->addData('[edoc][main-document]', $mainDocument->getData());
            }
            $this->info(sprintf('Document: %s (%s)', $document->DocumentNumber, $document->DocumentIdentifier));

            // The main document.
            $version = $this->edoc->getDocumentVersion($document->DocumentVersionIdentifier);

            $data = $version->getData();
            unset($data['DocumentContents']);
            $minEjendomDocument->addData('[edoc][version]', $data);

            $this->info(sprintf('Version: %s', $version->DocumentVersionNumber));

            $imageFormat = $this->archiveFormats[$version->ArchiveFormatCode]->FileExtension ?? '';
            $aktNummer = 1;
            if (preg_match('/-(?<number>\d+)$/', $document->DocumentNumber, $matches)) {
                $aktNummer = (int) $matches['number'];
            }
            $data = [
                'byggesagGuid' => $byggesagGuid,
                'originalCreatedDate' => $document->DocumentDate,
                'EksternID' => $document->DocumentNumber,
                'aktNummer' => $aktNummer,
                'beskrivelse' => $document->TitleText,
                'filename' => $documentTitle,
                'imageFormat' => '.'.strtolower($imageFormat),
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
