<?php

/*
 * This file is part of hoeringsportal-sync-files.
 *
 * (c) 2018–2019 ITK Development
 *
 * This source file is subject to the MIT license.
 */

namespace App\Command\ShareFile;

use App\Command\Command;
use App\Service\ShareFileService;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ItemMetadataShow extends Command
{
    /** @var ShareFileService */
    private $shareFile;

    public function __construct(ShareFileService $shareFile)
    {
        parent::__construct();
        $this->shareFile = $shareFile;
    }

    public function configure()
    {
        parent::configure();
        $this->setName('app:sharefile:item-metadata-show')
            ->addArgument('item-id', InputArgument::REQUIRED, 'The item id');
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        parent::execute($input, $output);
        $itemId = $input->getArgument('item-id');

        $this->shareFile->setArchiver($this->archiver);
        $item = $this->shareFile->getItem($itemId);

        $table = new Table($output);
        $table->addRow(['id', $item->getId()]);
        $table->render();

        $metadata = $this->shareFile->getMetadataValues($item);

        $table = new Table($output);
        foreach ($metadata as $name => $value) {
            if (\is_array($value)) {
                foreach ($value as $key => $val) {
                    $table->addRow([$name, $key, is_scalar($val) ? $val : json_encode($val, JSON_PRETTY_PRINT)]);
                }
            } elseif (is_scalar($value)) {
                $table->addRow([$name, $value]);
            } else {
                $table->addRow([$name, json_encode($value, JSON_PRETTY_PRINT)]);
            }
        }
        $table->render();

        return 0;
    }
}
