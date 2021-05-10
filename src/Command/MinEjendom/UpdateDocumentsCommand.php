<?php

/*
 * This file is part of hoeringsportal-sync-files.
 *
 * (c) 2018–2019 ITK Development
 *
 * This source file is subject to the MIT license.
 */

namespace App\Command\MinEjendom;

use App\Command\Command;
use App\Entity\Archiver;
use App\MinEjendom\Helper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;

class UpdateDocumentsCommand extends Command
{
    protected $archiverType = Archiver::TYPE_MIN_EJENDOM;

    /** @var Helper */
    private $helper;

    public function __construct(Helper $helper)
    {
        parent::__construct();
        $this->helper = $helper;
    }

    protected function configure()
    {
        parent::configure();
        $this->setName('app:min-ejendom:update-documents')
            ->setDescription('Upload documents to “Min ejendom”')
            ->addOption('eDoc-case-sequence-number', null, InputOption::VALUE_REQUIRED, 'eDoc case to update')
            ->addOption('eDoc-document-number', null, InputOption::VALUE_REQUIRED, 'eDoc document to update');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        parent::execute($input, $output);

        $eDocCaseSequenceNumber = $input->getOption('eDoc-case-sequence-number');
        $eDocDocumentNumber = $input->getOption('eDoc-document-number');
        $logger = new ConsoleLogger($output);
        $this->helper->setLogger($logger);
        $this->helper->updateDocuments($this->archiver, [
            'eDocCaseSequenceNumber' => $eDocCaseSequenceNumber,
            'eDocDocumentNumber' => $eDocDocumentNumber,
        ]);
    }
}
