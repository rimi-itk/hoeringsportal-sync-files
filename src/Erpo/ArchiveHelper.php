<?php

/*
 * This file is part of hoeringsportal-sync-files.
 *
 * (c) 2018–2019 ITK Development
 *
 * This source file is subject to the MIT license.
 */

namespace App\Erpo;

use App\Entity\Archiver;
use App\Entity\EDoc\CaseFile;
use App\Entity\ExceptionLogEntry;
use App\Exception\RuntimeException;
use App\Repository\EDoc\CaseFileRepository;
use App\Service\AbstractArchiveHelper;
use App\Service\EdocService;
use App\ShareFile\Item;
use App\Util\TemplateHelper;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use GuzzleHttp\Client;
use ItkDev\Edoc\Entity\ArchiveFormat;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerTrait;

class ArchiveHelper extends AbstractArchiveHelper
{
    use LoggerAwareTrait;
    use LoggerTrait;

    /**
     * {@inheritdoc}
     */
    protected $archiverType = Archiver::TYPE_ERPO2SHAREFILE2EDOC;

    /** @var ShareFileService */
    private $shareFile;

    /** @var EdocService */
    private $edoc;

    /** @var EntityManagerInterface */
    private $entityManager;

    /** @var \Swift_Mailer */
    private $mailer;

    /** @var Archiver */
    private $archiver;

    /** @var TemplateHelper */
    private $templateHelper;

    public function __construct(ShareFileService $shareFile, EdocService $edoc, CaseFileRepository $caseFileRepository, EntityManagerInterface $entityManager, \Swift_Mailer $mailer, TemplateHelper $templateHelper)
    {
        $this->shareFile = $shareFile;
        $this->edoc = $edoc;
        $this->entityManager = $entityManager;
        $this->mailer = $mailer;
        $this->templateHelper = $templateHelper;
    }

    public function archive(Archiver $archiver, $itemId = null)
    {
        $this->archiver = $archiver;

        try {
            if (!$archiver->isEnabled()) {
                throw new \RuntimeException('Archiver '.$archiver.' is not enabled.');
            }

            if ($archiver->getType() !== $this->archiverType) {
                throw new \RuntimeException('Cannot handle archiver with type '.$archiver->getType());
            }

            $this->shareFile->setArchiver($archiver);
            $this->edoc->setArchiver($archiver);

            $startTime = new \DateTime();
            $date = null;

            // @FIXME: Getting data from ShareFile should be moved into a service/helper.
            if (null !== $itemId) {
                $this->info('Getting item '.$itemId);
                $erpo = $this->shareFile->getErpoItem($itemId);
                $shareFileData = [$erpo];
            } else {
                $date = $archiver->getLastRunAt() ?? new \DateTime('1 month ago');
                $this->info('Getting files updated since '.$date->format(\DateTime::ATOM).' from ShareFile');
                $shareFileData = $this->shareFile->getUpdatedFiles($date);
            }

            foreach ($shareFileData as $shareFileFolder) {
                $edocCaseFile = null;

                foreach ($shareFileFolder->getChildren() as $shareFileDocument) {
                    try {
                        $metadata = $shareFileFolder->metadata;
                        $documentMetadata = $shareFileDocument->metadata;

                        if (null === $edocCaseFile) {
                            if ($archiver->getCreateCaseFile()) {
                                $this->info('Getting case file: '.$shareFileFolder->name);

                                $data = [];

                                if (isset($metadata['handlingsfacet'])) {
                                    $handlingCode = $this->edoc->getHandlingCodeByName($metadata['handlingsfacet']);
                                    if ($handlingCode) {
                                        $data['HandlingCodeId'] = $handlingCode['HandlingCodeId'];
                                    }
                                }
                                if (isset($metadata['kle'])) {
                                    $primaryCode = $this->edoc->getPrimaryCodeByCode($metadata['kle']);
                                    if ($primaryCode) {
                                        $data['PrimaryCode'] = $primaryCode['PrimaryCodeId'];
                                    }
                                }

                                if (isset($metadata['indsigtsgradId'])) {
                                    $data['PublicAccess'] = $metadata['indsigtsgradId'];
                                }

                                $this->debug(json_encode(['eDoc Case file data' => $data], JSON_PRETTY_PRINT));

                                $callback = function (array $parameters) use ($archiver) {
                                    $status = $parameters['status'] ?? null;
                                    if (EdocService::CREATED !== $status) {
                                        return;
                                    }

                                    /** @var Item $item */
                                    $item = $parameters['item'] ?? null;
                                    /** @var CaseFile $caseFile */
                                    $caseFile = $parameters['case_file'] ?? null;
                                    $webhook = $archiver->getConfigurationValue('[edoc][case_file][webhook]');
                                    if ($caseFile && isset($webhook['url'])) {
                                        $url = $this->templateHelper->render($webhook['url'], [
                                            'item' => $item,
                                        ]);
                                        $payload = [
                                            'edoc_case_file' => $caseFile->getData(),
                                        ];
                                        $payload['esdh'] = $payload['edoc_case_file']['SequenceNumber'];
                                        $client = new Client($webhook['guzzle_options'] ?? []);
                                        $client->request($webhook['method'], $url, [
                                            'json' => $payload,
                                        ]);
                                    }
                                };

                                $edocCaseFile = $this->edoc->ensureCaseFile($shareFileFolder, $data, [
                                    'callback' => $callback,
                                ]);

                                if (null === $edocCaseFile) {
                                    throw new RuntimeException('Error creating case file: '.$shareFileFolder['Name']);
                                }
                            }
                        }

                        if (null === $edocCaseFile) {
                            throw new RuntimeException('Cannot get case file: '.$shareFileFolder['Name']);
                        }

                        $this->info($shareFileDocument->name);
                        $edocDocument = $this->edoc->getResponse($edocCaseFile, $shareFileDocument);
                        $this->info('Getting file contents from ShareFile');

                        $sourceFile = null;
                        $sourceFileCreatedAt = null;
                        $sourceFileType = null;

                        $sourceFile = $shareFileDocument;
                        $sourceFileCreatedAt = new \DateTime($shareFileDocument->creationDate);
                        $sourceFileType = ArchiveFormat::getArchiveFormat($sourceFile->getName());

                        // @TODO Download file content only if it's needed.
                        $fileContents = $this->shareFile->downloadFile($sourceFile);
                        if (null === $fileContents) {
                            throw new RuntimeException('Cannot get file contents for item '.$shareFileDocument->id);
                        }
                        $fileData = [
                            'ArchiveFormatCode' => $sourceFileType,
                            'DocumentContents' => base64_encode($fileContents),
                        ];
                        if (null === $edocDocument) {
                            $this->info('Creating new document in eDoc');

                            $data = [
                                'DocumentVersion' => $fileData,
                            ];
                            if (isset($metadata['indsigtsgradId'])) {
                                $data['PublicAccess'] = $metadata['indsigtsgradId'];
                            }

                            if (isset($documentMetadata['dokumentdato'])) {
                                try {
                                    $documentDate = new DateTimeImmutable($documentMetadata['dokumentdato']);
                                    $data['DocumentDate'] = $documentDate->format('Y-m-d');
                                } catch (Exception $e) {
                                }
                            }

                            if (isset($documentMetadata['dokumenttype'])) {
                                $documentType = $this->edoc->getDocumentTypeByName($documentMetadata['dokumenttype']);
                                if ($documentType) {
                                    $data['DocumentTypeReference'] = $documentType['DocumentTypeId'];
                                }
                            }

                            if (isset($documentMetadata['status'])) {
                                $documentStatus = $this->edoc->getDocumentStatusByName($documentMetadata['status']);
                                if ($documentStatus) {
                                    $data['DocumentStatusCode'] = $documentStatus['DocumentStatusCodeId'];
                                }
                            }

                            $this->debug(json_encode(['eDoc Document data' => $data], JSON_PRETTY_PRINT));

                            $edocDocument = $this->edoc->createDocument($edocCaseFile, $shareFileDocument, $data);
                        } else {
                            $documentUpdatedAt = $this->edoc->getDocumentUpdatedAt($edocDocument);
                            if ($documentUpdatedAt < $sourceFileCreatedAt) {
                                $this->info('Updating document in eDoc');
                                $edocDocument = $this->edoc->updateDocument(
                                    $edocDocument,
                                    $shareFileDocument,
                                    $fileData
                                );
                            } else {
                                $this->info('Document in eDoc is already up to date');
                            }
                        }
                        if (null === $edocDocument) {
                            throw new RuntimeException('Error creating response: '.$shareFileDocument['Name']);
                        }
                    } catch (\Throwable $t) {
                        $this->logException($t, [
                            'shareFileFolder' => $shareFileFolder,
                            'shareFileDocument' => $shareFileDocument,
                        ]);
                    }
                }
            }

            if (null === $itemId) {
                $archiver->setLastRunAt($startTime);
                $this->entityManager->persist($archiver);
                $this->entityManager->flush();
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
