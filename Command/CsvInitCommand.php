<?php

namespace Plugin\StripeRec\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Eccube\Entity\Block;
use Eccube\Entity\BlockPosition;
use Eccube\Entity\Page;
use Eccube\Entity\PageLayout;
use Eccube\Entity\Layout;
use Eccube\Repository\PageRepository;
use Plugin\StripeRec\PluginManager;


class CsvInitCommand extends Command {
    protected static $defaultName = "striperec:csv:init";

    protected $container;
    protected $entityManager;

    public function __construct(
        ContainerInterface $container
    )
    {
        $this->container = $container;
        $this->entityManager = $container->get('doctrine.orm.entity_manager');
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        PluginManager::updateCsvExportData($this->container);
        $output->write("csv insertion completed\n");
    }

}